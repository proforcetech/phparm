<?php

namespace App\Services\Integrations;

use App\Database\Connection;
use App\Models\ThirdPartyIntegration;
use PDO;

/**
 * Repository for the `third_party_integrations` table.
 *
 * The `credentials` column holds an opaque encrypted blob produced by
 * IntegrationService via FieldCipher — this repository never inspects
 * or mutates that blob. Callers fetch the full row, decrypt the
 * credentials at the call site, and discard the plaintext as soon as
 * the API call completes.
 */
class ThirdPartyIntegrationRepository
{
    private const COLUMNS = [
        'id', 'provider_key', 'name', 'category', 'status', 'credentials',
        'settings', 'sync_cadence_minutes', 'last_sync_at', 'last_sync_status',
        'last_sync_error', 'next_sync_at', 'owner_user_id', 'created_at',
        'updated_at',
    ];

    private const WRITABLE = [
        'provider_key', 'name', 'category', 'status', 'credentials',
        'settings', 'sync_cadence_minutes', 'last_sync_at', 'last_sync_status',
        'last_sync_error', 'next_sync_at', 'owner_user_id',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?ThirdPartyIntegration
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM third_party_integrations WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByProviderKey(string $providerKey): ?ThirdPartyIntegration
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS)
            . ' FROM third_party_integrations WHERE provider_key = :p ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute(['p' => $providerKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, ThirdPartyIntegration>
     */
    public function listAll(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM third_party_integrations ORDER BY category ASC, name ASC'
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, ThirdPartyIntegration>
     */
    public function listByCategory(string $category): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS)
            . ' FROM third_party_integrations WHERE category = :c ORDER BY name ASC'
        );
        $stmt->execute(['c' => $category]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Used by the periodic sync cron — only returns connected rows past
     * their next_sync_at. NULL next_sync_at is treated as "never been
     * scheduled" and excluded.
     *
     * @return array<int, ThirdPartyIntegration>
     */
    public function listDue(string $now): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM third_party_integrations '
            . "WHERE status = 'connected' AND next_sync_at IS NOT NULL AND next_sync_at <= :n "
            . 'ORDER BY next_sync_at ASC'
        );
        $stmt->execute(['n' => $now]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): ThirdPartyIntegration
    {
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach (self::WRITABLE as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $columns[] = $col;
            $placeholders[] = ':' . $col;
            $params[$col] = $this->encode($col, $payload[$col]);
        }

        $sql = 'INSERT INTO third_party_integrations (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        $this->connection->pdo()->prepare($sql)->execute($params);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new ThirdPartyIntegration(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): ?ThirdPartyIntegration
    {
        $sets = [];
        $params = ['id' => $id];
        foreach (self::WRITABLE as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $sets[] = $col . ' = :' . $col;
            $params[$col] = $this->encode($col, $payload[$col]);
        }
        if ($sets === []) {
            return $this->find($id);
        }
        $this->connection->pdo()->prepare(
            'UPDATE third_party_integrations SET ' . implode(', ', $sets) . ' WHERE id = :id'
        )->execute($params);
        return $this->find($id);
    }

    /**
     * Atomic post-sync state update — keeps the hot read columns
     * coherent in a single statement so a UI refresh during a sync
     * never sees a half-applied row.
     */
    public function recordSync(
        int $id,
        string $status,
        ?string $error,
        string $finishedAt,
        ?string $nextSyncAt
    ): void {
        $this->connection->pdo()->prepare(
            'UPDATE third_party_integrations SET '
            . 'last_sync_at = :f, last_sync_status = :s, last_sync_error = :e, next_sync_at = :n '
            . 'WHERE id = :id'
        )->execute([
            'id' => $id,
            'f' => $finishedAt,
            's' => $status,
            'e' => $error,
            'n' => $nextSyncAt,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM third_party_integrations WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function encode(string $col, mixed $value): mixed
    {
        if ($col === 'settings' && $value !== null && !is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): ThirdPartyIntegration
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new ThirdPartyIntegration($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'sync_cadence_minutes', 'owner_user_id' => (int) $value,
            'settings' => is_string($value) ? (json_decode($value, true) ?: null) : $value,
            default => $value,
        };
    }
}
