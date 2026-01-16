<?php

namespace App\Services\BankFeeds;

use App\Database\Connection;
use PDO;

class BankFeedRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<int, array<string, mixed>> $transactions
     * @return array<int, array<string, mixed>>
     */
    public function upsertTransactions(string $provider, array $transactions): array
    {
        if ($transactions === []) {
            return [];
        }

        $sql = <<<SQL
            INSERT INTO bank_transactions (
                provider,
                external_id,
                account_name,
                amount,
                currency,
                description,
                transaction_date,
                posted_at,
                status,
                raw_payload,
                created_at,
                updated_at
            ) VALUES (
                :provider,
                :external_id,
                :account_name,
                :amount,
                :currency,
                :description,
                :transaction_date,
                :posted_at,
                :status,
                :raw_payload,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                account_name = VALUES(account_name),
                amount = VALUES(amount),
                currency = VALUES(currency),
                description = VALUES(description),
                transaction_date = VALUES(transaction_date),
                posted_at = VALUES(posted_at),
                status = VALUES(status),
                raw_payload = VALUES(raw_payload),
                updated_at = NOW()
        SQL;

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($transactions as $transaction) {
            $stmt->execute([
                'provider' => $provider,
                'external_id' => $transaction['external_id'],
                'account_name' => $transaction['account_name'] ?? null,
                'amount' => $transaction['amount'],
                'currency' => $transaction['currency'] ?? 'USD',
                'description' => $transaction['description'] ?? null,
                'transaction_date' => $transaction['transaction_date'],
                'posted_at' => $transaction['posted_at'] ?? null,
                'status' => $transaction['status'] ?? 'posted',
                'raw_payload' => isset($transaction['raw_payload'])
                    ? json_encode($transaction['raw_payload'], JSON_THROW_ON_ERROR)
                    : null,
            ]);
        }

        return $this->fetchByProviderExternalIds(
            $provider,
            array_map(static fn ($transaction) => $transaction['external_id'], $transactions)
        );
    }

    /**
     * @param array<int, string> $externalIds
     * @return array<int, array<string, mixed>>
     */
    private function fetchByProviderExternalIds(string $provider, array $externalIds): array
    {
        if ($externalIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($externalIds), '?'));
        $sql = sprintf(
            'SELECT * FROM bank_transactions WHERE provider = ? AND external_id IN (%s)',
            $placeholders
        );

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(array_merge([$provider], $externalIds));

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listUnmatched(int $limit = 100): array
    {
        $sql = <<<SQL
            SELECT bt.*
            FROM bank_transactions bt
            LEFT JOIN bank_transaction_matches btm ON btm.bank_transaction_id = bt.id
            WHERE btm.id IS NULL
            ORDER BY bt.transaction_date DESC, bt.id DESC
            LIMIT :limit
        SQL;

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function createMatch(
        int $bankTransactionId,
        string $referenceType,
        int $referenceId,
        ?string $reason,
        ?int $actorId
    ): void {
        $sql = <<<SQL
            INSERT INTO bank_transaction_matches (
                bank_transaction_id,
                reference_type,
                reference_id,
                match_reason,
                matched_by,
                matched_at,
                created_at
            ) VALUES (
                :bank_transaction_id,
                :reference_type,
                :reference_id,
                :match_reason,
                :matched_by,
                NOW(),
                NOW()
            )
            ON DUPLICATE KEY UPDATE
                match_reason = VALUES(match_reason),
                matched_by = VALUES(matched_by),
                matched_at = NOW()
        SQL;

        $this->connection->pdo()->prepare($sql)->execute([
            'bank_transaction_id' => $bankTransactionId,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'match_reason' => $reason,
            'matched_by' => $actorId,
        ]);
    }

    public function findPaymentMatch(float $amount, string $transactionDate): ?int
    {
        $sql = <<<SQL
            SELECT id
            FROM payment_transactions
            WHERE amount = :amount
              AND DATE(created_at) = :transaction_date
            ORDER BY created_at DESC
            LIMIT 1
        SQL;

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'amount' => $amount,
            'transaction_date' => $transactionDate,
        ]);

        $match = $stmt->fetchColumn();
        return $match ? (int) $match : null;
    }
}
