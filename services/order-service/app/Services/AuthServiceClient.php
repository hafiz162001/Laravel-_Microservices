<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AuthServiceClient
{
    protected string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(config('services.auth_service.url', env('AUTH_SERVICE_URL', 'http://auth-service:8000')), '/');
    }

    /**
     * Mengambil detail profil user dari auth-service secara sinkron dengan mekanisme Resilience (Timeout & Retry).
     */
    public function getUserProfile(string $token, ?string $correlationId = null): ?array
    {
        try {
            $response = Http::timeout(2) // Timeout maksimal 2 detik agar tidak memblokir order service
                ->retry(3, 100)           // Coba ulang 3 kali dengan jeda 100ms jika terjadi kegagalan jaringan
                ->withHeaders([
                    'Authorization' => 'Bearer ' . $token,
                    'X-Correlation-ID' => $correlationId,
                    'Accept' => 'application/json'
                ])
                ->get("{$this->baseUrl}/api/profile");

            if ($response->successful()) {
                return $response->json();
            }

            Log::warning("Gagal mengambil profil dari auth-service", [
                'status' => $response->status(),
                'body' => $response->body()
            ]);
            return null;
        } catch (\Throwable $e) {
            // Circuit-breaking / Fallback logging
            Log::error("Auth service tidak dapat dihubungi (Circuit Break fallback)", [
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }
}
