<?php

namespace App\Services\Credit;

use App\Database\Connection;
use App\Models\CreditAccount;
use App\Models\CreditPayment;
use App\Models\CreditPaymentReminder;
use App\Models\CreditTransaction;
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

        $limit = $payload['credit_limit'] ?? 0;
        $balance = $payload['balance'] ?? 0;
        $available = max(0, (float) $limit - (float) $balance);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO credit_accounts (customer_id, type, credit_limit, balance, available_credit, net_days, apr, late_fee, status, created_at, updated_at) VALUES (:customer_id, :type, :limit, :balance, :available, :net_days, :apr, :late_fee, :status, NOW(), NOW())'
        );
        $stmt->execute([
            'customer_id' => $payload['customer_id'],
            'type' => $payload['type'],
            'limit' => $limit,
            'balance' => $balance,
            'available' => $available,
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

        $limit = $payload['credit_limit'] ?? $existing->credit_limit;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE credit_accounts SET credit_limit = COALESCE(:limit, credit_limit), available_credit = GREATEST(0, COALESCE(:limit, credit_limit) - balance), net_days = COALESCE(:net_days, net_days), apr = COALESCE(:apr, apr), late_fee = COALESCE(:late_fee, late_fee), status = COALESCE(:status, status), updated_at = NOW() WHERE id = :id'
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

        $account = $this->find($accountId);
        if ($account === null) {
            return null;
        }

        if ($account->status !== 'active') {
            throw new InvalidArgumentException('Credit account is not active.');
        }

        $updated = $this->updateAccountBalance($account, $account->balance + $amount);
        $this->logTransaction($account, 'charge', $amount, $updated->balance, $actorId, $reason);
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

        $account = $this->find($accountId);
        if ($account === null) {
            return null;
        }

        if ($account->status !== 'active') {
            throw new InvalidArgumentException('Credit account is not active.');
        }

        $applied = min($amount, $account->balance);
        $updated = $this->updateAccountBalance($account, max(0, $account->balance - $amount));
        $paymentId = $this->logPayment($updated, $applied, $source, $actorId);
        $transactionId = $this->logTransaction($updated, 'payment', -$applied, $updated->balance, $actorId, null, [
            'reference_type' => 'payment',
            'reference_id' => $paymentId,
        ]);
        $this->log($actorId, 'credit.payment', $accountId, [
            'amount' => $applied,
            'source' => $source,
            'payment_id' => $paymentId,
            'transaction_id' => $transactionId,
        ]);

        return $updated;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, CreditAccount>
     */
    public function list(array $filters = []): array
    {
        $sql = 'SELECT * FROM credit_accounts';
        $params = [];
        $where = [];

        $activeOnly = $filters['active_only'] ?? true;

        if (isset($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
            $activeOnly = false;
        }

        if (isset($filters['customer_id'])) {
            $where[] = 'customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }

        if (isset($filters['type'])) {
            $where[] = 'type = :type';
            $params['type'] = (string) $filters['type'];
        }

        if ($activeOnly) {
            $where[] = 'status = :active_status';
            $params['active_status'] = 'active';
        }

        if (count($where) > 0) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $row) => new CreditAccount($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function findById(int $accountId): ?CreditAccount
    {
        return $this->find($accountId);
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

    public function findByCustomerId(int $customerId): ?CreditAccount
    {
        return $this->findByCustomer($customerId);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function recordPayment(int $accountId, array $data, int $actorId): array
    {
        $account = $this->find($accountId);
        if ($account === null) {
            throw new InvalidArgumentException('Credit account not found.');
        }

        if ($account->status !== 'active') {
            throw new InvalidArgumentException('Credit account is not active.');
        }

        $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        $source = isset($data['source']) ? (string) $data['source'] : 'manual';
        $notes = isset($data['notes']) ? (string) $data['notes'] : null;
        $referenceNumber = isset($data['reference_number']) ? (string) $data['reference_number'] : null;
        $paymentMethod = isset($data['payment_method']) ? (string) $data['payment_method'] : $source;

        [$updated, $appliedAmount] = $this->applyPaymentWithMetadata($account, $amount, [
            'source' => $paymentMethod,
            'notes' => $notes,
            'reference_number' => $referenceNumber,
        ], $actorId);

        $balance = $this->getBalance($accountId);
        $availableCredit = $this->getAvailableCredit($accountId);

        return [
            'account' => $updated?->toArray() ?? $account->toArray(),
            'balance' => $balance,
            'available_credit' => $availableCredit,
            'applied_amount' => $appliedAmount,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function submitCustomerPayment(int $customerId, array $data, int $actorId): array
    {
        $account = $this->findByCustomer($customerId);
        if ($account === null) {
            throw new InvalidArgumentException('No credit account found.');
        }

        if ($account->status !== 'active') {
            throw new InvalidArgumentException('Credit account is not active.');
        }

        $amount = isset($data['amount']) ? (float) $data['amount'] : 0.0;
        if ($amount <= 0) {
            throw new InvalidArgumentException('Payment amount must be positive.');
        }

        $method = isset($data['payment_method']) ? (string) $data['payment_method'] : 'portal';
        $notes = isset($data['notes']) ? (string) $data['notes'] : null;
        $referenceNumber = isset($data['reference_number']) ? (string) $data['reference_number'] : null;

        $paymentId = $this->logPayment($account, $amount, $method, null, $referenceNumber, $notes, 'pending');

        $this->log($actorId, 'credit.payment.submitted', $account->id, [
            'amount' => $amount,
            'payment_method' => $method,
            'reference_number' => $referenceNumber,
            'payment_id' => $paymentId,
        ]);

        return [
            'account' => $account->toArray(),
            'payment' => [
                'id' => $paymentId,
                'amount' => $amount,
                'payment_method' => $method,
                'reference_number' => $referenceNumber,
                'notes' => $notes,
                'status' => 'pending',
            ],
        ];
    }

    public function getBalance(int $accountId): float
    {
        $account = $this->find($accountId);
        return $account?->balance ?? 0.0;
    }

    public function getAvailableCredit(int $accountId): float
    {
        $account = $this->find($accountId);
        if ($account === null) {
            return 0.0;
        }

        return max(0, $account->available_credit);
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
            'available' => max(0, $account->available_credit),
            'balance' => $account->balance,
            'limit' => $account->credit_limit,
            'status' => $account->status,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function customerLedger(int $customerId): array
    {
        $account = $this->findByCustomer($customerId);
        if ($account === null) {
            throw new InvalidArgumentException('No credit account found.');
        }

        $transactions = $this->getTransactions($account->id);
        $payments = $this->getPayments($account->id);
        $reminders = $this->getReminders($account->id);

        return [
            'account' => $account->toArray(),
            'balance' => $account->balance,
            'available_credit' => max(0, $account->available_credit),
            'transactions' => array_map(static fn (CreditTransaction $t) => $t->toArray(), $transactions),
            'payments' => array_map(static fn (CreditPayment $p) => $p->toArray(), $payments),
            'reminders' => array_map(static fn (CreditPaymentReminder $r) => $r->toArray(), $reminders),
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

    private function updateAccountBalance(CreditAccount $account, float $newBalance): CreditAccount
    {
        $available = max(0, $account->credit_limit - $newBalance);

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE credit_accounts SET balance = :balance, available_credit = :available_credit, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $account->id,
            'balance' => $newBalance,
            'available_credit' => $available,
        ]);

        $account->balance = $newBalance;
        $account->available_credit = $available;

        return $account;
    }

    private function logPayment(
        CreditAccount $account,
        float $amount,
        string $source,
        ?int $actorId,
        ?string $referenceNumber = null,
        ?string $notes = null,
        string $status = 'completed'
    ): int {
        $stmt = $this->connection->pdo()->prepare('INSERT INTO credit_payments (credit_account_id, customer_id, payment_method, amount, payment_date, reference_number, notes, processed_by, status, created_at, updated_at) VALUES (:account_id, :customer_id, :method, :amount, NOW(), :reference_number, :notes, :processed_by, :status, NOW(), NOW())');
        $stmt->execute([
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'method' => $source,
            'amount' => $amount,
            'reference_number' => $referenceNumber,
            'notes' => $notes,
            'processed_by' => $actorId,
            'status' => $status,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @return array<int, CreditTransaction>
     */
    private function getTransactions(int $accountId, int $limit = 50): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM credit_transactions WHERE credit_account_id = :account ORDER BY occurred_at DESC LIMIT :limit');
        $stmt->bindValue('account', $accountId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row) => new CreditTransaction($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, CreditPayment>
     */
    private function getPayments(int $accountId, int $limit = 50): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM credit_payments WHERE credit_account_id = :account ORDER BY payment_date DESC LIMIT :limit');
        $stmt->bindValue('account', $accountId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row) => new CreditPayment($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, CreditPaymentReminder>
     */
    private function getReminders(int $accountId, int $limit = 10): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM credit_payment_reminders WHERE credit_account_id = :account ORDER BY sent_at DESC LIMIT :limit');
        $stmt->bindValue('account', $accountId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row) => new CreditPaymentReminder($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array{reference_type?: string|null, reference_id?: int|null} $reference
     */
    private function logTransaction(
        CreditAccount $account,
        string $type,
        float $amount,
        float $balanceAfter,
        int $actorId,
        ?string $description = null,
        array $reference = []
    ): int {
        $stmt = $this->connection->pdo()->prepare('INSERT INTO credit_transactions (credit_account_id, customer_id, transaction_type, amount, balance_after, reference_type, reference_id, description, created_by, occurred_at, created_at) VALUES (:account_id, :customer_id, :type, :amount, :balance_after, :reference_type, :reference_id, :description, :created_by, NOW(), NOW())');
        $stmt->execute([
            'account_id' => $account->id,
            'customer_id' => $account->customer_id,
            'type' => $type,
            'amount' => $amount,
            'balance_after' => $balanceAfter,
            'reference_type' => $reference['reference_type'] ?? null,
            'reference_id' => $reference['reference_id'] ?? null,
            'description' => $description,
            'created_by' => $actorId,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @return array{0: CreditAccount, 1: float}
     */
    private function applyPaymentWithMetadata(CreditAccount $account, float $amount, array $meta, int $actorId): array
    {
        $appliedAmount = min($amount, $account->balance);
        $updated = $this->updateAccountBalance($account, max(0, $account->balance - $amount));

        $paymentId = $this->logPayment($account, $appliedAmount, $meta['source'], $actorId, $meta['reference_number'] ?? null, $meta['notes'] ?? null);
        $this->logTransaction($updated, 'payment', -$appliedAmount, $updated->balance, $actorId, $meta['notes'] ?? null, [
            'reference_type' => 'payment',
            'reference_id' => $paymentId,
        ]);

        $this->log($actorId, 'credit.payment', $account->id, [
            'amount' => $appliedAmount,
            'source' => $meta['source'],
            'payment_id' => $paymentId,
        ]);

        return [$updated, $appliedAmount];
    }

    private function log(int $actorId, string $event, int $accountId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'credit_account', (string) $accountId, $actorId, $context));
    }
}
