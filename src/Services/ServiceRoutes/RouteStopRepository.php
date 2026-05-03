<?php

namespace App\Services\ServiceRoutes;

use App\Database\Connection;
use App\Models\RouteStop;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `route_stops` — Phase 15 / M7 of
 * docs/woms-expansion-plan.md.
 *
 * sequence is unique per route. The repository accepts an optional
 * `auto_sequence` flag on create() that picks the next slot at the tail of
 * the route, so callers don't have to count first.
 */
class RouteStopRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, RouteStop>
     */
    public function listForRoute(int $serviceRouteId, bool $activeOnly = false): array
    {
        $sql = 'SELECT * FROM route_stops WHERE service_route_id = :id';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY sequence ASC, id ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['id' => $serviceRouteId]);
        return array_map(
            static fn (array $row) => RouteStop::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?RouteStop
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM route_stops WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? RouteStop::fromRow($row) : null;
    }

    public function nextSequence(int $serviceRouteId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COALESCE(MAX(sequence), 0) + 1 FROM route_stops
              WHERE service_route_id = :id'
        );
        $stmt->execute(['id' => $serviceRouteId]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): RouteStop
    {
        $serviceRouteId = (int) ($data['service_route_id'] ?? 0);
        $siteId = (int) ($data['site_id'] ?? 0);
        if ($serviceRouteId <= 0 || $siteId <= 0) {
            throw new InvalidArgumentException(
                'service_route_id and site_id are required'
            );
        }

        $sequence = isset($data['sequence']) && (int) $data['sequence'] > 0
            ? (int) $data['sequence']
            : $this->nextSequence($serviceRouteId);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO route_stops
                (service_route_id, sequence, site_id, site_asset_id, stop_name,
                 estimated_minutes, checklist_template_id, required_photos,
                 notes, is_active)
             VALUES
                (:service_route_id, :sequence, :site_id, :site_asset_id, :stop_name,
                 :estimated_minutes, :checklist_template_id, :required_photos,
                 :notes, :is_active)'
        );
        $stmt->execute([
            'service_route_id' => $serviceRouteId,
            'sequence' => $sequence,
            'site_id' => $siteId,
            'site_asset_id' => $this->nullableInt($data['site_asset_id'] ?? null),
            'stop_name' => $this->nullableString($data['stop_name'] ?? null),
            'estimated_minutes' => max(1, min(1440, (int) ($data['estimated_minutes'] ?? 15))),
            'checklist_template_id' => $this->nullableInt($data['checklist_template_id'] ?? null),
            'required_photos' => $this->nullableRequiredPhotos($data['required_photos'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
            'is_active' => array_key_exists('is_active', $data) && !$data['is_active'] ? 0 : 1,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created route_stop');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): RouteStop
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("route_stop {$id} not found");
        }

        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('sequence', $data)) {
            $n = (int) $data['sequence'];
            if ($n < 1) {
                throw new InvalidArgumentException('sequence must be >= 1');
            }
            $fields[] = 'sequence = :sequence';
            $params['sequence'] = $n;
        }
        $stringCols = ['stop_name', 'notes'];
        foreach ($stringCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }
        if (array_key_exists('site_id', $data)) {
            $fields[] = 'site_id = :site_id';
            $params['site_id'] = (int) $data['site_id'];
        }
        if (array_key_exists('site_asset_id', $data)) {
            $fields[] = 'site_asset_id = :site_asset_id';
            $params['site_asset_id'] = $this->nullableInt($data['site_asset_id']);
        }
        if (array_key_exists('checklist_template_id', $data)) {
            $fields[] = 'checklist_template_id = :checklist_template_id';
            $params['checklist_template_id'] = $this->nullableInt($data['checklist_template_id']);
        }
        if (array_key_exists('estimated_minutes', $data)) {
            $fields[] = 'estimated_minutes = :estimated_minutes';
            $params['estimated_minutes'] = max(1, min(1440, (int) $data['estimated_minutes']));
        }
        if (array_key_exists('required_photos', $data)) {
            $fields[] = 'required_photos = :required_photos';
            $params['required_photos'] = $this->nullableRequiredPhotos($data['required_photos']);
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = $data['is_active'] ? 1 : 0;
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE route_stops SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("route_stop {$id} not found after update");
        }
        return $row;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM route_stops WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Apply a list of (stop_id => sequence) writes inside a single
     * transaction. Used by the admin UI's drag-to-reorder action so all
     * sequence updates land atomically (the UNIQUE (route_id, sequence)
     * constraint can otherwise trip on a transient collision).
     *
     * The two-pass write (offset → final) avoids the constraint violation:
     * pass 1 pushes every affected row above the route's current max so no
     * pair ever shares a sequence; pass 2 writes the requested values.
     *
     * @param array<int, int> $sequenceById  stop_id => new sequence
     */
    public function reorder(int $serviceRouteId, array $sequenceById): void
    {
        if ($sequenceById === []) {
            return;
        }
        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            $maxStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(sequence), 0) FROM route_stops
                  WHERE service_route_id = :id'
            );
            $maxStmt->execute(['id' => $serviceRouteId]);
            $offset = (int) $maxStmt->fetchColumn() + 1000;

            $bumpStmt = $pdo->prepare(
                'UPDATE route_stops SET sequence = :seq
                  WHERE id = :id AND service_route_id = :route_id'
            );
            $finalStmt = $pdo->prepare(
                'UPDATE route_stops SET sequence = :seq
                  WHERE id = :id AND service_route_id = :route_id'
            );

            $i = 0;
            foreach ($sequenceById as $stopId => $_seq) {
                $bumpStmt->execute([
                    'seq' => $offset + $i,
                    'id' => (int) $stopId,
                    'route_id' => $serviceRouteId,
                ]);
                $i++;
            }
            foreach ($sequenceById as $stopId => $seq) {
                $finalStmt->execute([
                    'seq' => max(1, (int) $seq),
                    'id' => (int) $stopId,
                    'route_id' => $serviceRouteId,
                ]);
            }

            if ($startedTx) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function nullableRequiredPhotos(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return max(0, min(255, (int) $value));
    }
}
