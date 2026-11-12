<?php

namespace App\Http\Controllers;

use App\Helpers\JwtHelper;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    private function incrementMetric(string $key): void
    {
        try {
            Cache::increment($key);
        } catch (\Throwable) {
            // Jangan gagalkan request utama jika cache/metrics sementara tidak terjangkau
        }
    }

    private function getMetric(string $key): int
    {
        try {
            return (int) Cache::get($key, 0);
        } catch (\Throwable) {
            return 0;
        }
    }

    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'auth-service',
            'cache_driver' => config('cache.default'),
            'timestamp' => now()->toIso8601String()
        ]);
    }

    public function metrics(): Response
    {
        $registrations = $this->getMetric('metrics:auth_registrations_total');
        $logins = $this->getMetric('metrics:auth_logins_total');
        $usersCount = 0;
        try {
            $usersCount = User::count();
        } catch (\Throwable) {
        }

        $body = implode("\n", [
            '# HELP auth_service_up Auth service availability',
            '# TYPE auth_service_up gauge',
            'auth_service_up 1',
            '# HELP auth_registrations_total Total user registrations',
            '# TYPE auth_registrations_total counter',
            "auth_registrations_total {$registrations}",
            '# HELP auth_logins_total Total successful logins',
            '# TYPE auth_logins_total counter',
            "auth_logins_total {$logins}",
            '# HELP auth_users_total Total registered users in PostgreSQL',
            '# TYPE auth_users_total gauge',
            "auth_users_total {$usersCount}",
            ''
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    public function register(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|string|min:6',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'email' => $validated['email'],
            'password' => Hash::make($validated['password']),
        ]);

        $this->incrementMetric('metrics:auth_registrations_total');

        return response()->json([
            'message' => 'User registered successfully',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]
        ], 201);
    }

    public function login(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'email' => 'required|email',
            'password' => 'required|string',
        ]);

        $user = User::where('email', $validated['email'])->first();

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $secret = config('app.jwt_secret', env('JWT_SECRET', 'super-secret-jwt-key-for-microservices-demo-2026'));

        $token = JwtHelper::encode([
            'sub' => $user->id,
            'email' => $user->email,
            'name' => $user->name,
            'role' => 'customer'
        ], $secret);

        $this->incrementMetric('metrics:auth_logins_total');

        return response()->json([
            'message' => 'Login successful',
            'token_type' => 'Bearer',
            'access_token' => $token,
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
            ]
        ]);
    }

    public function me(Request $request): JsonResponse
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json(['message' => 'Missing or invalid token'], 401);
        }

        $token = substr($authHeader, 7);
        $secret = config('app.jwt_secret', env('JWT_SECRET', 'super-secret-jwt-key-for-microservices-demo-2026'));
        $payload = JwtHelper::decode($token, $secret);

        if (!$payload) {
            return response()->json(['message' => 'Unauthorized / Token expired'], 401);
        }

        return response()->json([
            'service' => 'auth-service',
            'user' => $payload
        ]);
    }
}
