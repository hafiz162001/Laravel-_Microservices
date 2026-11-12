package main

import (
	"context"
	"encoding/json"
	"fmt"
	"log"
	"net"
	"net/http"
	"os"
	"sync"
	"sync/atomic"
	"time"

	"inventory-service/pb"

	"google.golang.org/grpc"
	"google.golang.org/grpc/reflection"
)

type StockStore struct {
	mu    sync.RWMutex
	items map[string]int32
}

func NewStockStore() *StockStore {
	return &StockStore{
		items: map[string]int32{
			"MacBook Pro M3":            10,
			"Keychron K2 Keyboard":      25,
			"Enterprise Polyglot Book":  100,
			"Monitor 4K Dell Ultrasharp": 15,
			"Out Of Stock Item":         0,
		},
	}
}

func (s *StockStore) Get(item string) int32 {
	s.mu.Lock()
	defer s.mu.Unlock()
	qty, exists := s.items[item]
	if !exists {
		qty = 50 // Default initial stock for newly introduced items
		s.items[item] = qty
	}
	return qty
}

func (s *StockStore) Reserve(item string, quantity int32) (bool, int32, string) {
	if quantity <= 0 {
		quantity = 1
	}
	s.mu.Lock()
	defer s.mu.Unlock()

	available, exists := s.items[item]
	if !exists {
		available = 50
		s.items[item] = available
	}

	if available < quantity {
		return false, available, fmt.Sprintf("Insufficient stock for '%s' (requested: %d, available: %d)", item, quantity, available)
	}

	remaining := available - quantity
	s.items[item] = remaining
	return true, remaining, fmt.Sprintf("Reserved %d unit(s) of '%s'", quantity, item)
}

type inventoryServer struct {
	pb.UnimplementedInventoryServiceServer
	store *StockStore
}

var (
	grpcRequestsTotal     atomic.Int64
	stockReservationsOK   atomic.Int64
	stockReservationsFail atomic.Int64
)

func (s *inventoryServer) GetStock(ctx context.Context, req *pb.GetStockRequest) (*pb.GetStockResponse, error) {
	grpcRequestsTotal.Add(1)
	available := s.store.Get(req.GetItem())
	log.Printf("[gRPC GetStock] item=%q available=%d", req.GetItem(), available)

	return &pb.GetStockResponse{
		Item:           req.GetItem(),
		AvailableStock: available,
		InStock:        available > 0,
	}, nil
}

func (s *inventoryServer) ReserveStock(ctx context.Context, req *pb.ReserveStockRequest) (*pb.ReserveStockResponse, error) {
	grpcRequestsTotal.Add(1)
	ok, remaining, msg := s.store.Reserve(req.GetItem(), req.GetQuantity())
	if ok {
		stockReservationsOK.Add(1)
	} else {
		stockReservationsFail.Add(1)
	}

	log.Printf("[gRPC ReserveStock] order=%q item=%q qty=%d success=%v remaining=%d",
		req.GetOrderId(), req.GetItem(), req.GetQuantity(), ok, remaining)

	return &pb.ReserveStockResponse{
		Success:        ok,
		Message:        msg,
		RemainingStock: remaining,
	}, nil
}

func buildHTTPMux(store *StockStore) *http.ServeMux {
	mux := http.NewServeMux()

	mux.HandleFunc("/health", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_ = json.NewEncoder(w).Encode(map[string]any{
			"status":    "healthy",
			"service":   "inventory-service",
			"protocol":  "gRPC + HTTP/2",
			"grpc_port": 50051,
			"timestamp": time.Now().UTC().Format(time.RFC3339),
		})
	})

	mux.HandleFunc("/metrics", func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "text/plain; version=0.0.4; charset=utf-8")
		fmt.Fprintf(w, "# HELP inventory_service_up Inventory gRPC service availability\n")
		fmt.Fprintf(w, "# TYPE inventory_service_up gauge\n")
		fmt.Fprintf(w, "inventory_service_up 1\n")
		fmt.Fprintf(w, "# HELP inventory_grpc_requests_total Total gRPC requests handled\n")
		fmt.Fprintf(w, "# TYPE inventory_grpc_requests_total counter\n")
		fmt.Fprintf(w, "inventory_grpc_requests_total %d\n", grpcRequestsTotal.Load())
		fmt.Fprintf(w, "# HELP inventory_stock_reservations_total Total stock reservations\n")
		fmt.Fprintf(w, "# TYPE inventory_stock_reservations_total counter\n")
		fmt.Fprintf(w, "inventory_stock_reservations_total{status=\"success\"} %d\n", stockReservationsOK.Load())
		fmt.Fprintf(w, "inventory_stock_reservations_total{status=\"failed\"} %d\n", stockReservationsFail.Load())
	})

	return mux
}

func main() {
	grpcPort := os.Getenv("GRPC_PORT")
	if grpcPort == "" {
		grpcPort = "50051"
	}
	httpPort := os.Getenv("HTTP_PORT")
	if httpPort == "" {
		httpPort = "8004"
	}

	store := NewStockStore()
	srv := &inventoryServer{store: store}

	// Start HTTP health/metrics server
	go func() {
		log.Printf("Inventory HTTP health/metrics listening on :%s", httpPort)
		if err := http.ListenAndServe(":"+httpPort, buildHTTPMux(store)); err != nil {
			log.Fatalf("HTTP server failed: %v", err)
		}
	}()

	// Start native gRPC server
	lis, err := net.Listen("tcp", ":"+grpcPort)
	if err != nil {
		log.Fatalf("Failed to listen on :%s: %v", grpcPort, err)
	}

	grpcServer := grpc.NewServer()
	pb.RegisterInventoryServiceServer(grpcServer, srv)
	reflection.Register(grpcServer) // Enables grpcurl / Postman gRPC introspection

	log.Printf("Inventory gRPC Server listening on :%s", grpcPort)
	if err := grpcServer.Serve(lis); err != nil {
		log.Fatalf("gRPC server failed: %v", err)
	}
}
