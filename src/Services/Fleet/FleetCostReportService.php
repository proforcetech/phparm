<?php

namespace App\Services\Fleet;

use App\Models\FleetUnitReading;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;

/**
 * Phase 7.4 of docs/expansion-plan.md — cost-per-mile / cost-per-hour.
 *
 * Read-only reporting surface. Joins completed-workorder cost (from
 * FleetCostReportRepository) against meter-delta readings over a date
 * range to produce per-unit utilization economics.
 *
 * Gates: fleet.view (read-only — no write paths here). Managers,
 * dispatchers, and technicians with fleet.view all land here; the
 * report has no per-row permission refinements because a fleet unit a
 * user can see at all is one whose cost they're cleared to see.
 *
 * Date range conventions:
 *   * from / to are YYYY-MM-DD strings — report spans full calendar days
 *     from 00:00:00 on `from` through 23:59:59 on `to` (inclusive)
 *   * from must be <= to (throws InvalidArgumentException otherwise)
 *   * Max window: 5 years — longer ranges risk exploding the
 *     workorder_items subquery and are better served as multiple calls
 *
 * Zero-miles / zero-hours handling: a unit with reading activity but
 * zero delta (one or fewer readings in the window) returns miles=0,
 * cost_per_mile=null — the caller renders "—" rather than "∞". A unit
 * with workorder cost but no readings likewise gets a null
 * cost_per_unit (distance is unknown, not zero). Retired units still
 * appear if they had activity in the window — historical spend on
 * retired fleet stays visible so "did we waste money keeping T-17
 * alive" is answerable after the fact.
 */
class FleetCostReportService
{
    public const MAX_WINDOW_DAYS = 5 * 365 + 1;

    public function __construct(
        private readonly FleetCostReportRepository $reports,
        private readonly AccessGate $gate,
        private readonly ?FleetExternalRepairRepository $externalRepairs = null,
    ) {
    }

    /**
     * @return array{
     *   unit: string,
     *   from: string,
     *   to: string,
     *   rows: array<int, array<string, mixed>>,
     *   totals: array<string, mixed>
     * }
     */
    public function costPerMile(
        User $actor,
        int $companyId,
        string $from,
        string $to,
        bool $includeExternal = false,
    ): array {
        return $this->buildReport(
            $actor,
            $companyId,
            $from,
            $to,
            FleetUnitReading::TYPE_ODOMETER,
            'miles',
            $includeExternal,
        );
    }

    /**
     * @return array{
     *   unit: string,
     *   from: string,
     *   to: string,
     *   rows: array<int, array<string, mixed>>,
     *   totals: array<string, mixed>
     * }
     */
    public function costPerHour(
        User $actor,
        int $companyId,
        string $from,
        string $to,
        bool $includeExternal = false,
    ): array {
        return $this->buildReport(
            $actor,
            $companyId,
            $from,
            $to,
            FleetUnitReading::TYPE_ENGINE_HOURS,
            'hours',
            $includeExternal,
        );
    }

    // ── Internals ──────────────────────────────────────────────────────

    /**
     * @return array{unit: string, from: string, to: string, rows: array<int, array<string, mixed>>, totals: array<string, mixed>}
     */
    private function buildReport(
        User $actor,
        int $companyId,
        string $from,
        string $to,
        string $readingType,
        string $unitLabel,
        bool $includeExternal = false,
    ): array {
        $this->gate->assert($actor, 'fleet.view');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company_id is required');
        }
        [$fromTs, $toTs] = $this->normalizeRange($from, $to);

        $costRows = $this->reports->aggregateCostsForCompany($companyId, $fromTs, $toTs);
        $readingRows = $this->reports->readingDeltasForCompany($companyId, $readingType, $fromTs, $toTs);

        // External repair costs key on service_date (DATE), not the
        // timestamp bounds used by workorders/readings — so pass the
        // date-only from/to that the caller supplied.
        $externalByUnit = [];
        if ($includeExternal && $this->externalRepairs !== null) {
            $fromDate = substr($fromTs, 0, 10);
            $toDate = substr($toTs, 0, 10);
            foreach ($this->externalRepairs->aggregateCostsForCompany($companyId, $fromDate, $toDate) as $r) {
                $externalByUnit[$r['fleet_unit_id']] = $r;
            }
        }

        $costByUnit = [];
        foreach ($costRows as $r) {
            $costByUnit[$r['fleet_unit_id']] = $r;
        }
        $readingByUnit = [];
        foreach ($readingRows as $r) {
            $readingByUnit[$r['fleet_unit_id']] = $r;
        }
        $unitIds = array_unique(array_merge(
            array_keys($costByUnit),
            array_keys($readingByUnit),
            array_keys($externalByUnit),
        ));
        $identities = $this->reports->fetchUnitIdentities($companyId, $unitIds);

        $rows = [];
        $totalCost = 0.0;
        $totalLabor = 0.0;
        $totalParts = 0.0;
        $totalUsage = 0.0;
        $totalWorkorders = 0;

        foreach ($unitIds as $uid) {
            if (!isset($identities[$uid])) {
                // Unit sits outside the company — defensive skip. The
                // repository already filters by company_id so this only
                // trips if the FK is out of sync with company scoping.
                continue;
            }
            $ident = $identities[$uid];
            $cost = $costByUnit[$uid] ?? null;
            $reading = $readingByUnit[$uid] ?? null;
            $external = $externalByUnit[$uid] ?? null;

            $internalCost = $cost !== null ? (float) $cost['total_cost'] : 0.0;
            $internalLabor = $cost !== null ? (float) $cost['labor_cost'] : 0.0;
            $internalParts = $cost !== null ? (float) $cost['parts_cost'] : 0.0;
            $externalCost = $external !== null ? (float) $external['total_cost'] : 0.0;
            $externalLabor = $external !== null ? (float) $external['labor_cost'] : 0.0;
            $externalParts = $external !== null ? (float) $external['parts_cost'] : 0.0;
            $externalOther = $external !== null ? (float) $external['other_cost'] : 0.0;
            $externalCount = $external !== null ? (int) $external['repair_count'] : 0;

            $totalCostForUnit = $internalCost + $externalCost;
            $laborForUnit = $internalLabor + $externalLabor;
            $partsForUnit = $internalParts + $externalParts;
            $woCount = $cost !== null ? (int) $cost['workorder_count'] : 0;

            $usage = 0.0;
            $firstReading = null;
            $lastReading = null;
            if ($reading !== null) {
                $delta = (float) $reading['last_value'] - (float) $reading['first_value'];
                $usage = $delta > 0 ? $delta : 0.0;
                $firstReading = [
                    'value' => (float) $reading['first_value'],
                    'at' => $reading['first_at'],
                ];
                $lastReading = [
                    'value' => (float) $reading['last_value'],
                    'at' => $reading['last_at'],
                ];
            }

            $costPerUnit = $usage > 0 ? round($totalCostForUnit / $usage, 4) : null;

            $row = [
                'fleet_unit_id' => $uid,
                'unit_number' => $ident['unit_number'],
                'unit_type' => $ident['unit_type'],
                'status' => $ident['status'],
                'total_cost' => round($totalCostForUnit, 2),
                'labor_cost' => round($laborForUnit, 2),
                'parts_cost' => round($partsForUnit, 2),
                $unitLabel => round($usage, 2),
                'cost_per_' . $this->costPerKey($unitLabel) => $costPerUnit,
                'workorder_count' => $woCount,
                'first_reading' => $firstReading,
                'last_reading' => $lastReading,
            ];
            if ($includeExternal) {
                // Expose the split so the UI can show "of which $2k was
                // external vendor work" without re-querying the repair
                // log.
                $row['internal_cost'] = round($internalCost, 2);
                $row['external_cost'] = round($externalCost, 2);
                $row['external_other_cost'] = round($externalOther, 2);
                $row['external_repair_count'] = $externalCount;
            }
            $rows[] = $row;

            $totalCost += $totalCostForUnit;
            $totalLabor += $laborForUnit;
            $totalParts += $partsForUnit;
            $totalUsage += $usage;
            $totalWorkorders += $woCount;
        }

        // Sort rows by total_cost DESC so the most expensive units float
        // to the top — matches how fleet managers triage cost reports.
        usort($rows, fn(array $a, array $b) => $b['total_cost'] <=> $a['total_cost']);

        $totalCostPerUnit = $totalUsage > 0 ? round($totalCost / $totalUsage, 4) : null;

        return [
            'unit' => $unitLabel,
            'from' => $fromTs,
            'to' => $toTs,
            'rows' => $rows,
            'totals' => [
                'total_cost' => round($totalCost, 2),
                'labor_cost' => round($totalLabor, 2),
                'parts_cost' => round($totalParts, 2),
                $unitLabel => round($totalUsage, 2),
                'cost_per_' . $this->costPerKey($unitLabel) => $totalCostPerUnit,
                'workorder_count' => $totalWorkorders,
                'unit_count' => count($rows),
            ],
        ];
    }

    /**
     * "miles" → "mile", "hours" → "hour" for the cost_per_* key naming.
     */
    private function costPerKey(string $unitLabel): string
    {
        return rtrim($unitLabel, 's');
    }

    /**
     * @return array{0: string, 1: string} [fromStart, toEnd] as datetime strings
     */
    private function normalizeRange(string $from, string $to): array
    {
        $fromDate = $this->parseDate($from, 'from');
        $toDate = $this->parseDate($to, 'to');
        if ($fromDate->format('Y-m-d') > $toDate->format('Y-m-d')) {
            throw new InvalidArgumentException('from must be <= to');
        }
        $spanDays = (int) $fromDate->diff($toDate)->days;
        if ($spanDays > self::MAX_WINDOW_DAYS) {
            throw new InvalidArgumentException(
                'date window exceeds ' . self::MAX_WINDOW_DAYS . ' days — split into smaller reports'
            );
        }
        return [
            $fromDate->format('Y-m-d') . ' 00:00:00',
            $toDate->format('Y-m-d') . ' 23:59:59',
        ];
    }

    private function parseDate(string $raw, string $field): DateTimeImmutable
    {
        $raw = trim($raw);
        if ($raw === '') {
            throw new InvalidArgumentException("{$field} date is required");
        }
        try {
            return new DateTimeImmutable($raw);
        } catch (Exception $e) {
            throw new InvalidArgumentException("{$field} is not a valid date: " . $e->getMessage());
        }
    }
}
