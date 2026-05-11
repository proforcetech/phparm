<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Contract;
use App\Models\ContractEntitlement;
use App\Models\Ticket;
use App\Models\TicketSlaClock;
use App\Models\TicketSlaPolicy;
use App\Services\Contracts\ContractEntitlementRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSlaResolver;
use App\Services\Tickets\SlaClockService;
use App\Services\Tickets\TicketSlaClockRepository;
use App\Services\Tickets\TicketSlaPolicyRepository;

/**
 * Phase 4.5 of docs/expansion-plan.md: contract SLA tier override at ticket
 * create. Exercises the resolver in isolation (override selection across
 * competing contracts, site-scoping, inactive guards) and verifies that
 * SlaClockService.attachForTicket adopts the contract policy when available.
 */

class SlaFakeContracts extends ContractRepository
{
    public array $store = [];
    public array $siteScope = [];
    public int $nextId = 1;
    public function __construct()
    {
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
    public function add(array $data, array $siteIds = []): Contract
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

class SlaFakeEntitlements extends ContractEntitlementRepository
{
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function listForContract(int $contractId, bool $activeOnly = true): array
    {
        return array_values(array_filter(
            $this->store,
            fn($e) => $e->contract_id === $contractId && (!$activeOnly || $e->is_active)
        ));
    }
    public function add(array $data): ContractEntitlement
    {
        $e = new ContractEntitlement();
        $e->id = $this->nextId++;
        $e->contract_id = (int) $data['contract_id'];
        $e->entitlement_kind = $data['entitlement_kind'] ?? 'coverage';
        $e->description = $data['description'] ?? '';
        $e->sla_policy_id = $data['sla_policy_id'] ?? null;
        $e->is_active = (int) ($data['is_active'] ?? 1);
        $this->store[$e->id] = $e;
        return $e;
    }
}

class SlaFakePolicies extends TicketSlaPolicyRepository
{
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function findById(int $id): ?TicketSlaPolicy
    {
        return $this->store[$id] ?? null;
    }
    public function findByIds(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if (isset($this->store[$id])) {
                $out[$id] = $this->store[$id];
            }
        }
        return $out;
    }
    public function findForTicket(?int $divisionId, string $priority): ?TicketSlaPolicy
    {
        foreach ($this->store as $p) {
            if ($p->priority === $priority && $p->division_id === null && $p->is_active) {
                return $p;
            }
        }
        return null;
    }
    public function add(array $data): TicketSlaPolicy
    {
        $p = new TicketSlaPolicy();
        $p->id = $this->nextId++;
        $p->name = $data['name'] ?? 'policy';
        $p->priority = $data['priority'] ?? 'p3_normal';
        $p->response_minutes = $data['response_minutes'] ?? null;
        $p->arrival_minutes = $data['arrival_minutes'] ?? null;
        $p->resolution_minutes = $data['resolution_minutes'] ?? null;
        $p->is_active = (int) ($data['is_active'] ?? 1);
        $this->store[$p->id] = $p;
        return $p;
    }
}

class SlaFakeClocks extends TicketSlaClockRepository
{
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function create(array $data): TicketSlaClock
    {
        $c = new TicketSlaClock();
        $c->id = $this->nextId++;
        foreach ($data as $k => $v) {
            if (property_exists($c, $k)) {
                $c->{$k} = $v;
            }
        }
        $this->store[$c->id] = $c;
        return $c;
    }
    public function findForTicketAndKind(int $ticketId, string $kind): ?TicketSlaClock
    {
        foreach ($this->store as $c) {
            if ($c->ticket_id === $ticketId && $c->clock_kind === $kind) {
                return $c;
            }
        }
        return null;
    }
    public function listForTicket(int $ticketId): array
    {
        return array_values(array_filter(
            $this->store,
            fn($c) => $c->ticket_id === $ticketId
        ));
    }
    public function update(int $id, array $data): TicketSlaClock
    {
        $c = $this->store[$id];
        foreach ($data as $k => $v) {
            if (property_exists($c, $k)) {
                $c->{$k} = $v;
            }
        }
        return $c;
    }
    public function listDueForBreach(): array
    {
        return [];
    }
}

function slaEnv(): array
{
    $c = new SlaFakeContracts();
    $e = new SlaFakeEntitlements();
    $p = new SlaFakePolicies();
    $resolver = new ContractSlaResolver($c, $e, $p);
    $clocks = new SlaFakeClocks();
    $service = new SlaClockService($clocks, $p, $resolver);
    return compact('c', 'e', 'p', 'resolver', 'clocks', 'service');
}

function mkTicket(?int $companyId, ?int $siteId, string $priority = 'p3_normal'): Ticket
{
    $t = new Ticket();
    $t->id = 9001;
    $t->company_id = $companyId;
    $t->site_id = $siteId;
    $t->priority = $priority;
    $t->division_id = null;
    return $t;
}

function scheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $ex) {
        echo "  FAIL {$label}: " . $ex->getMessage() . "\n";
        exit(1);
    }
}

echo "Phase 4.5 — contract SLA tier override\n";

// 1. Ticket with no company_id → resolver returns null.
$env = slaEnv();
scheck(function () use ($env) {
    $t = mkTicket(null, null);
    if ($env['resolver']->resolveForTicket($t) !== null) {
        throw new RuntimeException('expected null when ticket has no company');
    }
}, 'null company → no override');

// 2. No covering contract → resolver returns null; default lookup used.
$env = slaEnv();
$default = $env['p']->add([
    'name' => 'default-p3', 'priority' => 'p3_normal',
    'response_minutes' => 60, 'arrival_minutes' => 240, 'resolution_minutes' => 480,
]);
scheck(function () use ($env, $default) {
    $t = mkTicket(10, 500, 'p3_normal');
    if ($env['resolver']->resolveForTicket($t) !== null) {
        throw new RuntimeException('expected no override');
    }
    $resolved = $env['service']->resolvePolicy($t);
    if ($resolved === null || $resolved['source'] !== 'default') {
        throw new RuntimeException('should fall through to default');
    }
    if ($resolved['policy']->id !== $default->id) {
        throw new RuntimeException('wrong default policy');
    }
}, 'no contract → default policy wins');

// 3. Contract entitlement with no sla_policy_id → fall through.
$env = slaEnv();
$env['p']->add([
    'name' => 'default-p3', 'priority' => 'p3_normal',
    'response_minutes' => 60, 'arrival_minutes' => 240, 'resolution_minutes' => 480,
]);
$c = $env['c']->add([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->add([
    'contract_id' => $c->id, 'entitlement_kind' => 'hours',
    'sla_policy_id' => null,
]);
scheck(function () use ($env) {
    $t = mkTicket(10, null, 'p3_normal');
    if ($env['resolver']->resolveForTicket($t) !== null) {
        throw new RuntimeException('contract entitlement with no sla_policy should not override');
    }
}, 'entitlement without sla_policy_id is skipped');

// 4. Contract entitlement with sla_policy_id → override wins.
$env = slaEnv();
$default = $env['p']->add([
    'name' => 'default-p3', 'priority' => 'p3_normal',
    'response_minutes' => 120, 'arrival_minutes' => 480, 'resolution_minutes' => 960,
]);
$premium = $env['p']->add([
    'name' => 'gold-tier', 'priority' => 'p3_normal',
    'response_minutes' => 15, 'arrival_minutes' => 60, 'resolution_minutes' => 240,
]);
$c = $env['c']->add([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$ent = $env['e']->add([
    'contract_id' => $c->id, 'entitlement_kind' => 'coverage',
    'sla_policy_id' => $premium->id,
]);
scheck(function () use ($env, $premium, $c, $ent) {
    $t = mkTicket(10, null);
    $resolved = $env['service']->resolvePolicy($t);
    if ($resolved === null || $resolved['source'] !== 'contract') {
        throw new RuntimeException('expected contract source');
    }
    if ($resolved['policy']->id !== $premium->id) {
        throw new RuntimeException('premium policy should win');
    }
    if ($resolved['contract_id'] !== $c->id || $resolved['entitlement_id'] !== $ent->id) {
        throw new RuntimeException('contract/entitlement metadata missing');
    }
}, 'contract policy overrides default');

// 5. Competing contracts → pick the tightest response_minutes.
$env = slaEnv();
$env['p']->add([
    'name' => 'default-p3', 'priority' => 'p3_normal',
    'response_minutes' => 120,
]);
$gold = $env['p']->add([
    'name' => 'gold', 'priority' => 'p3_normal',
    'response_minutes' => 30,
]);
$platinum = $env['p']->add([
    'name' => 'platinum', 'priority' => 'p3_normal',
    'response_minutes' => 10,
]);
$c1 = $env['c']->add([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$c2 = $env['c']->add([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->add([
    'contract_id' => $c1->id, 'entitlement_kind' => 'coverage',
    'sla_policy_id' => $gold->id,
]);
$env['e']->add([
    'contract_id' => $c2->id, 'entitlement_kind' => 'coverage',
    'sla_policy_id' => $platinum->id,
]);
scheck(function () use ($env, $platinum) {
    $resolved = $env['resolver']->resolveForTicket(mkTicket(10, null));
    if ($resolved === null || $resolved['policy']->id !== $platinum->id) {
        throw new RuntimeException('tightest SLA should win');
    }
}, 'tightest response_minutes wins across competing contracts');

// 6. Site-scoped contract applies only to covered sites.
$env = slaEnv();
$env['p']->add([
    'name' => 'default', 'priority' => 'p3_normal', 'response_minutes' => 120,
]);
$premium = $env['p']->add([
    'name' => 'gold', 'priority' => 'p3_normal', 'response_minutes' => 15,
]);
$c = $env['c']->add([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
], [501]);
$env['e']->add([
    'contract_id' => $c->id, 'entitlement_kind' => 'coverage',
    'sla_policy_id' => $premium->id,
]);
scheck(function () use ($env) {
    if ($env['resolver']->resolveForTicket(mkTicket(10, 502)) !== null) {
        throw new RuntimeException('site 502 should not match site-scoped contract');
    }
}, 'site-scoped contract excludes uncovered site');

scheck(function () use ($env, $premium) {
    $r = $env['resolver']->resolveForTicket(mkTicket(10, 501));
    if ($r === null || $r['policy']->id !== $premium->id) {
        throw new RuntimeException('site 501 should match');
    }
}, 'site-scoped contract applies to covered site');

// 7. Inactive entitlement filtered out.
$env = slaEnv();
$premium = $env['p']->add([
    'name' => 'gold', 'priority' => 'p3_normal', 'response_minutes' => 15,
]);
$c = $env['c']->add([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->add([
    'contract_id' => $c->id, 'entitlement_kind' => 'coverage',
    'sla_policy_id' => $premium->id, 'is_active' => 0,
]);
scheck(function () use ($env) {
    if ($env['resolver']->resolveForTicket(mkTicket(10, null)) !== null) {
        throw new RuntimeException('inactive entitlement should not apply');
    }
}, 'inactive entitlement filtered');

// 8. Inactive policy falls through.
$env = slaEnv();
$inactive = $env['p']->add([
    'name' => 'old-gold', 'priority' => 'p3_normal',
    'response_minutes' => 15, 'is_active' => 0,
]);
$c = $env['c']->add([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->add([
    'contract_id' => $c->id, 'entitlement_kind' => 'coverage',
    'sla_policy_id' => $inactive->id,
]);
scheck(function () use ($env) {
    if ($env['resolver']->resolveForTicket(mkTicket(10, null)) !== null) {
        throw new RuntimeException('inactive policy should not override');
    }
}, 'inactive policy filtered');

// 9. Non-active contract excluded.
$env = slaEnv();
$premium = $env['p']->add([
    'name' => 'gold', 'priority' => 'p3_normal', 'response_minutes' => 15,
]);
$c = $env['c']->add([
    'company_id' => 10, 'status' => 'draft',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->add([
    'contract_id' => $c->id, 'entitlement_kind' => 'coverage',
    'sla_policy_id' => $premium->id,
]);
scheck(function () use ($env) {
    if ($env['resolver']->resolveForTicket(mkTicket(10, null)) !== null) {
        throw new RuntimeException('draft contract should not override SLA');
    }
}, 'non-active contract filtered');

// 10. attachForTicket mints clocks using the contract policy's targets.
$env = slaEnv();
$env['p']->add([
    'name' => 'default-p3', 'priority' => 'p3_normal',
    'response_minutes' => 600, 'arrival_minutes' => 1200, 'resolution_minutes' => 2400,
]);
$premium = $env['p']->add([
    'name' => 'gold', 'priority' => 'p3_normal',
    'response_minutes' => 15, 'arrival_minutes' => 60, 'resolution_minutes' => 240,
]);
$c = $env['c']->add([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env['e']->add([
    'contract_id' => $c->id, 'entitlement_kind' => 'coverage',
    'sla_policy_id' => $premium->id,
]);
scheck(function () use ($env, $premium) {
    $t = mkTicket(10, null);
    $clocks = $env['service']->attachForTicket($t);
    if (count($clocks) !== 3) {
        throw new RuntimeException('expected 3 clocks, got ' . count($clocks));
    }
    foreach ($clocks as $clock) {
        if ($clock->policy_id !== $premium->id) {
            throw new RuntimeException('clock linked to wrong policy');
        }
    }
    $byKind = [];
    foreach ($clocks as $c2) {
        $byKind[$c2->clock_kind] = $c2->target_minutes;
    }
    if ($byKind['response'] !== 15 || $byKind['arrival'] !== 60 || $byKind['resolution'] !== 240) {
        throw new RuntimeException('clock targets did not come from contract policy');
    }
}, 'attachForTicket uses contract policy targets');

// 11. attachForTicket with no resolver (backwards-compat) uses default lookup.
$env = slaEnv();
$default = $env['p']->add([
    'name' => 'default-p3', 'priority' => 'p3_normal',
    'response_minutes' => 45,
]);
$service = new SlaClockService($env['clocks'], $env['p'], null);
scheck(function () use ($service, $default) {
    $t = mkTicket(10, null);
    $resolved = $service->resolvePolicy($t);
    if ($resolved === null || $resolved['source'] !== 'default') {
        throw new RuntimeException('no resolver → default expected');
    }
    if ($resolved['policy']->id !== $default->id) {
        throw new RuntimeException('default policy not chosen');
    }
}, 'null resolver → default lookup still works');

// 12. No policy at all → null.
$env = slaEnv();
$c = $env['c']->add([
    'company_id' => 10, 'status' => 'active',
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
scheck(function () use ($env) {
    $t = mkTicket(10, null);
    if ($env['service']->resolvePolicy($t) !== null) {
        throw new RuntimeException('no default + no contract → should be null');
    }
    if (count($env['service']->attachForTicket($t)) !== 0) {
        throw new RuntimeException('no policy → no clocks');
    }
}, 'no policy → no clocks minted');

echo "Phase 4.5 — 13/13 passed\n";
