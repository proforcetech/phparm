<?php

namespace App\Services\ChainRollup;

use App\Database\Connection;
use InvalidArgumentException;
use PDO;

/**
 * Multi-site chain rollup — Phase 17 / S4 of docs/woms-expansion-plan.md.
 *
 * Read-only aggregation surface: pick a company (the chain), get one
 * dashboard payload that combines a company-wide rollup with a per-site
 * breakdown. The metrics chosen here are the ones a chain operations director
 * actually compares across sites:
 *
 *   - tickets:    open count, breached-SLA count, avg first-response minutes
 *   - workorders: open + completed-in-window counts
 *   - invoices:   spend in window, outstanding balance
 *   - assets:     active site asset count
 *   - contracts:  active count + monthly billing value
 *
 * No migration needed — this layer is pure read aggregation against existing
 * tables (companies, sites, tickets, workorders, invoices, site_assets,
 * contracts). All site-scoped rows are joined back through the site_assets
 * pointer where the underlying table doesn't carry site_id directly (e.g.
 * workorders/invoices link via site_asset_id).
 */
class ChainRollupService
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, array{id: int, name: string, status: string, site_count: int}>
     */
    public function listChains(?string $search = null, int $limit = 100): array
    {
        $where = "WHERE c.status = 'active'";
        $params = [];
        if ($search !== null && trim($search) !== '') {
            $where .= ' AND c.name LIKE :q';
            $params['q'] = '%' . trim($search) . '%';
        }
        $sql = "SELECT c.id, c.name, c.status,
                       (SELECT COUNT(*) FROM sites s WHERE s.company_id = c.id) AS site_count
                  FROM companies c
                  {$where}
              ORDER BY c.name ASC
                 LIMIT :limit";
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue(':' . $k, $v, PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn (array $r) => [
            'id' => (int) $r['id'],
            'name' => (string) $r['name'],
            'status' => (string) $r['status'],
            'site_count' => (int) $r['site_count'],
        ], $rows);
    }

    /**
     * Build the rollup payload for one chain.
     *
     * @return array{
     *   company: array<string, mixed>,
     *   period: array{from: string, to: string},
     *   chain_totals: array<string, int|float>,
     *   sites: array<int, array<string, mixed>>
     * }
     */
    public function rollup(int $companyId, string $periodStart, string $periodEnd): array
    {
        $this->assertValidPeriod($periodStart, $periodEnd);

        $company = $this->fetchCompany($companyId);
        if ($company === null) {
            throw new InvalidArgumentException("company {$companyId} not found");
        }

        $sites = $this->fetchSites($companyId);
        if ($sites === []) {
            return [
                'company' => $company,
                'period' => ['from' => $periodStart, 'to' => $periodEnd],
                'chain_totals' => $this->emptyTotals(),
                'sites' => [],
            ];
        }

        $siteIds = array_map(static fn (array $s) => (int) $s['id'], $sites);
        $tickets = $this->ticketMetricsBySite($siteIds, $periodStart, $periodEnd);
        $workorders = $this->workorderMetricsBySite($siteIds, $periodStart, $periodEnd);
        $invoices = $this->invoiceMetricsBySite($siteIds, $periodStart, $periodEnd);
        $assets = $this->assetCountsBySite($siteIds);
        $contracts = $this->contractMetricsBySite($companyId, $siteIds);

        $rows = [];
        $totals = $this->emptyTotals();
        foreach ($sites as $site) {
            $sid = (int) $site['id'];
            $row = array_merge($site, [
                'open_tickets' => $tickets[$sid]['open_tickets'] ?? 0,
                'breached_sla_tickets' => $tickets[$sid]['breached_sla_tickets'] ?? 0,
                'avg_first_response_minutes' => $tickets[$sid]['avg_first_response_minutes'] ?? null,
                'open_workorders' => $workorders[$sid]['open_workorders'] ?? 0,
                'completed_workorders_in_window' => $workorders[$sid]['completed_workorders_in_window'] ?? 0,
                'spend_in_window' => $invoices[$sid]['spend_in_window'] ?? 0.0,
                'outstanding_balance' => $invoices[$sid]['outstanding_balance'] ?? 0.0,
                'active_assets' => $assets[$sid] ?? 0,
                'active_contracts' => $contracts[$sid]['active_contracts'] ?? 0,
                'monthly_contract_value' => $contracts[$sid]['monthly_contract_value'] ?? 0.0,
            ]);
            $rows[] = $row;

            $totals['open_tickets'] += (int) $row['open_tickets'];
            $totals['breached_sla_tickets'] += (int) $row['breached_sla_tickets'];
            $totals['open_workorders'] += (int) $row['open_workorders'];
            $totals['completed_workorders_in_window'] += (int) $row['completed_workorders_in_window'];
            $totals['spend_in_window'] += (float) $row['spend_in_window'];
            $totals['outstanding_balance'] += (float) $row['outstanding_balance'];
            $totals['active_assets'] += (int) $row['active_assets'];
            $totals['active_contracts'] += (int) $row['active_contracts'];
            $totals['monthly_contract_value'] += (float) $row['monthly_contract_value'];
        }

        return [
            'company' => $company,
            'period' => ['from' => $periodStart, 'to' => $periodEnd],
            'chain_totals' => $totals,
            'sites' => $rows,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function emptyTotals(): array
    {
        return [
            'open_tickets' => 0,
            'breached_sla_tickets' => 0,
            'open_workorders' => 0,
            'completed_workorders_in_window' => 0,
            'spend_in_window' => 0.0,
            'outstanding_balance' => 0.0,
            'active_assets' => 0,
            'active_contracts' => 0,
            'monthly_contract_value' => 0.0,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchCompany(int $companyId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, legal_name, company_type, status, primary_phone, primary_email, website
               FROM companies WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchSites(int $companyId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT id, name, code, status, city, state, country
               FROM sites
              WHERE company_id = :c AND status = 'active'
           ORDER BY name ASC"
        );
        $stmt->execute(['c' => $companyId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<int, int> $siteIds
     * @return array<int, array{open_tickets: int, breached_sla_tickets: int, avg_first_response_minutes: ?float}>
     */
    private function ticketMetricsBySite(array $siteIds, string $periodStart, string $periodEnd): array
    {
        if ($siteIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));

        $openSql = "SELECT site_id, COUNT(*) AS cnt
                      FROM tickets
                     WHERE site_id IN ({$placeholders})
                       AND status NOT IN ('resolved','closed','cancelled')
                  GROUP BY site_id";
        $openStmt = $this->connection->pdo()->prepare($openSql);
        $openStmt->execute($siteIds);
        $open = [];
        foreach ($openStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $open[(int) $r['site_id']] = (int) $r['cnt'];
        }

        $breachedSql = "SELECT t.site_id, COUNT(DISTINCT t.id) AS cnt
                          FROM tickets t
                          JOIN ticket_sla_clocks c ON c.ticket_id = t.id
                         WHERE t.site_id IN ({$placeholders})
                           AND c.breached_at IS NOT NULL
                           AND c.breached_at BETWEEN ? AND ?
                      GROUP BY t.site_id";
        $breachedParams = array_merge($siteIds, [$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59']);
        $breachedStmt = $this->connection->pdo()->prepare($breachedSql);
        $breachedStmt->execute($breachedParams);
        $breached = [];
        foreach ($breachedStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $breached[(int) $r['site_id']] = (int) $r['cnt'];
        }

        $frrSql = "SELECT site_id,
                          AVG(TIMESTAMPDIFF(MINUTE, created_at, first_response_at)) AS avg_minutes
                     FROM tickets
                    WHERE site_id IN ({$placeholders})
                      AND first_response_at IS NOT NULL
                      AND created_at BETWEEN ? AND ?
                 GROUP BY site_id";
        $frrParams = array_merge($siteIds, [$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59']);
        $frrStmt = $this->connection->pdo()->prepare($frrSql);
        $frrStmt->execute($frrParams);
        $frr = [];
        foreach ($frrStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $frr[(int) $r['site_id']] = $r['avg_minutes'] !== null ? (float) $r['avg_minutes'] : null;
        }

        $out = [];
        foreach ($siteIds as $sid) {
            $out[$sid] = [
                'open_tickets' => $open[$sid] ?? 0,
                'breached_sla_tickets' => $breached[$sid] ?? 0,
                'avg_first_response_minutes' => $frr[$sid] ?? null,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, int> $siteIds
     * @return array<int, array{open_workorders: int, completed_workorders_in_window: int}>
     */
    private function workorderMetricsBySite(array $siteIds, string $periodStart, string $periodEnd): array
    {
        if ($siteIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));

        $openSql = "SELECT sa.site_id, COUNT(DISTINCT w.id) AS cnt
                      FROM workorders w
                      JOIN site_assets sa ON sa.id = w.site_asset_id
                     WHERE sa.site_id IN ({$placeholders})
                       AND w.status NOT IN ('completed','closed','cancelled')
                  GROUP BY sa.site_id";
        $openStmt = $this->connection->pdo()->prepare($openSql);
        $openStmt->execute($siteIds);
        $open = [];
        foreach ($openStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $open[(int) $r['site_id']] = (int) $r['cnt'];
        }

        $doneSql = "SELECT sa.site_id, COUNT(DISTINCT w.id) AS cnt
                      FROM workorders w
                      JOIN site_assets sa ON sa.id = w.site_asset_id
                     WHERE sa.site_id IN ({$placeholders})
                       AND w.status IN ('completed','closed')
                       AND w.updated_at BETWEEN ? AND ?
                  GROUP BY sa.site_id";
        $doneParams = array_merge($siteIds, [$periodStart . ' 00:00:00', $periodEnd . ' 23:59:59']);
        $doneStmt = $this->connection->pdo()->prepare($doneSql);
        $doneStmt->execute($doneParams);
        $done = [];
        foreach ($doneStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $done[(int) $r['site_id']] = (int) $r['cnt'];
        }

        $out = [];
        foreach ($siteIds as $sid) {
            $out[$sid] = [
                'open_workorders' => $open[$sid] ?? 0,
                'completed_workorders_in_window' => $done[$sid] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, int> $siteIds
     * @return array<int, array{spend_in_window: float, outstanding_balance: float}>
     */
    private function invoiceMetricsBySite(array $siteIds, string $periodStart, string $periodEnd): array
    {
        if ($siteIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));

        $spendSql = "SELECT sa.site_id, COALESCE(SUM(i.total),0) AS spend
                       FROM invoices i
                       JOIN site_assets sa ON sa.id = i.site_asset_id
                      WHERE sa.site_id IN ({$placeholders})
                        AND i.issue_date BETWEEN ? AND ?
                        AND i.status NOT IN ('cancelled','voided','refunded')
                   GROUP BY sa.site_id";
        $spendParams = array_merge($siteIds, [$periodStart, $periodEnd]);
        $spendStmt = $this->connection->pdo()->prepare($spendSql);
        $spendStmt->execute($spendParams);
        $spend = [];
        foreach ($spendStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $spend[(int) $r['site_id']] = (float) $r['spend'];
        }

        $balSql = "SELECT sa.site_id, COALESCE(SUM(i.balance_due),0) AS bal
                     FROM invoices i
                     JOIN site_assets sa ON sa.id = i.site_asset_id
                    WHERE sa.site_id IN ({$placeholders})
                      AND i.status NOT IN ('cancelled','voided','refunded','paid')
                      AND i.balance_due > 0
                 GROUP BY sa.site_id";
        $balStmt = $this->connection->pdo()->prepare($balSql);
        $balStmt->execute($siteIds);
        $bal = [];
        foreach ($balStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $bal[(int) $r['site_id']] = (float) $r['bal'];
        }

        $out = [];
        foreach ($siteIds as $sid) {
            $out[$sid] = [
                'spend_in_window' => $spend[$sid] ?? 0.0,
                'outstanding_balance' => $bal[$sid] ?? 0.0,
            ];
        }
        return $out;
    }

    /**
     * @param array<int, int> $siteIds
     * @return array<int, int>
     */
    private function assetCountsBySite(array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
        $sql = "SELECT site_id, COUNT(*) AS cnt
                  FROM site_assets
                 WHERE site_id IN ({$placeholders})
                   AND (status IS NULL OR status NOT IN ('retired','decommissioned','disposed'))
              GROUP BY site_id";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($siteIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['site_id']] = (int) $r['cnt'];
        }
        return $out;
    }

    /**
     * Active contracts joined to sites via contract_sites; monthly value is
     * normalized from contracts.billing_amount_cents using billing_frequency.
     *
     * @param array<int, int> $siteIds
     * @return array<int, array{active_contracts: int, monthly_contract_value: float}>
     */
    private function contractMetricsBySite(int $companyId, array $siteIds): array
    {
        if ($siteIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($siteIds), '?'));
        $sql = "SELECT cs.site_id,
                       COUNT(DISTINCT c.id) AS cnt,
                       COALESCE(SUM(
                           CASE c.billing_frequency
                               WHEN 'monthly'    THEN c.billing_amount_cents / 100.0
                               WHEN 'quarterly'  THEN c.billing_amount_cents / 100.0 / 3
                               WHEN 'semi_annual' THEN c.billing_amount_cents / 100.0 / 6
                               WHEN 'annual'     THEN c.billing_amount_cents / 100.0 / 12
                               WHEN 'one_time'   THEN 0
                               ELSE c.billing_amount_cents / 100.0
                           END
                       ), 0) AS monthly_value
                  FROM contracts c
                  JOIN contract_sites cs ON cs.contract_id = c.id
                 WHERE c.company_id = ?
                   AND c.status = 'active'
                   AND cs.site_id IN ({$placeholders})
              GROUP BY cs.site_id";
        $params = array_merge([$companyId], $siteIds);
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
            $out[(int) $r['site_id']] = [
                'active_contracts' => (int) $r['cnt'],
                'monthly_contract_value' => (float) $r['monthly_value'],
            ];
        }
        return $out;
    }

    private function assertValidPeriod(string $periodStart, string $periodEnd): void
    {
        $ts1 = strtotime($periodStart);
        $ts2 = strtotime($periodEnd);
        if (!$ts1 || !$ts2) {
            throw new InvalidArgumentException('period start and end must be valid dates');
        }
        if ($ts1 > $ts2) {
            throw new InvalidArgumentException('period start must be <= end');
        }
    }
}
