<?php

namespace App\Services\Financial;

use App\Database\Connection;
use App\Models\CashDrawerSession;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class CashDrawerService
{
    private Connection $connection;
    private FinancialEntryService $financialEntries;

    public function __construct(Connection $connection, FinancialEntryService $financialEntries)
    {
        $this->connection = $connection;
        $this->financialEntries = $financialEntries;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function startSession(int $actorId, array $payload): array
    {
        $startFloat = $this->parseAmount($payload['start_float'] ?? null, 'start_float');
        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;

        $active = $this->getActiveSession($actorId);
        if ($active !== null) {
            throw new InvalidArgumentException('Cash drawer session already active.');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO cash_drawer_sessions (opened_by, started_at, start_float, notes, status, created_at, updated_at) '
            . 'VALUES (:opened_by, NOW(), :start_float, :notes, :status, NOW(), NOW())'
        );
        $stmt->execute([
            'opened_by' => $actorId,
            'start_float' => $startFloat,
            'notes' => $notes,
            'status' => 'open',
        ]);

        $sessionId = (int) $this->connection->pdo()->lastInsertId();

        return $this->fetchSession($sessionId) ?? ['id' => $sessionId];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function closeSession(int $sessionId, int $actorId, array $payload): array
    {
        $endFloat = $this->parseAmount($payload['end_float'] ?? null, 'end_float');
        $notes = isset($payload['notes']) ? (string) $payload['notes'] : null;

        $session = $this->fetchSession($sessionId);
        if ($session === null) {
            throw new InvalidArgumentException('Cash drawer session not found.');
        }

        if (($session['status'] ?? '') !== 'open') {
            throw new InvalidArgumentException('Cash drawer session already closed.');
        }

        $startTime = new DateTimeImmutable($session['started_at']);
        $endTime = new DateTimeImmutable();
        $cashSales = $this->calculateCashSales($startTime, $endTime);
        $expectedCash = (float) $session['start_float'] + $cashSales;
        $overShort = $endFloat - $expectedCash;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cash_drawer_sessions '
            . 'SET ended_at = :ended_at, end_float = :end_float, cash_sales = :cash_sales, expected_cash = :expected_cash, '
            . 'over_short = :over_short, notes = :notes, status = :status, closed_by = :closed_by, updated_at = NOW() '
            . 'WHERE id = :id'
        );
        $stmt->execute([
            'ended_at' => $endTime->format('Y-m-d H:i:s'),
            'end_float' => $endFloat,
            'cash_sales' => $cashSales,
            'expected_cash' => $expectedCash,
            'over_short' => $overShort,
            'notes' => $notes,
            'status' => 'closed',
            'closed_by' => $actorId,
            'id' => $sessionId,
        ]);

        if (abs($overShort) > 0.0001) {
            $this->recordOverShortEntry($sessionId, $overShort, $endTime, $actorId);
        }

        return $this->fetchSession($sessionId) ?? ['id' => $sessionId];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getActiveSession(int $actorId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cash_drawer_sessions WHERE opened_by = :opened_by AND status = :status ORDER BY started_at DESC LIMIT 1'
        );
        $stmt->execute([
            'opened_by' => $actorId,
            'status' => 'open',
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $startTime = new DateTimeImmutable($row['started_at']);
        $now = new DateTimeImmutable();
        $cashSales = $this->calculateCashSales($startTime, $now);
        $row['cash_sales'] = $cashSales;
        $row['expected_cash'] = (float) $row['start_float'] + $cashSales;

        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listCloseouts(array $filters): array
    {
        $sql = 'SELECT cds.*, opened.name AS opened_by_name, closed.name AS closed_by_name '
            . 'FROM cash_drawer_sessions cds '
            . 'LEFT JOIN users opened ON opened.id = cds.opened_by '
            . 'LEFT JOIN users closed ON closed.id = cds.closed_by '
            . 'WHERE 1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= ' AND cds.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['start_date']) && !empty($filters['end_date'])) {
            $start = DateTimeImmutable::createFromFormat('Y-m-d', (string) $filters['start_date']);
            $end = DateTimeImmutable::createFromFormat('Y-m-d', (string) $filters['end_date']);
            if (!$start || !$end) {
                throw new InvalidArgumentException('Invalid date range');
            }
            $sql .= ' AND cds.ended_at BETWEEN :start AND :end';
            $params['start'] = $start->setTime(0, 0, 0)->format('Y-m-d H:i:s');
            $params['end'] = $end->setTime(23, 59, 59)->format('Y-m-d H:i:s');
        }

        $sql .= ' ORDER BY cds.ended_at DESC, cds.started_at DESC, cds.id DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchSession(int $sessionId): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM cash_drawer_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function calculateCashSales(DateTimeImmutable $start, DateTimeImmutable $end): float
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COALESCE(SUM(amount), 0) AS total '
            . 'FROM payments '
            . 'WHERE method = :method '
            . 'AND status IN ("succeeded", "paid") '
            . 'AND COALESCE(paid_at, created_at) BETWEEN :start AND :end'
        );
        $stmt->execute([
            'method' => 'cash',
            'start' => $start->format('Y-m-d H:i:s'),
            'end' => $end->format('Y-m-d H:i:s'),
        ]);

        return (float) ($stmt->fetchColumn() ?: 0);
    }

    private function recordOverShortEntry(int $sessionId, float $overShort, DateTimeImmutable $entryDate, int $actorId): void
    {
        $type = $overShort > 0 ? 'income' : 'expense';
        $amount = abs($overShort);

        $this->financialEntries->create([
            'type' => $type,
            'category' => 'Cash Over/Short',
            'reference' => "Cash Drawer Closeout #{$sessionId}",
            'purchase_order' => 'N/A',
            'amount' => $amount,
            'entry_date' => $entryDate->format('Y-m-d'),
            'vendor' => 'Cash Drawer',
            'description' => sprintf('Cash drawer %s of $%0.2f for closeout %d.', $overShort > 0 ? 'overage' : 'shortage', $amount, $sessionId),
        ], $actorId);
    }

    /**
     * @param mixed $value
     */
    private function parseAmount($value, string $field): float
    {
        if ($value === null || $value === '') {
            throw new InvalidArgumentException("Missing {$field}");
        }

        if (!is_numeric($value)) {
            throw new InvalidArgumentException("Invalid {$field}");
        }

        return (float) $value;
    }
}
