<?php

namespace App\Support\Observability;

use App\Database\Connection;
use DateTimeImmutable;

class ApiMonitoringService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string,mixed> $request
     * @param array<string,mixed> $response
     */
    public function logRequest(array $request, array $response, float $durationMs): void
    {
        $stmt = $this->connection->pdo()->prepare('INSERT INTO api_logs (path, method, status_code, duration_ms, ip_address, user_agent, request_body, response_body, created_at) VALUES (:path, :method, :status_code, :duration_ms, :ip_address, :user_agent, :request_body, :response_body, NOW())');
        $stmt->execute([
            'path' => $request['path'] ?? '',
            'method' => $request['method'] ?? 'GET',
            'status_code' => $response['status'] ?? 200,
            'duration_ms' => $durationMs,
            'ip_address' => $request['ip'] ?? null,
            'user_agent' => $request['user_agent'] ?? null,
            'request_body' => json_encode($request['body'] ?? []),
            'response_body' => json_encode($response['body'] ?? []),
        ]);
    }

    /**
     * @param array<string,mixed> $context
     */
    public function logError(string $message, array $context = []): void
    {
        $stmt = $this->connection->pdo()->prepare('INSERT INTO error_logs (message, context, created_at) VALUES (:message, :context, NOW())');
        $stmt->execute([
            'message' => $message,
            'context' => json_encode($context),
        ]);
    }

    /**
     * @param callable $callback
     * @return mixed
     */
    public function profile(string $segment, callable $callback)
    {
        $start = microtime(true);
        $result = $callback();
        $duration = (microtime(true) - $start) * 1000;

        $stmt = $this->connection->pdo()->prepare('INSERT INTO performance_profiles (segment, duration_ms, captured_at) VALUES (:segment, :duration_ms, :captured_at)');
        $stmt->execute([
            'segment' => $segment,
            'duration_ms' => $duration,
            'captured_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ]);

        return $result;
    }
}
