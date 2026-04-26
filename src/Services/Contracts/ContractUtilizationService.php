<?php

namespace App\Services\Contracts;

use App\Models\Contract;
use App\Models\ContractEntitlement;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Phase 4.3 of docs/expansion-plan.md: utilization reporting.
 *
 * Two views:
 *   * utilizationForContract() — per-entitlement quota vs used, plus overall
 *                                health flag (ok / warning / exceeded).
 *   * companyRollup()          — aggregate utilization across all active
 *                                contracts under a company.
 *
 * "Warning" threshold is 80% used; "exceeded" is >100%.
 */
class ContractUtilizationService
{
    private const WARNING_THRESHOLD = 0.80;

    public function __construct(
        private readonly ContractRepository $contracts,
        private readonly ContractEntitlementRepository $entitlements,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function utilizationForContract(User $user, int $contractId): array
    {
        $this->gate->assert($user, 'contracts.view');
        $contract = $this->contracts->findById($contractId);
        if ($contract === null) {
            throw new InvalidArgumentException("Contract {$contractId} not found");
        }
        return $this->buildReport($contract);
    }

    /**
     * @return array<string, mixed>
     */
    public function companyRollup(User $user, int $companyId, ?string $onDate = null): array
    {
        $this->gate->assert($user, 'contracts.view');
        $onDate = $onDate ?? date('Y-m-d');
        $result = $this->contracts->search([
            'company_id' => $companyId,
            'status' => 'active',
            'active_on' => $onDate,
            'limit' => 1000,
        ]);

        $contractReports = [];
        $aggregate = [
            'total_contracts' => 0,
            'entitlements_exceeded' => 0,
            'entitlements_warning' => 0,
            'entitlements_ok' => 0,
        ];

        foreach ($result['data'] as $contract) {
            $report = $this->buildReport($contract);
            $contractReports[] = $report;
            $aggregate['total_contracts']++;
            foreach ($report['entitlements'] as $ent) {
                $aggregate['entitlements_' . $ent['status']]++;
            }
        }

        return [
            'data' => [
                'company_id' => $companyId,
                'on_date' => $onDate,
                'aggregate' => $aggregate,
                'contracts' => $contractReports,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildReport(Contract $contract): array
    {
        $entitlements = $this->entitlements->listForContract($contract->id, false);
        $entReports = [];
        $overallStatus = 'ok';
        foreach ($entitlements as $ent) {
            $row = $this->entitlementRow($ent);
            $entReports[] = $row;
            if ($row['status'] === 'exceeded') {
                $overallStatus = 'exceeded';
            } elseif ($row['status'] === 'warning' && $overallStatus !== 'exceeded') {
                $overallStatus = 'warning';
            }
        }
        return [
            'contract_id' => $contract->id,
            'contract_number' => $contract->contract_number,
            'title' => $contract->title,
            'status' => $contract->status,
            'start_date' => $contract->start_date,
            'end_date' => $contract->end_date,
            'days_remaining' => max(
                0,
                (int) round((strtotime($contract->end_date) - time()) / 86400)
            ),
            'overall_status' => $overallStatus,
            'entitlements' => $entReports,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function entitlementRow(ContractEntitlement $ent): array
    {
        $allowed = $ent->quantity_allowed === null ? null : (float) $ent->quantity_allowed;
        $used = (float) $ent->quantity_used;
        $percent = null;
        $status = 'ok';
        if ($allowed !== null && $allowed > 0) {
            $percent = round($used / $allowed, 4);
            if ($used > $allowed) {
                $status = 'exceeded';
            } elseif ($percent >= self::WARNING_THRESHOLD) {
                $status = 'warning';
            }
        }
        return [
            'id' => $ent->id,
            'entitlement_kind' => $ent->entitlement_kind,
            'description' => $ent->description,
            'period' => $ent->period,
            'quantity_allowed' => $allowed,
            'quantity_used' => $used,
            'quantity_remaining' => $allowed === null ? null : max(0, $allowed - $used),
            'percent_used' => $percent,
            'is_active' => (bool) $ent->is_active,
            'status' => $status,
        ];
    }
}
