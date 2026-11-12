<?php

namespace Tests\Feature;

use App\Grpc\Inventory\ReserveStockRequest;
use App\Services\GrpcInventoryClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class OrderApiTest extends TestCase
{
    use RefreshDatabase;

    private function createTestJwt(array $payload, string $secret): string
    {
        $encode = fn (string $data) => str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));

        $header = $encode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $payload['iat'] = time();
        $payload['exp'] = time() + 3600;
        $body = $encode(json_encode($payload));

        $sig = $encode(hash_hmac('sha256', "{$header}.{$body}", $secret, true));

        return "{$header}.{$body}.{$sig}";
    }

    public function test_health_endpoint_returns_healthy(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => 'healthy',
                     'service' => 'order-service',
                 ])
                 ->assertHeader('X-Correlation-ID');
    }

    public function test_metrics_endpoint_returns_prometheus_format(): void
    {
        $response = $this->get('/api/metrics');

        $response->assertStatus(200);
        $this->assertStringContainsString('order_service_up 1', $response->getContent());
    }

    public function test_orders_endpoint_rejects_unauthenticated_requests(): void
    {
        $response = $this->getJson('/api');

        $response->assertStatus(401)
                 ->assertJson(['error' => 'Unauthorized']);
    }

    public function test_authenticated_user_can_list_orders_with_cache_miss_then_hit(): void
    {
        $secret = env('JWT_SECRET', 'super-secret-jwt-key-for-microservices-demo-2026');
        $token = $this->createTestJwt([
            'sub' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'customer',
        ], $secret);

        $first = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api');

        $first->assertStatus(200)
              ->assertHeader('X-Cache', 'MISS')
              ->assertJsonPath('cache_status', 'MISS')
              ->assertJsonPath('service', 'order-service');

        $second = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api');

        $second->assertStatus(200)
               ->assertHeader('X-Cache', 'HIT')
               ->assertJsonPath('cache_status', 'HIT');
    }

    public function test_protobuf_binary_serialization_works(): void
    {
        $req = (new ReserveStockRequest())
            ->setOrderId('ORD-PROTO1')
            ->setItem('MacBook Pro M3')
            ->setQuantity(2);

        $binary = $req->serializeToString();
        $decoded = new ReserveStockRequest();
        $decoded->mergeFromString($binary);

        $this->assertSame('ORD-PROTO1', $decoded->getOrderId());
        $this->assertSame('MacBook Pro M3', $decoded->getItem());
        $this->assertSame(2, $decoded->getQuantity());
    }

    public function test_create_order_rejects_when_grpc_inventory_reports_out_of_stock(): void
    {
        $mockGrpc = Mockery::mock(GrpcInventoryClient::class);
        $mockGrpc->shouldReceive('reserveStock')
            ->once()
            ->andReturn([
                'connected' => true,
                'success' => false,
                'message' => "Insufficient stock for 'Out Of Stock Item'",
                'remaining_stock' => 0,
                'protocol' => 'gRPC/HTTP2',
                'latency_ms' => 1.5,
            ]);
        $this->app->instance(GrpcInventoryClient::class, $mockGrpc);

        $secret = env('JWT_SECRET', 'super-secret-jwt-key-for-microservices-demo-2026');
        $token = $this->createTestJwt([
            'sub' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'customer',
        ], $secret);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->postJson('/api', [
            'item' => 'Out Of Stock Item',
            'amount' => 500000,
            'quantity' => 1,
        ]);

        $response->assertStatus(409)
                 ->assertJsonPath('error', 'Out of stock')
                 ->assertJsonPath('inventory_grpc.protocol', 'gRPC/HTTP2');
    }
}
