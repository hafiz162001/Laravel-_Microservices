<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

class OrderController extends Controller
{
    public function health(): JsonResponse
    {
        return response()->json([
            'status' => 'healthy',
            'service' => 'order-service',
            'timestamp' => now()->toIso8601String()
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $authUser = $request->input('auth_user');

        // Contoh data dummy pesanan milik user
        $orders = [
            [
                'order_id' => 'ORD-1001',
                'user_id' => $authUser['sub'] ?? null,
                'user_name' => $authUser['name'] ?? null,
                'item' => 'MacBook Pro M3',
                'amount' => 25000000,
                'status' => 'COMPLETED',
                'created_at' => now()->subDays(2)->toDateTimeString()
            ],
            [
                'order_id' => 'ORD-1002',
                'user_id' => $authUser['sub'] ?? null,
                'user_name' => $authUser['name'] ?? null,
                'item' => 'Keychron K2 Keyboard',
                'amount' => 1500000,
                'status' => 'PENDING',
                'created_at' => now()->subHours(4)->toDateTimeString()
            ]
        ];

        return response()->json([
            'service' => 'order-service',
            'authenticated_user' => $authUser,
            'orders' => $orders
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item' => 'required|string|max:255',
            'amount' => 'required|numeric|min:1000',
        ]);

        $authUser = $request->input('auth_user');
        $orderId = 'ORD-' . strtoupper(bin2hex(random_bytes(4)));

        $orderData = [
            'order_id' => $orderId,
            'user_id' => $authUser['sub'] ?? null,
            'user_email' => $authUser['email'] ?? null,
            'item' => $validated['item'],
            'amount' => $validated['amount'],
            'status' => 'PENDING',
            'created_at' => now()->toIso8601String()
        ];

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

