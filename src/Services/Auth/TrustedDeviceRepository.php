<?php

namespace App\Services\Auth;

use App\Database\Connection;
use App\Models\TrustedDevice;
use PDO;

class TrustedDeviceRepository
{
    private const COLUMNS = [
        'id', 'user_id', 'token_hash', 'label', 'user_agent', 'ip_address',
        'last_used_at', 'expires_at', 'revoked_at', 'created_at',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?TrustedDevice
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM trusted_devices WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByHash(string $hash): ?TrustedDevice
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM trusted_devices WHERE token_hash = :h'
        );
        $stmt->execute(['h' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, TrustedDevice>
     */
    public function listForUser(int $userId, bool $includeRevoked = false): array
    {
        $sql = 'SELECT ' . implode(', ', self::COLUMNS) . ' FROM trusted_devices WHERE user_id = :u';
        if (!$includeRevoked) {
            $sql .= ' AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY last_used_at DESC, created_at DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['u' => $userId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload Required: user_id, token_hash, expires_at.
     */
    public function create(array $payload): TrustedDevice
    {
        $this->connection->pdo()->prepare(
            'INSERT INTO trusted_devices (user_id, token_hash, label, user_agent, ip_address, expires_at) '
            . 'VALUES (:u, :h, :l, :ua, :ip, :ex)'
        )->execute([
            'u' => (int) $payload['user_id'],
            'h' => (string) $payload['token_hash'],
            'l' => $payload['label'] ?? null,
            'ua' => $payload['user_agent'] ?? null,
            'ip' => $payload['ip_address'] ?? null,
            'ex' => (string) $payload['expires_at'],
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new TrustedDevice(['id' => $id]);
    }

    public function touch(int $id, ?string $when = null): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE trusted_devices SET last_used_at = :t WHERE id = :id'
        )->execute(['id' => $id, 't' => $when ?? date('Y-m-d H:i:s')]);
    }

    public function revoke(int $id, ?string $when = null): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE trusted_devices SET revoked_at = :t WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $id, 't' => $when ?? date('Y-m-d H:i:s')]);
        return $stmt->rowCount() > 0;
    }

    public function revokeAllForUser(int $userId, ?string $when = null): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE trusted_devices SET revoked_at = :t WHERE user_id = :u AND revoked_at IS NULL'
        );
        $stmt->execute(['u' => $userId, 't' => $when ?? date('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    /**
     * Hard-delete trust rows whose expires_at has passed. Run from cron
     * to keep the table from growing unbounded with dead trust tokens.
     */
    public function purgeExpired(?string $cutoff = null): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM trusted_devices WHERE expires_at < :cutoff'
        );
        $stmt->execute(['cutoff' => $cutoff ?? date('Y-m-d H:i:s')]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): TrustedDevice
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new TrustedDevice($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'user_id' => (int) $value,
            default => $value,
        };
    }
}
