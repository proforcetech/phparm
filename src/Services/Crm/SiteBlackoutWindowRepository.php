<?php

namespace App\Services\Crm;

use App\Database\Connection;
use App\Models\SiteBlackoutWindow;
use PDO;
use RuntimeException;

class SiteBlackoutWindowRepository
{
    private const COLUMNS = 'id, site_id, starts_at, ends_at, reason, recurrence, is_active,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, SiteBlackoutWindow>
     */
    public function listForSite(int $siteId, bool $activeOnly = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM site_blackout_windows WHERE site_id = :sid';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY starts_at ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['sid' => $siteId]);

        return array_map(
            static fn(array $r) => new SiteBlackoutWindow($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?SiteBlackoutWindow
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM site_blackout_windows WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new SiteBlackoutWindow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): SiteBlackoutWindow
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO site_blackout_windows (site_id, starts_at, ends_at, reason, recurrence, is_active)
             VALUES (:site_id, :starts_at, :ends_at, :reason, :recurrence, :is_active)'
        );
        $stmt->execute([
            'site_id' => (int) $data['site_id'],
            'starts_at' => $data['starts_at'],
            'ends_at' => $data['ends_at'],
            'reason' => $data['reason'] ?? null,
            'recurrence' => $data['recurrence'] ?? null,
            'is_active' => (int) ($data['is_active'] ?? 1),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $w = $this->findById($id);
        if ($w === null) {
            throw new RuntimeException('Failed to load newly created blackout window');
        }
        return $w;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): SiteBlackoutWindow
    {
        $fields = [];
        $params = ['id' => $id];
        foreach (['starts_at','ends_at','reason','recurrence'] as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "{$k} = :{$k}";
                $params[$k] = $data[$k];
            }
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) $data['is_active'];
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE site_blackout_windows SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $w = $this->findById($id);
        if ($w === null) {
            throw new RuntimeException("Blackout window {$id} not found after update");
        }
        return $w;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM site_blackout_windows WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
