<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Contract;
use App\Models\ContractConsumptionLedger;
use App\Models\ContractEntitlement;
use App\Models\User;
use App\Services\Contracts\ContractBillingService;
use App\Services\Contracts\ContractConsumptionRepository;
use App\Services\Contracts\ContractEntitlementRepository;
use App\Services\Contracts\ContractRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;

/**
 * Phase 4.4 of docs/expansion-plan.md: contract-driven billing.
 *
 * Covers coverage resolution (site scope, unlimited entitlement, bucket
 * draining order), applyConsumption ledger + quantity_used increments +
 * audit, invoice-line suggestions, and manual-adjustment guardrails.
 */

class BillFakeAccessGate extends AccessGate
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

class BillFakeAudit extends AuditLogger
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

class BillFakeContracts extends ContractRepository
{
    public array $store = [];
    public array $siteScope = []; // contract_id => [site_ids]
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function findById(int $id): ?Contract
    {
        return $this->store[$id] ?? null;
    }
    public function listActiveForSite(int $companyId, ?int $siteId, string $onDate): array
    {
        $rows = [];
        foreach ($this->store as $c) {
            if ($c->status !== 'active' || $c->company_id !== $companyId) {
                continue;
            }
            if ($c->start_date > $onDate || $c->end_date < $onDate) {
                continue;
            }
            $scoped = $this->siteScope[$c->id] ?? [];
            if ($scoped === []) {
                $rows[] = $c;
                continue;
            }
            if ($siteId !== null && in_array($siteId, $scoped, true)) {
                $rows[] = $c;
            }
        }
        return $rows;
    }
    public function addContract(array $data, array $siteIds = []): Contract
    {
        $c = new Contract();
        $c->id = $this->nextId++;
        $c->contract_number = 'C-TEST-' . $c->id;
        foreach ($data as $k => $v) {
            if (property_exists($c, $k)) {
                $c->{$k} = $v;
            }
        }
        $this->store[$c->id] = $c;
        if ($siteIds !== []) {
            $this->siteScope[$c->id] = $siteIds;
        }
        return $c;
    }
}

class BillFakeEntitlements extends ContractEntitlementRepository
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
            fn($e) => $e->contract_id === $contractId && (!$activeOnly || $e->is_active)
        ));
    }
    public function consume(int $id, float $amount): ContractEntitlement
    {
        $e = $this->store[$id];
        $e->quantity_used = (string) ((float) $e->quantity_used + $amount);
        return $e;
    }
    public function addEntitlement(array $data): ContractEntitlement
    {
        $e = new ContractEntitlement();
        $e->id = $this->nextId++;
        $e->contract_id = (int) $data['contract_id'];
        $e->entitlement_kind = $data['entitlement_kind'] ?? 'hours';
        $e->description = $data['description'] ?? '';
        $e->quantity_allowed = array_key_exists('quantity_allowed', $data)
            ? ($data['quantity_allowed'] === null ? null : (string) $data['quantity_allowed'])
            : null;
        $e->quantity_used = (string) ($data['quantity_used'] ?? '0.00');
        $e->unit_rate_cents = $data['unit_rate_cents'] ?? null;
        $e->is_active = (int) ($data['is_active'] ?? 1);
        $this->store[$e->id] = $e;
        return $e;
    }
}

class BillFakeLedger extends ContractConsumptionRepository
{
    public array $rows = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function record(array $data): ContractConsumptionLedger
    {
        $row = new ContractConsumptionLedger();
        $row->id = $this->nextId++;
        $row->contract_id = (int) $data['contract_id'];
        $row->entitlement_id = $data['entitlement_id'] ?? null;
        $row->source_type = (string) $data['source_type'];
        $row->source_id = $data['source_id'] ?? null;
        $row->entitlement_kind = (string) $data['entitlement_kind'];
        $row->amount_requested = (string) $data['amount_requested'];
        $row->amount_covered = (string) ($data['amount_covered'] ?? '0');
        $row->amount_overage = (string) ($data['amount_overage'] ?? '0');
        $row->unit_rate_cents = $data['unit_rate_cents'] ?? null;
        $row->notes = $data['notes'] ?? null;
        $row->actor_user_id = $data['actor_user_id'] ?? null;
        $row->occurred_at = $data['occurred_at'] ?? date('Y-m-d H:i:s');
        $this->rows[$row->id] = $row;
        return $row;
    }
    public function listForContract(int $contractId, int $limit = 500): array
    {
        return array_values(array_filter(
            $this->rows,
            fn($r) => $r->contract_id === $contractId
        ));
    }
}

function billEnv(): array
{
    $c = new BillFakeContracts();
    $e = new BillFakeEntitlements();
    $l = new BillFakeLedger();
    $gate = new BillFakeAccessGate();
    $audit = new BillFakeAudit();
    $service = new ContractBillingService($c, $e, $l, $gate, $audit);
    return compact('c', 'e', 'l', 'gate', 'audit', 'service');
}

function billUser(int $id = 1): User
{
    $u = new User();
    $u->id = $id;
    return $u;
}

function bcheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $e) {
        echo "  FAIL {$label}: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function bcheckFail(callable $fn, string $label, string $needle = ''): void
{
    try {
        $fn();
        echo "  FAIL {$label}: expected exception\n";
        exit(1);
    } catch (Throwable $e) {
        if ($needle !== '' && !str_contains(strtolower($e->getMessage()), strtolower($needle))) {
            echo "  FAIL {$label}: '{$e->getMessage()}' missing '{$needle}'\n";
            exit(1);
        }
        echo "  PASS {$label}\n";
    }
}

$today = '2026-04-23';

echo "Phase 4.4 — contract-driven billing\n";

// 1. No contract → entire amount is overage.
$env = billEnv();
bcheck(function () use ($env, $today) {
    $r = $env['service']->resolveCoverage(10, null, 'hours', 4.0, $today);
    if ($r['contract'] !== null || $r['entitlement'] !== null) {
        throw new RuntimeException('expected no contract match');
    }
    if ($r['amount_covered'] !== 0.0 || $r['amount_overage'] !== 4.0) {
        throw new RuntimeException('expected full overage');
    }
}, 'no contract → full overage');

// 2. Single contract with entitlement → partial coverage + overage.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->addEntitlement([
    'contract_id' => $c->id,
    'entitlement_kind' => 'hours',
    'description' => '10h labor',
    'quantity_allowed' => '10',
    'quantity_used' => '6',
    'unit_rate_cents' => 0,
]);
bcheck(function () use ($env, $today) {
    $r = $env['service']->resolveCoverage(10, null, 'hours', 6.0, $today);
    if ($r['contract'] === null || $r['entitlement'] === null) {
        throw new RuntimeException('expected match');
    }
    if (abs($r['amount_covered'] - 4.0) > 0.001) {
        throw new RuntimeException("covered: {$r['amount_covered']}");
    }
    if (abs($r['amount_overage'] - 2.0) > 0.001) {
        throw new RuntimeException("overage: {$r['amount_overage']}");
    }
}, 'partial coverage splits covered + overage');

// 3. NULL quantity_allowed entitlement = unlimited coverage.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->addEntitlement([
    'contract_id' => $c->id,
    'entitlement_kind' => 'coverage',
    'description' => 'SLA access',
    'quantity_allowed' => null,
]);
bcheck(function () use ($env, $today) {
    $r = $env['service']->resolveCoverage(10, null, 'coverage', 99.0, $today);
    if (abs($r['amount_covered'] - 99.0) > 0.001 || $r['amount_overage'] !== 0.0) {
        throw new RuntimeException('unlimited should cover full amount');
    }
}, 'unlimited entitlement covers full amount');

// 4. Entitlement kind mismatch is ignored.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->addEntitlement([
    'contract_id' => $c->id,
    'entitlement_kind' => 'visits',
    'quantity_allowed' => '10',
]);
bcheck(function () use ($env, $today) {
    $r = $env['service']->resolveCoverage(10, null, 'hours', 4.0, $today);
    if ($r['entitlement'] !== null) {
        throw new RuntimeException('kind mismatch should not match');
    }
}, 'wrong entitlement_kind filtered out');

// 5. Inactive entitlement ignored.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->addEntitlement([
    'contract_id' => $c->id,
    'entitlement_kind' => 'hours',
    'quantity_allowed' => '10',
    'is_active' => 0,
]);
bcheck(function () use ($env, $today) {
    $r = $env['service']->resolveCoverage(10, null, 'hours', 4.0, $today);
    if ($r['entitlement'] !== null) {
        throw new RuntimeException('inactive entitlement matched');
    }
}, 'inactive entitlement filtered out');

// 6. Exhausted entitlement (quantity_used >= quantity_allowed) skipped.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->addEntitlement([
    'contract_id' => $c->id,
    'entitlement_kind' => 'hours',
    'quantity_allowed' => '10', 'quantity_used' => '10',
]);
bcheck(function () use ($env, $today) {
    $r = $env['service']->resolveCoverage(10, null, 'hours', 4.0, $today);
    if ($r['entitlement'] !== null) {
        throw new RuntimeException('exhausted bucket should be skipped');
    }
}, 'exhausted entitlement skipped');

// 7. Smallest-remaining-first ranking drains finite bucket first.
$env = billEnv();
$c1 = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$c2 = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
// Big bucket: 50 remaining
$big = $env['e']->addEntitlement([
    'contract_id' => $c1->id, 'entitlement_kind' => 'hours',
    'quantity_allowed' => '50', 'quantity_used' => '0',
]);
// Small bucket: 3 remaining
$small = $env['e']->addEntitlement([
    'contract_id' => $c2->id, 'entitlement_kind' => 'hours',
    'quantity_allowed' => '5', 'quantity_used' => '2',
]);
bcheck(function () use ($env, $today, $small) {
    $r = $env['service']->resolveCoverage(10, null, 'hours', 1.0, $today);
    if (($r['entitlement']['id'] ?? null) !== $small->id) {
        throw new RuntimeException("expected smallest bucket (id {$small->id}), got " . ($r['entitlement']['id'] ?? 'null'));
    }
}, 'smallest-remaining bucket drained first');

// 8. NULL (unlimited) sorts after finite, so finite bucket drains first.
$env = billEnv();
$c1 = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$finite = $env['e']->addEntitlement([
    'contract_id' => $c1->id, 'entitlement_kind' => 'hours',
    'quantity_allowed' => '5', 'quantity_used' => '0',
]);
$unlimited = $env['e']->addEntitlement([
    'contract_id' => $c1->id, 'entitlement_kind' => 'hours',
    'quantity_allowed' => null,
]);
bcheck(function () use ($env, $today, $finite) {
    $r = $env['service']->resolveCoverage(10, null, 'hours', 1.0, $today);
    if (($r['entitlement']['id'] ?? null) !== $finite->id) {
        throw new RuntimeException('finite should outrank unlimited');
    }
}, 'finite bucket drains before unlimited');

// 9. Site-scoped contract excludes requests for other sites.
$env = billEnv();
$env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
], [501]);
bcheck(function () use ($env, $today) {
    $r = $env['service']->resolveCoverage(10, 502, 'hours', 1.0, $today);
    if ($r['contract'] !== null) {
        throw new RuntimeException('site-scoped contract leaked to wrong site');
    }
}, 'site-scoped contract excludes other site');

// 10. Wrong company_id excluded.
$env = billEnv();
$env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
bcheck(function () use ($env, $today) {
    $r = $env['service']->resolveCoverage(99, null, 'hours', 1.0, $today);
    if ($r['contract'] !== null) {
        throw new RuntimeException('wrong company matched');
    }
}, 'other company excluded');

// 11. Non-active contract (draft, expired, renewed) excluded.
$env = billEnv();
$env['c']->addContract([
    'company_id' => 10, 'status' => 'draft',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
bcheck(function () use ($env, $today) {
    $r = $env['service']->resolveCoverage(10, null, 'hours', 1.0, $today);
    if ($r['contract'] !== null) {
        throw new RuntimeException('draft contract matched');
    }
}, 'non-active contract excluded');

// 12. Invalid entitlement_kind rejected.
$env = billEnv();
bcheckFail(function () use ($env, $today) {
    $env['service']->resolveCoverage(10, null, 'bogus', 1.0, $today);
}, 'invalid kind rejected', 'unknown entitlement_kind');

// 13. Non-positive amount rejected.
$env = billEnv();
bcheckFail(function () use ($env, $today) {
    $env['service']->resolveCoverage(10, null, 'hours', 0.0, $today);
}, 'zero amount rejected', 'positive');

// 14. applyConsumption writes ledger, increments quantity_used, emits audit.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$ent = $env['e']->addEntitlement([
    'contract_id' => $c->id, 'entitlement_kind' => 'hours',
    'quantity_allowed' => '10', 'quantity_used' => '0',
    'unit_rate_cents' => 0,
]);
bcheck(function () use ($env, $today, $c, $ent) {
    $row = $env['service']->applyConsumption(
        10, null, 'hours', 3.0, 'workorder', 5551, 42, $today, 'ro notes'
    );
    if ($row->contract_id !== $c->id) {
        throw new RuntimeException('wrong contract_id');
    }
    if ($row->entitlement_id !== $ent->id) {
        throw new RuntimeException('entitlement not linked');
    }
    if ((float) $row->amount_covered !== 3.0 || (float) $row->amount_overage !== 0.0) {
        throw new RuntimeException('ledger amounts wrong');
    }
    $updated = $env['e']->findById($ent->id);
    if ((float) $updated->quantity_used !== 3.0) {
        throw new RuntimeException("quantity_used not incremented: {$updated->quantity_used}");
    }
    $audits = array_filter(
        $env['audit']->entries,
        fn($a) => $a->event === 'contract.consumption_recorded'
    );
    if (count($audits) !== 1) {
        throw new RuntimeException('expected 1 consumption audit entry');
    }
}, 'applyConsumption writes ledger + increments usage + audits');

// 15. applyConsumption when no coverage → ledger row still written, no
//     entitlement increment, no audit (contractId is null).
$env = billEnv();
bcheck(function () use ($env, $today) {
    $row = $env['service']->applyConsumption(
        10, null, 'hours', 2.0, 'workorder', 999, 1, $today
    );
    if ($row->entitlement_id !== null) {
        throw new RuntimeException('should have no entitlement');
    }
    if ($row->contract_id !== 0) {
        throw new RuntimeException('contract_id should be 0 sentinel');
    }
    if ((float) $row->amount_overage !== 2.0) {
        throw new RuntimeException('full overage expected');
    }
    if (count($env['audit']->entries) !== 0) {
        throw new RuntimeException('no audit should be emitted without contract');
    }
}, 'applyConsumption with no coverage still records ledger');

// 16. buildInvoiceLineSuggestions returns covered + overage pair.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->addEntitlement([
    'contract_id' => $c->id, 'entitlement_kind' => 'hours',
    'description' => '10h labor', 'quantity_allowed' => '10', 'quantity_used' => '0',
    'unit_rate_cents' => 0,
]);
bcheck(function () use ($env, $today) {
    $cov = $env['service']->resolveCoverage(10, null, 'hours', 14.0, $today);
    $lines = $env['service']->buildInvoiceLineSuggestions($cov, 9500, 'labor');
    if (count($lines) !== 2) {
        throw new RuntimeException('expected covered + overage lines');
    }
    if ((float) $lines[0]['quantity'] !== 10.0 || $lines[0]['unit_rate_cents'] !== 0) {
        throw new RuntimeException('covered line wrong');
    }
    if ((float) $lines[1]['quantity'] !== 4.0 || $lines[1]['unit_rate_cents'] !== 9500) {
        throw new RuntimeException('overage line wrong');
    }
}, 'invoice-line suggestions split covered + overage');

// 17. buildInvoiceLineSuggestions with no coverage → one overage line only.
$env = billEnv();
bcheck(function () use ($env, $today) {
    $cov = $env['service']->resolveCoverage(10, null, 'hours', 3.0, $today);
    $lines = $env['service']->buildInvoiceLineSuggestions($cov, 9500, 'labor');
    if (count($lines) !== 1 || (float) $lines[0]['quantity'] !== 3.0) {
        throw new RuntimeException('expected single overage line');
    }
}, 'no coverage → single overage invoice line');

// 18. recordManualAdjustment requires non-empty notes.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
bcheckFail(function () use ($env, $c) {
    $env['service']->recordManualAdjustment(
        billUser(), $c->id, 'hours', 1.0, ''
    );
}, 'manual adjustment requires notes', 'notes required');

// 19. recordManualAdjustment blocks when gate denies contracts.manage.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['gate']->denied = ['contracts.manage'];
bcheckFail(function () use ($env, $c) {
    $env['service']->recordManualAdjustment(
        billUser(), $c->id, 'hours', 1.0, 'credit back'
    );
}, 'manual adjustment gated', 'denied');

// 20. recordManualAdjustment rejects entitlement from another contract.
$env = billEnv();
$c1 = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$c2 = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$stray = $env['e']->addEntitlement([
    'contract_id' => $c2->id, 'entitlement_kind' => 'hours', 'quantity_allowed' => '10',
]);
bcheckFail(function () use ($env, $c1, $stray) {
    $env['service']->recordManualAdjustment(
        billUser(), $c1->id, 'hours', 1.0, 'credit', $stray->id
    );
}, 'cross-contract entitlement rejected', 'does not belong');

// 21. recordManualAdjustment with entitlement: increments usage + audit + ledger.
$env = billEnv();
$c = $env['c']->addContract([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$ent = $env['e']->addEntitlement([
    'contract_id' => $c->id, 'entitlement_kind' => 'hours',
    'quantity_allowed' => '10', 'quantity_used' => '0',
]);
bcheck(function () use ($env, $c, $ent) {
    $row = $env['service']->recordManualAdjustment(
        billUser(7), $c->id, 'hours', 2.5, 'crediting back mistakenly logged', $ent->id
    );
    if ($row->source_type !== 'manual') {
        throw new RuntimeException('source_type should be manual');
    }
    if ((float) $row->amount_covered !== 2.5) {
        throw new RuntimeException('covered should equal amount');
    }
    $updated = $env['e']->findById($ent->id);
    if ((float) $updated->quantity_used !== 2.5) {
        throw new RuntimeException('entitlement not consumed');
    }
    $audits = array_filter(
        $env['audit']->entries,
        fn($a) => $a->event === 'contract.manual_adjustment'
    );
    if (count($audits) !== 1) {
        throw new RuntimeException('expected 1 manual_adjustment audit');
    }
}, 'manual adjustment with entitlement writes ledger + audit');

// 22. listLedger denies when gate rejects contracts.view.
$env = billEnv();
$env['gate']->denied = ['contracts.view'];
bcheckFail(function () use ($env) {
    $env['service']->listLedger(billUser(), 1);
}, 'listLedger respects contracts.view', 'denied');

echo "Phase 4.4 — 22/22 passed\n";
