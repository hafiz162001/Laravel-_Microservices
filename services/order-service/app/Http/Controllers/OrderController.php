<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class OrderController extends Controller
{
    private function incrementMetric(string $key): void
    {
        try {
            Cache::increment($key);
        } catch (\Throwable) {
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
            'service' => 'order-service',
            'cache_driver' => config('cache.default'),
            'timestamp' => now()->toIso8601String()
        ]);
    }

    public function metrics(): Response
    {
        $ordersCreated = $this->getMetric('metrics:orders_created_total');
        $cacheHits = $this->getMetric('metrics:orders_cache_hits_total');
        $cacheMisses = $this->getMetric('metrics:orders_cache_misses_total');

        $body = implode("\n", [
            '# HELP order_service_up Order service availability',
            '# TYPE order_service_up gauge',
            'order_service_up 1',
            '# HELP orders_created_total Total orders created',
            '# TYPE orders_created_total counter',
            "orders_created_total {$ordersCreated}",
            '# HELP orders_cache_hits_total Total Redis cache hits on GET orders',
            '# TYPE orders_cache_hits_total counter',
            "orders_cache_hits_total {$cacheHits}",
            '# HELP orders_cache_misses_total Total Redis cache misses on GET orders',
            '# TYPE orders_cache_misses_total counter',
            "orders_cache_misses_total {$cacheMisses}",
            ''
        ]);

        return response($body, 200, ['Content-Type' => 'text/plain; version=0.0.4; charset=utf-8']);
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $request->input('auth_user');
        $userId = $authUser['sub'] ?? 'guest';
        $cacheKey = "orders_cache_user_{$userId}";

        $cachedOrders = Cache::get($cacheKey);
        if ($cachedOrders !== null) {
            $this->incrementMetric('metrics:orders_cache_hits_total');
            return response()->json([
                'service' => 'order-service',
                'cache_status' => 'HIT',
                'cache_driver' => config('cache.default'),
                'authenticated_user' => $authUser,
                'orders' => $cachedOrders
            ])->header('X-Cache', 'HIT');
        }

        $this->incrementMetric('metrics:orders_cache_misses_total');

        $defaultOrders = [
            [
                'order_id' => 'ORD-1001',
                'user_id' => $userId,
                'user_name' => $authUser['name'] ?? null,
                'item' => 'MacBook Pro M3',
                'amount' => 25000000,
                'status' => 'COMPLETED',
                'created_at' => now()->subDays(2)->toDateTimeString()
            ],
            [
                'order_id' => 'ORD-1002',
                'user_id' => $userId,
                'user_name' => $authUser['name'] ?? null,
                'item' => 'Keychron K2 Keyboard',
                'amount' => 1500000,
                'status' => 'PENDING',
                'created_at' => now()->subHours(4)->toDateTimeString()
            ]
        ];

        $storedCustomOrders = Cache::get("orders_store_user_{$userId}", []);
        $orders = array_merge($storedCustomOrders, $defaultOrders);

        Cache::put($cacheKey, $orders, 60);

        return response()->json([
            'service' => 'order-service',
            'cache_status' => 'MISS',
            'cache_driver' => config('cache.default'),
            'authenticated_user' => $authUser,
            'orders' => $orders
        ])->header('X-Cache', 'MISS');
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1000',
        ]);

        $authUser = $request->input('auth_user');
        $userId = $authUser['sub'] ?? 'guest';
        $orderId = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));

        $orderData = [
            'order_id' => $orderId,
            'user_id' => $userId,
            'user_email' => $authUser['email'] ?? null,
            'item' => $validated['item'],
            'amount' => $validated['amount'],
            'status' => 'PENDING',
            'created_at' => now()->toIso8601String()
        ];

        // Simpan ke persistent store sementara di Redis & invalidasi read-cache
        $stored = Cache::get("orders_store_user_{$userId}", []);
        array_unshift($stored, $orderData);
        Cache::put("orders_store_user_{$userId}", $stored, 3600);
        Cache::forget("orders_cache_user_{$userId}");
        $this->incrementMetric('metrics:orders_created_total');

        // Publikasikan event nyata ke Message Broker (RabbitMQ)
        $rabbitHost = env('RABBITMQ_HOST', 'rabbitmq');
        $rabbitPort = (int) env('RABBITMQ_PORT', 5672);
        $rabbitUser = env('RABBITMQ_USER', 'guest');
        $rabbitPass = env('RABBITMQ_PASSWORD', 'guest');
        $queueName = 'order_created_queue';

        $eventStatus = 'PUBLISHED';
        try {
            $connection = new AMQPStreamConnection($rabbitHost, $rabbitPort, $rabbitUser, $rabbitPass);
            $channel = $connection->channel();

            // Deklarasikan antrean (Queue)
            $channel->queue_declare($queueName, false, true, false, false);

            // Buat payload pesan persistent
            $msg = new AMQPMessage(
                json_encode($orderData, JSON_PRETTY_PRINT),
                [
                    'content_type' => 'application/json',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_PERSISTENT
                ]
            );

            // Kirim pesan ke antrean
            $channel->basic_publish($msg, '', $queueName);

            $channel->close();
            $connection->close();

            Log::info("EVENT PUBLISHED TO RABBITMQ: [{$queueName}]", $orderData);
        } catch (\Throwable $e) {
            Log::error("Failed to publish event to RabbitMQ: " . $e->getMessage());
            $eventStatus = 'FAILED: ' . $e->getMessage();
        }

        return response()->json([
            'message' => 'Order created successfully and event published to RabbitMQ queue',
            'order' => $orderData,
            'event' => [
                'queue' => $queueName,
                'status' => $eventStatus
            ]
        ], 201);
    }
}
