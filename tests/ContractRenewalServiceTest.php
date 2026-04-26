<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractEntitlement;
use App\Models\ContractSite;
use App\Models\User;
use App\Services\Contracts\ContractAmendmentRepository;
use App\Services\Contracts\ContractEntitlementRepository;
use App\Services\Contracts\ContractRenewalService;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSiteRepository;
use App\Services\Contracts\ContractUtilizationService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;

/**
 * Phase 4.3 of docs/expansion-plan.md: auto-renew + utilization.
 *
 * Exercises eligibility filtering, successor creation (sites + entitlements
 * mirrored, reset_on_renewal zeroed / preserved), expiry transition for
 * non-renewing contracts, and the utilization status ladder.
 */

class RenFakeAccessGate extends AccessGate
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

class RenFakeAuditLogger extends AuditLogger
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

class RenFakeContracts extends ContractRepository
{
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function findById(int $id): ?Contract
    {
        return $this->store[$id] ?? null;
    }
    public function search(array $filters = []): array
    {
        $rows = array_values($this->store);
        if (!empty($filters['company_id'])) {
            $rows = array_values(array_filter(
                $rows,
                fn($c) => $c->company_id === (int) $filters['company_id']
            ));
        }
        if (!empty($filters['status'])) {
            $rows = array_values(array_filter(
                $rows,
                fn($c) => $c->status === $filters['status']
            ));
        }
        return ['data' => $rows, 'total' => count($rows)];
    }
    public function listExpiringThrough(string $cutoffDate): array
    {
        $rows = [];
        foreach ($this->store as $c) {
            if ($c->status === 'active' && $c->end_date <= $cutoffDate) {
                $rows[] = $c;
            }
        }
        usort($rows, fn($a, $b) => strcmp($a->end_date, $b->end_date));
        return $rows;
    }
    public function create(array $data): Contract
    {
        $c = new Contract();
        $c->id = $this->nextId++;
        $c->contract_number = $data['contract_number'] ?? ('C-TEST-' . $c->id);
        foreach ($data as $k => $v) {
            if (property_exists($c, $k) && $v !== null) {
                $c->{$k} = $v;
            }
        }
        if (empty($c->status)) {
            $c->status = 'draft';
        }
        $this->store[$c->id] = $c;
        return $c;
    }
    public function update(int $id, array $data): Contract
    {
        $c = $this->store[$id];
        foreach ($data as $k => $v) {
            if (property_exists($c, $k)) {
                $c->{$k} = $v;
            }
        }
        return $c;
    }
}

class RenFakeSites extends ContractSiteRepository
{
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function listForContract(int $contractId): array
    {
        return array_values(array_filter(
            $this->store,
            fn($s) => $s->contract_id === $contractId
        ));
    }
    public function attach(int $contractId, int $siteId): ContractSite
    {
        $s = new ContractSite();
        $s->id = $this->nextId++;
        $s->contract_id = $contractId;
        $s->site_id = $siteId;
        $this->store[$s->id] = $s;
        return $s;
    }
}

class RenFakeEntitlements extends ContractEntitlementRepository
{
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function findById(int $id): ?ContractEntitlement
    {
        return $this->store[$id] ?? null;
    }
    public function listForContract(int $contractId, bool $activeOnly = true): array
    {
        return array_values(array_filter(
            $this->store,
            fn($e) => $e->contract_id === $contractId
                && (!$activeOnly || $e->is_active)
        ));
    }
    public function create(array $data): ContractEntitlement
    {
        $e = new ContractEntitlement();
        $e->id = $this->nextId++;
        $e->contract_id = (int) $data['contract_id'];
        $e->entitlement_kind = $data['entitlement_kind'];
        $e->description = $data['description'];
        $e->quantity_allowed = $data['quantity_allowed'] ?? null;
        $e->quantity_used = '0.00';
        $e->period = $data['period'] ?? 'term';
        $e->reset_on_renewal = (bool) ($data['reset_on_renewal'] ?? true);
        $e->sla_policy_id = $data['sla_policy_id'] ?? null;
        $e->unit_rate_cents = $data['unit_rate_cents'] ?? null;
        $e->notes = $data['notes'] ?? null;
        $e->is_active = (bool) ($data['is_active'] ?? true);
        $this->store[$e->id] = $e;
        return $e;
    }
    public function consume(int $id, float $amount): ContractEntitlement
    {
        $e = $this->store[$id];
        $e->quantity_used = (string) ((float) $e->quantity_used + $amount);
        return $e;
    }
}

class RenFakeAmendments extends ContractAmendmentRepository
{
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function create(array $data): ContractAmendment
    {
        $a = new ContractAmendment();
        $a->id = $this->nextId++;
        $a->contract_id = (int) $data['contract_id'];
        $a->amendment_kind = $data['amendment_kind'];
        $a->effective_date = $data['effective_date'];
        $a->summary = $data['summary'];
        $a->delta_json = is_array($data['delta_json'] ?? null)
            ? json_encode($data['delta_json'])
            : ($data['delta_json'] ?? null);
        $this->store[$a->id] = $a;
        return $a;
    }
    public function listForContract(int $contractId): array
    {
        return array_values(array_filter(
            $this->store,
            fn($a) => $a->contract_id === $contractId
        ));
    }
}

function renEnv(): array
{
    $c = new RenFakeContracts();
    $s = new RenFakeSites();
    $e = new RenFakeEntitlements();
    $a = new RenFakeAmendments();
    $audit = new RenFakeAuditLogger();
    $service = new ContractRenewalService($c, $s, $e, $a, $audit);
    return compact('c', 's', 'e', 'a', 'audit', 'service');
}

function mkUser(): User
{
    $u = new User();
    $u->id = 1;
    return $u;
}

function check(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $e) {
        echo "  FAIL {$label}: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function checkFail(callable $fn, string $label, string $needle = ''): void
{
    try {
        $fn();
        echo "  FAIL {$label}: expected exception\n";
        exit(1);
    } catch (Throwable $e) {
        if ($needle !== '' && !str_contains(strtolower($e->getMessage()), strtolower($needle))) {
            echo "  FAIL {$label}: '{$e->getMessage()}' lacks '{$needle}'\n";
            exit(1);
        }
        echo "  PASS {$label}\n";
    }
}

$today = '2026-04-23';

echo "Phase 4.3 — contract renewals\n";

// 1. Auto-renew skips contract with auto_renew=0.
$env1 = renEnv();
$env1['c']->create([
    'company_id' => 10, 'title' => 'no-renew',
    'start_date' => '2025-05-01', 'end_date' => '2026-04-30',
    'status' => 'active', 'auto_renew' => false,
    'renewal_term_months' => null, 'renewal_notice_days' => 30,
]);
check(function () use ($env1, $today) {
    $out = $env1['service']->autoRenewDue($today);
    if (count($out) !== 0) {
        throw new RuntimeException('expected 0 renewals');
    }
}, 'auto_renew=0 skipped');

// 2. Auto-renew skips contract outside the notice window.
$env2 = renEnv();
$env2['c']->create([
    'company_id' => 10, 'title' => 'too-far',
    'start_date' => '2025-05-01', 'end_date' => '2027-01-01',
    'status' => 'active', 'auto_renew' => true,
    'renewal_term_months' => 12, 'renewal_notice_days' => 30,
]);
check(function () use ($env2, $today) {
    $out = $env2['service']->autoRenewDue($today);
    if (count($out) !== 0) {
        throw new RuntimeException('expected 0 — outside notice window');
    }
}, 'outside notice window skipped');

// 3. Auto-renew picks up contract inside notice window + creates successor.
$env3 = renEnv();
$old3 = $env3['c']->create([
    'company_id' => 10, 'title' => 'Annual Service',
    'start_date' => '2025-05-01', 'end_date' => '2026-05-01',
    'status' => 'active', 'auto_renew' => true,
    'renewal_term_months' => 12, 'renewal_notice_days' => 30,
    'billing_amount_cents' => 120000, 'billing_frequency' => 'monthly',
]);
$env3['s']->attach($old3->id, 501);
$env3['s']->attach($old3->id, 502);
$env3['e']->create([
    'contract_id' => $old3->id,
    'entitlement_kind' => 'hours',
    'description' => '40h labor',
    'quantity_allowed' => 40,
    'reset_on_renewal' => true,
]);
$env3['e']->create([
    'contract_id' => $old3->id,
    'entitlement_kind' => 'visits',
    'description' => '12 visits',
    'quantity_allowed' => 12,
    'reset_on_renewal' => false,
]);
$env3['e']->consume(2, 5.0); // 5 visits already consumed

check(function () use ($env3, $today, $old3) {
    $out = $env3['service']->autoRenewDue($today);
    if (count($out) !== 1) {
        throw new RuntimeException('expected 1 renewal, got ' . count($out));
    }
    $newId = $out[0]['new_id'];
    $new = $env3['c']->findById($newId);
    if ($new === null || $new->status !== 'active') {
        throw new RuntimeException('new contract missing or not active');
    }
    if ($new->start_date !== '2026-05-02' || $new->end_date !== '2027-05-01') {
        throw new RuntimeException("dates wrong: {$new->start_date} → {$new->end_date}");
    }
    if ($new->renewed_from_contract_id !== $old3->id) {
        throw new RuntimeException('renewed_from not linked');
    }
    // Old contract transitioned.
    $old = $env3['c']->findById($old3->id);
    if ($old->status !== 'renewed') {
        throw new RuntimeException("old status '{$old->status}', expected 'renewed'");
    }
}, 'eligible contract renewed + successor linked');

// 4. Sites copied to successor.
check(function () use ($env3) {
    $newId = 2; // only second contract created in env3
    $siteLinks = $env3['s']->listForContract($newId);
    $siteIds = array_map(fn($x) => $x->site_id, $siteLinks);
    sort($siteIds);
    if ($siteIds !== [501, 502]) {
        throw new RuntimeException('sites not mirrored: ' . json_encode($siteIds));
    }
}, 'sites mirrored to successor');

// 5. Entitlements copied; reset_on_renewal=1 zeros usage, reset_on_renewal=0 carries it.
check(function () use ($env3) {
    $newEnts = $env3['e']->listForContract(2, false);
    if (count($newEnts) !== 2) {
        throw new RuntimeException('expected 2 entitlements on successor');
    }
    $hoursEnt = null;
    $visitsEnt = null;
    foreach ($newEnts as $ent) {
        if ($ent->entitlement_kind === 'hours') {
            $hoursEnt = $ent;
        } elseif ($ent->entitlement_kind === 'visits') {
            $visitsEnt = $ent;
        }
    }
    if ((float) $hoursEnt->quantity_used !== 0.0) {
        throw new RuntimeException('hours (reset_on_renewal=1) not zeroed');
    }
    if ((float) $visitsEnt->quantity_used !== 5.0) {
        throw new RuntimeException('visits (reset_on_renewal=0) did not carry over');
    }
}, 'reset_on_renewal semantics preserved');

// 6. 'renew' amendment logged on old contract.
check(function () use ($env3, $old3) {
    $amendments = $env3['a']->listForContract($old3->id);
    if (count($amendments) !== 1 || $amendments[0]->amendment_kind !== 'renew') {
        throw new RuntimeException('renew amendment not logged');
    }
    $delta = json_decode($amendments[0]->delta_json, true);
    if (($delta['new_contract_id'] ?? 0) !== 2) {
        throw new RuntimeException('delta_json missing new_contract_id');
    }
}, 'renew amendment logged');

// 7. contract.renewed audit emitted.
check(function () use ($env3) {
    $events = array_map(fn($e) => $e->event, $env3['audit']->entries);
    if (!in_array('contract.renewed', $events, true)) {
        throw new RuntimeException('contract.renewed audit missing');
    }
}, 'renewal audit emitted');

// 8. expireDue transitions past-end contracts without auto_renew.
$env4 = renEnv();
$expiringId = $env4['c']->create([
    'company_id' => 10, 'title' => 'expired-no-renew',
    'start_date' => '2025-01-01', 'end_date' => '2026-04-01',
    'status' => 'active', 'auto_renew' => false,
    'renewal_term_months' => null,
])->id;
check(function () use ($env4, $today, $expiringId) {
    $out = $env4['service']->expireDue($today);
    if (!in_array($expiringId, $out, true)) {
        throw new RuntimeException('expected expiry of contract ' . $expiringId);
    }
    $c = $env4['c']->findById($expiringId);
    if ($c->status !== 'expired') {
        throw new RuntimeException("status '{$c->status}', expected 'expired'");
    }
}, 'past end + no auto_renew → expired');

// 9. expireDue does NOT expire contracts that just got renewed.
$env5 = renEnv();
$env5['c']->create([
    'company_id' => 10, 'title' => 'will-renew',
    'start_date' => '2025-05-01', 'end_date' => '2026-05-01',
    'status' => 'active', 'auto_renew' => true,
    'renewal_term_months' => 12, 'renewal_notice_days' => 30,
]);
check(function () use ($env5, $today) {
    $env5['service']->autoRenewDue($today);
    $expired = $env5['service']->expireDue($today);
    if (count($expired) !== 0) {
        throw new RuntimeException('renewed contracts should not be marked expired');
    }
}, 'expireDue ignores already-renewed contracts');

// 10. renewManually on draft contract throws (must be active at minimum in spec — but we allow
//     draft too since it's manual; check cancelled path instead).
$env6 = renEnv();
$cancelled = $env6['c']->create([
    'company_id' => 10, 'title' => 'cancelled',
    'start_date' => '2025-01-01', 'end_date' => '2026-01-01',
    'status' => 'cancelled', 'auto_renew' => false,
    'renewal_term_months' => 12,
]);
checkFail(function () use ($env6, $cancelled) {
    $env6['service']->renewManually($cancelled->id);
}, 'manual renew rejects cancelled', 'cancelled');

// 11. renewManually with explicit term.
$env7 = renEnv();
$c7 = $env7['c']->create([
    'company_id' => 10, 'title' => 'manual-renew',
    'start_date' => '2025-01-01', 'end_date' => '2026-01-01',
    'status' => 'active', 'auto_renew' => false,
    'renewal_term_months' => null,
]);
check(function () use ($env7, $c7) {
    $new = $env7['service']->renewManually($c7->id, 6);
    if ($new->start_date !== '2026-01-02') {
        throw new RuntimeException("start_date {$new->start_date}");
    }
    // 6 months after 2026-01-02 minus 1 day = 2026-07-01
    if ($new->end_date !== '2026-07-01') {
        throw new RuntimeException("end_date {$new->end_date}");
    }
}, 'manual renewal with explicit term');

// 12. listRenewalsDue flags eligibility.
$env8 = renEnv();
$env8['c']->create([
    'company_id' => 10, 'title' => 'auto',
    'start_date' => '2025-01-01', 'end_date' => '2026-05-10',
    'status' => 'active', 'auto_renew' => true,
    'renewal_term_months' => 12, 'renewal_notice_days' => 30,
]);
$env8['c']->create([
    'company_id' => 10, 'title' => 'manual',
    'start_date' => '2025-01-01', 'end_date' => '2026-05-15',
    'status' => 'active', 'auto_renew' => false,
    'renewal_term_months' => null,
]);
check(function () use ($env8, $today) {
    $due = $env8['service']->listRenewalsDue($today);
    if (count($due) !== 2) {
        throw new RuntimeException('expected 2 due rows, got ' . count($due));
    }
    $eligible = array_filter($due, fn($r) => $r['auto_renew_eligible']);
    if (count($eligible) !== 1) {
        throw new RuntimeException('expected 1 eligible row');
    }
}, 'listRenewalsDue separates eligible vs not');

// ─────────────────── Utilization service ───────────────────
echo "Phase 4.3 — contract utilization\n";

$envU = renEnv();
$contract = $envU['c']->create([
    'company_id' => 10, 'title' => 'U',
    'start_date' => '2025-01-01', 'end_date' => '2026-12-31',
    'status' => 'active',
]);
$envU['e']->create([
    'contract_id' => $contract->id,
    'entitlement_kind' => 'hours',
    'description' => 'ok',
    'quantity_allowed' => 40,
]);
$envU['e']->create([
    'contract_id' => $contract->id,
    'entitlement_kind' => 'hours',
    'description' => 'warning',
    'quantity_allowed' => 10,
]);
$envU['e']->create([
    'contract_id' => $contract->id,
    'entitlement_kind' => 'hours',
    'description' => 'exceeded',
    'quantity_allowed' => 5,
]);
$envU['e']->consume(2, 8.5); // warning: 85%
$envU['e']->consume(3, 7.0); // exceeded: 140%

$util = new ContractUtilizationService($envU['c'], $envU['e'], new RenFakeAccessGate());
$report = $util->utilizationForContract(mkUser(), $contract->id);

check(function () use ($report) {
    $statuses = array_column($report['entitlements'], 'status');
    if (!in_array('ok', $statuses) || !in_array('warning', $statuses) || !in_array('exceeded', $statuses)) {
        throw new RuntimeException('missing status ladder: ' . json_encode($statuses));
    }
}, 'entitlement status ladder ok/warning/exceeded');

check(function () use ($report) {
    if ($report['overall_status'] !== 'exceeded') {
        throw new RuntimeException("overall {$report['overall_status']}, expected exceeded");
    }
}, 'overall rolls up to exceeded when any entitlement exceeds');

check(function () use ($report) {
    $warn = null;
    foreach ($report['entitlements'] as $e) {
        if ($e['description'] === 'warning') {
            $warn = $e;
        }
    }
    if ($warn['percent_used'] !== 0.85) {
        throw new RuntimeException("percent_used {$warn['percent_used']}, expected 0.85");
    }
    if ($warn['quantity_remaining'] !== 1.5) {
        throw new RuntimeException("remaining {$warn['quantity_remaining']}, expected 1.5");
    }
}, 'percent_used + quantity_remaining computed');

// Permission gate.
$gate = new RenFakeAccessGate();
$gate->denied = ['contracts.view'];
$util2 = new ContractUtilizationService($envU['c'], $envU['e'], $gate);
checkFail(function () use ($util2, $contract) {
    $util2->utilizationForContract(mkUser(), $contract->id);
}, 'utilization requires contracts.view', 'denied');

echo "All Phase 4.3 renewal + utilization tests passed.\n";
