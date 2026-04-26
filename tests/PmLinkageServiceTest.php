<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\ContractConsumptionLedger;
use App\Models\PmGeneration;
use App\Models\PmSchedule;
use App\Models\User;
use App\Services\Contracts\ContractBillingService;
use App\Services\Contracts\ContractConsumptionRepository;
use App\Services\Contracts\ContractEntitlementRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Pm\PmGenerationRepository;
use App\Services\Pm\PmLinkageService;
use App\Services\Pm\PmScheduleRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;

/**
 * Phase 5.5 of docs/expansion-plan.md — contract entitlement linkage.
 *
 * Covers contract-bound completion threading through ContractBillingService
 * with sourceType=ticket, non-contract schedule still marks consumption
 * applied, idempotency guard (second call returns skipped), positive-amount
 * validation, generation-not-found + already-failed guards, and permission
 * gate on pm.manage.
 */

class LinkFakeGate extends AccessGate
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

class LinkFakeAudit extends AuditLogger
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

class LinkFakeGenerations extends PmGenerationRepository
{
    public array $store = [];
    public array $marks = [];
    public function __construct()
    {
    }
    public function findById(int $id): ?PmGeneration
    {
        return $this->store[$id] ?? null;
    }
    public function markConsumptionApplied(
        int $id,
        ?int $entitlementId,
        float $amount,
        ?int $ledgerId,
        ?string $appliedAt = null,
    ): PmGeneration {
        $g = $this->store[$id] ?? null;
        if ($g === null || $g->consumption_applied_at !== null) {
            throw new RuntimeException("already applied: {$id}");
        }
        $g->consumption_applied_at = $appliedAt ?? '2026-04-23 10:00:00';
        $g->consumption_entitlement_id = $entitlementId;
        $g->consumption_amount = $amount;
        $g->consumption_ledger_id = $ledgerId;
        $this->marks[] = [
            'id' => $id,
            'entitlement_id' => $entitlementId,
            'amount' => $amount,
            'ledger_id' => $ledgerId,
        ];
        return $g;
    }
    public function add(PmGeneration $g): void
    {
        $this->store[$g->id] = $g;
    }
}

class LinkFakeSchedules extends PmScheduleRepository
{
    public array $store = [];
    public function __construct()
    {
    }
    public function findById(int $id): ?PmSchedule
    {
        return $this->store[$id] ?? null;
    }
    public function add(PmSchedule $s): void
    {
        $this->store[$s->id] = $s;
    }
}

class LinkFakeBilling extends ContractBillingService
{
    public array $calls = [];
    public bool $simulateOverage = false;
    public int $nextLedgerId = 500;
    public ?int $nextEntitlementId = 77;
    public function __construct()
    {
    }
    public function applyConsumption(
        int $companyId,
        ?int $siteId,
        string $entitlementKind,
        float $amount,
        string $sourceType,
        ?int $sourceId,
        ?int $actorUserId = null,
        ?string $onDate = null,
        ?string $notes = null
    ): ContractConsumptionLedger {
        $this->calls[] = compact(
            'companyId', 'siteId', 'entitlementKind', 'amount',
            'sourceType', 'sourceId', 'actorUserId', 'notes'
        );
        $covered = $this->simulateOverage ? $amount / 2 : $amount;
        $overage = $this->simulateOverage ? $amount / 2 : 0.0;
        $row = new ContractConsumptionLedger();
        $row->id = $this->nextLedgerId++;
        $row->contract_id = 1;
        $row->entitlement_id = $this->nextEntitlementId;
        $row->source_type = $sourceType;
        $row->source_id = $sourceId;
        $row->entitlement_kind = $entitlementKind;
        $row->amount_requested = number_format($amount, 2, '.', '');
        $row->amount_covered = number_format($covered, 2, '.', '');
        $row->amount_overage = number_format($overage, 2, '.', '');
        return $row;
    }
}

function lkUser(): User
{
    $u = new User();
    $u->id = 7;
    $u->role = 'manager';
    return $u;
}

function lkGeneration(int $id, int $scheduleId, ?int $ticketId, string $status = 'generated'): PmGeneration
{
    $g = new PmGeneration();
    $g->id = $id;
    $g->schedule_id = $scheduleId;
    $g->plan_id = 1;
    $g->ticket_id = $ticketId;
    $g->due_at = '2026-04-20';
    $g->status = $status;
    return $g;
}

function lkSchedule(int $id, ?int $contractId, ?int $entitlementId = null): PmSchedule
{
    $s = new PmSchedule();
    $s->id = $id;
    $s->company_id = 10;
    $s->site_id = 500;
    $s->plan_id = 1;
    $s->starts_at = '2026-01-01';
    $s->contract_id = $contractId;
    $s->contract_entitlement_id = $entitlementId;
    return $s;
}

function lkEnv(): array
{
    $gens = new LinkFakeGenerations();
    $schs = new LinkFakeSchedules();
    $bill = new LinkFakeBilling();
    $gate = new LinkFakeGate();
    $audit = new LinkFakeAudit();
    $svc = new PmLinkageService($gens, $schs, $bill, $gate, $audit);
    return compact('gens', 'schs', 'bill', 'gate', 'audit', 'svc');
}

function lkCheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $ex) {
        echo "  FAIL {$label}: " . $ex->getMessage() . "\n";
        exit(1);
    }
}

function lkExpectThrow(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        echo "  FAIL {$label}: expected throw with '{$needle}'\n";
        exit(1);
    } catch (Throwable $ex) {
        if (!str_contains($ex->getMessage(), $needle)) {
            echo "  FAIL {$label}: wrong throw — '" . $ex->getMessage() . "' vs '{$needle}'\n";
            exit(1);
        }
        echo "  PASS {$label}\n";
    }
}

echo "Phase 5.5 — contract entitlement linkage\n";

// 1. Contract-bound completion threads through billing service.
$env = lkEnv();
$env['gens']->add(lkGeneration(1, 10, 2001));
$env['schs']->add(lkSchedule(10, 42, 77));
lkCheck(function () use ($env) {
    $out = $env['svc']->applyCompletion(lkUser(), 1, 'hours', 2.5, 'quarterly PM');
    if ($out['status'] !== 'applied') {
        throw new RuntimeException('status should be applied');
    }
    if (count($env['bill']->calls) !== 1) {
        throw new RuntimeException('billing should be called once');
    }
    $call = $env['bill']->calls[0];
    if ($call['companyId'] !== 10 || $call['siteId'] !== 500) {
        throw new RuntimeException('company/site not threaded to billing');
    }
    if ($call['entitlementKind'] !== 'hours' || $call['amount'] !== 2.5) {
        throw new RuntimeException('kind/amount not forwarded');
    }
    if ($call['sourceType'] !== 'ticket' || $call['sourceId'] !== 2001) {
        throw new RuntimeException('source should be ticket + ticket_id');
    }
    if ($out['entitlement_id'] !== 77 || $out['ledger_id'] === null) {
        throw new RuntimeException('entitlement/ledger not returned');
    }
    // consumption_applied_at should be stamped on the generation.
    if ($env['gens']->store[1]->consumption_applied_at === null) {
        throw new RuntimeException('generation should be marked applied');
    }
    $events = array_map(fn($e) => $e->event, $env['audit']->entries);
    if (!in_array('pm.completion_recorded', $events, true)) {
        throw new RuntimeException('completion audit missing');
    }
}, 'contract-bound completion applies consumption');

// 2. Non-contract schedule — billing is skipped but generation is marked applied.
$env = lkEnv();
$env['gens']->add(lkGeneration(2, 11, 2002));
$env['schs']->add(lkSchedule(11, null));
lkCheck(function () use ($env) {
    $out = $env['svc']->applyCompletion(lkUser(), 2, 'hours', 1.0);
    if ($out['status'] !== 'applied' || ($out['reason'] ?? null) !== 'no_contract_binding') {
        throw new RuntimeException('expected no_contract_binding applied');
    }
    if ($env['bill']->calls !== []) {
        throw new RuntimeException('billing should NOT be called for non-contract schedule');
    }
    if ($env['gens']->store[2]->consumption_applied_at === null) {
        throw new RuntimeException('generation should still be marked applied');
    }
    if ($env['gens']->store[2]->consumption_entitlement_id !== null) {
        throw new RuntimeException('no entitlement should be recorded');
    }
}, 'non-contract schedule still marks applied, skips billing');

// 3. Idempotency — second call returns skipped without re-calling billing.
$env = lkEnv();
$g = lkGeneration(3, 12, 2003);
$g->consumption_applied_at = '2026-04-22 09:00:00';
$g->consumption_entitlement_id = 99;
$g->consumption_amount = 1.5;
$g->consumption_ledger_id = 400;
$env['gens']->add($g);
$env['schs']->add(lkSchedule(12, 42, 99));
lkCheck(function () use ($env) {
    $out = $env['svc']->applyCompletion(lkUser(), 3, 'hours', 1.5);
    if ($out['status'] !== 'skipped' || ($out['reason'] ?? null) !== 'already_applied') {
        throw new RuntimeException('second call should return skipped/already_applied');
    }
    if ($env['bill']->calls !== []) {
        throw new RuntimeException('billing should not be re-invoked');
    }
    if ($out['ledger_id'] !== 400 || $out['entitlement_id'] !== 99) {
        throw new RuntimeException('should echo prior application metadata');
    }
}, 'idempotency — second call returns skipped');

// 4. Positive-amount validation.
$env = lkEnv();
$env['gens']->add(lkGeneration(4, 13, 2004));
$env['schs']->add(lkSchedule(13, 42, 77));
lkExpectThrow(
    fn() => $env['svc']->applyCompletion(lkUser(), 4, 'hours', 0.0),
    'amount must be positive',
    'zero amount rejected'
);
lkExpectThrow(
    fn() => $env['svc']->applyCompletion(lkUser(), 4, 'hours', -5.0),
    'amount must be positive',
    'negative amount rejected'
);

// 5. Missing generation throws.
$env = lkEnv();
lkExpectThrow(
    fn() => $env['svc']->applyCompletion(lkUser(), 999, 'hours', 1.0),
    'pm_generation 999 not found',
    'missing generation rejected'
);

// 6. Failed-generation (no ticket) rejected.
$env = lkEnv();
$env['gens']->add(lkGeneration(5, 14, null, 'failed'));
$env['schs']->add(lkSchedule(14, 42, 77));
lkExpectThrow(
    fn() => $env['svc']->applyCompletion(lkUser(), 5, 'hours', 1.0),
    'no ticket to bill against',
    'failed-generation cannot be completed'
);

// 7. Missing schedule (orphan generation) rejected.
$env = lkEnv();
$env['gens']->add(lkGeneration(6, 99, 2005));
// note: no schedule added
lkExpectThrow(
    fn() => $env['svc']->applyCompletion(lkUser(), 6, 'hours', 1.0),
    'pm_schedule 99 not found',
    'orphan generation rejected'
);

// 8. Permission gate — pm.manage denied blocks completion.
$env = lkEnv();
$env['gate']->denied = ['pm.manage'];
$env['gens']->add(lkGeneration(7, 15, 2007));
$env['schs']->add(lkSchedule(15, 42, 77));
lkExpectThrow(
    fn() => $env['svc']->applyCompletion(lkUser(), 7, 'hours', 1.0),
    'denied: pm.manage',
    'pm.manage gate enforced'
);

// 9. Overage split reported in return + audit.
$env = lkEnv();
$env['bill']->simulateOverage = true;
$env['gens']->add(lkGeneration(8, 16, 2008));
$env['schs']->add(lkSchedule(16, 42, 77));
lkCheck(function () use ($env) {
    $out = $env['svc']->applyCompletion(lkUser(), 8, 'hours', 4.0);
    if (abs(($out['amount_covered'] ?? 0) - 2.0) > 0.0001) {
        throw new RuntimeException('amount_covered should be 2.0');
    }
    if (abs(($out['amount_overage'] ?? 0) - 2.0) > 0.0001) {
        throw new RuntimeException('amount_overage should be 2.0');
    }
    $last = end($env['audit']->entries);
    if ($last->context['amount_overage'] !== 2.0) {
        throw new RuntimeException('overage not in audit');
    }
}, 'overage split surfaced in result + audit');

echo "\nALL 9 PASS\n";
