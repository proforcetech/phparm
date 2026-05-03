<?php

namespace App\Services\ServiceRoutes;

use App\Database\Connection;
use App\Models\RouteVisit;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `route_visits` — Phase 15 / M7 of
 * docs/woms-expansion-plan.md.
 *
 * State transitions go through RouteVisitService::transition() so the
 * timestamp columns (en_route_at, arrived_at, completed_at, skipped_at,
 * missed_at) stay in sync with status and the audit ledger captures the
 * move. update() refuses direct status writes for that reason.
 */
class RouteVisitRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{service_route_id?: int, route_stop_id?: int,
     *              workorder_id?: int, assigned_user_id?: int, status?: string,
     *              statuses?: array<int, string>,
     *              scheduled_from?: string, scheduled_to?: string,
     *              customer_id?: int, site_id?: int} $filters
     * @return array<int, RouteVisit>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        $limit = max(1, min(500, $limit));
        $offset = max(0, $offset);

        [$where, $params, $join] = $this->buildFilters($filters);
        $sql = 'SELECT v.* FROM route_visits v ' . $join . ' ' . $where
            . ' ORDER BY v.scheduled_for ASC, v.id ASC'
            . ' LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => RouteVisit::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params, $join] = $this->buildFilters($filters);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM route_visits v ' . $join . ' ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?RouteVisit
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM route_visits WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? RouteVisit::fromRow($row) : null;
    }

    /**
     * Locking read used by RouteVisitService::transition() so concurrent
     * arrival/completion attempts on the same visit serialize. Caller MUST
     * be inside a transaction or the lock has no effect.
     */
    public function findByIdForUpdate(int $id): ?RouteVisit
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM route_visits WHERE id = :id LIMIT 1 FOR UPDATE'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? RouteVisit::fromRow($row) : null;
    }

    public function findByQrToken(string $token): ?RouteVisit
    {
        if ($token === '') {
            return null;
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM route_visits WHERE qr_token = :t LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? RouteVisit::fromRow($row) : null;
    }

    /**
     * The latest scheduled_for for a stop. Used by the generator to pick up
     * where the previous run left off without re-scanning history.
     */
    public function latestScheduledForStop(int $routeStopId): ?string
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT MAX(scheduled_for) FROM route_visits WHERE route_stop_id = :id'
        );
        $stmt->execute(['id' => $routeStopId]);
        $value = $stmt->fetchColumn();
        return $value === false || $value === null ? null : (string) $value;
    }

    /**
     * Insert a planned visit. INSERT IGNORE on the (route_stop_id,
     * scheduled_for) UNIQUE blocks duplicate emit if the generator restarts
     * mid-run. Returns the inserted row OR the pre-existing row at that slot.
     *
     * @param array{service_route_id: int, route_stop_id: int,
     *              assigned_user_id?: int|null, scheduled_for: string,
     *              scheduled_window_minutes?: int, qr_token: string} $data
     */
    public function createPlanned(array $data): RouteVisit
    {
        return $this->createPlannedWithFlag($data)['visit'];
    }

    /**
     * Same as {@see createPlanned()} but also reports whether the row was
     * freshly inserted. The cron generator uses the flag for accurate
     * "visits_created" reporting because INSERT IGNORE silently no-ops on
     * the (route_stop_id, scheduled_for) duplicate.
     *
     * @param array{service_route_id: int, route_stop_id: int,
     *              assigned_user_id?: int|null, scheduled_for: string,
     *              scheduled_window_minutes?: int, qr_token: string} $data
     * @return array{visit: RouteVisit, inserted: bool}
     */
    public function createPlannedWithFlag(array $data): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT IGNORE INTO route_visits
                (service_route_id, route_stop_id, assigned_user_id,
                 scheduled_for, scheduled_window_minutes, status, qr_token)
             VALUES
                (:service_route_id, :route_stop_id, :assigned_user_id,
                 :scheduled_for, :scheduled_window_minutes, :status, :qr_token)'
        );
        $stmt->execute([
            'service_route_id' => (int) $data['service_route_id'],
            'route_stop_id' => (int) $data['route_stop_id'],
            'assigned_user_id' => $data['assigned_user_id'] === null || $data['assigned_user_id'] === ''
                ? null : (int) $data['assigned_user_id'],
            'scheduled_for' => (string) $data['scheduled_for'],
            'scheduled_window_minutes' => max(1, min(1440, (int) ($data['scheduled_window_minutes'] ?? 60))),
            'status' => RouteVisit::STATUS_PLANNED,
            'qr_token' => (string) $data['qr_token'],
        ]);

        // rowCount() returns 1 when the row was actually inserted, 0 when
        // INSERT IGNORE skipped a duplicate. lastInsertId() is the
        // canonical id pointer when rowCount > 0.
        $inserted = $stmt->rowCount() > 0;
        $id = (int) $this->connection->pdo()->lastInsertId();
        if ($inserted && $id > 0) {
            $row = $this->findById($id);
            if ($row !== null) {
                return ['visit' => $row, 'inserted' => true];
            }
        }
        $existing = $this->findBySlot((int) $data['route_stop_id'], (string) $data['scheduled_for']);
        if ($existing === null) {
            throw new RuntimeException('Failed to insert or load visit at slot');
        }
        return ['visit' => $existing, 'inserted' => false];
    }

    public function findBySlot(int $routeStopId, string $scheduledFor): ?RouteVisit
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM route_visits
              WHERE route_stop_id = :id AND scheduled_for = :scheduled_for
              LIMIT 1'
        );
        $stmt->execute(['id' => $routeStopId, 'scheduled_for' => $scheduledFor]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? RouteVisit::fromRow($row) : null;
    }

    /**
     * Service-only state writer. Updates status + the relevant timestamp
     * column atomically so the visit row stays consistent. Caller
     * (RouteVisitService) MUST run inside a transaction, hold the row
     * lock from findByIdForUpdate(), and validate the transition against
     * RouteVisit::TRANSITIONS first.
     *
     * @param array<string, mixed> $extra additional column writes paired
     *                                     with this transition (assigned_user_id,
     *                                     workorder_id, arrival_lat, arrival_lng,
     *                                     skip_reason, verification_passed,
     *                                     verification_notes)
     */
    public function applyTransition(int $id, string $toStatus, array $extra = []): RouteVisit
    {
        $fields = ['status = :status'];
        $params = ['id' => $id, 'status' => $toStatus];

        $now = date('Y-m-d H:i:s');
        switch ($toStatus) {
            case RouteVisit::STATUS_EN_ROUTE:
                $fields[] = 'en_route_at = :en_route_at';
                $params['en_route_at'] = $now;
                break;
            case RouteVisit::STATUS_ARRIVED:
                $fields[] = 'arrived_at = :arrived_at';
                $params['arrived_at'] = $now;
                break;
            case RouteVisit::STATUS_COMPLETED:
                $fields[] = 'completed_at = :completed_at';
                $params['completed_at'] = $now;
                break;
            case RouteVisit::STATUS_SKIPPED:
                $fields[] = 'skipped_at = :skipped_at';
                $params['skipped_at'] = $now;
                break;
            case RouteVisit::STATUS_MISSED:
                $fields[] = 'missed_at = :missed_at';
                $params['missed_at'] = $now;
                break;
        }

        $extraCols = ['assigned_user_id', 'workorder_id',
                      'arrival_lat', 'arrival_lng',
                      'skip_reason', 'verification_passed', 'verification_notes'];
        foreach ($extraCols as $key) {
            if (array_key_exists($key, $extra)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $extra[$key];
            }
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE route_visits SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("route_visit {$id} not found after transition");
        }
        return $row;
    }

    /**
     * Write the S8 verification verdict onto the visit row without touching
     * status. Called by PhotoVerifier::evaluateVisit() after the row is
     * already in 'completed' (or by the back office when re-reviewing).
     * `verification_passed` is nullable: NULL = not yet evaluated,
     * 1 = passed, 0 = flagged.
     */
    public function setVerification(int $id, ?bool $passed, ?string $notes): RouteVisit
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE route_visits
                SET verification_passed = :passed,
                    verification_notes = :notes
              WHERE id = :id'
        );
        $stmt->bindValue(':id', $id, PDO::PARAM_INT);
        if ($passed === null) {
            $stmt->bindValue(':passed', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':passed', $passed ? 1 : 0, PDO::PARAM_INT);
        }
        if ($notes === null) {
            $stmt->bindValue(':notes', null, PDO::PARAM_NULL);
        } else {
            $stmt->bindValue(':notes', $notes, PDO::PARAM_STR);
        }
        $stmt->execute();

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("route_visit {$id} not found after setVerification");
        }
        return $row;
    }

    /**
     * Update only the assignment + (optional) workorder link without
     * touching status. Used by the dispatcher view to re-assign a planned
     * visit without going through the state machine.
     */
    public function reassign(int $id, ?int $assignedUserId, ?int $workorderId = null): RouteVisit
    {
        $fields = ['assigned_user_id = :assigned_user_id'];
        $params = ['id' => $id, 'assigned_user_id' => $assignedUserId];
        if ($workorderId !== null) {
            $fields[] = 'workorder_id = :workorder_id';
            $params['workorder_id'] = $workorderId;
        }
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE route_visits SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("route_visit {$id} not found after reassign");
        }
        return $row;
    }

    /**
     * Increment the denormalized photo counter inside the same transaction
     * as the photo insert. Returns the new value.
     */
    public function incrementPhotoCounter(int $id, int $delta = 1): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE route_visits
                SET photos_uploaded = GREATEST(0, CAST(photos_uploaded AS SIGNED) + :delta)
              WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'delta' => $delta]);

        $countStmt = $this->connection->pdo()->prepare(
            'SELECT photos_uploaded FROM route_visits WHERE id = :id'
        );
        $countStmt->execute(['id' => $id]);
        return (int) $countStmt->fetchColumn();
    }

    /**
     * Find visits whose scheduled window has expired but are still in a
     * non-terminal state. Used by the cron sweeper to mark them missed.
     *
     * @return array<int, RouteVisit>
     */
    public function listOverdueOpen(string $now, int $limit = 200): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM route_visits
              WHERE status IN (:planned, :en_route, :arrived)
                AND DATE_ADD(scheduled_for, INTERVAL scheduled_window_minutes MINUTE) < :now
              ORDER BY scheduled_for ASC, id ASC
              LIMIT :limit'
        );
        $stmt->bindValue(':planned', RouteVisit::STATUS_PLANNED, PDO::PARAM_STR);
        $stmt->bindValue(':en_route', RouteVisit::STATUS_EN_ROUTE, PDO::PARAM_STR);
        $stmt->bindValue(':arrived', RouteVisit::STATUS_ARRIVED, PDO::PARAM_STR);
        $stmt->bindValue(':now', $now, PDO::PARAM_STR);
        $stmt->bindValue(':limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => RouteVisit::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM route_visits WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>, 2: string}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        $join = '';

        if (isset($filters['service_route_id'])) {
            $where .= ' AND v.service_route_id = :service_route_id';
            $params['service_route_id'] = (int) $filters['service_route_id'];
        }
        if (isset($filters['route_stop_id'])) {
            $where .= ' AND v.route_stop_id = :route_stop_id';
            $params['route_stop_id'] = (int) $filters['route_stop_id'];
        }
        if (isset($filters['workorder_id'])) {
            $where .= ' AND v.workorder_id = :workorder_id';
            $params['workorder_id'] = (int) $filters['workorder_id'];
        }
        if (isset($filters['assigned_user_id'])) {
            $where .= ' AND v.assigned_user_id = :assigned_user_id';
            $params['assigned_user_id'] = (int) $filters['assigned_user_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND v.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['statuses']) && is_array($filters['statuses'])) {
            $placeholders = [];
            foreach (array_values($filters['statuses']) as $i => $s) {
                $key = 'st' . $i;
                $placeholders[] = ':' . $key;
                $params[$key] = (string) $s;
            }
            if ($placeholders !== []) {
                $where .= ' AND v.status IN (' . implode(', ', $placeholders) . ')';
            }
        }
        if (!empty($filters['scheduled_from'])) {
            $where .= ' AND v.scheduled_for >= :scheduled_from';
            $params['scheduled_from'] = (string) $filters['scheduled_from'];
        }
        if (!empty($filters['scheduled_to'])) {
            $where .= ' AND v.scheduled_for <= :scheduled_to';
            $params['scheduled_to'] = (string) $filters['scheduled_to'];
        }
        if (isset($filters['customer_id'])) {
            $join = ' INNER JOIN service_routes r ON r.id = v.service_route_id ';
            $where .= ' AND r.customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        if (isset($filters['site_id'])) {
            // Joining to stops for site filter (no overlap with the customer
            // join above — the alias is different).
            $join .= ' INNER JOIN route_stops s ON s.id = v.route_stop_id ';
            $where .= ' AND s.site_id = :site_id';
            $params['site_id'] = (int) $filters['site_id'];
        }

        return [$where, $params, $join];
    }
}
