<?php

namespace App\Services\Credit;

use App\Database\Connection;
use App\Models\CreditAccount;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class CreditAccountService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, int $actorId): CreditAccount
    {
        $this->validate($payload);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO credit_accounts (customer_id, type, credit_limit, balance, net_days, apr, late_fee, status, created_at, updated_at) VALUES (:customer_id, :type, :limit, :balance, :net_days, :apr, :late_fee, :status, NOW(), NOW())'
        );
        $stmt->execute([
            'customer_id' => $payload['customer_id'],
            'type' => $payload['type'],
            'limit' => $payload['credit_limit'] ?? 0,
            'balance' => $payload['balance'] ?? 0,
            'net_days' => $payload['net_days'] ?? 0,
            'apr' => $payload['apr'] ?? 0,
            'late_fee' => $payload['late_fee'] ?? 0,
            'status' => $payload['status'] ?? 'active',
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $this->log($actorId, 'credit.create', $id, $payload);

        return $this->find($id) ?? new CreditAccount(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $accountId, array $payload, int $actorId): ?CreditAccount
    {
        $existing = $this->find($accountId);
        if ($existing === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE credit_accounts SET credit_limit = COALESCE(:limit, credit_limit), net_days = COALESCE(:net_days, net_days), apr = COALESCE(:apr, apr), late_fee = COALESCE(:late_fee, late_fee), status = COALESCE(:status, status), updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $accountId,
            'limit' => $payload['credit_limit'] ?? null,
            'net_days' => $payload['net_days'] ?? null,
            'apr' => $payload['apr'] ?? null,
            'late_fee' => $payload['late_fee'] ?? null,
            'status' => $payload['status'] ?? null,
        ]);

        $updated = $this->find($accountId);
        $this->log($actorId, 'credit.update', $accountId, [
            'before' => $existing->toArray(),
            'after' => $updated?->toArray(),
        ]);

        return $updated;
    }

    public function applyCharge(int $accountId, float $amount, string $reason, int $actorId): ?CreditAccount
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Charge amount must be positive.');
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE credit_accounts SET balance = balance + :amount, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $accountId, 'amount' => $amount]);

        $updated = $this->find($accountId);
        $this->log($actorId, 'credit.charge', $accountId, [
            'amount' => $amount,
            'reason' => $reason,
        ]);

        return $updated;
    }

    public function applyPayment(int $accountId, float $amount, string $source, int $actorId): ?CreditAccount
    {
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE credit_accounts SET balance = GREATEST(0, balance - :amount), updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $accountId, 'amount' => $amount]);

        $updated = $this->find($accountId);
        $this->log($actorId, 'credit.payment', $accountId, [
            'amount' => $amount,
            'source' => $source,
        ]);

        return $updated;
    }

    public function findByCustomer(int $customerId): ?CreditAccount
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM credit_accounts WHERE customer_id = :customer LIMIT 1');
        $stmt->execute(['customer' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new CreditAccount($row);
    }

    public function find(int $accountId): ?CreditAccount
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM credit_accounts WHERE id = :id');
        $stmt->execute(['id' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new CreditAccount($row);
    }

    /**
     * @return array<string, float|int>
     */
    public function customerView(int $customerId): array
    {
        $account = $this->findByCustomer($customerId);
        if ($account === null) {
            return [
                'available' => 0.0,
                'balance' => 0.0,
                'limit' => 0.0,
                'status' => 'inactive',
            ];
        }

        return [
            'available' => max(0, $account->credit_limit - $account->balance),
            'balance' => $account->balance,
            'limit' => $account->credit_limit,
            'status' => $account->status,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validate(array $payload): void
    {
        foreach (['customer_id', 'type'] as $field) {
            if (empty($payload[$field])) {
                throw new InvalidArgumentException('Credit account missing required field: ' . $field);
            }
        }
    }

    private function log(int $actorId, string $event, int $accountId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'credit_account', (string) $accountId, $actorId, $context));
    }
}
