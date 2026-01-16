<?php

namespace App\Services\CMS;

use App\Database\Connection;
use PDO;

class CMSRevisionService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function recordRevision(string $entityType, int $entityId, array $snapshot, ?int $userId, string $action = 'save'): void
    {
        $payload = json_encode($snapshot, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            throw new \RuntimeException('Failed to serialize revision snapshot.');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO cms_revisions (entity_type, entity_id, action, snapshot_data, created_by, created_at) '
            . 'VALUES (:entity_type, :entity_id, :action, :snapshot_data, :created_by, NOW())'
        );

        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
            'snapshot_data' => $payload,
            'created_by' => $userId,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listRevisions(string $entityType, int $entityId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT r.id, r.entity_type, r.entity_id, r.action, r.created_by, r.created_at, u.name AS author_name '
            . 'FROM cms_revisions r '
            . 'LEFT JOIN users u ON u.id = r.created_by '
            . 'WHERE r.entity_type = :entity_type AND r.entity_id = :entity_id '
            . 'ORDER BY r.created_at DESC, r.id DESC'
        );

        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getRevision(string $entityType, int $entityId, int $revisionId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_revisions '
            . 'WHERE id = :id AND entity_type = :entity_type AND entity_id = :entity_id'
        );

        $stmt->execute([
            'id' => $revisionId,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }
}
