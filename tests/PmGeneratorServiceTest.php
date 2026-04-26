<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\PmGeneration;
use App\Models\PmPlan;
use App\Models\PmSchedule;
use App\Models\Ticket;
use App\Services\Pm\PmFrequencyService;
use App\Services\Pm\PmGenerationRepository;
use App\Services\Pm\PmGeneratorService;
use App\Services\Pm\PmPlanRepository;
use App\Services\Pm\PmScheduleRepository;
use App\Services\Tickets\TicketRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;

/**
 * Phase 5.3 of docs/expansion-plan.md — PM→ticket auto-generation.
 *
 * Covers: listDueThrough pickup, ticket payload shape (title/source/plan
 * defaults), generation audit row + cadence advance, frequency_config
 * mutation propagation (meter baseline), error isolation (one schedule
 * fails, others succeed + failure row recorded).
 */

class GenFakePlans extends PmPlanRepository
{
    public array $store = [];
    public function __construct()
    {
    }
    public function findById(int $id): ?PmPlan
    {
        return $this->store[$id] ?? null;
    }
    public function add(array $data): PmPlan
    {
        $p = new PmPlan();
        static $seq = 0;
        $p->id = ++$seq;
        foreach ($data as $k => $v) {
            if (property_exists($p, $k)) {
                $p->{$k} = $v;
            }
        }
        $this->store[$p->id] = $p;
        return $p;
    }
}

class GenFakeSchedules extends PmScheduleRepository
{
    public array $due = [];
    public array $updates = [];
    public array $store = [];
    public function __construct()
    {
    }
    public function listDueThrough(string $cutoff): array
    {
        return $this->due;
    }
    public function update(int $id, array $data): PmSchedule
    {
        $this->updates[$id] = $data;
        $s = $this->store[$id] ?? new PmSchedule();
        foreach ($data as $k => $v) {
            if (property_exists($s, $k)) {
                $s->{$k} = $v;
            }
        }
        return $s;
    }
    public function add(PmSchedule $s): void
    {
        $this->store[$s->id] = $s;
        $this->due[] = $s;
    }
}

class GenFakeTickets extends TicketRepository
{
    public array $created = [];
    public bool $failNext = false;
    public function __construct()
    {
    }
    public function create(array $data): Ticket
    {
        if ($this->failNext) {
            $this->failNext = false;
            throw new RuntimeException('simulated ticket create failure');
        }
        $t = new Ticket();
        $t->id = count($this->created) + 1;
        $t->ticket_number = 'T-TEST-' . $t->id;
        foreach ($data as $k => $v) {
            if (property_exists($t, $k)) {
                $t->{$k} = $v;
            }
        }
        $this->created[] = ['ticket' => $t, 'data' => $data];
        return $t;
    }
}

class GenFakeGenerations extends PmGenerationRepository
{
    public array $records = [];
    public function __construct()
    {
    }
    public function record(array $data): PmGeneration
    {
        $g = new PmGeneration();
        $g->id = count($this->records) + 1;
        $g->schedule_id = (int) $data['schedule_id'];
        $g->plan_id = (int) $data['plan_id'];
        $g->ticket_id = $data['ticket_id'] ?? null;
        $g->due_at = (string) $data['due_at'];
        $g->status = $data['status'] ?? 'generated';
        $g->failure_reason = $data['failure_reason'] ?? null;
        $g->generated_at = $data['generated_at'] ?? '2026-04-23 02:00:00';
        $this->records[] = $g;
        return $g;
    }
}

class GenFakeAudit extends AuditLogger
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

function mkSchedule(int $id, int $planId, array $overrides = []): PmSchedule
{
    $s = new PmSchedule();
    $s->id = $id;
    $s->plan_id = $planId;
    $s->company_id = 10;
    $s->starts_at = '2026-04-01';
    $s->next_due_at = '2026-04-20';
    $s->frequency_kind = 'fixed_interval';
    $s->frequency_config = ['interval_days' => 30];
    foreach ($overrides as $k => $v) {
        if (property_exists($s, $k)) {
            $s->{$k} = $v;
        }
    }
    return $s;
}

function genEnv(): array
{
    $plans = new GenFakePlans();
    $schedules = new GenFakeSchedules();
    $tickets = new GenFakeTickets();
    $generations = new GenFakeGenerations();
    $audit = new GenFakeAudit();
    $svc = new PmGeneratorService(
        $schedules, $plans, $tickets, $generations,
        new PmFrequencyService(), $audit
    );
    return compact('plans', 'schedules', 'tickets', 'generations', 'audit', 'svc');
}

function genCheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $ex) {
        echo "  FAIL {$label}: " . $ex->getMessage() . "\n";
        exit(1);
    }
}

echo "Phase 5.3 — PM generator cron\n";

// 1. Empty due list — service returns 0/0 and no side effects.
$env = genEnv();
genCheck(function () use ($env) {
    $out = $env['svc']->runDueThrough('2026-04-23');
    if ($out['generated'] !== 0 || $out['failed'] !== 0) {
        throw new RuntimeException('empty run should be 0/0');
    }
    if ($env['tickets']->created !== []) {
        throw new RuntimeException('no tickets should be created');
    }
}, 'empty due list is a no-op');

// 2. Happy path — one schedule, fixed_interval, advances cadence + records.
$env = genEnv();
$plan = $env['plans']->add([
    'title' => 'Monthly HVAC filter',
    'description' => 'Replace air filter.',
    'default_priority' => 'p3_normal',
    'default_category_id' => 88,
    'default_queue_id' => 5,
    'default_assigned_user_id' => 42,
    'division_id' => 7,
]);
$env['schedules']->add(mkSchedule(1, $plan->id));
genCheck(function () use ($env) {
    $out = $env['svc']->runDueThrough('2026-04-23');
    if ($out['generated'] !== 1 || $out['failed'] !== 0) {
        throw new RuntimeException('expected 1/0, got ' . json_encode($out));
    }
    $data = $env['tickets']->created[0]['data'];
    if ($data['source'] !== 'pm_generator') {
        throw new RuntimeException('source should be pm_generator');
    }
    if ($data['source_ref'] !== 'pm_schedule:1') {
        throw new RuntimeException('source_ref mismatch');
    }
    if ($data['category_id'] !== 88 || $data['queue_id'] !== 5
        || $data['assigned_to_user_id'] !== 42 || $data['division_id'] !== 7) {
        throw new RuntimeException('plan defaults not applied to ticket');
    }
    if (!str_contains($data['title'], 'Monthly HVAC filter')
        || !str_contains($data['title'], '2026-04-20')) {
        throw new RuntimeException("title missing plan/date: {$data['title']}");
    }
    // Schedule update should include last_generated_at and next_due_at.
    $upd = $env['schedules']->updates[1] ?? [];
    if (!isset($upd['last_generated_at'])) {
        throw new RuntimeException('last_generated_at not persisted');
    }
    if (($upd['next_due_at'] ?? null) !== '2026-05-20') {
        throw new RuntimeException('next_due_at should advance 30d to 2026-05-20, got '
            . var_export($upd['next_due_at'] ?? null, true));
    }
    if (count($env['generations']->records) !== 1) {
        throw new RuntimeException('exactly one generation row expected');
    }
    $rec = $env['generations']->records[0];
    if ($rec->status !== 'generated' || $rec->ticket_id !== $env['tickets']->created[0]['ticket']->id) {
        throw new RuntimeException('generation row wrong');
    }
    if ($env['audit']->entries === []
        || $env['audit']->entries[0]->event !== 'pm.generated') {
        throw new RuntimeException('pm.generated audit missing');
    }
}, 'fixed_interval happy path: ticket + cadence advance + audit');

// 3. Checklist is appended to description.
$env = genEnv();
$plan = $env['plans']->add([
    'title' => 'Quarterly',
    'description' => 'Quarterly walk.',
    'checklist_json' => ['Check breakers', ['label' => 'Test battery'], 'Photos'],
]);
$env['schedules']->add(mkSchedule(1, $plan->id));
genCheck(function () use ($env) {
    $env['svc']->runDueThrough('2026-04-23');
    $desc = $env['tickets']->created[0]['data']['description'];
    if (!str_contains($desc, 'Checklist:')
        || !str_contains($desc, 'Check breakers')
        || !str_contains($desc, 'Test battery')
        || !str_contains($desc, 'Photos')) {
        throw new RuntimeException("checklist not appended: {$desc}");
    }
}, 'checklist appended to description');

// 4. Meter kind — ticket created but next_due_at is NOT advanced by cron
//    (meter events clear it separately).
$env = genEnv();
$plan = $env['plans']->add(['title' => 'Meter PM']);
$env['schedules']->add(mkSchedule(1, $plan->id, [
    'frequency_kind' => 'meter',
    'frequency_config' => ['interval_units' => 250, 'baseline_reading' => 1000],
]));
genCheck(function () use ($env) {
    $env['svc']->runDueThrough('2026-04-23');
    $upd = $env['schedules']->updates[1] ?? [];
    if (array_key_exists('next_due_at', $upd)) {
        throw new RuntimeException('meter kind must not advance next_due_at in cron');
    }
    if (!isset($upd['last_generated_at'])) {
        throw new RuntimeException('last_generated_at should still update');
    }
}, 'meter kind leaves next_due_at untouched');

// 5. Missing plan throws → schedule records failed row, run continues.
$env = genEnv();
$env['schedules']->add(mkSchedule(1, 9999)); // plan 9999 doesn't exist
$plan2 = $env['plans']->add(['title' => 'Other']);
$env['schedules']->add(mkSchedule(2, $plan2->id));
genCheck(function () use ($env) {
    $out = $env['svc']->runDueThrough('2026-04-23');
    if ($out['generated'] !== 1 || $out['failed'] !== 1) {
        throw new RuntimeException('expected 1/1, got ' . json_encode($out));
    }
    $failures = array_filter(
        $env['generations']->records,
        fn($g) => $g->status === 'failed'
    );
    if (count($failures) !== 1) {
        throw new RuntimeException('exactly one failed generation row expected');
    }
    $failure = array_values($failures)[0];
    if ($failure->schedule_id !== 1) {
        throw new RuntimeException('failure should be on schedule 1');
    }
    if (!str_contains((string) $failure->failure_reason, 'pm_plan 9999 not found')) {
        throw new RuntimeException('failure_reason should capture error');
    }
}, 'failure on one schedule does not abort run');

// 6. Ticket creation failure captured as failed row.
$env = genEnv();
$plan = $env['plans']->add(['title' => 'P']);
$env['schedules']->add(mkSchedule(1, $plan->id));
$env['tickets']->failNext = true;
genCheck(function () use ($env) {
    $out = $env['svc']->runDueThrough('2026-04-23');
    if ($out['failed'] !== 1) {
        throw new RuntimeException('ticket create failure should record failed=1');
    }
    $failure = array_values(array_filter(
        $env['generations']->records,
        fn($g) => $g->status === 'failed'
    ))[0] ?? null;
    if ($failure === null) {
        throw new RuntimeException('no failure row recorded');
    }
    if (!str_contains((string) $failure->failure_reason, 'simulated ticket create failure')) {
        throw new RuntimeException('failure reason lost');
    }
}, 'ticket create failure records failed row');

// 7. Default priority from plan flows through.
$env = genEnv();
$plan = $env['plans']->add([
    'title' => 'Critical PM', 'default_priority' => 'p1_critical',
]);
$env['schedules']->add(mkSchedule(1, $plan->id));
genCheck(function () use ($env) {
    $env['svc']->runDueThrough('2026-04-23');
    $data = $env['tickets']->created[0]['data'];
    if ($data['priority'] !== 'p1_critical') {
        throw new RuntimeException('plan priority should flow to ticket');
    }
}, 'plan priority applied to ticket');

echo "\nALL 7 PASS\n";
