<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalEtaPromise;
use PDO;

/**
 * Phase 6.7 of docs/expansion-plan.md — data access for portal_eta_promises.
 *
 * The table is append-only. Create always inserts a new row; update is
 * limited to "stamp superseded_at / superseded_by_id on a prior row" so
 * the chain remains reconstructable.
 */
class PortalEtaPromiseRepository
{
    public const ENTITY_TICKET = 'ticket';
    public const ENTITY_WORKORDER = 'workorder';

    public const ENTITY_TYPES = [
        self::ENTITY_TICKET,
        self::ENTITY_WORKORDER,
    ];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{company_id: int, entity_type: string, entity_id: int, window_start_at: string, window_end_at: string, source: string, confidence: ?int, note: ?string, created_by_user_id: int} $row
     */
    public function create(array $row): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO portal_eta_promises
                (company_id, entity_type, entity_id,
                 window_start_at, window_end_at,
                 source, confidence, note,
                 created_by_user_id, created_at)
             VALUES
                (:co, :et, :ei, :ws, :we, :src, :conf, :note, :by, CURRENT_TIMESTAMP)'
        );
        $stmt->execute([
            'co' => $row['company_id'],
            'et' => $row['entity_type'],
            'ei' => $row['entity_id'],
            'ws' => $row['window_start_at'],
            'we' => $row['window_end_at'],
            'src' => $row['source'],
            'conf' => $row['confidence'],
            'note' => $row['note'],
            'by' => $row['created_by_user_id'],
        ]);
        return (int) $this->connection->pdo()->lastInsertId();
    }

    public function findById(int $id): ?PortalEtaPromise
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM portal_eta_promises WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * The "live" promise on an entity is the latest row (by created_at,
     * tiebroken by id) that has not been superseded. There is at most
     * one such row at any time — the service layer enforces this by
     * always superseding the prior current before inserting a new one.
     */
    public function findCurrentForEntity(string $entityType, int $entityId): ?PortalEtaPromise
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM portal_eta_promises
             WHERE entity_type = :et AND entity_id = :ei AND superseded_at IS NULL
             ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $stmt->execute(['et' => $entityType, 'ei' => $entityId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, PortalEtaPromise>
     */
    public function listHistoryForEntity(string $entityType, int $entityId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM portal_eta_promises
             WHERE entity_type = :et AND entity_id = :ei
             ORDER BY created_at DESC, id DESC'
        );
        $stmt->execute(['et' => $entityType, 'ei' => $entityId]);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Stamp superseded_at (+ optional successor id) on an active promise.
     * Returns false if the row was already superseded — the service layer
     * treats that as a concurrency race and rejects loudly rather than
     * silently racing two supersede stamps.
     */
    public function markSuperseded(int $id, ?int $supersededById): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE portal_eta_promises
             SET superseded_at = CURRENT_TIMESTAMP, superseded_by_id = :sb
             WHERE id = :id AND superseded_at IS NULL'
        );
        $stmt->execute([
            'sb' => $supersededById,
            'id' => $id,
        ]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PortalEtaPromise
    {
        $p = new PortalEtaPromise();
        $p->id = (int) $row['id'];
        $p->company_id = (int) $row['company_id'];
        $p->entity_type = (string) $row['entity_type'];
        $p->entity_id = (int) $row['entity_id'];
        $p->window_start_at = (string) $row['window_start_at'];
        $p->window_end_at = (string) $row['window_end_at'];
        $p->source = (string) $row['source'];
        $p->confidence = $row['confidence'] !== null ? (int) $row['confidence'] : null;
        $p->note = $row['note'] !== null ? (string) $row['note'] : null;
        $p->created_by_user_id = (int) $row['created_by_user_id'];
        $p->created_at = $row['created_at'] ?? null;
        $p->superseded_at = $row['superseded_at'] ?? null;
        $p->superseded_by_id = $row['superseded_by_id'] !== null ? (int) $row['superseded_by_id'] : null;
        return $p;
    }
}
