<?php

namespace App\Services\Contracts;

use App\Database\Connection;
use App\Models\ContractPublicLink;
use PDO;
use RuntimeException;

/**
 * Phase 4.2 of docs/expansion-plan.md: shareable short-code + token links
 * for signing a contract without an app account. Mirrors the
 * estimate_public_links pattern.
 */
class ContractPublicLinkRepository
{
    private const COLUMNS = 'id, contract_id, token_hash, short_code,
        expires_at, last_accessed_at, revoked_at, created_by_user_id,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(
        int $contractId,
        string $tokenHash,
        string $shortCode,
        ?string $expiresAt,
        ?int $createdByUserId
    ): ContractPublicLink {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO contract_public_links
             (contract_id, token_hash, short_code, expires_at, created_by_user_id)
             VALUES (:cid, :hash, :short, :exp, :uid)'
        );
        $stmt->execute([
            'cid' => $contractId,
            'hash' => $tokenHash,
            'short' => $shortCode,
            'exp' => $expiresAt,
            'uid' => $createdByUserId,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('contract_public_links insert did not return a row');
        }
        return $found;
    }

    public function findById(int $id): ?ContractPublicLink
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_public_links WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractPublicLink($row) : null;
    }

    public function findByTokenHash(string $tokenHash): ?ContractPublicLink
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_public_links
             WHERE token_hash = :hash LIMIT 1'
        );
        $stmt->execute(['hash' => $tokenHash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractPublicLink($row) : null;
    }

    public function findByShortCode(string $shortCode): ?ContractPublicLink
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_public_links
             WHERE short_code = :short LIMIT 1'
        );
        $stmt->execute(['short' => $shortCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractPublicLink($row) : null;
    }

    /**
     * @return array<int, ContractPublicLink>
     */
    public function listForContract(int $contractId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_public_links
             WHERE contract_id = :cid ORDER BY id DESC'
        );
        $stmt->execute(['cid' => $contractId]);
        return array_map(
            static fn(array $r) => new ContractPublicLink($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function touchLastAccessed(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE contract_public_links SET last_accessed_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    public function revoke(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE contract_public_links SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }
}
