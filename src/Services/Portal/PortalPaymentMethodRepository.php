<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalPaymentMethod;
use PDO;
use RuntimeException;

/**
 * Phase 6.4 of docs/expansion-plan.md — saved payment method vault
 * references for portal users.
 *
 * Scoped strictly by portal_account_id; callers at the service layer
 * must load the account first and only pass authenticated IDs in here.
 */
class PortalPaymentMethodRepository
{
    public const ALLOWED_GATEWAYS = ['stripe', 'square', 'paypal'];

    private const COLUMNS = 'id, portal_account_id, gateway, external_customer_id,
        external_method_id, brand, last4, exp_month, exp_year, label,
        is_default, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, PortalPaymentMethod>
     */
    public function listForAccount(int $portalAccountId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_payment_methods
             WHERE portal_account_id = :aid
             ORDER BY is_default DESC, id DESC'
        );
        $stmt->execute(['aid' => $portalAccountId]);
        return array_map(
            static fn(array $r) => new PortalPaymentMethod($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?PortalPaymentMethod
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_payment_methods WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PortalPaymentMethod($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): PortalPaymentMethod
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO portal_payment_methods (
                portal_account_id, gateway, external_customer_id, external_method_id,
                brand, last4, exp_month, exp_year, label, is_default
            ) VALUES (
                :portal_account_id, :gateway, :external_customer_id, :external_method_id,
                :brand, :last4, :exp_month, :exp_year, :label, :is_default
            )'
        );
        $stmt->execute([
            'portal_account_id' => (int) $data['portal_account_id'],
            'gateway' => $data['gateway'],
            'external_customer_id' => $data['external_customer_id'] ?? null,
            'external_method_id' => $data['external_method_id'],
            'brand' => $data['brand'] ?? null,
            'last4' => $data['last4'] ?? null,
            'exp_month' => isset($data['exp_month']) ? (int) $data['exp_month'] : null,
            'exp_year' => isset($data['exp_year']) ? (int) $data['exp_year'] : null,
            'label' => $data['label'] ?? null,
            'is_default' => (int) (bool) ($data['is_default'] ?? false),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('portal_payment_methods insert did not return a row');
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM portal_payment_methods WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    /**
     * Atomic swap: demote any existing default for this account, then flip
     * the target row to is_default=1. Wrapped in a single transaction so
     * two near-simultaneous calls can't leave the account with two
     * defaults.
     */
    public function setDefault(int $portalAccountId, int $methodId): void
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $clear = $pdo->prepare(
                'UPDATE portal_payment_methods SET is_default = 0
                 WHERE portal_account_id = :aid'
            );
            $clear->execute(['aid' => $portalAccountId]);

            $setIt = $pdo->prepare(
                'UPDATE portal_payment_methods SET is_default = 1
                 WHERE id = :id AND portal_account_id = :aid'
            );
            $setIt->execute(['id' => $methodId, 'aid' => $portalAccountId]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }
}
