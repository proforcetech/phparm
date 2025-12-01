<?php

namespace App\Services\Health;

use App\Database\Connection;
use PDO;

class HealthStatusService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        return [
            'database' => $this->checkDatabase(),
            'queues' => $this->checkQueueHeartbeat(),
            'schedulers' => $this->checkSchedulerHeartbeat(),
            'integrations' => $this->checkIntegrations(),
        ];
    }

    private function checkDatabase(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT 1');
        $alive = (int) $stmt->fetchColumn() === 1;

        return ['ok' => $alive];
    }

    private function checkQueueHeartbeat(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT MAX(checked_at) as last_heartbeat FROM queue_workers');
        $last = $stmt->fetch(PDO::FETCH_ASSOC)['last_heartbeat'] ?? null;

        return [
            'ok' => $last !== null,
            'last_heartbeat' => $last,
        ];
    }

    private function checkSchedulerHeartbeat(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT MAX(ran_at) as last_run FROM scheduler_runs');
        $last = $stmt->fetch(PDO::FETCH_ASSOC)['last_run'] ?? null;

        return [
            'ok' => $last !== null,
            'last_run' => $last,
        ];
    }

    private function checkIntegrations(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT provider, status, last_checked_at FROM integration_statuses');
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ok = true;
        foreach ($rows as $row) {
            if (($row['status'] ?? '') !== 'ok') {
                $ok = false;
                break;
            }
        }

        return ['ok' => $ok, 'providers' => $rows];
    }
}
