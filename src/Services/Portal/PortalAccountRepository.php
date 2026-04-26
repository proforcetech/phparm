<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalAccount;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Phase 6.1 of docs/expansion-plan.md — portal scope binding persistence.
 *
 * Every method that writes assumes a row exists OR explicitly creates one;
 * the (user_id, company_id) unique key enforces "a user can have at most
 * one active scope per company" at the DB layer. Revocation flips
 * is_active=0 + stamps revoked_at; we never DELETE so the audit trail stays
 * intact for past portal session explanations.
 */
class PortalAccountRepository
{
    private const COLUMNS = 'id, user_id, company_id, allowed_site_ids,
        is_active, provisioned_by_user_id, provisioned_at, last_login_at,
        revoked_at, revoked_reason, notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?PortalAccount
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_accounts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function findActiveByUserId(int $userId): ?PortalAccount
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_accounts
             WHERE user_id = :user_id AND is_active = 1 AND revoked_at IS NULL
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    public function findByUserAndCompany(int $userId, int $companyId): ?PortalAccount
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_accounts
             WHERE user_id = :user_id AND company_id = :company_id LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId, 'company_id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->hydrate($row) : null;
    }

    /**
     * @return array<int, PortalAccount>
     */
    public function listByCompany(int $companyId, bool $activeOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM portal_accounts WHERE company_id = :company_id';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1 AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY id ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['company_id' => $companyId]);
        return array_map(
            fn(array $r) => $this->hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<int, int>|null $allowedSiteIds
     */
    public function provision(
        int $userId,
        int $companyId,
        ?array $allowedSiteIds,
        ?int $provisionedByUserId,
        ?string $notes = null,
    ): PortalAccount {
        if ($this->findByUserAndCompany($userId, $companyId) !== null) {
            throw new InvalidArgumentException(
                "portal_account already exists for user={$userId} company={$companyId} — "
                . 'use updateScope() or revoke() instead of double-provisioning'
            );
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO portal_accounts
             (user_id, company_id, allowed_site_ids, is_active,
              provisioned_by_user_id, provisioned_at, notes, created_at, updated_at)
             VALUES (:user_id, :company_id, :allowed_site_ids, 1,
                     :provisioned_by, NOW(), :notes, NOW(), NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'company_id' => $companyId,
            'allowed_site_ids' => $allowedSiteIds === null
                ? null
                : json_encode(array_values(array_map('intval', $allowedSiteIds))),
            'provisioned_by' => $provisionedByUserId,
            'notes' => $notes,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("failed to reload portal_account id={$id}");
        }
        return $row;
    }

    /**
     * @param array<int, int>|null $allowedSiteIds
     */
    public function updateScope(int $id, ?array $allowedSiteIds, ?string $notes): PortalAccount
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE portal_accounts
             SET allowed_site_ids = :allowed_site_ids,
                 notes = :notes,
                 updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'allowed_site_ids' => $allowedSiteIds === null
                ? null
                : json_encode(array_values(array_map('intval', $allowedSiteIds))),
            'notes' => $notes,
        ]);
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("portal_account id={$id} vanished after updateScope");
        }
        return $row;
    }

    public function revoke(int $id, string $reason): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE portal_accounts
             SET is_active = 0, revoked_at = NOW(), revoked_reason = :reason, updated_at = NOW()
             WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute(['id' => $id, 'reason' => $reason]);
    }

    public function recordLogin(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE portal_accounts SET last_login_at = NOW(), updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): PortalAccount
    {
        if (isset($row['allowed_site_ids']) && is_string($row['allowed_site_ids'])) {
            $decoded = json_decode($row['allowed_site_ids'], true);
            $row['allowed_site_ids'] = is_array($decoded) ? array_map('intval', $decoded) : null;
        }
        return new PortalAccount($row);
    }
}
