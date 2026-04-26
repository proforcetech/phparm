<?php

namespace App\Services\Assets;

use App\Database\Connection;
use App\Models\AssetLink;
use PDO;
use RuntimeException;

/**
 * Polymorphic linkage between an installed asset and a downstream record
 * (workorder, inspection, contract, ticket, estimate, invoice) — Phase 2.1
 * of docs/expansion-plan.md.
 *
 * The set of allowed `related_type` values is enforced at the controller
 * layer so new phases can widen it without schema churn.
 */
class AssetLinkRepository
{
    private const COLUMNS = 'id, asset_id, related_type, related_id, relation, notes, created_at, created_by';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, AssetLink>
     */
    public function listForAsset(int $assetId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM asset_links
             WHERE asset_id = :aid ORDER BY created_at DESC'
        );
        $stmt->execute(['aid' => $assetId]);
        return array_map(
            static fn(array $r) => new AssetLink($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, AssetLink>
     */
    public function listForRelated(string $relatedType, int $relatedId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM asset_links
             WHERE related_type = :t AND related_id = :i ORDER BY created_at DESC'
        );
        $stmt->execute(['t' => $relatedType, 'i' => $relatedId]);
        return array_map(
            static fn(array $r) => new AssetLink($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?AssetLink
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM asset_links WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new AssetLink($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AssetLink
    {
        // INSERT IGNORE on the natural-key unique index so re-linking the
        // same (asset, related, relation) is a no-op instead of an error.
        $stmt = $this->connection->pdo()->prepare(
            'INSERT IGNORE INTO asset_links (asset_id, related_type, related_id, relation, notes, created_by)
             VALUES (:asset_id, :related_type, :related_id, :relation, :notes, :created_by)'
        );
        $stmt->execute([
            'asset_id' => (int) $data['asset_id'],
            'related_type' => $data['related_type'],
            'related_id' => (int) $data['related_id'],
            'relation' => $data['relation'] ?? null,
            'notes' => $data['notes'] ?? null,
            'created_by' => $data['created_by'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        if ($id === 0) {
            // IGNORE suppressed the insert — fetch the existing row so the
            // caller gets an object to return.
            $stmt = $this->connection->pdo()->prepare(
                'SELECT ' . self::COLUMNS . ' FROM asset_links
                 WHERE asset_id = :a AND related_type = :t AND related_id = :i
                   AND (relation <=> :r) LIMIT 1'
            );
            $stmt->execute([
                'a' => (int) $data['asset_id'],
                't' => $data['related_type'],
                'i' => (int) $data['related_id'],
                'r' => $data['relation'] ?? null,
            ]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                throw new RuntimeException('asset_links insert failed and no matching row exists');
            }
            return new AssetLink($row);
        }
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('asset_links insert did not return a row');
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM asset_links WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
