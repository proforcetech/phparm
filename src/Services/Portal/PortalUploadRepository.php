<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalUpload;
use PDO;

/**
 * Phase 6.6 of docs/expansion-plan.md — data access for portal_uploads.
 *
 * All reads filter deleted_at IS NULL by default; the optional
 * $includeDeleted flag is provided for cleanup jobs that walk the
 * soft-deleted tail to unlink physical files.
 */
class PortalUploadRepository
{
    public const ENTITY_TICKET = 'ticket';
    public const ENTITY_WORKORDER = 'workorder';
    public const ENTITY_MESSAGE_THREAD = 'message_thread';

    public const ENTITY_TYPES = [
        self::ENTITY_TICKET,
        self::ENTITY_WORKORDER,
        self::ENTITY_MESSAGE_THREAD,
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{portal_account_id: int, company_id: int, entity_type: string, entity_id: int, original_name: string, stored_path: string, mime_type: string, size_bytes: int, sha256: string, uploaded_by_user_id: int} $row
     */
    public function create(array $row): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO portal_uploads
                (portal_account_id, company_id, entity_type, entity_id,
                 original_name, stored_path, mime_type, size_bytes, sha256,
                 uploaded_by_user_id, created_at)
             VALUES
                (:pa, :co, :et, :ei, :on, :sp, :mt, :sz, :sha, :uu, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'pa' => $row['portal_account_id'],
            'co' => $row['company_id'],
            'et' => $row['entity_type'],
            'ei' => $row['entity_id'],
            'on' => $row['original_name'],
            'sp' => $row['stored_path'],
            'mt' => $row['mime_type'],
            'sz' => $row['size_bytes'],
            'sha' => $row['sha256'],
            'uu' => $row['uploaded_by_user_id'],
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id, bool $includeDeleted = false): ?PortalUpload
    {
        $where = 'id = :id';
        if (!$includeDeleted) {
            $where .= ' AND deleted_at IS NULL';
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM portal_uploads WHERE ' . $where . ' LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, PortalUpload>
     */
    public function listForEntity(string $entityType, int $entityId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM portal_uploads
             WHERE entity_type = :et AND entity_id = :ei AND deleted_at IS NULL
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['et' => $entityType, 'ei' => $entityId]);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function softDelete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE portal_uploads SET deleted_at = CURRENT_TIMESTAMP
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PortalUpload
    {
        $u = new PortalUpload();
        $u->id = (int) $row['id'];
        $u->portal_account_id = (int) $row['portal_account_id'];
        $u->company_id = (int) $row['company_id'];
        $u->entity_type = (string) $row['entity_type'];
        $u->entity_id = (int) $row['entity_id'];
        $u->original_name = (string) $row['original_name'];
        $u->stored_path = (string) $row['stored_path'];
        $u->mime_type = (string) $row['mime_type'];
        $u->size_bytes = (int) $row['size_bytes'];
        $u->sha256 = (string) $row['sha256'];
        $u->uploaded_by_user_id = (int) $row['uploaded_by_user_id'];
        $u->created_at = $row['created_at'] ?? null;
        $u->deleted_at = $row['deleted_at'] ?? null;
        return $u;
    }
}
