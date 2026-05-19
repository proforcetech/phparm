<?php

namespace App\Services\Pm;

use App\Models\PmPlan;
use App\Models\PmSchedule;
use App\Models\User;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Preventative-maintenance CRUD (Phase 5.1 of docs/expansion-plan.md).
 *
 * Gates:
 *   - pm.view    — read plans and schedules
 *   - pm.manage  — create / update / delete plans and schedules
 *
 * Plans are templates; schedules bind them to targets with a cadence. The
 * generator cron (5.3) and frequency engine (5.2) read both — this controller
 * owns only the shape of the records.
 */
class PmController
{
    public function __construct(
        private readonly PmPlanRepository $plans,
        private readonly PmScheduleRepository $schedules,
        private readonly SiteRepository $sites,
        private readonly SiteAssetRepository $assets,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
        private readonly ?PmComplianceService $compliance = null,
        private readonly ?PmLinkageService $linkage = null,
    ) {
    }

    // ─────────────────────────── Completion (5.5) ───────────────────────────

    /**
     * Record completion of a PM run and apply contract-entitlement
     * consumption when the schedule is bound to a contract.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function recordCompletion(User $user, int $generationId, array $body): array
    {
        if ($this->linkage === null) {
            throw new InvalidArgumentException('linkage service not configured');
        }
        $kind = isset($body['entitlement_kind']) && is_string($body['entitlement_kind'])
            ? $body['entitlement_kind']
            : 'hours';
        if (!isset($body['amount'])) {
            throw new InvalidArgumentException('amount is required');
        }
        $amount = (float) $body['amount'];
        $notes = isset($body['notes']) && is_string($body['notes']) ? $body['notes'] : null;
        return ['data' => $this->linkage->applyCompletion($user, $generationId, $kind, $amount, $notes)];
    }

    // ───────────────────────────── Compliance (5.4) ─────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function overdueReport(User $user, array $filters): array
    {
        $this->gate->assert($user, 'pm.view');
        if ($this->compliance === null) {
            return ['data' => ['as_of' => null, 'overdue' => [], 'count' => 0]];
        }
        return ['data' => $this->compliance->overdueReport(
            $filters['as_of'] ?? null,
            [
                'company_id' => $filters['company_id'] ?? null,
                'site_id' => $filters['site_id'] ?? null,
            ]
        )];
    }

    /**
     * @return array<string, mixed>
     */
    public function scheduleCompliance(User $user, int $id, ?string $since, ?string $until): array
    {
        $this->gate->assert($user, 'pm.view');
        $schedule = $this->schedules->findById($id);
        if ($schedule === null) {
            throw new InvalidArgumentException("pm_schedule {$id} not found");
        }
        if ($this->compliance === null) {
            throw new InvalidArgumentException('compliance service not configured');
        }
        $since ??= (new \DateTimeImmutable('-90 days'))->format('Y-m-d');
        return ['data' => $this->compliance->scheduleCompliance($schedule, $since, $until)];
    }

    /**
     * @return array<string, mixed>
     */
    public function companyCompliance(User $user, int $companyId, ?string $since, ?string $until): array
    {
        $this->gate->assert($user, 'pm.view');
        if ($this->compliance === null) {
            throw new InvalidArgumentException('compliance service not configured');
        }
        $since ??= (new \DateTimeImmutable('-90 days'))->format('Y-m-d');
        return ['data' => $this->compliance->companyCompliance($companyId, $since, $until)];
    }

    // ───────────────────────────────────── Plans ────────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listPlans(User $user, array $filters): array
    {
        $this->gate->assert($user, 'pm.view');
        $rows = $this->plans->search($filters);
        return [
            'data' => array_map(fn(PmPlan $p) => $this->serializePlan($p), $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPlan(User $user, int $id): array
    {
        $this->gate->assert($user, 'pm.view');
        $plan = $this->plans->findById($id);
        if ($plan === null) {
            throw new InvalidArgumentException("pm_plan {$id} not found");
        }
        return ['data' => $this->serializePlan($plan)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createPlan(User $user, array $body): array
    {
        $this->gate->assert($user, 'pm.manage');
        $body = $this->normalizePlanPayload($body);
        $this->assertPlanFields($body, true);
        $plan = $this->plans->create($body + ['created_by_user_id' => $user->id ?? null]);
        $this->audit->log(new AuditEntry(
            'pm_plan.created',
            'pm_plan',
            $plan->id,
            $user->id ?? null,
            ['title' => $plan->title]
        ));
        return ['data' => $this->serializePlan($plan)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updatePlan(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'pm.manage');
        $existing = $this->plans->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("pm_plan {$id} not found");
        }
        $body = $this->normalizePlanPayload($body);
        $this->assertPlanFields($body, false);
        $plan = $this->plans->update($id, $body);
        $this->audit->log(new AuditEntry(
            'pm_plan.updated',
            'pm_plan',
            $plan->id,
            $user->id ?? null,
            ['changed_keys' => array_keys($body)]
        ));
        return ['data' => $this->serializePlan($plan)];
    }

    public function deletePlan(User $user, int $id): void
    {
        $this->gate->assert($user, 'pm.manage');
        $existing = $this->plans->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("pm_plan {$id} not found");
        }
        // Block deletion if active schedules still reference this plan —
        // archiving a schedule is reversible, deleting the plan out from
        // under it would orphan the cron.
        $linked = $this->schedules->search(['plan_id' => $id]);
        if ($linked !== []) {
            throw new InvalidArgumentException(
                "pm_plan {$id} has {$this->countActive($linked)} active schedule(s); "
                . 'archive or delete them first'
            );
        }
        $this->plans->delete($id);
        $this->audit->log(new AuditEntry(
            'pm_plan.deleted',
            'pm_plan',
            $id,
            $user->id ?? null,
            []
        ));
    }

    // ──────────────────────────────────── Schedules ─────────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listSchedules(User $user, array $filters): array
    {
        $this->gate->assert($user, 'pm.view');
        $rows = $this->schedules->search($filters);
        return [
            'data' => array_map(fn(PmSchedule $s) => $s->toArray(), $rows),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchedule(User $user, int $id): array
    {
        $this->gate->assert($user, 'pm.view');
        $schedule = $this->schedules->findById($id);
        if ($schedule === null) {
            throw new InvalidArgumentException("pm_schedule {$id} not found");
        }
        return ['data' => $schedule->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createSchedule(User $user, array $body): array
    {
        $this->gate->assert($user, 'pm.manage');
        $this->assertScheduleFields($body, true);

        $plan = $this->plans->findById((int) $body['plan_id']);
        if ($plan === null) {
            throw new InvalidArgumentException("plan_id {$body['plan_id']} not found");
        }
        if (!empty($body['site_id']) && $this->sites->findById((int) $body['site_id']) === null) {
            throw new InvalidArgumentException("site_id {$body['site_id']} not found");
        }
        if (!empty($body['asset_id']) && $this->assets->findById((int) $body['asset_id']) === null) {
            throw new InvalidArgumentException("asset_id {$body['asset_id']} not found");
        }

        $schedule = $this->schedules->create($body + ['created_by_user_id' => $user->id ?? null]);
        $this->audit->log(new AuditEntry(
            'pm_schedule.created',
            'pm_schedule',
            $schedule->id,
            $user->id ?? null,
            [
                'plan_id' => $schedule->plan_id,
                'site_id' => $schedule->site_id,
                'asset_id' => $schedule->asset_id,
                'frequency_kind' => $schedule->frequency_kind,
            ]
        ));
        return ['data' => $schedule->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateSchedule(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'pm.manage');
        $existing = $this->schedules->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("pm_schedule {$id} not found");
        }
        $this->assertScheduleFields($body, false);
        // last_generated_at is owned by the generator cron — block manual edits.
        unset($body['last_generated_at']);
        $schedule = $this->schedules->update($id, $body);
        $this->audit->log(new AuditEntry(
            'pm_schedule.updated',
            'pm_schedule',
            $schedule->id,
            $user->id ?? null,
            ['changed_keys' => array_keys($body)]
        ));
        return ['data' => $schedule->toArray()];
    }

    public function deleteSchedule(User $user, int $id): void
    {
        $this->gate->assert($user, 'pm.manage');
        $existing = $this->schedules->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("pm_schedule {$id} not found");
        }
        $this->schedules->delete($id);
        $this->audit->log(new AuditEntry(
            'pm_schedule.deleted',
            'pm_schedule',
            $id,
            $user->id ?? null,
            []
        ));
    }

    // ──────────────────────────────── Validation helpers ────────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function assertPlanFields(array $body, bool $isCreate): void
    {
        if ($isCreate) {
            if (empty($body['title']) || !is_string($body['title'])) {
                throw new InvalidArgumentException('title is required');
            }
        } elseif (array_key_exists('title', $body) && (empty($body['title']) || !is_string($body['title']))) {
            throw new InvalidArgumentException('title must be non-empty');
        }
        if (array_key_exists('default_priority', $body)
            && !in_array($body['default_priority'], PmPlanRepository::PRIORITIES, true)
        ) {
            throw new InvalidArgumentException(
                "default_priority must be one of: " . implode(',', PmPlanRepository::PRIORITIES)
            );
        }
        if (array_key_exists('target_kind', $body)
            && !in_array($body['target_kind'], PmPlanRepository::TARGET_KINDS, true)
        ) {
            throw new InvalidArgumentException(
                "target_kind must be one of: " . implode(',', PmPlanRepository::TARGET_KINDS)
            );
        }
        if (array_key_exists('estimated_duration_minutes', $body)
            && $body['estimated_duration_minutes'] !== null
            && (int) $body['estimated_duration_minutes'] < 0
        ) {
            throw new InvalidArgumentException('estimated_duration_minutes must be non-negative');
        }
    }

    /**
     * The React PM plan form historically posted name/task_definitions while
     * the database-backed PM model uses title/checklist_json. Accept both
     * shapes so older clients and the current UI land on one canonical payload.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    private function normalizePlanPayload(array $body): array
    {
        if (!array_key_exists('title', $body) && array_key_exists('name', $body)) {
            $body['title'] = $body['name'];
        }
        unset($body['name']);

        if (!array_key_exists('checklist_json', $body)) {
            if (array_key_exists('task_definitions', $body)) {
                $body['checklist_json'] = $body['task_definitions'];
            } elseif (array_key_exists('tasks', $body)) {
                $body['checklist_json'] = $body['tasks'];
            }
        }
        unset($body['task_definitions'], $body['tasks']);

        if (array_key_exists('target_kind', $body) && is_string($body['target_kind'])) {
            $body['target_kind'] = strtolower(trim($body['target_kind']));
        }

        return $body;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlan(PmPlan $plan): array
    {
        $data = $plan->toArray();
        $data['name'] = $plan->title;
        $data['task_definitions'] = $plan->checklist_json;
        return $data;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertScheduleFields(array $body, bool $isCreate): void
    {
        if ($isCreate) {
            if (empty($body['plan_id'])) {
                throw new InvalidArgumentException('plan_id is required');
            }
            if (empty($body['company_id'])) {
                throw new InvalidArgumentException('company_id is required');
            }
            if (empty($body['starts_at'])) {
                throw new InvalidArgumentException('starts_at is required');
            }
        }
        if (array_key_exists('status', $body)
            && !in_array($body['status'], PmScheduleRepository::STATUSES, true)
        ) {
            throw new InvalidArgumentException(
                "status must be one of: " . implode(',', PmScheduleRepository::STATUSES)
            );
        }
        if (array_key_exists('frequency_kind', $body)
            && !in_array($body['frequency_kind'], PmScheduleRepository::FREQUENCY_KINDS, true)
        ) {
            throw new InvalidArgumentException(
                "frequency_kind must be one of: " . implode(',', PmScheduleRepository::FREQUENCY_KINDS)
            );
        }
        if (array_key_exists('lead_time_days', $body)
            && (int) $body['lead_time_days'] < 0
        ) {
            throw new InvalidArgumentException('lead_time_days must be non-negative');
        }
    }

    /**
     * @param array<int, PmSchedule> $rows
     */
    private function countActive(array $rows): int
    {
        return count(array_filter($rows, fn(PmSchedule $s) => $s->status === 'active'));
    }
}
