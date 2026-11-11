<?php

namespace App\Http\Controllers;

use App\Helpers\JwtHelper;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'auth-service',
            'timestamp' => now()->toIso8601String()
        ]);
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

