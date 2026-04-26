<?php

namespace App\Services\Integrations;

use App\Database\Connection;
use App\Models\IntegrationWebhookEvent;
use PDO;

/**
 * Inbound webhook receipts. The unique index on
 * (provider_key, payload_hash) gives us idempotent receipt — a redelivered
 * webhook returns the existing row instead of inserting a duplicate.
 */
class IntegrationWebhookEventRepository
{
    private const COLUMNS = [
        'id', 'integration_id', 'provider_key', 'event_type', 'external_id',
        'payload_hash', 'raw_payload', 'headers', 'status', 'error_message',
        'received_at', 'processed_at',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?IntegrationWebhookEvent
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM integration_webhook_events WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByHash(string $providerKey, string $payloadHash): ?IntegrationWebhookEvent
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS)
            . ' FROM integration_webhook_events WHERE provider_key = :p AND payload_hash = :h LIMIT 1'
        );
        $stmt->execute(['p' => $providerKey, 'h' => $payloadHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $headers
     */
    public function record(
        ?int $integrationId,
        string $providerKey,
        ?string $eventType,
        ?string $externalId,
        string $rawPayload,
        array $headers
    ): IntegrationWebhookEvent {
        $hash = hash('sha256', $providerKey . '|' . $rawPayload);

        $existing = $this->findByHash($providerKey, $hash);
        if ($existing !== null) {
            return $existing;
        }

        $sql = 'INSERT INTO integration_webhook_events '
            . '(integration_id, provider_key, event_type, external_id, payload_hash, raw_payload, headers, status) '
            . "VALUES (:i, :p, :t, :x, :h, :r, :hd, 'received')";
        $this->connection->pdo()->prepare($sql)->execute([
            'i' => $integrationId,
            'p' => $providerKey,
            't' => $eventType,
            'x' => $externalId,
            'h' => $hash,
            'r' => $rawPayload,
            'hd' => json_encode($headers, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new IntegrationWebhookEvent(['id' => $id]);
    }

    public function markProcessed(int $id, string $processedAt): void
    {
        $this->connection->pdo()->prepare(
            "UPDATE integration_webhook_events SET status = 'processed', processed_at = :p, error_message = NULL WHERE id = :id"
        )->execute(['id' => $id, 'p' => $processedAt]);
    }

    public function markFailed(int $id, string $error, string $processedAt): void
    {
        $this->connection->pdo()->prepare(
            "UPDATE integration_webhook_events SET status = 'failed', processed_at = :p, error_message = :e WHERE id = :id"
        )->execute(['id' => $id, 'p' => $processedAt, 'e' => $error]);
    }

    /**
     * @return array<int, IntegrationWebhookEvent>
     */
    public function listUnprocessed(int $limit = 100): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS)
            . " FROM integration_webhook_events WHERE status = 'received' "
            . 'ORDER BY received_at ASC LIMIT ' . $limit
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, IntegrationWebhookEvent>
     */
    public function listForIntegration(int $integrationId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 500));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS)
            . ' FROM integration_webhook_events WHERE integration_id = :i '
            . 'ORDER BY received_at DESC LIMIT ' . $limit
        );
        $stmt->execute(['i' => $integrationId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): IntegrationWebhookEvent
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new IntegrationWebhookEvent($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'integration_id' => (int) $value,
            'headers' => is_string($value) ? (json_decode($value, true) ?: null) : $value,
            default => $value,
        };
    }
}
