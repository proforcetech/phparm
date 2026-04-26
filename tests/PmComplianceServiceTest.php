<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\PmGeneration;
use App\Models\PmSchedule;
use App\Services\Pm\PmComplianceService;
use App\Services\Pm\PmGenerationRepository;
use App\Services\Pm\PmScheduleRepository;

/**
 * Phase 5.4 of docs/expansion-plan.md — missed/overdue + compliance.
 *
 * Covers overdue listing with days-overdue, fixed_interval expected-tick
 * math across window, calendar expected-tick walk, meter/condition return
 * null compliance with reason='event_driven', companyCompliance weighted
 * rollup, and empty-window edge cases.
 */

class CmpFakeSchedules extends PmScheduleRepository
{
    public array $overdue = [];
    public array $byCompany = [];
    public function __construct()
    {
    }
    public function listOverdue(string $asOf, array $filters = []): array
    {
        $out = $this->overdue;
        if (!empty($filters['company_id'])) {
            $cid = (int) $filters['company_id'];
            $out = array_values(array_filter($out, fn($s) => $s->company_id === $cid));
        }
        return $out;
    }
    public function search(array $filters = []): array
    {
        $cid = (int) ($filters['company_id'] ?? 0);
        return $this->byCompany[$cid] ?? [];
    }
}

class CmpFakeGenerations extends PmGenerationRepository
{
    /** @var array<int, array<int, PmGeneration>> */
    public array $byScheduleSince = [];
    public function __construct()
    {
    }
    public function countsForScheduleSince(int $scheduleId, string $sinceDate): array
    {
        $rows = $this->byScheduleSince[$scheduleId] ?? [];
        $out = ['generated' => 0, 'failed' => 0, 'total' => 0];
        foreach ($rows as $g) {
            if ($g->due_at < $sinceDate) {
                continue;
            }
            if ($g->status === 'generated') {
                $out['generated']++;
            } elseif ($g->status === 'failed') {
                $out['failed']++;
            }
            $out['total']++;
        }
        return $out;
    }
    public function seed(int $scheduleId, string $status, string $dueAt): void
    {
        $g = new PmGeneration();
        $g->schedule_id = $scheduleId;
        $g->status = $status;
        $g->due_at = $dueAt;
        $this->byScheduleSince[$scheduleId][] = $g;
    }
}

function mkSched(int $id, int $companyId, string $kind, array $cfg, string $startsAt, ?string $nextDue = null): PmSchedule
{
    $s = new PmSchedule();
    $s->id = $id;
    $s->company_id = $companyId;
    $s->plan_id = 1;
    $s->frequency_kind = $kind;
    $s->frequency_config = $cfg;
    $s->starts_at = $startsAt;
    $s->next_due_at = $nextDue ?? $startsAt;
    $s->status = 'active';
    return $s;
}

function cmpCheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $ex) {
        echo "  FAIL {$label}: " . $ex->getMessage() . "\n";
        exit(1);
    }
}

echo "Phase 5.4 — compliance reporting\n";

// 1. overdueReport — days_overdue arithmetic.
$s1 = mkSched(10, 100, 'fixed_interval', ['interval_days' => 30], '2026-01-01', '2026-04-18');
$s2 = mkSched(11, 100, 'fixed_interval', ['interval_days' => 30], '2026-01-01', '2026-04-10');
$sch = new CmpFakeSchedules();
$sch->overdue = [$s1, $s2];
$svc = new PmComplianceService($sch, new CmpFakeGenerations());
cmpCheck(function () use ($svc) {
    $r = $svc->overdueReport('2026-04-23');
    if ($r['count'] !== 2) {
        throw new RuntimeException('count wrong');
    }
    $days = array_column($r['overdue'], 'days_overdue');
    sort($days);
    if ($days !== [5, 13]) {
        throw new RuntimeException('days_overdue wrong: ' . json_encode($days));
    }
}, 'overdueReport computes days_overdue');

// 2. overdueReport — company filter propagates.
$s1 = mkSched(10, 100, 'fixed_interval', ['interval_days' => 30], '2026-01-01', '2026-04-18');
$s2 = mkSched(11, 200, 'fixed_interval', ['interval_days' => 30], '2026-01-01', '2026-04-10');
$sch = new CmpFakeSchedules();
$sch->overdue = [$s1, $s2];
$svc = new PmComplianceService($sch, new CmpFakeGenerations());
cmpCheck(function () use ($svc) {
    $r = $svc->overdueReport('2026-04-23', ['company_id' => 100]);
    if ($r['count'] !== 1 || $r['overdue'][0]['schedule_id'] !== 10) {
        throw new RuntimeException('company filter not applied');
    }
}, 'overdueReport honours company filter');

// 3. scheduleCompliance fixed_interval — expected = 3 over 90 days with interval_days=30.
$sched = mkSched(1, 100, 'fixed_interval', ['interval_days' => 30], '2026-01-01');
$gens = new CmpFakeGenerations();
$gens->seed(1, 'generated', '2026-02-01');
$gens->seed(1, 'generated', '2026-03-01');
$gens->seed(1, 'failed', '2026-04-01');
$svc = new PmComplianceService(new CmpFakeSchedules(), $gens);
cmpCheck(function () use ($svc, $sched) {
    $r = $svc->scheduleCompliance($sched, '2026-01-25', '2026-04-24');
    if ($r['expected'] !== 3) {
        throw new RuntimeException('expected should be 3, got ' . ($r['expected'] ?? 'null'));
    }
    if ($r['generated'] !== 2 || $r['failed'] !== 1) {
        throw new RuntimeException('counts wrong');
    }
    if (abs(($r['compliance_rate'] ?? 0) - (2 / 3)) > 0.0001) {
        throw new RuntimeException('rate should be ~0.667');
    }
}, 'fixed_interval compliance over 90-day window');

// 4. scheduleCompliance fixed_interval — 100% rate capped.
$sched = mkSched(1, 100, 'fixed_interval', ['interval_days' => 30], '2026-01-01');
$gens = new CmpFakeGenerations();
for ($i = 0; $i < 5; $i++) {
    $gens->seed(1, 'generated', '2026-01-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT));
}
$svc = new PmComplianceService(new CmpFakeSchedules(), $gens);
cmpCheck(function () use ($svc, $sched) {
    $r = $svc->scheduleCompliance($sched, '2026-01-01', '2026-03-31');
    if (($r['compliance_rate'] ?? 0) !== 1.0) {
        throw new RuntimeException('rate should cap at 1.0');
    }
}, 'compliance_rate caps at 1.0');

// 5. meter kind returns null rate + event_driven reason.
$sched = mkSched(1, 100, 'meter', ['interval_units' => 250, 'baseline_reading' => 1000], '2026-01-01');
$svc = new PmComplianceService(new CmpFakeSchedules(), new CmpFakeGenerations());
cmpCheck(function () use ($svc, $sched) {
    $r = $svc->scheduleCompliance($sched, '2026-01-01', '2026-04-23');
    if ($r['expected'] !== null || $r['compliance_rate'] !== null) {
        throw new RuntimeException('meter should have null expected and rate');
    }
    if ($r['reason'] !== 'event_driven') {
        throw new RuntimeException('meter reason should be event_driven');
    }
}, 'meter kind returns event_driven null rate');

// 6. condition kind same treatment.
$sched = mkSched(1, 100, 'condition', ['trigger' => 'manual'], '2026-01-01');
$svc = new PmComplianceService(new CmpFakeSchedules(), new CmpFakeGenerations());
cmpCheck(function () use ($svc, $sched) {
    $r = $svc->scheduleCompliance($sched, '2026-01-01', '2026-04-23');
    if ($r['reason'] !== 'event_driven' || $r['compliance_rate'] !== null) {
        throw new RuntimeException('condition should be event_driven');
    }
}, 'condition kind returns event_driven null rate');

// 7. calendar monthly — expected ticks walks forward.
$sched = mkSched(1, 100, 'calendar', ['day_of_month' => 1], '2026-01-01');
$gens = new CmpFakeGenerations();
$gens->seed(1, 'generated', '2026-02-01');
$gens->seed(1, 'generated', '2026-03-01');
$svc = new PmComplianceService(new CmpFakeSchedules(), $gens);
cmpCheck(function () use ($svc, $sched) {
    $r = $svc->scheduleCompliance($sched, '2026-02-01', '2026-04-23');
    // walks from starts_at 2026-01-01: first tick lands Feb 1, then Mar 1, Apr 1.
    // all three in [Feb 1, Apr 23], so expected=3.
    if ($r['expected'] !== 3) {
        throw new RuntimeException('calendar expected should be 3, got ' . ($r['expected'] ?? 'null'));
    }
    if ($r['generated'] !== 2) {
        throw new RuntimeException('generated should be 2');
    }
}, 'calendar monthly expected ticks');

// 8. Zero-interval config → expected 0 → rate 1.0 by convention.
$sched = mkSched(1, 100, 'fixed_interval', ['interval_days' => 0], '2026-01-01');
$svc = new PmComplianceService(new CmpFakeSchedules(), new CmpFakeGenerations());
cmpCheck(function () use ($svc, $sched) {
    $r = $svc->scheduleCompliance($sched, '2026-01-01', '2026-04-23');
    if ($r['expected'] !== 0 || $r['compliance_rate'] !== 1.0) {
        throw new RuntimeException('zero-interval should yield expected=0, rate=1.0');
    }
}, 'zero-interval → rate 1.0 by convention');

// 9. companyCompliance — weighted rollup.
$a = mkSched(1, 100, 'fixed_interval', ['interval_days' => 30], '2026-01-01');
$b = mkSched(2, 100, 'fixed_interval', ['interval_days' => 7], '2026-01-01');
$meter = mkSched(3, 100, 'meter', ['interval_units' => 100, 'baseline_reading' => 0], '2026-01-01');
$sch = new CmpFakeSchedules();
$sch->byCompany[100] = [$a, $b, $meter];
$gens = new CmpFakeGenerations();
// a: expected 3, generated 2
$gens->seed(1, 'generated', '2026-02-01');
$gens->seed(1, 'generated', '2026-03-01');
// b: expected ~12 (90/7), generated 10
for ($i = 0; $i < 10; $i++) {
    $gens->seed(2, 'generated', '2026-02-' . str_pad((string) ($i + 1), 2, '0', STR_PAD_LEFT));
}
// meter contributes nothing to expected
$gens->seed(3, 'generated', '2026-03-01');
$svc = new PmComplianceService($sch, $gens);
cmpCheck(function () use ($svc) {
    $r = $svc->companyCompliance(100, '2026-01-25', '2026-04-24');
    if ($r['schedule_count'] !== 3) {
        throw new RuntimeException('schedule_count should be 3');
    }
    // expected = 3 (a) + 12 (b, 90 days / 7 = 12) = 15
    if ($r['expected'] !== 15) {
        throw new RuntimeException('expected total should be 15, got ' . $r['expected']);
    }
    // generated = 2 (a) + 10 (b) + 1 (meter) = 13 (meter still counts in totals)
    if ($r['generated'] !== 13) {
        throw new RuntimeException('generated total should be 13, got ' . $r['generated']);
    }
    // rate = min(1, 13/15) = 0.8666...
    if (abs(($r['compliance_rate'] ?? 0) - (13 / 15)) > 0.0001) {
        throw new RuntimeException('weighted rate wrong');
    }
    if (count($r['per_schedule']) !== 3) {
        throw new RuntimeException('per_schedule missing rows');
    }
}, 'companyCompliance weighted rollup');

// 10. companyCompliance — no schedules → null rate.
$sch = new CmpFakeSchedules();
$sch->byCompany[999] = [];
$svc = new PmComplianceService($sch, new CmpFakeGenerations());
cmpCheck(function () use ($svc) {
    $r = $svc->companyCompliance(999, '2026-01-01', '2026-04-23');
    if ($r['schedule_count'] !== 0) {
        throw new RuntimeException('no schedules should report count 0');
    }
    if ($r['compliance_rate'] !== null) {
        throw new RuntimeException('no-data rate should be null');
    }
}, 'companyCompliance empty → null rate');

echo "\nALL 10 PASS\n";
