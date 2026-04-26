<?php

namespace App\Services\Financial;

use App\Database\Connection;
use App\Models\ReconciliationBankTransaction;
use App\Models\ReconciliationMatch;
use App\Models\ReconciliationSession;
use InvalidArgumentException;
use PDO;

class ReconciliationService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, ReconciliationSession>
     */
    public function listSessions(array $filters = []): array
    {
        $conditions = [];
        $params = [];

        if (!empty($filters['status'])) {
            $conditions[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        $where = $conditions ? 'WHERE ' . implode(' AND ', $conditions) : '';
        $sql = "SELECT * FROM reconciliation_sessions {$where} ORDER BY created_at DESC, id DESC";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn ($row) => new ReconciliationSession($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createSession(array $payload, int $actorId): ReconciliationSession
    {
        $data = $this->validateSession($payload);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO reconciliation_sessions (name, start_date, end_date, status, created_by) ' .
            'VALUES (:name, :start_date, :end_date, :status, :created_by)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => $data['status'],
            'created_by' => $actorId,
        ]);

        return $this->fetchSession((int) $this->connection->pdo()->lastInsertId());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateSession(int $sessionId, array $payload): ReconciliationSession
    {
        $session = $this->fetchSession($sessionId);
        $data = $this->validateSession(array_merge($session->toArray(), $payload), false);

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE reconciliation_sessions SET name = :name, start_date = :start_date, end_date = :end_date, status = :status WHERE id = :id'
        );
        $stmt->execute([
            'id' => $sessionId,
            'name' => $data['name'],
            'start_date' => $data['start_date'],
            'end_date' => $data['end_date'],
            'status' => $data['status'],
        ]);

        return $this->fetchSession($sessionId);
    }

    public function fetchSession(int $sessionId): ReconciliationSession
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM reconciliation_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new InvalidArgumentException('Reconciliation session not found');
        }

        return new ReconciliationSession($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createBankTransaction(int $sessionId, array $payload, int $actorId): ReconciliationBankTransaction
    {
        $data = $this->validateBankTransaction($payload);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO reconciliation_bank_transactions (session_id, transaction_date, description, reference, amount, created_by) ' .
            'VALUES (:session_id, :transaction_date, :description, :reference, :amount, :created_by)'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'transaction_date' => $data['transaction_date'],
            'description' => $data['description'],
            'reference' => $data['reference'],
            'amount' => $data['amount'],
            'created_by' => $actorId,
        ]);

        return $this->fetchBankTransaction((int) $this->connection->pdo()->lastInsertId());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBankTransactions(int $sessionId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT bt.*, m.id AS match_id, m.status AS match_status, m.amount_difference, m.discrepancy_reason, m.notes, m.ledger_entry_id ' .
            'FROM reconciliation_bank_transactions bt ' .
            'LEFT JOIN reconciliation_matches m ON m.bank_transaction_id = bt.id AND m.session_id = :session_id ' .
            'WHERE bt.session_id = :session_id ' .
            'ORDER BY bt.transaction_date DESC, bt.id DESC'
        );
        $stmt->execute(['session_id' => $sessionId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listLedgerEntries(int $sessionId, array $filters = []): array
    {
        $session = $this->fetchSession($sessionId);
        $conditions = ['fe.entry_date BETWEEN :start_date AND :end_date'];
        $params = [
            'session_id' => $sessionId,
            'start_date' => $filters['start_date'] ?? $session->start_date,
            'end_date' => $filters['end_date'] ?? $session->end_date,
        ];

        if (!empty($filters['search'])) {
            $conditions[] = '(fe.reference LIKE :search OR fe.vendor LIKE :search OR fe.description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['type'])) {
            $conditions[] = 'fe.type = :type';
            $params['type'] = $filters['type'];
        }

        $where = 'WHERE ' . implode(' AND ', $conditions);

        $stmt = $this->connection->pdo()->prepare(
            'SELECT fe.*, m.id AS match_id, m.status AS match_status, m.amount_difference, m.discrepancy_reason, m.notes, m.bank_transaction_id ' .
            'FROM financial_entries fe ' .
            'LEFT JOIN reconciliation_matches m ON m.ledger_entry_id = fe.id AND m.session_id = :session_id ' .
            "{$where} " .
            'ORDER BY fe.entry_date DESC, fe.id DESC'
        );
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createMatch(int $sessionId, array $payload, int $actorId): ReconciliationMatch
    {
        $bankId = isset($payload['bank_transaction_id']) ? (int) $payload['bank_transaction_id'] : null;
        $ledgerId = isset($payload['ledger_entry_id']) ? (int) $payload['ledger_entry_id'] : null;

        if (!$bankId && !$ledgerId) {
            throw new InvalidArgumentException('Provide a bank transaction or ledger entry to match');
        }

        $bankAmount = null;
        if ($bankId) {
            $bank = $this->fetchBankTransaction($bankId);
            if ($bank->session_id !== $sessionId) {
                throw new InvalidArgumentException('Bank transaction does not belong to this session');
            }
            $bankAmount = $bank->amount;
        }

        $ledgerAmount = null;
        if ($ledgerId) {
            $ledgerAmount = $this->fetchLedgerAmount($ledgerId);
        }

        $status = $payload['status'] ?? (($bankId && $ledgerId) ? 'matched' : 'discrepancy');
        $difference = $payload['amount_difference'] ?? (($bankAmount !== null && $ledgerAmount !== null) ? $bankAmount - $ledgerAmount : 0.0);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO reconciliation_matches (session_id, bank_transaction_id, ledger_entry_id, status, amount_difference, discrepancy_reason, notes, created_by) ' .
            'VALUES (:session_id, :bank_transaction_id, :ledger_entry_id, :status, :amount_difference, :discrepancy_reason, :notes, :created_by)'
        );
        $stmt->execute([
            'session_id' => $sessionId,
            'bank_transaction_id' => $bankId,
            'ledger_entry_id' => $ledgerId,
            'status' => $status,
            'amount_difference' => $difference,
            'discrepancy_reason' => $payload['discrepancy_reason'] ?? null,
            'notes' => $payload['notes'] ?? null,
            'created_by' => $actorId,
        ]);

        return $this->fetchMatch((int) $this->connection->pdo()->lastInsertId());
    }

    public function deleteMatch(int $matchId): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM reconciliation_matches WHERE id = :id');
        $stmt->execute(['id' => $matchId]);

        return $stmt->rowCount() > 0;
    }

    /**
     * @return array<string, mixed>
     */
    public function sessionSummary(int $sessionId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                COALESCE(bt.bank_count, 0) AS bank_count,
                COALESCE(bt.bank_total, 0) AS bank_total,
                COALESCE(fe.ledger_count, 0) AS ledger_count,
                COALESCE(fe.ledger_total, 0) AS ledger_total,
                COALESCE(rm.matched_count, 0) AS matched_count,
                COALESCE(rm.discrepancy_count, 0) AS discrepancy_count
             FROM reconciliation_sessions rs
             LEFT JOIN (
                SELECT session_id, COUNT(*) AS bank_count, COALESCE(SUM(amount), 0) AS bank_total
                FROM reconciliation_bank_transactions
                GROUP BY session_id
             ) bt ON bt.session_id = rs.id
             LEFT JOIN (
                SELECT
                    session_id,
                    SUM(CASE WHEN status = "matched" THEN 1 ELSE 0 END) AS matched_count,
                    SUM(CASE WHEN status = "discrepancy" THEN 1 ELSE 0 END) AS discrepancy_count
                FROM reconciliation_matches
                GROUP BY session_id
             ) rm ON rm.session_id = rs.id
             LEFT JOIN (
                SELECT rs2.id AS session_id, COUNT(fe.id) AS ledger_count, COALESCE(SUM(fe.amount), 0) AS ledger_total
                FROM reconciliation_sessions rs2
                LEFT JOIN financial_entries fe
                    ON fe.entry_date BETWEEN rs2.start_date AND rs2.end_date
                GROUP BY rs2.id
             ) fe ON fe.session_id = rs.id
             WHERE rs.id = :session_id'
        );
        $stmt->execute(['session_id' => $sessionId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: [
            'bank_count' => 0,
            'bank_total' => 0,
            'ledger_count' => 0,
            'ledger_total' => 0,
            'matched_count' => 0,
            'discrepancy_count' => 0,
        ];
    }

    private function fetchBankTransaction(int $bankId): ReconciliationBankTransaction
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM reconciliation_bank_transactions WHERE id = :id');
        $stmt->execute(['id' => $bankId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Bank transaction not found');
        }

        return new ReconciliationBankTransaction($row);
    }

    private function fetchLedgerAmount(int $ledgerId): float
    {
        $stmt = $this->connection->pdo()->prepare('SELECT amount FROM financial_entries WHERE id = :id');
        $stmt->execute(['id' => $ledgerId]);
        $amount = $stmt->fetchColumn();
        if ($amount === false) {
            throw new InvalidArgumentException('Ledger entry not found');
        }

        return (float) $amount;
    }

    private function fetchMatch(int $matchId): ReconciliationMatch
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM reconciliation_matches WHERE id = :id');
        $stmt->execute(['id' => $matchId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new InvalidArgumentException('Reconciliation match not found');
        }

        return new ReconciliationMatch($row);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{name: string, start_date: string, end_date: string, status: string}
     */
    private function validateSession(array $payload, bool $isCreate = true): array
    {
        $name = trim((string) ($payload['name'] ?? ''));
        $start = (string) ($payload['start_date'] ?? '');
        $end = (string) ($payload['end_date'] ?? '');
        $status = (string) ($payload['status'] ?? 'open');

        if ($isCreate && $name === '') {
            throw new InvalidArgumentException('Name is required');
        }

        if ($start === '' || $end === '') {
            throw new InvalidArgumentException('Start and end dates are required');
        }

        if ($start > $end) {
            throw new InvalidArgumentException('Start date must be before end date');
        }

        if (!in_array($status, ['open', 'completed'], true)) {
            throw new InvalidArgumentException('Invalid session status');
        }

        return [
            'name' => $name !== '' ? $name : ($payload['name'] ?? ''),
            'start_date' => $start,
            'end_date' => $end,
            'status' => $status,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{transaction_date: string, description: string, reference: ?string, amount: float}
     */
    private function validateBankTransaction(array $payload): array
    {
        $transactionDate = (string) ($payload['transaction_date'] ?? '');
        $description = trim((string) ($payload['description'] ?? ''));
        $reference = $payload['reference'] ?? null;
        $amount = isset($payload['amount']) ? (float) $payload['amount'] : null;

        if ($transactionDate === '' || $description === '') {
            throw new InvalidArgumentException('Transaction date and description are required');
        }

        if ($amount === null) {
            throw new InvalidArgumentException('Amount is required');
        }

        return [
            'transaction_date' => $transactionDate,
            'description' => $description,
            'reference' => $reference ? (string) $reference : null,
            'amount' => $amount,
        ];
    }
}
