<?php

namespace App\Services\Financial;

use App\Database\Connection;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use Throwable;

class CashDepositService
{
    private Connection $connection;
    private FinancialEntryService $financialEntries;

    public function __construct(Connection $connection, FinancialEntryService $financialEntries)
    {
        $this->connection = $connection;
        $this->financialEntries = $financialEntries;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listUndepositedPayments(array $filters = []): array
    {
        $sql = 'SELECT p.id, p.invoice_id, i.number AS invoice_number, p.amount, p.method, p.reference, p.status, p.paid_at, '
            . 'CONCAT(c.first_name, " ", c.last_name) AS customer_name '
            . 'FROM payments p '
            . 'JOIN invoices i ON i.id = p.invoice_id '
            . 'LEFT JOIN customers c ON c.id = i.customer_id '
            . 'LEFT JOIN cash_deposit_items cdi ON cdi.payment_id = p.id '
            . 'WHERE cdi.payment_id IS NULL '
            . 'AND p.method IN ("cash", "check") '
            . 'AND p.status IN ("succeeded", "paid")';

        $params = [];

        if (!empty($filters['start_date'])) {
            $sql .= ' AND p.paid_at >= :start_date';
            $params['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= ' AND p.paid_at <= :end_date';
            $params['end_date'] = $filters['end_date'];
        }

        $sql .= ' ORDER BY p.paid_at DESC, p.id DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listDeposits(array $filters = []): array
    {
        $sql = 'SELECT cd.*, u.name AS created_by_name, COUNT(cdi.id) AS payment_count '
            . 'FROM cash_deposits cd '
            . 'LEFT JOIN users u ON u.id = cd.created_by '
            . 'LEFT JOIN cash_deposit_items cdi ON cdi.deposit_id = cd.id '
            . 'WHERE 1=1';

        $params = [];

        if (!empty($filters['start_date'])) {
            $sql .= ' AND cd.deposit_date >= :start_date';
            $params['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= ' AND cd.deposit_date <= :end_date';
            $params['end_date'] = $filters['end_date'];
        }

        if (!empty($filters['status'])) {
            $sql .= ' AND cd.status = :status';
            $params['status'] = $filters['status'];
        }

        $sql .= ' GROUP BY cd.id ORDER BY cd.deposit_date DESC, cd.id DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getDeposit(int $depositId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT cd.*, u.name AS created_by_name '
            . 'FROM cash_deposits cd '
            . 'LEFT JOIN users u ON u.id = cd.created_by '
            . 'WHERE cd.id = :id'
        );
        $stmt->execute(['id' => $depositId]);
        $deposit = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$deposit) {
            return null;
        }

        $itemsStmt = $this->connection->pdo()->prepare(
            'SELECT cdi.id, cdi.payment_id, cdi.amount, p.invoice_id, i.number AS invoice_number, '
            . 'p.method, p.reference, p.paid_at, CONCAT(c.first_name, " ", c.last_name) AS customer_name '
            . 'FROM cash_deposit_items cdi '
            . 'JOIN payments p ON p.id = cdi.payment_id '
            . 'JOIN invoices i ON i.id = p.invoice_id '
            . 'LEFT JOIN customers c ON c.id = i.customer_id '
            . 'WHERE cdi.deposit_id = :deposit_id '
            . 'ORDER BY p.paid_at DESC, p.id DESC'
        );
        $itemsStmt->execute(['deposit_id' => $depositId]);
        $deposit['items'] = $itemsStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return $deposit;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createDeposit(array $payload, int $actorId): array
    {
        $paymentIds = array_values(array_unique(array_map('intval', (array) ($payload['payment_ids'] ?? []))));
        if (empty($paymentIds)) {
            throw new InvalidArgumentException('payment_ids are required');
        }

        $bankAccount = trim((string) ($payload['bank_account'] ?? ''));
        if ($bankAccount === '') {
            throw new InvalidArgumentException('bank_account is required');
        }

        $depositDate = $payload['deposit_date'] ?? null;
        if ($depositDate) {
            $date = DateTimeImmutable::createFromFormat('Y-m-d', (string) $depositDate);
            if (!$date) {
                throw new InvalidArgumentException('Invalid deposit_date');
            }
            $depositDate = $date->format('Y-m-d');
        } else {
            $depositDate = date('Y-m-d');
        }

        $reference = isset($payload['reference']) ? trim((string) $payload['reference']) : null;

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $payments = $this->fetchEligiblePayments($paymentIds);
            if (count($payments) !== count($paymentIds)) {
                throw new InvalidArgumentException('One or more payments are already deposited or not eligible.');
            }

            $total = array_sum(array_map(static fn (array $payment) => (float) $payment['amount'], $payments));
            if ($total <= 0) {
                throw new InvalidArgumentException('Deposit total must be greater than zero.');
            }

            $stmt = $pdo->prepare(
                'INSERT INTO cash_deposits (deposit_date, bank_account, reference, total_amount, status, created_by, created_at, updated_at) '
                . 'VALUES (:deposit_date, :bank_account, :reference, :total_amount, :status, :created_by, NOW(), NOW())'
            );
            $stmt->execute([
                'deposit_date' => $depositDate,
                'bank_account' => $bankAccount,
                'reference' => $reference ?: null,
                'total_amount' => $total,
                'status' => 'posted',
                'created_by' => $actorId,
            ]);

            $depositId = (int) $pdo->lastInsertId();

            $itemStmt = $pdo->prepare(
                'INSERT INTO cash_deposit_items (deposit_id, payment_id, amount, created_at) VALUES (:deposit_id, :payment_id, :amount, NOW())'
            );

            foreach ($payments as $payment) {
                $itemStmt->execute([
                    'deposit_id' => $depositId,
                    'payment_id' => $payment['id'],
                    'amount' => $payment['amount'],
                ]);
            }

            $this->recordLedgerEntries($depositId, $depositDate, $bankAccount, $reference, (float) $total, $actorId);

            $pdo->commit();

            return $this->getDeposit($depositId) ?? ['id' => $depositId];
        } catch (Throwable $exception) {
            $pdo->rollBack();
            throw $exception;
        }
    }

    /**
     * @param array<int, int> $paymentIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchEligiblePayments(array $paymentIds): array
    {
        $placeholders = implode(', ', array_fill(0, count($paymentIds), '?'));
        $sql = 'SELECT p.id, p.amount '
            . 'FROM payments p '
            . 'LEFT JOIN cash_deposit_items cdi ON cdi.payment_id = p.id '
            . 'WHERE cdi.payment_id IS NULL '
            . 'AND p.method IN ("cash", "check") '
            . 'AND p.status IN ("succeeded", "paid") '
            . "AND p.id IN ({$placeholders})";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($paymentIds);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function recordLedgerEntries(
        int $depositId,
        string $depositDate,
        string $bankAccount,
        ?string $reference,
        float $total,
        int $actorId
    ): void {
        $referenceText = $reference ?: "Cash Deposit #{$depositId}";

        $this->financialEntries->create([
            'type' => 'income',
            'category' => 'Undeposited Funds',
            'reference' => $referenceText,
            'purchase_order' => 'cash-deposit',
            'amount' => -1 * $total,
            'entry_date' => $depositDate,
            'vendor' => 'Undeposited Funds',
            'description' => sprintf('Moved cash/check receipts into bank deposit %d.', $depositId),
            'idempotency_key' => 'cash-deposit-undeposited-' . $depositId,
        ], $actorId);

        $this->financialEntries->create([
            'type' => 'income',
            'category' => 'Checking Account',
            'reference' => $referenceText,
            'purchase_order' => 'cash-deposit',
            'amount' => $total,
            'entry_date' => $depositDate,
            'vendor' => $bankAccount,
            'description' => sprintf('Bank deposit recorded for cash/check receipts (%d).', $depositId),
            'idempotency_key' => 'cash-deposit-bank-' . $depositId,
        ], $actorId);
    }
}
