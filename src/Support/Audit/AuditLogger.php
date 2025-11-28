<?php

namespace App\Support\Audit;

use App\Database\Connection;
use InvalidArgumentException;

class AuditLogger
{
    private Connection $connection;
    private array $config;

    public function __construct(Connection $connection, array $config)
    {
        $this->connection = $connection;
        $this->config = $config;
    }

    public function enabled(): bool
    {
        return (bool) ($this->config['enabled'] ?? true);
    }

    public function log(AuditEntry $entry): void
    {
        if (!$this->enabled()) {
            return;
        }

        $table = $this->config['table'] ?? 'audit_logs';
        if (!is_string($table) || $table === '') {
            throw new InvalidArgumentException('Audit log table is not configured.');
        }

        $payload = $this->redactContext($entry->context);

        $stmt = $this->connection->pdo()->prepare(
            "INSERT INTO {$table} (event, entity_type, entity_id, actor_id, context, created_at) VALUES (:event, :entity_type, :entity_id, :actor_id, :context, NOW())"
        );

        $stmt->execute([
            'event' => $entry->event,
            'entity_type' => $entry->entityType,
            'entity_id' => $entry->entityId,
            'actor_id' => $entry->actorId,
            'context' => json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    private function redactContext(array $context): array
    {
        $redactKeys = $this->config['redact_keys'] ?? [];

        array_walk_recursive($context, function (&$value, $key) use ($redactKeys) {
            if (in_array($key, $redactKeys, true)) {
                $value = '[REDACTED]';
            }
        });

        return $context;
    }
}
