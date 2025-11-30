<?php

namespace App\Services\Audit;

use App\Database\Connection;
use App\Support\Audit\AuditEntry;
use DateTimeImmutable;
use PDO;

class AuditLogViewerService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string,mixed> $filters
     * @return array<int,AuditEntry>
     */
    public function list(array $filters = []): array
    {
        $sql = 'SELECT event, entity_type, entity_id, actor_id, context, created_at FROM audit_logs WHERE 1=1';
        $params = [];
        if (!empty($filters['entity_type'])) {
            $sql .= ' AND entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['actor_id'])) {
            $sql .= ' AND actor_id = :actor_id';
            $params['actor_id'] = (int) $filters['actor_id'];
        }
        if (!empty($filters['from'])) {
            $sql .= ' AND created_at >= :from';
            $params['from'] = (new DateTimeImmutable($filters['from']))->format('Y-m-d H:i:s');
        }
        if (!empty($filters['to'])) {
            $sql .= ' AND created_at <= :to';
            $params['to'] = (new DateTimeImmutable($filters['to']))->format('Y-m-d H:i:s');
        }

        $sql .= ' ORDER BY created_at DESC LIMIT 500';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $context = json_decode($row['context'] ?? '[]', true) ?: [];
            $entries[] = new AuditEntry($row['event'], $row['entity_type'], $row['entity_id'], $row['actor_id'], $context);
        }

        return $entries;
    }

    /**
     * Record a manual audit snapshot for settings changes or admin actions.
     *
     * @param array<string,mixed> $metadata
     */
    public function snapshot(string $event, string $entityType, $entityId, int $actorId, array $metadata): void
    {
        $stmt = $this->connection->pdo()->prepare('INSERT INTO audit_logs (event, entity_type, entity_id, actor_id, context, created_at) VALUES (:event, :entity_type, :entity_id, :actor_id, :context, NOW())');
        $stmt->execute([
            'event' => $event,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'actor_id' => $actorId,
            'context' => json_encode($metadata),
        ]);
    }
}
