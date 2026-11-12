<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_orders_endpoint_rejects_unauthenticated_requests(): void
    {
        $response = $this->getJson('/api');

        $response->assertStatus(401)
                 ->assertJson(['error' => 'Unauthorized']);
    }

    public function test_authenticated_user_can_list_orders(): void
    {
        $secret = env('JWT_SECRET', 'super-secret-jwt-key-for-microservices-demo-2026');
        $token = $this->createTestJwt([
            'sub' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'role' => 'customer',
        ], $secret);

        $response = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api');

        $response->assertStatus(200)
                 ->assertJsonPath('service', 'order-service')
                 ->assertJsonPath('authenticated_user.email', 'test@example.com')
                 ->assertJsonStructure(['orders']);
    }
}
