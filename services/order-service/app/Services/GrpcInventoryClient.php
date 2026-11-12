<?php

namespace App\Services;

use App\Grpc\Inventory\GetStockRequest;
use App\Grpc\Inventory\GetStockResponse;
use App\Grpc\Inventory\ReserveStockRequest;
use App\Grpc\Inventory\ReserveStockResponse;
use Google\Protobuf\Internal\Message;
use Illuminate\Support\Facades\Log;

class GrpcInventoryClient
{
    protected string $endpoint;

    public function __construct()
    {
        $this->endpoint = rtrim(env('INVENTORY_GRPC_URL', 'http://inventory-service:50051'), '/');
    }

    /**
     * Memeriksa ketersediaan stok barang melalui protokol biner gRPC (HTTP/2 + Protobuf).
     */
    public function getStock(string $item): array
    {
        $start = microtime(true);
        $req = (new GetStockRequest())->setItem($item);
        $resp = new GetStockResponse();

        $ok = $this->callUnary('/inventory.InventoryService/GetStock', $req, $resp);
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        if (!$ok['ok']) {
            return [
                'connected' => false,
                'error' => $ok['error'],
                'protocol' => 'gRPC/HTTP2',
                'latency_ms' => $latencyMs,
            ];
        }

        return [
            'connected' => true,
            'item' => $resp->getItem(),
            'available_stock' => $resp->getAvailableStock(),
            'in_stock' => $resp->getInStock(),
            'protocol' => 'gRPC/HTTP2',
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Memesan/memotong stok barang secara sinkron via gRPC sebelum membuat order.
     */
    public function reserveStock(string $orderId, string $item, int $quantity = 1): array
    {
        $start = microtime(true);
        $req = (new ReserveStockRequest())
            ->setOrderId($orderId)
            ->setItem($item)
            ->setQuantity($quantity);
        $resp = new ReserveStockResponse();

        $ok = $this->callUnary('/inventory.InventoryService/ReserveStock', $req, $resp);
        $latencyMs = round((microtime(true) - $start) * 1000, 2);

        if (!$ok['ok']) {
            return [
                'connected' => false,
                'success' => false,
                'message' => $ok['error'],
                'remaining_stock' => null,
                'protocol' => 'gRPC/HTTP2',
                'latency_ms' => $latencyMs,
            ];
        }

        return [
            'connected' => true,
            'success' => $resp->getSuccess(),
            'message' => $resp->getMessage(),
            'remaining_stock' => $resp->getRemainingStock(),
            'protocol' => 'gRPC/HTTP2',
            'latency_ms' => $latencyMs,
        ];
    }

    /**
     * Mengirim pesan unary gRPC (5-byte length-prefixed Protobuf frame) via HTTP/2 cleartext (h2c).
     */
    private function callUnary(string $methodPath, Message $request, Message $response): array
    {
        $binaryPayload = $request->serializeToString();
        // gRPC frame: 1 byte compression flag (0) + 4 bytes big-endian payload length
        $grpcFrame = pack('CN', 0, strlen($binaryPayload)) . $binaryPayload;

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->endpoint . $methodPath,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $grpcFrame,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_PRIOR_KNOWLEDGE,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/grpc',
                'TE: trailers',
                'Accept: application/grpc',
            ],
            CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_TIMEOUT => 3,
        ]);

        $rawResponse = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0 || $rawResponse === false) {
            Log::warning("gRPC call failed [{$methodPath}]: {$curlError}");
            return ['ok' => false, 'error' => "gRPC connection failed: {$curlError}"];
        }

        if ($httpCode !== 200) {
            return ['ok' => false, 'error' => "gRPC HTTP status {$httpCode}"];
        }

        if (strlen($rawResponse) < 5) {
            return ['ok' => false, 'error' => 'Empty or malformed gRPC response frame'];
        }

        $frameHeader = unpack('Ccompressed/Nlength', substr($rawResponse, 0, 5));
        $messageBytes = substr($rawResponse, 5, $frameHeader['length']);

        try {
            $response->mergeFromString($messageBytes);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'Failed to decode Protobuf response: ' . $e->getMessage()];
        }

        return ['ok' => true, 'error' => null];
    }
}
