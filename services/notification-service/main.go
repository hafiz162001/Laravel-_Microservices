package main

import (
	"encoding/json"
	"fmt"
	"log"
	"net/http"
	"os"
	"sync"
	"sync/atomic"
	"time"

	amqp "github.com/rabbitmq/amqp091-go"
)

type OrderEvent struct {
	OrderID   string  `json:"order_id"`
	UserID    int     `json:"user_id"`
	UserEmail string  `json:"user_email"`
	Item      string  `json:"item"`
	Amount    float64 `json:"amount"`
	Status    string  `json:"status"`
	CreatedAt string  `json:"created_at"`
}

type Notification struct {
	ID        string     `json:"id"`
	Channel   string     `json:"channel"`
	Recipient string     `json:"recipient"`
	Message   string     `json:"message"`
	Order     OrderEvent `json:"order"`
	SentAt    string     `json:"sent_at"`
}

type NotificationStore struct {
	mu            sync.RWMutex
	notifications []Notification
}

func (s *NotificationStore) Add(n Notification) {
	s.mu.Lock()
	defer s.mu.Unlock()
	s.notifications = append(s.notifications, n)
}

func (s *NotificationStore) List() []Notification {
	s.mu.RLock()
	defer s.mu.RUnlock()
	out := make([]Notification, len(s.notifications))
	copy(out, s.notifications)
	return out
}

var (
	store                       = &NotificationStore{}
	httpRequestsTotal           atomic.Int64
	notificationsProcessedTotal atomic.Int64
	rabbitmqConnected           atomic.Bool
)

func processOrderMessage(body []byte) (Notification, error) {
	var event OrderEvent
	if err := json.Unmarshal(body, &event); err != nil {
		return Notification{}, fmt.Errorf("invalid order event JSON: %w", err)
	}

	recipient := event.UserEmail
	if recipient == "" {
		recipient = fmt.Sprintf("user-%d@example.com", event.UserID)
	}

	seq := notificationsProcessedTotal.Add(1)
	notif := Notification{
		ID:        fmt.Sprintf("NOTIF-%d", seq),
		Channel:   "EMAIL",
		Recipient: recipient,
		Message:   fmt.Sprintf("Order %s for '%s' (Rp %.0f) received!", event.OrderID, event.Item, event.Amount),
		Order:     event,
		SentAt:    time.Now().UTC().Format(time.RFC3339),
	}

	store.Add(notif)
	log.Printf("[NOTIFICATION SENT] %s -> %s: %s", notif.ID, notif.Recipient, notif.Message)
	return notif, nil
}

func startRabbitMQConsumer(amqpURL string, queueName string) {
	for {
		conn, err := amqp.Dial(amqpURL)
		if err != nil {
			rabbitmqConnected.Store(false)
			log.Printf("RabbitMQ not ready (%v), retrying in 3s...", err)
			time.Sleep(3 * time.Second)
			continue
		}

		ch, err := conn.Channel()
		if err != nil {
			rabbitmqConnected.Store(false)
			conn.Close()
			log.Printf("Failed to open RabbitMQ channel (%v), retrying in 3s...", err)
			time.Sleep(3 * time.Second)
			continue
		}

		_, err = ch.QueueDeclare(queueName, true, false, false, false, nil)
		if err != nil {
			rabbitmqConnected.Store(false)
			ch.Close()
			conn.Close()
			log.Printf("Failed to declare queue (%v), retrying in 3s...", err)
			time.Sleep(3 * time.Second)
			continue
		}

		msgs, err := ch.Consume(queueName, "notification-service", false, false, false, false, nil)
		if err != nil {
			rabbitmqConnected.Store(false)
			ch.Close()
			conn.Close()
			log.Printf("Failed to start consumer (%v), retrying in 3s...", err)
			time.Sleep(3 * time.Second)
			continue
		}

		rabbitmqConnected.Store(true)
		log.Printf("Connected to RabbitMQ! Listening on queue '%s'...", queueName)

		for d := range msgs {
			if _, err := processOrderMessage(d.Body); err != nil {
				log.Printf("Error processing message: %v", err)
				_ = d.Nack(false, false)
				continue
			}
			_ = d.Ack(false)
		}

		rabbitmqConnected.Store(false)
		log.Println("RabbitMQ connection closed, reconnecting in 3s...")
		ch.Close()
		conn.Close()
		time.Sleep(3 * time.Second)
	}
}

func buildMux() *http.ServeMux {
	mux := http.NewServeMux()

	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		httpRequestsTotal.Add(1)
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"status":             "healthy",
			"service":            "notification-service",
			"language":           "go",
			"rabbitmq_connected": rabbitmqConnected.Load(),
			"timestamp":          time.Now().UTC().Format(time.RFC3339),
		})
	})

	mux.HandleFunc("/notifications", func(w http.ResponseWriter, r *http.Request) {
		httpRequestsTotal.Add(1)
		list := store.List()
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"service":       "notification-service",
			"total":         len(list),
			"notifications": list,
		})
	})

	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
		connectedVal := 0
		if rabbitmqConnected.Load() {
			connectedVal = 1
		}
		fmt.Fprintf(w, "# HELP notification_http_requests_total Total HTTP requests handled\n")
		fmt.Fprintf(w, "# TYPE notification_http_requests_total counter\n")
		fmt.Fprintf(w, "notification_http_requests_total %d\n", httpRequestsTotal.Load())
		fmt.Fprintf(w, "# HELP notifications_processed_total Total RabbitMQ order notifications processed\n")
		fmt.Fprintf(w, "# TYPE notifications_processed_total counter\n")
		fmt.Fprintf(w, "notifications_processed_total %d\n", notificationsProcessedTotal.Load())
		fmt.Fprintf(w, "# HELP notification_rabbitmq_connected RabbitMQ consumer connection status\n")
		fmt.Fprintf(w, "# TYPE notification_rabbitmq_connected gauge\n")
		fmt.Fprintf(w, "notification_rabbitmq_connected %d\n", connectedVal)
	})

	return mux
}

func main() {
	port := os.Getenv("PORT")
	if port == "" {
		port = "8003"
	}

	rabbitHost := os.Getenv("RABBITMQ_HOST")
	if rabbitHost == "" {
		rabbitHost = "rabbitmq"
	}
	rabbitPort := os.Getenv("RABBITMQ_PORT")
	if rabbitPort == "" {
		rabbitPort = "5672"
	}
	rabbitUser := os.Getenv("RABBITMQ_USER")
	if rabbitUser == "" {
		rabbitUser = "guest"
	}
	rabbitPass := os.Getenv("RABBITMQ_PASSWORD")
	if rabbitPass == "" {
		rabbitPass = "guest"
	}

	amqpURL := fmt.Sprintf("amqp://%s:%s@%s:%s/", rabbitUser, rabbitPass, rabbitHost, rabbitPort)
	go startRabbitMQConsumer(amqpURL, "order_created_queue")

	log.Printf("Notification Service (Go) listening on :%s", port)
	if err := http.ListenAndServe(":"+port, buildMux()); err != nil {
		log.Fatal(err)
	}
}

