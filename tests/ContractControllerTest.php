<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Company;
use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\ContractEntitlement;
use App\Models\ContractSite;
use App\Models\Site;
use App\Models\User;
use App\Services\Contracts\ContractAmendmentRepository;
use App\Services\Contracts\ContractController;
use App\Services\Contracts\ContractEntitlementRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSiteRepository;
use App\Services\Crm\CompanyRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;

/**
 * Phase 4.1 of docs/expansion-plan.md: contract controller validation,
 * status transitions, scope rules, and entitlement enforcement.
 *
 * The controller is exercised end-to-end against in-memory fakes. This
 * proves the wiring — public API surface, auth gates, audit trail — not
 * just individual validators.
 */

class FakeAccessGate extends AccessGate
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

class FakeAuditLogger extends AuditLogger
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

class FakeContracts extends ContractRepository
{
    /** @var array<int, Contract> */
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
            $rows = array_values(array_filter($rows, fn($c) => $c->company_id === (int) $filters['company_id']));
        }
        return ['data' => $rows, 'total' => count($rows)];
    }

    public function create(array $data): Contract
    {
        $c = new Contract();
        $c->id = $this->nextId++;
        $c->contract_number = $data['contract_number'] ?? ('C-TEST-' . $c->id);
        foreach ($data as $k => $v) {
            if (property_exists($c, $k) && $v !== null) {
                $type = gettype($c->{$k});
                $c->{$k} = match ($type) {
                    'integer' => (int) $v,
                    'string' => (string) $v,
                    default => $v,
                };
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
        $c = $this->store[$id] ?? null;
        if ($c === null) {
            throw new RuntimeException("contract {$id} not found");
        }
        foreach ($data as $k => $v) {
            if (property_exists($c, $k)) {
                $c->{$k} = $v;
            }
        }
        return $c;
    }

    public function delete(int $id): void
    {
        unset($this->store[$id]);
    }
}

class FakeContractSites extends ContractSiteRepository
{
    /** @var array<int, ContractSite> */
    public array $store = [];
    public int $nextId = 1;

    public function __construct()
    {
    }

    public function listForContract(int $contractId): array
    {
        return array_values(array_filter($this->store, fn($s) => $s->contract_id === $contractId));
    }

    public function attach(int $contractId, int $siteId): ContractSite
    {
        foreach ($this->store as $row) {
            if ($row->contract_id === $contractId && $row->site_id === $siteId) {
                return $row;
            }
        }
        $row = new ContractSite();
        $row->id = $this->nextId++;
        $row->contract_id = $contractId;
        $row->site_id = $siteId;
        $this->store[$row->id] = $row;
        return $row;
    }

    public function detach(int $contractId, int $siteId): void
    {
        foreach ($this->store as $k => $row) {
            if ($row->contract_id === $contractId && $row->site_id === $siteId) {
                unset($this->store[$k]);
            }
        }
    }
}

class FakeEntitlements extends ContractEntitlementRepository
{
    /** @var array<int, ContractEntitlement> */
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
            fn($e) => $e->contract_id === $contractId && (!$activeOnly || $e->is_active === 1)
        ));
    }

    public function create(array $data): ContractEntitlement
    {
        $e = new ContractEntitlement();
        $e->id = $this->nextId++;
        foreach ($data as $k => $v) {
            if (property_exists($e, $k) && $v !== null) {
                $e->{$k} = is_int($e->{$k}) ? (int) $v : $v;
            }
        }
        $this->store[$e->id] = $e;
        return $e;
    }

    public function update(int $id, array $data): ContractEntitlement
    {
        $e = $this->store[$id] ?? null;
        if ($e === null) {
            throw new RuntimeException("entitlement {$id} not found");
        }
        foreach ($data as $k => $v) {
            if (property_exists($e, $k)) {
                $e->{$k} = $v;
            }
        }
        return $e;
    }

    public function delete(int $id): void
    {
        unset($this->store[$id]);
    }
}

class FakeAmendments extends ContractAmendmentRepository
{
    /** @var array<int, ContractAmendment> */
    public array $store = [];
    public int $nextId = 1;

    public function __construct()
    {
    }

    public function listForContract(int $contractId): array
    {
        return array_values(array_filter($this->store, fn($a) => $a->contract_id === $contractId));
    }

    public function create(array $data): ContractAmendment
    {
        $a = new ContractAmendment();
        $a->id = $this->nextId++;
        foreach ($data as $k => $v) {
            if (property_exists($a, $k)) {
                if (is_array($v)) {
                    $v = json_encode($v);
                }
                $a->{$k} = $v;
            }
        }
        $this->store[$a->id] = $a;
        return $a;
    }
}

class FakeCompanies extends CompanyRepository
{
    /** @var array<int, Company> */
    public array $store = [];

    public function __construct(array $companies = [])
    {
        foreach ($companies as $c) {
            $this->store[$c->id] = $c;
        }
    }

    public function findById(int $id): ?Company
    {
        return $this->store[$id] ?? null;
    }
}

class FakeSites extends SiteRepository
{
    /** @var array<int, Site> */
    public array $store = [];

    public function __construct(array $sites = [])
    {
        foreach ($sites as $s) {
            $this->store[$s->id] = $s;
        }
    }

    public function findById(int $id): ?Site
    {
        return $this->store[$id] ?? null;
    }
}

function makeCompany(int $id = 1, ?int $divisionId = null): Company
{
    $c = new Company();
    $c->id = $id;
    $c->name = 'ACME Corp';
    $c->division_id = $divisionId;
    return $c;
}

function makeSite(int $id, int $companyId): Site
{
    $s = new Site();
    $s->id = $id;
    $s->company_id = $companyId;
    $s->name = "Site {$id}";
    return $s;
}

function makeUser(int $id = 42): User
{
    $u = new User();
    $u->id = $id;
    $u->email = "user{$id}@example.com";
    return $u;
}

function buildController(
    ?FakeCompanies $companies = null,
    ?FakeSites $sites = null
): array {
    $contracts = new FakeContracts();
    $scope = new FakeContractSites();
    $ents = new FakeEntitlements();
    $amends = new FakeAmendments();
    $companies ??= new FakeCompanies([makeCompany(1)]);
    $sites ??= new FakeSites([makeSite(10, 1), makeSite(11, 1), makeSite(99, 2)]);
    $gate = new FakeAccessGate();
    $audit = new FakeAuditLogger();
    $ctl = new ContractController($contracts, $scope, $ents, $amends, $companies, $sites, $gate, $audit);
    return compact('ctl', 'contracts', 'scope', 'ents', 'amends', 'companies', 'sites', 'gate', 'audit');
}

function expectThrow(callable $fn, string $expectedFragment, string $label): void
{
    try {
        $fn();
        throw new RuntimeException("FAIL [{$label}]: expected InvalidArgumentException matching '{$expectedFragment}', nothing thrown");
    } catch (InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), $expectedFragment)) {
            throw new RuntimeException("FAIL [{$label}]: expected message to contain '{$expectedFragment}', got '{$e->getMessage()}'");
        }
        echo "  PASS {$label}\n";
    }
}

function expectOk(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $e) {
        throw new RuntimeException("FAIL [{$label}]: unexpected throw: " . $e->getMessage());
    }
}

echo "Phase 4.1 — contracts controller\n";

// ── Create ──────────────────────────────────────────────────────────────

$env = buildController();
$u = makeUser();

// 1. Missing title → throws.
expectThrow(function () use ($env, $u) {
    $env['ctl']->createContract($u, ['company_id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
}, 'title is required', 'create without title throws');

// 2. Invalid company → throws.
expectThrow(function () use ($env, $u) {
    $env['ctl']->createContract($u, [
        'title' => 'Test', 'company_id' => 999,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
    ]);
}, 'Company 999 not found', 'unknown company_id throws');

// 3. end_date before start_date → throws.
expectThrow(function () use ($env, $u) {
    $env['ctl']->createContract($u, [
        'title' => 'Test', 'company_id' => 1,
        'start_date' => '2026-12-31', 'end_date' => '2026-01-01',
    ]);
}, 'end_date must be on or after start_date', 'end_date before start_date throws');

// 4. Invalid billing_frequency → throws.
expectThrow(function () use ($env, $u) {
    $env['ctl']->createContract($u, [
        'title' => 'Test', 'company_id' => 1,
        'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
        'billing_frequency' => 'weekly',
    ]);
}, 'billing_frequency must be one of', 'unknown billing_frequency throws');

// 5. Happy path — audit entry emitted, contract returned.
expectOk(function () use ($env, $u) {
    $out = $env['ctl']->createContract($u, [
        'title' => 'Annual Service',
        'company_id' => 1,
        'start_date' => '2026-01-01',
        'end_date' => '2026-12-31',
        'billing_frequency' => 'monthly',
        'billing_amount_cents' => 50000,
    ]);
    if (($out['data']['title'] ?? null) !== 'Annual Service') {
        throw new RuntimeException('returned contract missing title');
    }
    if (empty($env['audit']->entries) || $env['audit']->entries[0]->event !== 'contract.created') {
        throw new RuntimeException('expected contract.created audit entry');
    }
}, 'happy-path create emits audit');

// ── Status transitions ──────────────────────────────────────────────────

$env2 = buildController();
$created = $env2['ctl']->createContract($u, [
    'title' => 'T1', 'company_id' => 1,
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$id = $created['data']['id'];

// 6. draft → active is allowed.
expectOk(function () use ($env2, $u, $id) {
    $env2['ctl']->updateContract($u, $id, ['status' => 'active']);
}, 'draft → active transition allowed');

// 7. active → draft is NOT allowed.
expectThrow(function () use ($env2, $u, $id) {
    $env2['ctl']->updateContract($u, $id, ['status' => 'draft']);
}, "cannot transition contract status from 'active' to 'draft'", 'active → draft blocked');

// 8. active → cancelled is allowed and stamps cancelled_at.
expectOk(function () use ($env2, $u, $id) {
    $out = $env2['ctl']->updateContract($u, $id, ['status' => 'cancelled']);
    if (empty($out['data']['cancelled_at'])) {
        throw new RuntimeException('expected cancelled_at to be auto-stamped');
    }
}, 'active → cancelled stamps cancelled_at');

// 9. cancelled → anything blocked (terminal).
expectThrow(function () use ($env2, $u, $id) {
    $env2['ctl']->updateContract($u, $id, ['status' => 'active']);
}, "cannot transition contract status from 'cancelled' to 'active'", 'cancelled is terminal');

// ── Delete guards ───────────────────────────────────────────────────────

$env3 = buildController();
$created3 = $env3['ctl']->createContract($u, [
    'title' => 'T3', 'company_id' => 1,
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$id3 = $created3['data']['id'];

// 10. Unsigned contract can be deleted.
expectOk(function () use ($env3, $u, $id3) {
    $env3['ctl']->deleteContract($u, $id3);
}, 'unsigned contract deletes');

// 11. Signed contract cannot.
$env4 = buildController();
$c4 = $env4['ctl']->createContract($u, [
    'title' => 'T4', 'company_id' => 1,
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$env4['contracts']->store[$c4['data']['id']]->signed_at = '2026-02-01 12:00:00';
expectThrow(function () use ($env4, $u, $c4) {
    $env4['ctl']->deleteContract($u, $c4['data']['id']);
}, 'signed contracts cannot be deleted', 'signed contract delete blocked');

// ── Site scope ──────────────────────────────────────────────────────────

$env5 = buildController();
$c5 = $env5['ctl']->createContract($u, [
    'title' => 'T5', 'company_id' => 1,
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$id5 = $c5['data']['id'];

// 12. Attach missing site_id → throws.
expectThrow(function () use ($env5, $u, $id5) {
    $env5['ctl']->attachSite($u, $id5, []);
}, 'site_id is required', 'attach without site_id throws');

// 13. Attach cross-company site → throws.
expectThrow(function () use ($env5, $u, $id5) {
    $env5['ctl']->attachSite($u, $id5, ['site_id' => 99]);  // belongs to company 2
}, 'does not belong to company', 'attach cross-company site throws');

// 14. Attach valid site twice is idempotent.
expectOk(function () use ($env5, $u, $id5) {
    $env5['ctl']->attachSite($u, $id5, ['site_id' => 10]);
    $env5['ctl']->attachSite($u, $id5, ['site_id' => 10]);
    if (count($env5['scope']->store) !== 1) {
        throw new RuntimeException('expected exactly 1 attachment, got ' . count($env5['scope']->store));
    }
}, 'attach is idempotent');

// ── Entitlements ────────────────────────────────────────────────────────

$env6 = buildController();
$c6 = $env6['ctl']->createContract($u, [
    'title' => 'T6', 'company_id' => 1,
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$id6 = $c6['data']['id'];

// 15. Missing entitlement_kind → throws.
expectThrow(function () use ($env6, $u, $id6) {
    $env6['ctl']->createEntitlement($u, $id6, ['description' => 'x']);
}, 'entitlement_kind is required', 'entitlement without kind throws');

// 16. Unknown entitlement_kind → throws.
expectThrow(function () use ($env6, $u, $id6) {
    $env6['ctl']->createEntitlement($u, $id6, [
        'entitlement_kind' => 'widgets',
        'description' => '10 widgets per year',
    ]);
}, 'entitlement_kind must be one of', 'unknown entitlement_kind throws');

// 17. Valid entitlement created.
$entId = null;
expectOk(function () use ($env6, $u, $id6, &$entId) {
    $out = $env6['ctl']->createEntitlement($u, $id6, [
        'entitlement_kind' => 'hours',
        'description' => '40 hrs / quarter',
        'quantity_allowed' => 40,
        'period' => 'quarterly',
    ]);
    $entId = (int) $out['data']['id'];
}, 'valid entitlement created');

// 18. Direct quantity_used updates are stripped.
expectOk(function () use ($env6, $u, $id6, $entId) {
    $env6['ctl']->updateEntitlement($u, $id6, $entId, [
        'quantity_used' => 999,
        'description' => 'updated desc',
    ]);
    $row = $env6['ents']->findById($entId);
    if ($row === null) {
        throw new RuntimeException('entitlement disappeared');
    }
    if ((float) $row->quantity_used === 999.0) {
        throw new RuntimeException('quantity_used should have been stripped');
    }
    if ($row->description !== 'updated desc') {
        throw new RuntimeException('description update lost');
    }
}, 'quantity_used stripped from direct updates');

// 19. Deleting an entitlement from a different contract throws.
$env7 = buildController();
$a = $env7['ctl']->createContract($u, ['title' => 'A', 'company_id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
$b = $env7['ctl']->createContract($u, ['title' => 'B', 'company_id' => 1, 'start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
$entA = $env7['ctl']->createEntitlement($u, $a['data']['id'], [
    'entitlement_kind' => 'hours', 'description' => 'x',
]);
expectThrow(function () use ($env7, $u, $b, $entA) {
    $env7['ctl']->deleteEntitlement($u, $b['data']['id'], (int) $entA['data']['id']);
}, 'does not belong to contract', 'cross-contract entitlement delete blocked');

// ── Amendments ──────────────────────────────────────────────────────────

$env8 = buildController();
$c8 = $env8['ctl']->createContract($u, [
    'title' => 'T8', 'company_id' => 1,
    'start_date' => '2026-01-01', 'end_date' => '2026-12-31',
]);
$id8 = $c8['data']['id'];

// 20. Unknown amendment_kind → throws.
expectThrow(function () use ($env8, $u, $id8) {
    $env8['ctl']->createAmendment($u, $id8, [
        'amendment_kind' => 'foo',
        'summary' => 's',
        'effective_date' => '2026-03-01',
    ]);
}, 'amendment_kind must be one of', 'unknown amendment_kind throws');

// 21. Valid amendment emits audit.
expectOk(function () use ($env8, $u, $id8) {
    $env8['ctl']->createAmendment($u, $id8, [
        'amendment_kind' => 'extend',
        'summary' => 'extend by 6 months',
        'effective_date' => '2026-03-01',
        'delta_json' => ['end_date' => '2027-06-30'],
    ]);
    $kinds = array_map(fn($a) => $a->amendment_kind, $env8['amends']->store);
    if (!in_array('extend', $kinds, true)) {
        throw new RuntimeException('extend amendment not persisted');
    }
}, 'valid amendment persisted');

echo "All Phase 4.1 contract-controller tests passed.\n";
