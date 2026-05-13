<?php

namespace App\Services\Contracts;

use App\Database\Connection;
use App\Models\ContractSigner;
use PDO;
use RuntimeException;

/**
 * R-02c — invitation roster for first-class multi-party signing.
 *
 * Mirrors the public-link repository's shape: thin PDO wrapper, column
 * allow-list, no business logic. Service-layer rules (active-signer
 * dedupe by email, lifecycle transitions) live in
 * {@see ContractSignerService}.
 */
class ContractSignerRepository
{
    private const COLUMNS = 'id, contract_id, email, name, title, display_order,
        invited_at, invited_by_user_id, revoked_at,
        signed_signature_id, signed_at, notes,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function create(
        int $contractId,
        string $email,
        string $name,
        ?string $title,
        int $displayOrder,
        ?int $invitedByUserId,
        ?string $notes = null
    ): ContractSigner {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO contract_signers
             (contract_id, email, name, title, display_order, invited_by_user_id, notes)
             VALUES (:cid, :email, :name, :title, :ord, :uid, :notes)'
        );
        $stmt->execute([
            'cid' => $contractId,
            'email' => $email,
            'name' => $name,
            'title' => $title,
            'ord' => $displayOrder,
            'uid' => $invitedByUserId,
            'notes' => $notes,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('contract_signers insert did not return a row');
        }
        return $found;
    }

    public function findById(int $id): ?ContractSigner
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_signers WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractSigner($row) : null;
    }

    /**
     * Look up an active (not revoked) signer on a given contract by email.
     * Used by the service to enforce the "no duplicate live invites for the
     * same email" invariant since MySQL can't express it as a unique index
     * on a partial set.
     */
    public function findActiveByEmail(int $contractId, string $email): ?ContractSigner
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contract_signers
             WHERE contract_id = :cid AND email = :email AND revoked_at IS NULL
             LIMIT 1'
        );
        $stmt->execute(['cid' => $contractId, 'email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new ContractSigner($row) : null;
    }

    /**
     * @return array<int, ContractSigner>
     */
    public function listForContract(int $contractId, bool $includeRevoked = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM contract_signers
                WHERE contract_id = :cid';
        if (!$includeRevoked) {
            $sql .= ' AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY display_order ASC, id ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['cid' => $contractId]);
        return array_map(
            static fn(array $r) => new ContractSigner($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function revoke(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE contract_signers SET revoked_at = CURRENT_TIMESTAMP
             WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Stamp the signature linkage onto a signer row. Idempotent — if a
     * signer is somehow marked twice the second call is a no-op against
     * the WHERE clause, preserving the first signing record.
     */
    public function markSigned(int $id, int $signatureId, string $signedAt): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE contract_signers
                SET signed_signature_id = :sid, signed_at = :ts
              WHERE id = :id AND signed_signature_id IS NULL'
        );
        $stmt->execute(['id' => $id, 'sid' => $signatureId, 'ts' => $signedAt]);
    }

    /**
     * Lowest available display_order for a new signer on the contract.
     * Centralized here so the service doesn't have to repeat the +1 math.
     */
    public function nextDisplayOrder(int $contractId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COALESCE(MAX(display_order), -1) + 1 AS next_order
             FROM contract_signers WHERE contract_id = :cid'
        );
        $stmt->execute(['cid' => $contractId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return (int) ($row['next_order'] ?? 0);
    }
}
