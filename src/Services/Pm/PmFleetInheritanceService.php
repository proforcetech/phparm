<?php

namespace App\Services\Pm;

use App\Database\Connection;
use App\Models\FleetUnit;
use App\Models\FleetUnitReading;
use App\Models\PmFleetBinding;
use App\Models\PmSchedule;
use App\Models\User;
use App\Services\Fleet\FleetUnitRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * Phase 7.2 of docs/expansion-plan.md — fleet-specific PM inheritance.
 *
 * Two-layer responsibility:
 *
 *   1. CRUD on pm_fleet_bindings (plan ↔ fleet_unit or plan ↔ unit_type).
 *      Gated on pm.manage (write) / pm.view (read) so managers who
 *      already control PM plans also control fleet inheritance.
 *
 *   2. Two hook methods invoked by FleetUnitService:
 *        - ensureSchedulesForUnit — spawn pm_schedules for every active
 *          binding that applies to (unit, unit_type). Idempotent: a
 *          binding that already has a schedule for this unit is skipped.
 *        - onReadingRecorded — walk meter-kind schedules bound to this
 *          unit and delegate to PmFrequencyService::advanceForReading.
 *          When advanceForReading returns a fresh next_due_at, persist
 *          it + the mutated frequency_config.baseline_reading.
 *
 * The actual ticket generation still flows through PmGeneratorService's
 * due-date cron (Phase 5.3); this service only creates + advances the
 * schedules that feed it.
 */
class PmFleetInheritanceService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly PmFleetBindingRepository $bindings,
        private readonly PmScheduleRepository $schedules,
        private readonly PmPlanRepository $plans,
        private readonly FleetUnitRepository $units,
        private readonly PmFrequencyService $frequency,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    // ── Binding CRUD ─────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createBinding(User $actor, array $input): array
    {
        $this->gate->assert($actor, 'pm.manage');
        $fields = $this->validateBindingInput($input, isCreate: true);

        try {
            $id = $this->bindings->create(array_merge(
                $fields,
                ['created_by_user_id' => $actor->id],
            ));
        } catch (PDOException $e) {
            throw new RuntimeException(
                'failed to create pm_fleet_binding: ' . $e->getMessage(),
                0,
                $e,
            );
        }

        $this->audit->log(new AuditEntry(
            'pm.fleet_binding.created',
            'pm_fleet_binding',
            $id,
            $actor->id,
            [
                'plan_id' => $fields['plan_id'],
                'company_id' => $fields['company_id'],
                'scope_type' => $fields['scope_type'],
                'fleet_unit_id' => $fields['fleet_unit_id'],
                'unit_type' => $fields['unit_type'],
                'frequency_kind' => $fields['frequency_kind'],
            ],
        ));

        $binding = $this->bindings->findById($id);
        if ($binding === null) {
            throw new RuntimeException("pm_fleet_binding {$id} vanished after insert");
        }
        return $this->serializeBinding($binding);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateBinding(User $actor, int $id, array $input): array
    {
        $this->gate->assert($actor, 'pm.manage');
        $existing = $this->bindings->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("pm_fleet_binding {$id} not found");
        }

        // Identity columns (plan_id, scope_type, fleet_unit_id, unit_type,
        // company_id) are immutable — callers delete+recreate to change
        // scope. Everything else is a normal update.
        $patch = [
            'frequency_kind' => $input['frequency_kind'] ?? $existing->frequency_kind,
            'frequency_config' => array_key_exists('frequency_config', $input)
                ? $input['frequency_config']
                : $existing->frequency_config,
            'lead_time_days' => $input['lead_time_days'] ?? $existing->lead_time_days,
            'starts_at' => $input['starts_at'] ?? $existing->starts_at,
            'is_active' => array_key_exists('is_active', $input)
                ? (int) (bool) $input['is_active']
                : $existing->is_active,
        ];
        $this->validateFrequency($patch['frequency_kind'], $patch['frequency_config']);
        $patch['starts_at'] = $this->normalizeDate((string) $patch['starts_at'], 'starts_at');
        $patch['lead_time_days'] = max(0, (int) $patch['lead_time_days']);

        $this->bindings->update($id, $patch);

        $this->audit->log(new AuditEntry(
            'pm.fleet_binding.updated',
            'pm_fleet_binding',
            $id,
            $actor->id,
            [
                'company_id' => $existing->company_id,
                'is_active' => $patch['is_active'],
                'frequency_kind' => $patch['frequency_kind'],
            ],
        ));

        $fresh = $this->bindings->findById($id);
        if ($fresh === null) {
            throw new RuntimeException("pm_fleet_binding {$id} vanished after update");
        }
        return $this->serializeBinding($fresh);
    }

    public function deleteBinding(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'pm.manage');
        $existing = $this->bindings->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("pm_fleet_binding {$id} not found");
        }
        $this->bindings->delete($id);
        $this->audit->log(new AuditEntry(
            'pm.fleet_binding.deleted',
            'pm_fleet_binding',
            $id,
            $actor->id,
            [
                'company_id' => $existing->company_id,
                'plan_id' => $existing->plan_id,
                'scope_type' => $existing->scope_type,
            ],
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBindingsForCompany(User $actor, int $companyId, bool $activeOnly = false): array
    {
        $this->gate->assert($actor, 'pm.view');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company id is required');
        }
        return array_map(
            fn(PmFleetBinding $b) => $this->serializeBinding($b),
            $this->bindings->listForCompany($companyId, $activeOnly),
        );
    }

    // ── Hooks ────────────────────────────────────────────────────────────

    /**
     * Spawn a pm_schedule row for every active binding that applies to
     * this unit (unit-specific + unit_type default). Skips bindings that
     * already have a schedule for this (plan_id, fleet_unit_id) pair, so
     * this can be called repeatedly without duplicating schedules.
     *
     * @return array<int, int> new schedule ids (empty on idempotent re-run)
     */
    public function ensureSchedulesForUnit(User $actor, FleetUnit $unit): array
    {
        if ($unit->status === FleetUnit::STATUS_RETIRED) {
            // No point spawning schedules for a retired unit — they'd
            // just produce overdue alerts nobody can act on.
            return [];
        }
        $applicable = $this->bindings->listApplicable(
            $unit->company_id,
            $unit->id,
            $unit->unit_type,
        );
        if ($applicable === []) {
            return [];
        }

        $created = [];
        // listApplicable returns unit-specific bindings first then
        // unit_type bindings, each sorted by id. For inheritance precedence
        // that means a unit-specific binding for the same plan wins — we
        // skip a later unit_type binding that would duplicate it.
        $plansAlreadyBound = [];
        foreach ($this->schedules->search([
            'company_id' => $unit->company_id,
            'fleet_unit_id' => $unit->id,
        ]) as $existing) {
            $plansAlreadyBound[$existing->plan_id] = true;
        }

        foreach ($applicable as $binding) {
            if (isset($plansAlreadyBound[$binding->plan_id])) {
                continue; // already wired for this unit
            }
            // Guard: the plan must exist and be visible to this company.
            $plan = $this->plans->findById($binding->plan_id);
            if ($plan === null) {
                continue;
            }
            if ($plan->company_id !== null && $plan->company_id !== $binding->company_id) {
                continue;
            }
            $schedule = new PmSchedule([
                'plan_id' => $binding->plan_id,
                'company_id' => $binding->company_id,
                'fleet_unit_id' => $unit->id,
                'frequency_kind' => $binding->frequency_kind,
                'frequency_config' => $binding->frequency_config,
                'starts_at' => $binding->starts_at,
                'lead_time_days' => $binding->lead_time_days,
                'status' => 'active',
            ]);
            $nextDue = $this->frequency->initialNextDue($schedule);

            $row = $this->schedules->create([
                'plan_id' => $binding->plan_id,
                'company_id' => $binding->company_id,
                'fleet_unit_id' => $unit->id,
                'frequency_kind' => $binding->frequency_kind,
                'frequency_config' => $binding->frequency_config,
                'starts_at' => $binding->starts_at,
                'next_due_at' => $nextDue,
                'lead_time_days' => $binding->lead_time_days,
                'status' => 'active',
                'created_by_user_id' => $actor->id,
            ]);

            $created[] = $row->id;
            $plansAlreadyBound[$binding->plan_id] = true;

            $this->audit->log(new AuditEntry(
                'pm.fleet_binding.materialized',
                'pm_schedule',
                $row->id,
                $actor->id,
                [
                    'company_id' => $binding->company_id,
                    'fleet_unit_id' => $unit->id,
                    'plan_id' => $binding->plan_id,
                    'scope_type' => $binding->scope_type,
                ],
            ));
        }
        return $created;
    }

    /**
     * Called by FleetUnitService after a reading is committed. Walks
     * active meter-kind schedules bound to this unit and asks the
     * frequency engine whether any have crossed their threshold. When
     * one does, persist the advance so the cron can pick it up.
     *
     * Returns the ids of schedules that advanced.
     *
     * @return array<int, int>
     */
    public function onReadingRecorded(FleetUnit $unit, FleetUnitReading $reading): array
    {
        // A retired unit shouldn't trigger further PM work. Do this
        // check eagerly so a legacy reading being backfilled into a
        // retired unit can't resurrect a stale schedule.
        if ($unit->status === FleetUnit::STATUS_RETIRED) {
            return [];
        }

        $schedules = $this->schedules->search([
            'company_id' => $unit->company_id,
            'fleet_unit_id' => $unit->id,
            'status' => 'active',
        ]);
        if ($schedules === []) {
            return [];
        }

        $today = $this->todayFor($reading->recorded_at);
        $advanced = [];
        foreach ($schedules as $schedule) {
            if ($schedule->frequency_kind !== 'meter') {
                continue;
            }
            $config = $schedule->frequency_config ?? [];
            $unit_config = isset($config['unit']) && is_string($config['unit'])
                ? strtolower($config['unit'])
                : null;
            if (!$this->meterMatches($reading->reading_type, $unit_config)) {
                continue;
            }
            $nextDue = $this->frequency->advanceForReading($schedule, $reading->value, $today);
            if ($nextDue === null) {
                continue; // threshold not crossed
            }
            // advanceForReading mutates $schedule->frequency_config in
            // place to bump baseline_reading; persist both.
            $this->schedules->update($schedule->id, [
                'next_due_at' => $nextDue,
                'frequency_config' => $schedule->frequency_config,
            ]);
            $advanced[] = $schedule->id;

            $this->audit->log(new AuditEntry(
                'pm.fleet.meter_triggered',
                'pm_schedule',
                $schedule->id,
                $reading->recorded_by_user_id,
                [
                    'company_id' => $unit->company_id,
                    'fleet_unit_id' => $unit->id,
                    'reading_id' => $reading->id,
                    'reading_type' => $reading->reading_type,
                    'reading_value' => $reading->value,
                    'next_due_at' => $nextDue,
                ],
            ));
        }
        return $advanced;
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateBindingInput(array $input, bool $isCreate): array
    {
        $planId = (int) ($input['plan_id'] ?? 0);
        if ($planId <= 0) {
            throw new InvalidArgumentException('plan_id is required');
        }
        $plan = $this->plans->findById($planId);
        if ($plan === null) {
            throw new InvalidArgumentException("plan {$planId} not found");
        }

        $companyId = (int) ($input['company_id'] ?? 0);
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company_id is required');
        }
        if ($plan->company_id !== null && $plan->company_id !== $companyId) {
            throw new InvalidArgumentException('plan belongs to a different company');
        }

        $scope = isset($input['scope_type']) ? (string) $input['scope_type'] : '';
        if (!in_array($scope, PmFleetBinding::ALLOWED_SCOPES, true)) {
            throw new InvalidArgumentException(
                'scope_type must be one of ' . implode(',', PmFleetBinding::ALLOWED_SCOPES)
            );
        }

        $fleetUnitId = null;
        $unitType = null;
        if ($scope === PmFleetBinding::SCOPE_UNIT) {
            $fleetUnitId = (int) ($input['fleet_unit_id'] ?? 0);
            if ($fleetUnitId <= 0) {
                throw new InvalidArgumentException('fleet_unit_id is required for scope_type=unit');
            }
            $unit = $this->units->findById($fleetUnitId);
            if ($unit === null) {
                throw new InvalidArgumentException("fleet_unit {$fleetUnitId} not found");
            }
            if ($unit->company_id !== $companyId) {
                throw new InvalidArgumentException('fleet_unit belongs to a different company');
            }
        } else {
            $unitType = isset($input['unit_type']) ? (string) $input['unit_type'] : '';
            if ($unitType === '') {
                throw new InvalidArgumentException('unit_type is required for scope_type=unit_type');
            }
            if (!in_array($unitType, FleetUnit::ALLOWED_TYPES, true)) {
                throw new InvalidArgumentException(
                    'unit_type must be one of ' . implode(',', FleetUnit::ALLOWED_TYPES)
                );
            }
        }

        $frequencyKind = isset($input['frequency_kind']) ? (string) $input['frequency_kind'] : 'meter';
        if (!in_array($frequencyKind, PmScheduleRepository::FREQUENCY_KINDS, true)) {
            throw new InvalidArgumentException(
                'frequency_kind must be one of ' . implode(',', PmScheduleRepository::FREQUENCY_KINDS)
            );
        }
        $frequencyConfig = $input['frequency_config'] ?? null;
        if ($frequencyConfig !== null && !is_array($frequencyConfig)) {
            throw new InvalidArgumentException('frequency_config must be an object/array');
        }
        $this->validateFrequency($frequencyKind, $frequencyConfig);

        $startsAt = isset($input['starts_at']) ? (string) $input['starts_at'] : '';
        if ($startsAt === '') {
            throw new InvalidArgumentException('starts_at is required');
        }
        $startsAt = $this->normalizeDate($startsAt, 'starts_at');

        $leadTime = max(0, (int) ($input['lead_time_days'] ?? 0));
        $isActive = array_key_exists('is_active', $input)
            ? (int) (bool) $input['is_active']
            : 1;

        return [
            'plan_id' => $planId,
            'company_id' => $companyId,
            'scope_type' => $scope,
            'fleet_unit_id' => $fleetUnitId,
            'unit_type' => $unitType,
            'frequency_kind' => $frequencyKind,
            'frequency_config' => $frequencyConfig,
            'lead_time_days' => $leadTime,
            'starts_at' => $startsAt,
            'is_active' => $isActive,
        ];
    }

    /**
     * Sanity-check the shape of frequency_config against frequency_kind.
     * The Phase 5.2 PmFrequencyService already throws on bad configs
     * when it's actually called, but we want eager validation at write
     * time so bad bindings never get persisted.
     *
     * @param array<string, mixed>|null $config
     */
    private function validateFrequency(string $kind, ?array $config): void
    {
        $config = $config ?? [];
        if ($kind === 'meter') {
            $interval = isset($config['interval_units']) ? (float) $config['interval_units'] : 0.0;
            if ($interval <= 0) {
                throw new InvalidArgumentException(
                    'meter frequency requires frequency_config.interval_units > 0'
                );
            }
            $metricUnit = isset($config['unit']) ? strtolower((string) $config['unit']) : '';
            if (!in_array($metricUnit, ['miles', 'km', 'hours'], true)) {
                throw new InvalidArgumentException(
                    'meter frequency requires frequency_config.unit in (miles, km, hours)'
                );
            }
            return;
        }
        if ($kind === 'fixed_interval') {
            $keys = ['interval_days', 'interval_weeks', 'interval_months', 'interval_years'];
            foreach ($keys as $k) {
                if (isset($config[$k]) && (int) $config[$k] > 0) {
                    return;
                }
            }
            throw new InvalidArgumentException(
                'fixed_interval frequency requires one of ' . implode(', ', $keys)
            );
        }
        if ($kind === 'calendar') {
            if (!isset($config['day_of_month']) && !isset($config['day_of_week']) && empty($config['months_of_year'])) {
                throw new InvalidArgumentException(
                    'calendar frequency requires day_of_month, day_of_week, or months_of_year'
                );
            }
        }
        // condition — no config required; manual triggers only.
    }

    private function normalizeDate(string $raw, string $field): string
    {
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d');
        } catch (Exception $e) {
            throw new InvalidArgumentException("{$field} is not a valid date: " . $e->getMessage());
        }
    }

    /**
     * Match a reading_type against a schedule's meter config.unit. Only
     * odometer readings advance 'miles'/'km' schedules; engine_hours
     * readings advance 'hours' schedules. Anything else is a no-op so
     * a truck with both meters doesn't accidentally trigger the wrong
     * kind of service.
     */
    private function meterMatches(string $readingType, ?string $unit): bool
    {
        if ($readingType === FleetUnitReading::TYPE_ODOMETER) {
            return $unit === 'miles' || $unit === 'km';
        }
        if ($readingType === FleetUnitReading::TYPE_ENGINE_HOURS) {
            return $unit === 'hours';
        }
        return false;
    }

    private function todayFor(string $recordedAt): string
    {
        try {
            return (new DateTimeImmutable($recordedAt))->format('Y-m-d');
        } catch (Exception) {
            return (new DateTimeImmutable('today'))->format('Y-m-d');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBinding(PmFleetBinding $b): array
    {
        return [
            'id' => $b->id,
            'plan_id' => $b->plan_id,
            'company_id' => $b->company_id,
            'scope_type' => $b->scope_type,
            'fleet_unit_id' => $b->fleet_unit_id,
            'unit_type' => $b->unit_type,
            'frequency_kind' => $b->frequency_kind,
            'frequency_config' => $b->frequency_config,
            'lead_time_days' => $b->lead_time_days,
            'starts_at' => $b->starts_at,
            'is_active' => $b->is_active,
            'created_by_user_id' => $b->created_by_user_id,
            'created_at' => $b->created_at,
            'updated_at' => $b->updated_at,
        ];
    }
}
