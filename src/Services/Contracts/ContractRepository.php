<?php

namespace App\Services\Contracts;

use App\Database\Connection;
use App\Models\Contract;
use PDO;
use RuntimeException;

/**
 * Contracts CRUD + search (Phase 4.1 of docs/expansion-plan.md).
 *
 * Contract numbers are minted as C-YYYYMM-NNNN (per-month counter).
 * The create() path retries on UNIQUE collision, matching TicketRepository.
 */
class ContractRepository
{
    private const COLUMNS = 'id, contract_number, company_id, division_id,
        renewed_from_contract_id, title, description, contract_type, status,
        start_date, end_date, billing_frequency, billing_amount_cents,
        auto_renew, renewal_term_months, renewal_notice_days, terms_markdown,
        signed_at, signed_by_contact_id, signer_ip, signer_user_agent,
        signature_data, cancelled_at, cancellation_reason,
        created_by_user_id, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{
     *   company_id?: int, division_id?: int, status?: string,
     *   contract_type?: string, active_on?: string, query?: string,
     *   limit?: int, offset?: int
     * } $filters
     * @return array{data: array<int, Contract>, total: int}
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        foreach (['company_id', 'division_id'] as $intCol) {
            if (!empty($filters[$intCol])) {
                $where[] = "{$intCol} = :{$intCol}";
                $params[$intCol] = (int) $filters[$intCol];
            }
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['contract_type'])) {
            $where[] = 'contract_type = :contract_type';
            $params['contract_type'] = $filters['contract_type'];
        }
        if (!empty($filters['active_on'])) {
            $where[] = 'start_date <= :active_on AND end_date >= :active_on AND status = "active"';
            $params['active_on'] = $filters['active_on'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(contract_number LIKE :q OR title LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $pdo = $this->connection->pdo();
        $count = $pdo->prepare("SELECT COUNT(*) FROM contracts WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $list = $pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM contracts WHERE {$whereSql}
             ORDER BY start_date DESC, id DESC LIMIT {$limit} OFFSET {$offset}"
        );
        $list->execute($params);
        $rows = $list->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return [
            'data' => array_map(static fn(array $r) => new Contract($r), $rows),
            'total' => $total,
        ];
    }

    public function findById(int $id): ?Contract
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contracts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Contract($row) : null;
    }

    public function findByNumber(string $number): ?Contract
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contracts WHERE contract_number = :n LIMIT 1'
        );
        $stmt->execute(['n' => $number]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Contract($row) : null;
    }

    /**
     * Direct-WO eligibility check (Phase: estimate/WO restructuring). Returns
     * the active contract, if any, that covers the given company on the given
     * service line as of $onDate. A NULL service_line_id on the contract is
     * treated as "covers any line for this company" so legacy contracts written
     * before migration 152 don't lose their B2B coverage. Order is most-
     * recently-ending first so callers get the canonical active contract when
     * multiple overlap.
     */
    public function findActiveForCompanyAndLine(
        int $companyId,
        int $serviceLineId,
        string $onDate
    ): ?Contract {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contracts
             WHERE company_id = :company_id
               AND status = "active"
               AND start_date <= :on_date
               AND end_date >= :on_date
               AND (service_line_id IS NULL OR service_line_id = :service_line_id)
             ORDER BY end_date DESC, id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'company_id' => $companyId,
            'service_line_id' => $serviceLineId,
            'on_date' => $onDate,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Contract($row) : null;
    }

    /**
     * Active contracts whose end_date is on or before $cutoffDate — input for
     * the auto-renew cron and the expiring-soon report.
     *
     * @return array<int, Contract>
     */
    public function listExpiringThrough(string $cutoffDate): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM contracts
             WHERE status = "active" AND end_date <= :cutoff
             ORDER BY end_date ASC, id ASC'
        );
        $stmt->execute(['cutoff' => $cutoffDate]);
        return array_map(
            static fn(array $r) => new Contract($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Contracts covering a given company+site on a given date. A contract with
     * no rows in contract_sites covers all sites under its company; otherwise
     * only the listed sites.
     *
     * @return array<int, Contract>
     */
    public function listActiveForSite(int $companyId, ?int $siteId, string $onDate): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM contracts c
                WHERE c.company_id = :company_id
                  AND c.status = "active"
                  AND c.start_date <= :on_date
                  AND c.end_date >= :on_date
                  AND (
                      NOT EXISTS (SELECT 1 FROM contract_sites cs WHERE cs.contract_id = c.id)
                      OR EXISTS (
                          SELECT 1 FROM contract_sites cs
                          WHERE cs.contract_id = c.id AND cs.site_id = :site_id
                      )
                  )
                ORDER BY c.end_date DESC, c.id DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'company_id' => $companyId,
            'on_date' => $onDate,
            // Bind a sentinel that will never match when site_id is null —
            // the NOT EXISTS branch still covers the "all sites" case.
            'site_id' => $siteId ?? 0,
        ]);
        return array_map(
            static fn(array $r) => new Contract($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Contract
    {
        $attempts = 0;
        while (true) {
            $attempts++;
            $number = (string) ($data['contract_number'] ?? $this->mintContractNumber());
            try {
                $stmt = $this->connection->pdo()->prepare(
                    'INSERT INTO contracts (
                        contract_number, company_id, division_id,
                        renewed_from_contract_id, title, description,
                        contract_type, status, start_date, end_date,
                        billing_frequency, billing_amount_cents, auto_renew,
                        renewal_term_months, renewal_notice_days, terms_markdown,
                        created_by_user_id
                    ) VALUES (
                        :contract_number, :company_id, :division_id,
                        :renewed_from_contract_id, :title, :description,
                        :contract_type, :status, :start_date, :end_date,
                        :billing_frequency, :billing_amount_cents, :auto_renew,
                        :renewal_term_months, :renewal_notice_days, :terms_markdown,
                        :created_by_user_id
                    )'
                );
                $stmt->execute([
                    'contract_number' => $number,
                    'company_id' => (int) $data['company_id'],
                    'division_id' => $data['division_id'] ?? null,
                    'renewed_from_contract_id' => $data['renewed_from_contract_id'] ?? null,
                    'title' => $data['title'],
                    'description' => $data['description'] ?? null,
                    'contract_type' => $data['contract_type'] ?? 'service_agreement',
                    'status' => $data['status'] ?? 'draft',
                    'start_date' => $data['start_date'],
                    'end_date' => $data['end_date'],
                    'billing_frequency' => $data['billing_frequency'] ?? 'monthly',
                    'billing_amount_cents' => (int) ($data['billing_amount_cents'] ?? 0),
                    'auto_renew' => isset($data['auto_renew']) ? (int) (bool) $data['auto_renew'] : 0,
                    'renewal_term_months' => $data['renewal_term_months'] ?? null,
                    'renewal_notice_days' => (int) ($data['renewal_notice_days'] ?? 30),
                    'terms_markdown' => $data['terms_markdown'] ?? null,
                    'created_by_user_id' => $data['created_by_user_id'] ?? null,
                ]);
                break;
            } catch (\PDOException $e) {
                if ($e->getCode() === '23000' && $attempts < 3 && empty($data['contract_number'])) {
                    continue;
                }
                throw $e;
            }
        }
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('contracts insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Contract
    {
        $writable = [
            'company_id', 'division_id', 'title', 'description',
            'contract_type', 'status', 'start_date', 'end_date',
            'billing_frequency', 'billing_amount_cents', 'auto_renew',
            'renewal_term_months', 'renewal_notice_days', 'terms_markdown',
            'signed_at', 'signed_by_contact_id', 'signer_ip',
            'signer_user_agent', 'signature_data',
            'cancelled_at', 'cancellation_reason',
            'renewed_from_contract_id',
        ];
        $boolCols = ['auto_renew'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $value = $data[$col];
                if (in_array($col, $boolCols, true) && $value !== null) {
                    $value = (int) (bool) $value;
                }
                $params[$col] = $value;
            }
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("contract {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE contracts SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("contract {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM contracts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Format: C-YYYYMM-NNNN (monthly sequence).
     */
    private function mintContractNumber(): string
    {
        $prefix = 'C-' . date('Ym') . '-';
        $stmt = $this->connection->pdo()->prepare(
            'SELECT contract_number FROM contracts
             WHERE contract_number LIKE :p
             ORDER BY contract_number DESC LIMIT 1'
        );
        $stmt->execute(['p' => $prefix . '%']);
        $last = $stmt->fetchColumn();
        $next = 1;
        if (is_string($last) && preg_match('/-(\d+)$/', $last, $m)) {
            $next = (int) $m[1] + 1;
        }
        return $prefix . str_pad((string) $next, 4, '0', STR_PAD_LEFT);
    }
}
