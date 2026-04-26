<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\PmPlan;
use App\Models\PmSchedule;
use App\Models\Site;
use App\Models\SiteAsset;
use App\Models\User;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Pm\PmController;
use App\Services\Pm\PmPlanRepository;
use App\Services\Pm\PmScheduleRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;

/**
 * Phase 5.1 of docs/expansion-plan.md: pm_plans + pm_schedules CRUD.
 *
 * Covers plan validation (title required, priority enum, duration non-negative),
 * schedule validation (plan_id/company_id/starts_at required, status/frequency
 * enums, lead_time_days non-negative), delete guardrail (plan with active
 * schedules), updateSchedule last_generated_at strip, and permission gating.
 */

class PmFakeGate extends AccessGate
{
    public array $denied = [];
    public function __construct()
    {
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (in_array($permission, $this->denied, true)) {
            throw new RuntimeException("denied: {$permission}");
        }
    }
}

class PmFakeAudit extends AuditLogger
{
    public array $entries = [];
    public function __construct()
    {
    }
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class PmFakePlans extends PmPlanRepository
{
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function search(array $filters = []): array
    {
        $rows = array_values($this->store);
        if (isset($filters['is_active'])) {
            $want = (int) (bool) $filters['is_active'];
            $rows = array_values(array_filter($rows, fn($p) => $p->is_active === $want));
        }
        return $rows;
    }
    public function findById(int $id): ?PmPlan
    {
        return $this->store[$id] ?? null;
    }
    public function create(array $data): PmPlan
    {
        $p = new PmPlan();
        $p->id = $this->nextId++;
        foreach ($data as $k => $v) {
            if (property_exists($p, $k)) {
                $p->{$k} = $v;
            }
        }
        if (!isset($data['default_priority'])) {
            $p->default_priority = 'p3_normal';
        }
        $this->store[$p->id] = $p;
        return $p;
    }
    public function update(int $id, array $data): PmPlan
    {
        $p = $this->store[$id];
        foreach ($data as $k => $v) {
            if (property_exists($p, $k)) {
                $p->{$k} = $v;
            }
        }
        return $p;
    }
    public function delete(int $id): void
    {
        unset($this->store[$id]);
    }
}

class PmFakeSchedules extends PmScheduleRepository
{
    public array $store = [];
    public int $nextId = 1;
    /** @var array<int, array<string, mixed>> */
    public array $updates = [];
    public function __construct()
    {
    }
    public function search(array $filters = []): array
    {
        $rows = array_values($this->store);
        if (!empty($filters['plan_id'])) {
            $rows = array_values(array_filter(
                $rows, fn($s) => $s->plan_id === (int) $filters['plan_id']
            ));
        }
        return $rows;
    }
    public function findById(int $id): ?PmSchedule
    {
        return $this->store[$id] ?? null;
    }
    public function create(array $data): PmSchedule
    {
        $s = new PmSchedule();
        $s->id = $this->nextId++;
        foreach ($data as $k => $v) {
            if (property_exists($s, $k)) {
                $s->{$k} = $v;
            }
        }
        if (!isset($data['status'])) {
            $s->status = 'active';
        }
        if (!isset($data['frequency_kind'])) {
            $s->frequency_kind = 'fixed_interval';
        }
        $this->store[$s->id] = $s;
        return $s;
    }
    public function update(int $id, array $data): PmSchedule
    {
        $this->updates[$id] = $data;
        $s = $this->store[$id];
        foreach ($data as $k => $v) {
            if (property_exists($s, $k)) {
                $s->{$k} = $v;
            }
        }
        return $s;
    }
    public function delete(int $id): void
    {
        unset($this->store[$id]);
    }
}

class PmFakeSites extends SiteRepository
{
    public array $store = [];
    public function __construct()
    {
    }
    public function findById(int $id): ?Site
    {
        return $this->store[$id] ?? null;
    }
    public function add(int $id): void
    {
        $s = new Site();
        $s->id = $id;
        $this->store[$id] = $s;
    }
}

class PmFakeAssets extends SiteAssetRepository
{
    public array $store = [];
    public function __construct()
    {
    }
    public function findById(int $id): ?SiteAsset
    {
        return $this->store[$id] ?? null;
    }
    public function add(int $id): void
    {
        $a = new SiteAsset();
        $a->id = $id;
        $this->store[$id] = $a;
    }
}

function pmEnv(): array
{
    $plans = new PmFakePlans();
    $schedules = new PmFakeSchedules();
    $sites = new PmFakeSites();
    $assets = new PmFakeAssets();
    $gate = new PmFakeGate();
    $audit = new PmFakeAudit();
    $controller = new PmController($plans, $schedules, $sites, $assets, $gate, $audit);
    return compact('plans', 'schedules', 'sites', 'assets', 'gate', 'audit', 'controller');
}

function pmUser(): User
{
    $u = new User();
    $u->id = 42;
    $u->role = 'manager';
    return $u;
}

function pmCheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $ex) {
        echo "  FAIL {$label}: " . $ex->getMessage() . "\n";
        exit(1);
    }
}

function pmExpectThrow(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        echo "  FAIL {$label}: expected throw containing '{$needle}'\n";
        exit(1);
    } catch (Throwable $ex) {
        if (!str_contains($ex->getMessage(), $needle)) {
            echo "  FAIL {$label}: wrong throw — got '" . $ex->getMessage() . "', wanted '{$needle}'\n";
            exit(1);
        }
        echo "  PASS {$label}\n";
    }
}

echo "Phase 5.1 — preventative maintenance CRUD\n";

// 1. createPlan — title required.
$env = pmEnv();
pmExpectThrow(
    fn() => $env['controller']->createPlan(pmUser(), []),
    'title is required',
    'createPlan requires title'
);

// 2. createPlan — default_priority enum.
$env = pmEnv();
pmExpectThrow(
    fn() => $env['controller']->createPlan(pmUser(), [
        'title' => 'Quarterly HVAC', 'default_priority' => 'urgent',
    ]),
    'default_priority must be one of',
    'createPlan rejects invalid priority'
);

// 3. createPlan — estimated_duration_minutes non-negative.
$env = pmEnv();
pmExpectThrow(
    fn() => $env['controller']->createPlan(pmUser(), [
        'title' => 'Duration check', 'estimated_duration_minutes' => -5,
    ]),
    'non-negative',
    'createPlan rejects negative duration'
);

// 4. createPlan — happy path, creates + audits.
$env = pmEnv();
pmCheck(function () use ($env) {
    $out = $env['controller']->createPlan(pmUser(), [
        'title' => 'Monthly Inspection',
        'default_priority' => 'p2_high',
        'estimated_duration_minutes' => 60,
    ]);
    if (!isset($out['data']['id']) || $out['data']['title'] !== 'Monthly Inspection') {
        throw new RuntimeException('create did not return plan');
    }
    if ($env['audit']->entries === [] || $env['audit']->entries[0]->event !== 'pm_plan.created') {
        throw new RuntimeException('audit entry missing');
    }
    if ($env['audit']->entries[0]->actorId !== 42) {
        throw new RuntimeException('actor_id not captured');
    }
}, 'createPlan persists and audits');

// 5. updatePlan — missing plan throws.
$env = pmEnv();
pmExpectThrow(
    fn() => $env['controller']->updatePlan(pmUser(), 99, ['title' => 'x']),
    'not found',
    'updatePlan rejects missing id'
);

// 6. updatePlan — empty title rejected.
$env = pmEnv();
$env['controller']->createPlan(pmUser(), ['title' => 'Seed']);
pmExpectThrow(
    fn() => $env['controller']->updatePlan(pmUser(), 1, ['title' => '']),
    'title must be non-empty',
    'updatePlan rejects empty title'
);

// 7. deletePlan — blocked when active schedule references it.
$env = pmEnv();
$plan = $env['plans']->create(['title' => 'Locked', 'default_priority' => 'p3_normal']);
$env['schedules']->create([
    'plan_id' => $plan->id, 'company_id' => 10,
    'starts_at' => '2026-05-01', 'status' => 'active',
]);
pmExpectThrow(
    fn() => $env['controller']->deletePlan(pmUser(), $plan->id),
    'active schedule',
    'deletePlan blocked by linked active schedule'
);

// 8. deletePlan — allowed once schedules are archived/removed.
$env = pmEnv();
$plan = $env['plans']->create(['title' => 'Retired', 'default_priority' => 'p3_normal']);
pmCheck(function () use ($env, $plan) {
    $env['controller']->deletePlan(pmUser(), $plan->id);
    if (isset($env['plans']->store[$plan->id])) {
        throw new RuntimeException('plan should be deleted');
    }
    $events = array_map(fn($a) => $a->event, $env['audit']->entries);
    if (!in_array('pm_plan.deleted', $events, true)) {
        throw new RuntimeException('delete audit missing');
    }
}, 'deletePlan removes plan with no schedules');

// 9. createSchedule — plan_id/company_id/starts_at required.
$env = pmEnv();
pmExpectThrow(
    fn() => $env['controller']->createSchedule(pmUser(), []),
    'plan_id is required',
    'createSchedule requires plan_id'
);
$env = pmEnv();
pmExpectThrow(
    fn() => $env['controller']->createSchedule(pmUser(), ['plan_id' => 1]),
    'company_id is required',
    'createSchedule requires company_id'
);
$env = pmEnv();
pmExpectThrow(
    fn() => $env['controller']->createSchedule(pmUser(), [
        'plan_id' => 1, 'company_id' => 10,
    ]),
    'starts_at is required',
    'createSchedule requires starts_at'
);

// 10. createSchedule — status / frequency_kind enums.
$env = pmEnv();
$env['plans']->create(['title' => 'P']);
pmExpectThrow(
    fn() => $env['controller']->createSchedule(pmUser(), [
        'plan_id' => 1, 'company_id' => 10, 'starts_at' => '2026-05-01',
        'status' => 'bogus',
    ]),
    'status must be one of',
    'createSchedule status enum'
);
$env = pmEnv();
$env['plans']->create(['title' => 'P']);
pmExpectThrow(
    fn() => $env['controller']->createSchedule(pmUser(), [
        'plan_id' => 1, 'company_id' => 10, 'starts_at' => '2026-05-01',
        'frequency_kind' => 'every_full_moon',
    ]),
    'frequency_kind must be one of',
    'createSchedule frequency_kind enum'
);

// 11. createSchedule — lead_time_days non-negative.
$env = pmEnv();
$env['plans']->create(['title' => 'P']);
pmExpectThrow(
    fn() => $env['controller']->createSchedule(pmUser(), [
        'plan_id' => 1, 'company_id' => 10, 'starts_at' => '2026-05-01',
        'lead_time_days' => -3,
    ]),
    'non-negative',
    'createSchedule lead_time_days non-negative'
);

// 12. createSchedule — unknown plan_id rejected.
$env = pmEnv();
pmExpectThrow(
    fn() => $env['controller']->createSchedule(pmUser(), [
        'plan_id' => 77, 'company_id' => 10, 'starts_at' => '2026-05-01',
    ]),
    'plan_id 77 not found',
    'createSchedule validates plan existence'
);

// 13. createSchedule — unknown site_id / asset_id rejected.
$env = pmEnv();
$env['plans']->create(['title' => 'P']);
pmExpectThrow(
    fn() => $env['controller']->createSchedule(pmUser(), [
        'plan_id' => 1, 'company_id' => 10, 'starts_at' => '2026-05-01',
        'site_id' => 500,
    ]),
    'site_id 500 not found',
    'createSchedule validates site existence'
);
$env = pmEnv();
$env['plans']->create(['title' => 'P']);
$env['sites']->add(500);
pmExpectThrow(
    fn() => $env['controller']->createSchedule(pmUser(), [
        'plan_id' => 1, 'company_id' => 10, 'starts_at' => '2026-05-01',
        'site_id' => 500, 'asset_id' => 900,
    ]),
    'asset_id 900 not found',
    'createSchedule validates asset existence'
);

// 14. createSchedule — happy path persists + audits.
$env = pmEnv();
$env['plans']->create(['title' => 'P']);
$env['sites']->add(500);
$env['assets']->add(900);
pmCheck(function () use ($env) {
    $out = $env['controller']->createSchedule(pmUser(), [
        'plan_id' => 1, 'company_id' => 10, 'starts_at' => '2026-05-01',
        'site_id' => 500, 'asset_id' => 900,
        'frequency_kind' => 'calendar',
        'lead_time_days' => 7,
    ]);
    if ($out['data']['plan_id'] !== 1 || $out['data']['frequency_kind'] !== 'calendar') {
        throw new RuntimeException('schedule fields not persisted');
    }
    $events = array_map(fn($a) => $a->event, $env['audit']->entries);
    if (!in_array('pm_schedule.created', $events, true)) {
        throw new RuntimeException('schedule create audit missing');
    }
}, 'createSchedule persists and audits');

// 15. updateSchedule — last_generated_at stripped from body.
$env = pmEnv();
$env['plans']->create(['title' => 'P']);
$env['schedules']->create([
    'plan_id' => 1, 'company_id' => 10, 'starts_at' => '2026-05-01',
]);
pmCheck(function () use ($env) {
    $env['controller']->updateSchedule(pmUser(), 1, [
        'lead_time_days' => 3,
        'last_generated_at' => '2026-05-15 08:00:00',
    ]);
    $applied = $env['schedules']->updates[1] ?? [];
    if (array_key_exists('last_generated_at', $applied)) {
        throw new RuntimeException('last_generated_at must be stripped');
    }
    if (($applied['lead_time_days'] ?? null) !== 3) {
        throw new RuntimeException('lead_time_days should still reach repo');
    }
}, 'updateSchedule strips last_generated_at');

// 16. Gate — pm.view denial blocks reads.
$env = pmEnv();
$env['gate']->denied = ['pm.view'];
pmExpectThrow(
    fn() => $env['controller']->listPlans(pmUser(), []),
    'denied: pm.view',
    'listPlans requires pm.view'
);

// 17. Gate — pm.manage denial blocks writes.
$env = pmEnv();
$env['gate']->denied = ['pm.manage'];
pmExpectThrow(
    fn() => $env['controller']->createPlan(pmUser(), ['title' => 'x']),
    'denied: pm.manage',
    'createPlan requires pm.manage'
);

echo "\nALL 17 PASS\n";
