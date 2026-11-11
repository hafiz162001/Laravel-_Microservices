<?php

namespace App\Http\Middleware;

use App\Helpers\JwtHelper;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class VerifyJwt
{
    /**
     * Handle an incoming request and statelessly verify the JWT token.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $authHeader = $request->header('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Missing or malformed Authorization header. Expected Bearer token.'
            ], 401);
        }

        $token = substr($authHeader, 7);
        $secret = config('app.jwt_secret', env('JWT_SECRET', 'super-secret-jwt-key-for-microservices-demo-2026'));
        $payload = JwtHelper::decode($token, $secret);

        if (!$payload) {
            return response()->json([
                'error' => 'Unauthorized',
                'message' => 'Invalid or expired token.'
            ], 401);
        }

        // Attach authenticated user information to request without querying DB!
        $request->merge([
            'auth_user' => $payload
        ]);

        return $next($request);
    }
}

