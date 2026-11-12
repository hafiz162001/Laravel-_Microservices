package main

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
)

func TestHealthEndpoint(t *testing.T) {
	mux := buildMux()
	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	rec := httptest.NewRecorder()

	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected status 200, got %d", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), `"service":"notification-service"`) {
		t.Fatalf("unexpected body: %s", rec.Body.String())
	}
}

func TestProcessOrderMessageAndListNotifications(t *testing.T) {
	store = &NotificationStore{}
	notificationsProcessedTotal.Store(0)

	payload := []byte(`{"order_id":"ORD-TEST123","user_id":7,"user_email":"buyer@example.com","item":"Mechanical Keyboard","amount":1250000,"status":"PENDING"}`)
	notif, err := processOrderMessage(payload)
	if err != nil {
		t.Fatalf("unexpected error: %v", err)
	}
	if notif.Recipient != "buyer@example.com" {
		t.Fatalf("expected recipient buyer@example.com, got %s", notif.Recipient)
	}

	mux := buildMux()
	req := httptest.NewRequest(http.MethodGet, "/notifications", nil)
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected status 200, got %d", rec.Code)
	}

	var resp struct {
		Total         int            `json:"total"`
		Notifications []Notification `json:"notifications"`
	}
	if err := json.Unmarshal(rec.Body.Bytes(), &resp); err != nil {
		t.Fatalf("failed to decode JSON: %v", err)
	}
	if resp.Total != 1 || len(resp.Notifications) != 1 {
		t.Fatalf("expected 1 notification, got %d", resp.Total)
	}
	if resp.Notifications[0].Order.OrderID != "ORD-TEST123" {
		t.Fatalf("expected order ORD-TEST123, got %s", resp.Notifications[0].Order.OrderID)
	}
}

func TestMetricsEndpoint(t *testing.T) {
	mux := buildMux()
	req := httptest.NewRequest(http.MethodGet, "/metrics", nil)
	rec := httptest.NewRecorder()

	mux.ServeHTTP(rec, req)

	if rec.Code != http.StatusOK {
		t.Fatalf("expected status 200, got %d", rec.Code)
	}
	if !strings.Contains(rec.Body.String(), "notifications_processed_total") {
		t.Fatalf("missing Prometheus metric in body: %s", rec.Body.String())
	}
}
