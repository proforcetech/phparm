<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalApiToken;
use PDO;
use RuntimeException;

class PortalApiTokenRepository
{
    private const COLUMNS = 'id, portal_account_id, name, token_prefix, token_hash,
        scopes, last_used_at, expires_at, revoked_at, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?PortalApiToken
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_api_tokens WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PortalApiToken($row) : null;
    }

    public function findByPrefix(string $prefix): ?PortalApiToken
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_api_tokens
             WHERE token_prefix = :p LIMIT 1'
        );
        $stmt->execute(['p' => $prefix]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PortalApiToken($row) : null;
    }

    /**
     * @return array<int, PortalApiToken>
     */
    public function listForAccount(int $accountId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM portal_api_tokens
                WHERE portal_account_id = :a';
        if ($activeOnly) {
            $sql .= ' AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY id DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['a' => $accountId]);
        return array_map(
            fn(array $r) => new PortalApiToken($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<int, string>|null $scopes
     */
    public function create(
        int $accountId,
        string $name,
        string $tokenPrefix,
        string $tokenHash,
        ?array $scopes,
        ?string $expiresAt
    ): PortalApiToken {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO portal_api_tokens
             (portal_account_id, name, token_prefix, token_hash, scopes, expires_at, created_at, updated_at)
             VALUES (:a, :n, :p, :h, :s, :e, NOW(), NOW())'
        );
        $stmt->execute([
            'a' => $accountId,
            'n' => $name,
            'p' => $tokenPrefix,
            'h' => $tokenHash,
            's' => $scopes === null ? null : json_encode(array_values($scopes)),
            'e' => $expiresAt,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("portal_api_tokens id={$id} vanished after insert");
        }
        return $row;
    }

    public function recordUse(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE portal_api_tokens SET last_used_at = NOW(), updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function revoke(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE portal_api_tokens
             SET revoked_at = NOW(), updated_at = NOW()
             WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }
}
