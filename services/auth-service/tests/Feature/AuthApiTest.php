<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_health_endpoint_returns_healthy(): void
    {
        $response = $this->getJson('/api/health');

        $response->assertStatus(200)
                 ->assertJson([
                     'status' => 'healthy',
                     'service' => 'auth-service',
                 ])
                 ->assertHeader('X-Correlation-ID');
    }

    public function test_user_can_register_login_and_access_profile_with_jwt(): void
    {
        // 1. Register user
        $registerResponse = $this->postJson('/api/register', [
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $registerResponse->assertStatus(201)
                         ->assertJsonPath('user.email', 'test@example.com');

        // 2. Login and obtain JWT token
        $loginResponse = $this->postJson('/api/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $loginResponse->assertStatus(200)
                      ->assertJsonStructure(['access_token', 'token_type', 'user']);

        $token = $loginResponse->json('access_token');

        // 3. Access protected profile endpoint
        $profileResponse = $this->withHeaders([
            'Authorization' => "Bearer {$token}",
        ])->getJson('/api/profile');

        $profileResponse->assertStatus(200)
                        ->assertJsonPath('user.email', 'test@example.com');
    }
}
