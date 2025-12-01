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
    public function generate(int $customerId, ?string $period = null): array
    {
        [$from, $to] = $this->resolveRange($period);
        $account = $this->fetchAccount($customerId);
        $transactions = $this->fetchTransactions($account['id'] ?? null, $from, $to);

        $balance = $account['balance'] ?? 0.0;
        foreach ($transactions as $transaction) {
            $balance += $transaction['amount'];
        }

        return [
            'customer_id' => $customerId,
            'account' => $account,
            'period' => ['from' => $from?->format('Y-m-d'), 'to' => $to?->format('Y-m-d')],
            'transactions' => $transactions,
            'ending_balance' => $balance,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchAccount(int $customerId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id, limit_amount, balance as balance FROM credit_accounts WHERE customer_id = :customer_id LIMIT 1');
        $stmt->execute(['customer_id' => $customerId]);
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

        $sql = 'SELECT occurred_at, description, amount, reference FROM credit_account_transactions WHERE credit_account_id = :account_id';
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
            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array{0: ?DateTimeImmutable, 1: ?DateTimeImmutable}
     */
    private function resolveRange(?string $period): array
    {
        if ($period === null || $period === 'all') {
            return [null, null];
        }

        $end = new DateTimeImmutable('now');
        $start = match ($period) {
            '30d' => $end->sub(new DateInterval('P30D')),
            '90d' => $end->sub(new DateInterval('P90D')),
            '12m' => $end->sub(new DateInterval('P1Y')),
            default => null,
        };

        return [$start, $end];
    }
}
