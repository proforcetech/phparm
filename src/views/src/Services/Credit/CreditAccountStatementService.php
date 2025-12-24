<?php

namespace App\Services\Credit;

use App\Database\Connection;
use DateInterval;
use DateTimeImmutable;
use PDO;

class CreditAccountStatementService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(int $accountId, ?string $startDate = null, ?string $endDate = null): array
    {
        [$from, $to] = $this->resolveRange($startDate, $endDate);
        $account = $this->fetchAccount($accountId);
        $transactions = $this->fetchTransactions($account['id'] ?? null, $from, $to);

        $balance = $account['balance'] ?? 0.0;
        foreach ($transactions as $transaction) {
            $balance += $transaction['amount'];
        }

        return [
            'account_id' => $accountId,
            'account' => $account,
            'period' => ['from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d')],
            'transactions' => $transactions,
            'ending_balance' => $balance,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAccount(int $accountId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id, customer_id, credit_limit, balance, available_credit, status FROM credit_accounts WHERE id = :account_id LIMIT 1');
        $stmt->execute(['account_id' => $accountId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchTransactions(?int $accountId, ?DateTimeImmutable $from, ?DateTimeImmutable $to): array
    {
        if ($accountId === null) {
            return [];
        }

        $sql = 'SELECT occurred_at, transaction_type, description, amount, balance_after, reference_type, reference_id FROM credit_transactions WHERE credit_account_id = :account_id';
        $params = ['account_id' => $accountId];

        if ($from !== null) {
            $sql .= ' AND occurred_at >= :from';
            $params['from'] = $from->format('Y-m-d 00:00:00');
        }

        if ($to !== null) {
            $sql .= ' AND occurred_at <= :to';
            $params['to'] = $to->format('Y-m-d 23:59:59');
        }

        $sql .= ' ORDER BY occurred_at ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static function (array $row) {
            $row['amount'] = (float) $row['amount'];
            $row['balance_after'] = isset($row['balance_after']) ? (float) $row['balance_after'] : null;
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private function resolveRange(?string $start, ?string $end): array
    {
        if (($start === null || $start === 'all') && $end === null) {
            return [null, null];
        }

        if (in_array($start, ['30d', '90d', '12m'], true) && $end === null) {
            $computedEnd = new DateTimeImmutable('now');
            $computedStart = match ($start) {
                '30d' => $computedEnd->sub(new DateInterval('P30D')),
                '90d' => $computedEnd->sub(new DateInterval('P90D')),
                '12m' => $computedEnd->sub(new DateInterval('P1Y')),
                default => null,
            };

            return [$computedStart, $computedEnd];
        }

        $resolvedStart = $start !== null ? new DateTimeImmutable($start) : null;
        $resolvedEnd = $end !== null ? new DateTimeImmutable($end) : null;

        return [$resolvedStart, $resolvedEnd];
    }
}
