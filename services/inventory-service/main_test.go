package main

import (
	"context"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"

	"inventory-service/pb"
)

func TestGetAndReserveStock(t *testing.T) {
	store := NewStockStore()
	srv := &inventoryServer{store: store}
	ctx := context.Background()

	// 1. Check initial stock for MacBook Pro M3 (10)
	stockResp, err := srv.GetStock(ctx, &pb.GetStockRequest{Item: "MacBook Pro M3"})
	if err != nil {
		t.Fatalf("GetStock failed: %v", err)
	}
	if !stockResp.InStock || stockResp.AvailableStock != 10 {
		t.Fatalf("expected 10 in stock, got %d (in_stock=%v)", stockResp.AvailableStock, stockResp.InStock)
	}

	// 2. Reserve 2 units
	resResp, err := srv.ReserveStock(ctx, &pb.ReserveStockRequest{
		OrderId:  "ORD-1",
		Item:     "MacBook Pro M3",
		Quantity: 2,
	})
	if err != nil {
		t.Fatalf("ReserveStock failed: %v", err)
	}
	if !resResp.Success || resResp.RemainingStock != 8 {
		t.Fatalf("expected success=true remaining=8, got success=%v remaining=%d", resResp.Success, resResp.RemainingStock)
	}

	// 3. Out of stock item fails cleanly
	failResp, err := srv.ReserveStock(ctx, &pb.ReserveStockRequest{
		OrderId:  "ORD-2",
		Item:     "Out Of Stock Item",
		Quantity: 1,
	})
	if err != nil {
		t.Fatalf("ReserveStock failed: %v", err)
	}
	if failResp.Success {
		t.Fatalf("expected reservation to fail for Out Of Stock Item")
	}
}

func TestHealthAndMetricsEndpoints(t *testing.T) {
	store := NewStockStore()
	mux := buildHTTPMux(store)

	req := httptest.NewRequest(http.MethodGet, "/health", nil)
	rec := httptest.NewRecorder()
	mux.ServeHTTP(rec, req)
	if rec.Code != http.StatusOK || !strings.Contains(rec.Body.String(), `"service":"inventory-service"`) {
		t.Fatalf("unexpected health response: %d %s", rec.Code, rec.Body.String())
	}

	reqM := httptest.NewRequest(http.MethodGet, "/metrics", nil)
	recM := httptest.NewRecorder()
	mux.ServeHTTP(recM, reqM)
	if recM.Code != http.StatusOK || !strings.Contains(recM.Body.String(), "inventory_grpc_requests_total") {
		t.Fatalf("unexpected metrics response: %d %s", recM.Code, recM.Body.String())
	}
}
