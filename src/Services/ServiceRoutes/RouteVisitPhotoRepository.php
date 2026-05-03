<?php

namespace App\Services\ServiceRoutes;

use App\Database\Connection;
use App\Models\RouteVisitPhoto;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `route_visit_photos` — Phase 15 / M7+S8 of
 * docs/woms-expansion-plan.md.
 *
 * Pairs with RouteVisitRepository::incrementPhotoCounter() — the
 * RouteVisitService writes the photo row + bumps the counter inside the
 * same transaction so the denormalized count stays in sync.
 */
class RouteVisitPhotoRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, RouteVisitPhoto>
     */
    public function listForVisit(int $routeVisitId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM route_visit_photos
              WHERE route_visit_id = :id
              ORDER BY uploaded_at ASC, id ASC'
        );
        $stmt->execute(['id' => $routeVisitId]);
        return array_map(
            static fn (array $row) => RouteVisitPhoto::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?RouteVisitPhoto
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM route_visit_photos WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? RouteVisitPhoto::fromRow($row) : null;
    }

    /**
     * @param array{route_visit_id: int, uploaded_by_user_id?: int|null,
     *              file_path: string, file_mime?: string|null,
     *              file_size_bytes?: int|null, exif_taken_at?: string|null,
     *              exif_lat?: string|float|null, exif_lng?: string|float|null,
     *              perceptual_hash?: string|null, caption?: string|null} $data
     */
    public function create(array $data): RouteVisitPhoto
    {
        $visitId = (int) ($data['route_visit_id'] ?? 0);
        $filePath = trim((string) ($data['file_path'] ?? ''));
        if ($visitId <= 0 || $filePath === '') {
            throw new InvalidArgumentException(
                'route_visit_id and file_path are required'
            );
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO route_visit_photos
                (route_visit_id, uploaded_by_user_id, file_path, file_mime,
                 file_size_bytes, exif_taken_at, exif_lat, exif_lng,
                 perceptual_hash, caption)
             VALUES
                (:route_visit_id, :uploaded_by_user_id, :file_path, :file_mime,
                 :file_size_bytes, :exif_taken_at, :exif_lat, :exif_lng,
                 :perceptual_hash, :caption)'
        );
        $stmt->execute([
            'route_visit_id' => $visitId,
            'uploaded_by_user_id' => $this->nullableInt($data['uploaded_by_user_id'] ?? null),
            'file_path' => $filePath,
            'file_mime' => $this->nullableString($data['file_mime'] ?? null),
            'file_size_bytes' => $this->nullableInt($data['file_size_bytes'] ?? null),
            'exif_taken_at' => $this->nullableString($data['exif_taken_at'] ?? null),
            'exif_lat' => $this->nullableString($data['exif_lat'] ?? null),
            'exif_lng' => $this->nullableString($data['exif_lng'] ?? null),
            'perceptual_hash' => $this->nullablePerceptualHash($data['perceptual_hash'] ?? null),
            'caption' => $this->nullableString($data['caption'] ?? null),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created route_visit_photo');
        }
        return $row;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM route_visit_photos WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Find prior photos in this customer's recent visits that share a
     * perceptual hash. Used by the S8 verification pass to flag photos
     * that look like reused shots from a previous visit.
     *
     * @return array<int, array{id: int, route_visit_id: int, file_path: string,
     *                          uploaded_at: string}>
     */
    public function findRecentByPerceptualHash(
        string $hash,
        int $customerId,
        string $sinceDateTime,
        int $excludeVisitId
    ): array {
        if ($hash === '') {
            return [];
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT p.id, p.route_visit_id, p.file_path, p.uploaded_at
               FROM route_visit_photos p
               INNER JOIN route_visits v ON v.id = p.route_visit_id
               INNER JOIN service_routes r ON r.id = v.service_route_id
              WHERE p.perceptual_hash = :h
                AND r.customer_id = :customer_id
                AND p.uploaded_at >= :since
                AND p.route_visit_id <> :exclude
              ORDER BY p.uploaded_at DESC
              LIMIT 50'
        );
        $stmt->execute([
            'h' => $hash,
            'customer_id' => $customerId,
            'since' => $sinceDateTime,
            'exclude' => $excludeVisitId,
        ]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Bulk-count photos per visit for a list of visit IDs. Used by the
     * counter-rebuild action to detect drift between the denormalized
     * photos_uploaded counter and reality.
     *
     * @param array<int, int> $visitIds
     * @return array<int, int>  visit_id => actual count
     */
    public function countsForVisits(array $visitIds): array
    {
        $visitIds = array_values(array_unique(array_map('intval', $visitIds)));
        if ($visitIds === []) {
            return [];
        }
        $placeholders = [];
        $params = [];
        foreach ($visitIds as $i => $id) {
            $key = 'v' . $i;
            $placeholders[] = ':' . $key;
            $params[$key] = $id;
        }
        $sql = 'SELECT route_visit_id, COUNT(*) AS n FROM route_visit_photos
                 WHERE route_visit_id IN (' . implode(', ', $placeholders) . ')
                 GROUP BY route_visit_id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $out = array_fill_keys($visitIds, 0);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[(int) $row['route_visit_id']] = (int) $row['n'];
        }
        return $out;
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

    private function nullablePerceptualHash(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $hash = strtolower(trim((string) $value));
        if ($hash === '') {
            return null;
        }
        if (!preg_match('/^[0-9a-f]{1,16}$/', $hash)) {
            throw new InvalidArgumentException('perceptual_hash must be 1-16 hex chars');
        }
        return $hash;
    }
}
