<?php

namespace App\Services\ConsolidatedBilling;

use App\Database\Connection;
use App\Models\ConsolidatedStatement;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `consolidated_statements` and the
 * `consolidated_statement_invoices` join — Phase 17 / M11 of
 * docs/woms-expansion-plan.md.
 *
 * The roll-up totals are denormalized onto the header row at generation time
 * (subtotal/tax/total/amount_paid/balance_due/invoice_count) so the customer
 * portal doesn't have to recompute on every render. The recomputeTotals()
 * helper rebuilds them from the join when child invoices change.
 */
class ConsolidatedStatementRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?: int, status?: string, period_start?: string,
     *              period_end?: string} $filters
     * @return array<int, ConsolidatedStatement>
     */
    public function list(array $filters = [], int $limit = 100, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM consolidated_statements ' . $where
            . ' ORDER BY period_end DESC, id DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();
        return array_map(
            static fn (array $r) => ConsolidatedStatement::fromRow($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $stmt = $this->connection->pdo()->prepare('SELECT COUNT(*) FROM consolidated_statements ' . $where);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?ConsolidatedStatement
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM consolidated_statements WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ConsolidatedStatement::fromRow($row) : null;
    }

    public function findByPeriod(int $customerId, string $periodStart, string $periodEnd): ?ConsolidatedStatement
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM consolidated_statements
              WHERE customer_id = :c AND period_start = :ps AND period_end = :pe LIMIT 1'
        );
        $stmt->execute(['c' => $customerId, 'ps' => $periodStart, 'pe' => $periodEnd]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? ConsolidatedStatement::fromRow($row) : null;
    }

    /**
     * Create the header row. Caller is responsible for attaching invoices via
     * attachInvoices() and then calling recomputeTotals().
     */
    public function create(int $customerId, string $periodStart, string $periodEnd, ?int $generatedByUserId, ?string $notes = null): ConsolidatedStatement
    {
        $existing = $this->findByPeriod($customerId, $periodStart, $periodEnd);
        if ($existing !== null) {
            throw new RuntimeException(
                "Customer {$customerId} already has a statement for {$periodStart}..{$periodEnd}"
            );
        }

        $number = $this->mintNumber($customerId, $periodStart);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO consolidated_statements
                (number, customer_id, period_start, period_end, status, notes,
                 generated_by_user_id)
             VALUES
                (:number, :customer_id, :period_start, :period_end, :status, :notes,
                 :generated_by_user_id)'
        );
        $stmt->execute([
            'number' => $number,
            'customer_id' => $customerId,
            'period_start' => $periodStart,
            'period_end' => $periodEnd,
            'status' => ConsolidatedStatement::STATUS_DRAFT,
            'notes' => $notes !== null && trim($notes) !== '' ? trim($notes) : null,
            'generated_by_user_id' => $generatedByUserId,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created consolidated_statement');
        }
        return $row;
    }

    /**
     * Attach invoices to a statement, set invoices.consolidated_statement_id,
     * and recompute totals. Skips invoices already on a different statement.
     *
     * @param array<int, int> $invoiceIds
     * @return int Number of invoices actually attached.
     */
    public function attachInvoices(int $statementId, array $invoiceIds): int
    {
        if ($invoiceIds === []) {
            return 0;
        }
        $invoiceIds = array_values(array_unique(array_map('intval', $invoiceIds)));
        $statement = $this->findById($statementId);
        if ($statement === null) {
            throw new InvalidArgumentException("statement {$statementId} not found");
        }

        $placeholders = implode(',', array_fill(0, count($invoiceIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT id, total, amount_paid, customer_id, consolidated_statement_id
               FROM invoices
              WHERE id IN ({$placeholders})"
        );
        $stmt->execute($invoiceIds);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $attached = 0;
        foreach ($rows as $row) {
            if ((int) $row['customer_id'] !== $statement->customer_id) {
                continue;
            }
            if ($row['consolidated_statement_id'] !== null
                && (int) $row['consolidated_statement_id'] !== $statementId) {
                continue;
            }
            $invoiceId = (int) $row['id'];
            $invoiceTotal = (float) $row['total'];

            $up = $this->connection->pdo()->prepare(
                'INSERT IGNORE INTO consolidated_statement_invoices
                    (statement_id, invoice_id, invoice_total)
                 VALUES (:sid, :iid, :total)'
            );
            $up->execute(['sid' => $statementId, 'iid' => $invoiceId, 'total' => $invoiceTotal]);
            if ($up->rowCount() === 0) {
                continue;
            }
            $upInv = $this->connection->pdo()->prepare(
                'UPDATE invoices SET consolidated_statement_id = :sid WHERE id = :id'
            );
            $upInv->execute(['sid' => $statementId, 'id' => $invoiceId]);
            $attached++;
        }
        return $attached;
    }

    /**
     * Detach a single invoice (admin override).
     */
    public function detachInvoice(int $statementId, int $invoiceId): void
    {
        $del = $this->connection->pdo()->prepare(
            'DELETE FROM consolidated_statement_invoices
              WHERE statement_id = :sid AND invoice_id = :iid'
        );
        $del->execute(['sid' => $statementId, 'iid' => $invoiceId]);
        if ($del->rowCount() > 0) {
            $clr = $this->connection->pdo()->prepare(
                'UPDATE invoices SET consolidated_statement_id = NULL
                  WHERE id = :id AND consolidated_statement_id = :sid'
            );
            $clr->execute(['id' => $invoiceId, 'sid' => $statementId]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listInvoiceRows(int $statementId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT i.id, i.number, i.status, i.issue_date, i.due_date, i.total,
                    i.amount_paid, i.balance_due, i.workorder_id, i.vehicle_id,
                    i.site_asset_id, i.unit_id, i.service_line_id
               FROM consolidated_statement_invoices csi
               JOIN invoices i ON i.id = csi.invoice_id
              WHERE csi.statement_id = :sid
              ORDER BY i.issue_date ASC, i.id ASC'
        );
        $stmt->execute(['sid' => $statementId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Rebuild header totals from the join. Sets status to 'paid' / 'partial'
     * / current draft state based on balance.
     */
    public function recomputeTotals(int $statementId): ConsolidatedStatement
    {
        $statement = $this->findById($statementId);
        if ($statement === null) {
            throw new InvalidArgumentException("statement {$statementId} not found");
        }
        $aggStmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(i.id) AS cnt,
                    COALESCE(SUM(i.subtotal),0) AS subtotal,
                    COALESCE(SUM(i.tax),0) AS tax,
                    COALESCE(SUM(i.total),0) AS total,
                    COALESCE(SUM(i.amount_paid),0) AS amount_paid,
                    COALESCE(SUM(i.balance_due),0) AS balance_due
               FROM consolidated_statement_invoices csi
               JOIN invoices i ON i.id = csi.invoice_id
              WHERE csi.statement_id = :sid'
        );
        $aggStmt->execute(['sid' => $statementId]);
        $agg = $aggStmt->fetch(PDO::FETCH_ASSOC) ?: [
            'cnt' => 0, 'subtotal' => 0, 'tax' => 0, 'total' => 0,
            'amount_paid' => 0, 'balance_due' => 0,
        ];

        $newStatus = $statement->status;
        if ($statement->status !== ConsolidatedStatement::STATUS_CANCELLED) {
            if ((float) $agg['total'] > 0 && (float) $agg['balance_due'] <= 0.0001) {
                $newStatus = ConsolidatedStatement::STATUS_PAID;
            } elseif ((float) $agg['amount_paid'] > 0
                && (float) $agg['amount_paid'] < (float) $agg['total']) {
                $newStatus = ConsolidatedStatement::STATUS_PARTIAL;
            } elseif ($statement->status === ConsolidatedStatement::STATUS_PAID
                && (float) $agg['balance_due'] > 0.0001) {
                $newStatus = ConsolidatedStatement::STATUS_SENT;
            }
        }

        $up = $this->connection->pdo()->prepare(
            'UPDATE consolidated_statements
                SET subtotal = :s, tax = :t, total = :tot,
                    amount_paid = :paid, balance_due = :bal,
                    invoice_count = :cnt, status = :status,
                    paid_at = CASE WHEN :status = "paid" AND paid_at IS NULL THEN NOW() ELSE paid_at END
              WHERE id = :id'
        );
        $up->execute([
            's' => (float) $agg['subtotal'],
            't' => (float) $agg['tax'],
            'tot' => (float) $agg['total'],
            'paid' => (float) $agg['amount_paid'],
            'bal' => (float) $agg['balance_due'],
            'cnt' => (int) $agg['cnt'],
            'status' => $newStatus,
            'id' => $statementId,
        ]);

        $row = $this->findById($statementId);
        if ($row === null) {
            throw new RuntimeException("statement {$statementId} not found after recomputeTotals");
        }
        return $row;
    }

    public function markSent(int $statementId): ConsolidatedStatement
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE consolidated_statements
                SET status = "sent", sent_at = COALESCE(sent_at, NOW())
              WHERE id = :id AND status = "draft"'
        );
        $stmt->execute(['id' => $statementId]);
        $row = $this->findById($statementId);
        if ($row === null) {
            throw new RuntimeException("statement {$statementId} not found");
        }
        return $row;
    }

    public function cancel(int $statementId): ConsolidatedStatement
    {
        $del = $this->connection->pdo()->prepare(
            'UPDATE invoices SET consolidated_statement_id = NULL
              WHERE consolidated_statement_id = :id'
        );
        $del->execute(['id' => $statementId]);
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE consolidated_statements
                SET status = "cancelled", cancelled_at = NOW()
              WHERE id = :id'
        );
        $stmt->execute(['id' => $statementId]);
        $row = $this->findById($statementId);
        if ($row === null) {
            throw new RuntimeException("statement {$statementId} not found");
        }
        return $row;
    }

    /**
     * Customers with monthly_consolidated_billing = 1 who have at least one
     * eligible invoice in [periodStart, periodEnd] not yet on any statement.
     *
     * @return array<int, int>
     */
    public function eligibleCustomerIds(string $periodStart, string $periodEnd): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT DISTINCT c.id
               FROM customers c
               JOIN invoices i ON i.customer_id = c.id
              WHERE c.monthly_consolidated_billing = 1
                AND i.issue_date BETWEEN :ps AND :pe
                AND i.consolidated_statement_id IS NULL
                AND i.status NOT IN ('cancelled', 'voided', 'refunded')"
        );
        $stmt->execute(['ps' => $periodStart, 'pe' => $periodEnd]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @return array<int, int>
     */
    public function unconsolidatedInvoiceIdsForCustomer(int $customerId, string $periodStart, string $periodEnd): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT id FROM invoices
              WHERE customer_id = :c
                AND issue_date BETWEEN :ps AND :pe
                AND consolidated_statement_id IS NULL
                AND status NOT IN ('cancelled', 'voided', 'refunded')
              ORDER BY id ASC"
        );
        $stmt->execute(['c' => $customerId, 'ps' => $periodStart, 'pe' => $periodEnd]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];
        if (!empty($filters['customer_id'])) {
            $where .= ' AND customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['period_start'])) {
            $where .= ' AND period_start >= :period_start';
            $params['period_start'] = (string) $filters['period_start'];
        }
        if (!empty($filters['period_end'])) {
            $where .= ' AND period_end <= :period_end';
            $params['period_end'] = (string) $filters['period_end'];
        }
        return [$where, $params];
    }

    private function mintNumber(int $customerId, string $periodStart): string
    {
        $ym = date('Ym', strtotime($periodStart));
        return sprintf('CS-%s-%06d-%04d', $ym, $customerId, random_int(0, 9999));
    }
}
