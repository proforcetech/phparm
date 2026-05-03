<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\AssetAcquisition;
use App\Models\AssetDecommission;
use App\Models\AssetLease;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\User;
use App\Services\Assets\AssetAcquisitionRepository;
use App\Services\Assets\AssetAcquisitionService;
use App\Services\Assets\AssetDecommissionRepository;
use App\Services\Assets\AssetLeaseRepository;
use App\Services\Customer\CustomerRepository;
use App\Services\Portal\PortalLifecycleService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 13 (#123) of docs/woms-expansion-plan.md.
 *
 * Verifies the portal-facing lease/acquisition/decommission surface:
 *   * cross-company rows are invisible (rows scoped to the signed-in
 *     portal_account.company_id only)
 *   * revoked accounts get UnauthorizedException everywhere
 *   * lease decision recorded; second attempt rejected; ineligible
 *     status rejected; portal action audited with actor_kind=portal_user
 *   * acquisition approve/reject only legal from quoted; routed through
 *     staff service so the matrix transition still fires; portal audit
 *     entry added on top
 *   * decommissions are read-only (no write methods exposed)
 *   * day-counter on serialized lease honors end_date arithmetic
 */

class PlFakeGate extends AccessGate
{
    public function __construct() {}
    public function assert(User $user, string $permission, mixed $resource = null): void {}
}

class PlFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public int $nextId = 5000;
    public function __construct() {}
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
    public function logAndGetId(AuditEntry $entry): ?int
    {
        $this->entries[] = $entry;
        return $this->nextId++;
    }
}

class PlFakeCustomers extends CustomerRepository
{
    /** @var array<int, Customer> */
    public array $store = [];
    public function __construct() {}
    public function find(int $id): ?Customer
    {
        return $this->store[$id] ?? null;
    }
    public function listIdsForCompany(int $companyId): array
    {
        $ids = [];
        foreach ($this->store as $c) {
            if ((int) ($c->company_id ?? 0) === $companyId) {
                $ids[] = $c->id;
            }
        }
        sort($ids);
        return $ids;
    }
    public function seed(int $id, int $companyId): void
    {
        $c = new Customer();
        $c->id = $id;
        $c->first_name = 'A';
        $c->last_name = 'B';
        $c->email = 'a@b.c';
        $c->phone = '555';
        $c->company_id = $companyId;
        $this->store[$id] = $c;
    }
}

class PlFakeLeaseRepo extends AssetLeaseRepository
{
    /** @var array<int, AssetLease> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function findById(int $id): ?AssetLease
    {
        return $this->store[$id] ?? null;
    }
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $cid = (int) ($filters['customer_id'] ?? 0);
        $status = $filters['status'] ?? null;
        $rows = [];
        foreach ($this->store as $l) {
            if ($cid !== 0 && (int) $l->customer_id !== $cid) {
                continue;
            }
            if ($status !== null && $l->status !== $status) {
                continue;
            }
            $rows[] = $l;
        }
        return array_slice($rows, $offset, $limit);
    }
    public function recordDecision(int $id, string $decision, int $userId, ?string $when = null): AssetLease
    {
        $row = $this->store[$id] ?? null;
        if ($row === null) {
            throw new RuntimeException("lease {$id} not found");
        }
        $row->end_of_lease_decision = $decision;
        $row->decision_made_at = $when ?? '2026-05-02 12:00:00';
        $row->decision_made_by = $userId;
        $row->status = $decision === AssetLease::DECISION_BUYOUT
            ? AssetLease::STATUS_BUYOUT_PENDING
            : AssetLease::STATUS_PENDING_RENEWAL;
        return $row;
    }
    public function seed(array $data): AssetLease
    {
        $id = $data['id'] ?? $this->nextId++;
        $row = new AssetLease(array_merge([
            'id' => $id,
            'site_asset_id' => 1,
            'lessor_name' => 'BigLeasor',
            'start_date' => '2025-05-01',
            'end_date' => '2026-08-01',
            'status' => AssetLease::STATUS_ACTIVE,
        ], $data));
        $this->store[$id] = $row;
        return $row;
    }
}

class PlFakeAcqRepo extends AssetAcquisitionRepository
{
    /** @var array<int, AssetAcquisition> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function findById(int $id): ?AssetAcquisition
    {
        return $this->store[$id] ?? null;
    }
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $cid = (int) ($filters['customer_id'] ?? 0);
        $status = $filters['status'] ?? null;
        $rows = [];
        foreach ($this->store as $a) {
            if ($cid !== 0 && $a->customer_id !== $cid) {
                continue;
            }
            if ($status !== null && $a->status !== $status) {
                continue;
            }
            $rows[] = $a;
        }
        return array_slice($rows, $offset, $limit);
    }
    public function applyTransition(int $id, string $toStatus, int $actorId, array $sideEffects = []): AssetAcquisition
    {
        $row = $this->store[$id] ?? null;
        if ($row === null) {
            throw new RuntimeException("acquisition {$id} not found");
        }
        $row->status = $toStatus;
        $row->last_state_changed_at = '2026-05-02 12:00:00';
        $row->last_state_changed_by = $actorId;
        foreach ($sideEffects as $k => $v) {
            $row->{$k} = $v;
        }
        return $row;
    }
    public function seed(array $data): AssetAcquisition
    {
        $id = $data['id'] ?? $this->nextId++;
        $row = new AssetAcquisition(array_merge([
            'id' => $id,
            'customer_id' => 1,
            'title' => 'POS terminal',
            'status' => AssetAcquisition::STATUS_QUOTED,
            'estimate_id' => 99,
        ], $data));
        $this->store[$id] = $row;
        return $row;
    }
}

class PlFakeDecRepo extends AssetDecommissionRepository
{
    /** @var array<int, AssetDecommission> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function findById(int $id): ?AssetDecommission
    {
        return $this->store[$id] ?? null;
    }
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $cid = (int) ($filters['customer_id'] ?? 0);
        $status = $filters['status'] ?? null;
        $rows = [];
        foreach ($this->store as $d) {
            if ($cid !== 0 && $d->customer_id !== $cid) {
                continue;
            }
            if ($status !== null && $d->status !== $status) {
                continue;
            }
            $rows[] = $d;
        }
        return array_slice($rows, $offset, $limit);
    }
    public function seed(array $data): AssetDecommission
    {
        $id = $data['id'] ?? $this->nextId++;
        $row = new AssetDecommission(array_merge([
            'id' => $id,
            'site_asset_id' => 1,
            'customer_id' => 1,
            'status' => AssetDecommission::STATUS_INITIATED,
        ], $data));
        $this->store[$id] = $row;
        return $row;
    }
}

function plMakePortalUser(int $id = 200): User
{
    $u = new User();
    $u->id = $id;
    $u->name = 'Portal Caller';
    $u->email = 'portal@example.com';
    return $u;
}

function plMakeAccount(int $id, int $companyId, bool $usable = true): PortalAccount
{
    $a = new PortalAccount();
    $a->id = $id;
    $a->user_id = 200;
    $a->company_id = $companyId;
    $a->is_active = $usable;
    $a->revoked_at = $usable ? null : '2026-05-01 00:00:00';
    return $a;
}

function plAssertFails(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        fwrite(STDERR, "FAIL: {$label} did not throw\n");
        exit(1);
    } catch (Throwable $e) {
        if (!str_contains($e->getMessage(), $needle)) {
            fwrite(STDERR, "FAIL: {$label} threw '{$e->getMessage()}', expected to contain '{$needle}'\n");
            exit(1);
        }
    }
}

// =============================================================================
// FIXTURES
// =============================================================================

$customers = new PlFakeCustomers();
$customers->seed(101, 7);          // company 7 owns customers 101 + 102
$customers->seed(102, 7);
$customers->seed(999, 8);          // company 8 owns 999 (cross-tenant)

$leases = new PlFakeLeaseRepo();
$leases->seed(['id' => 10, 'customer_id' => 101, 'end_date' => '2026-08-01']);
$leases->seed(['id' => 11, 'customer_id' => 102, 'status' => AssetLease::STATUS_PENDING_RENEWAL]);
$leases->seed(['id' => 99, 'customer_id' => 999]);   // cross-tenant

$acqRepo = new PlFakeAcqRepo();
$acqRepo->seed(['id' => 20, 'customer_id' => 101, 'status' => AssetAcquisition::STATUS_QUOTED]);
$acqRepo->seed(['id' => 21, 'customer_id' => 101, 'status' => AssetAcquisition::STATUS_DRAFT]);
$acqRepo->seed(['id' => 88, 'customer_id' => 999]);  // cross-tenant

$decommissions = new PlFakeDecRepo();
$decommissions->seed(['id' => 30, 'customer_id' => 101, 'status' => AssetDecommission::STATUS_INITIATED]);
$decommissions->seed(['id' => 77, 'customer_id' => 999]); // cross-tenant

$audit = new PlFakeAudit();
$gate = new PlFakeGate();
$acqService = new AssetAcquisitionService($acqRepo, $gate, $audit);

$service = new PortalLifecycleService(
    $leases,
    $acqRepo,
    $acqService,
    $decommissions,
    $customers,
    $audit,
);

$portalUser = plMakePortalUser();
$account = plMakeAccount(50, 7);
$revoked = plMakeAccount(51, 7, false);

// =============================================================================
// LEASES — list scoped to company, cross-tenant invisible
// =============================================================================

$out = $service->listLeases($account);
$leaseIds = array_column($out['data'], 'id');
sort($leaseIds);
if ($leaseIds !== [10, 11]) {
    fwrite(STDERR, "FAIL: lease list returned IDs " . json_encode($leaseIds) . " (expected [10, 11])\n");
    exit(1);
}
if ($out['total'] !== 2) {
    fwrite(STDERR, "FAIL: lease total wrong (got {$out['total']})\n");
    exit(1);
}
// status filter
$out = $service->listLeases($account, ['status' => AssetLease::STATUS_PENDING_RENEWAL]);
if (count($out['data']) !== 1 || $out['data'][0]['id'] !== 11) {
    fwrite(STDERR, "FAIL: lease status filter broken\n");
    exit(1);
}

// cross-tenant lease invisible by direct ID
plAssertFails(
    fn () => $service->getLease($account, 99),
    'does not belong',
    'cross-tenant lease access'
);

// revoked account blocked
plAssertFails(
    fn () => $service->listLeases($revoked),
    'portal_account is not usable',
    'revoked account list leases'
);

// =============================================================================
// LEASE DECISION
// =============================================================================

$out = $service->recordLeaseDecision($portalUser, $account, 10, ['decision' => 'buyout', 'note' => 'we want to keep it']);
if ($out['end_of_lease_decision'] !== 'buyout' || $out['status'] !== AssetLease::STATUS_BUYOUT_PENDING) {
    fwrite(STDERR, "FAIL: lease decision did not stamp decision/status\n");
    exit(1);
}

// audit captures portal origin
$leaseAudits = array_filter($audit->entries, static fn ($e) => $e->event === 'lease.decision_recorded');
if (count($leaseAudits) !== 1) {
    fwrite(STDERR, "FAIL: lease decision audit not written exactly once\n");
    exit(1);
}
$leaseAudit = array_values($leaseAudits)[0];
if (($leaseAudit->context['actor_kind'] ?? null) !== 'portal_user') {
    fwrite(STDERR, "FAIL: lease decision audit missing actor_kind=portal_user\n");
    exit(1);
}
if (($leaseAudit->context['portal_account_id'] ?? null) !== 50
    || ($leaseAudit->context['company_id'] ?? null) !== 7) {
    fwrite(STDERR, "FAIL: lease decision audit missing portal_account_id/company_id\n");
    exit(1);
}

// second decision rejected
plAssertFails(
    fn () => $service->recordLeaseDecision($portalUser, $account, 10, ['decision' => 'renew']),
    'already been recorded',
    'second lease decision'
);

// invalid decision rejected
plAssertFails(
    fn () => $service->recordLeaseDecision($portalUser, $account, 11, ['decision' => 'bogus']),
    'decision must be one of',
    'invalid lease decision'
);

// ineligible status rejected — terminate lease 11 first via direct mutate
$leases->store[11]->status = AssetLease::STATUS_TERMINATED;
plAssertFails(
    fn () => $service->recordLeaseDecision($portalUser, $account, 11, ['decision' => 'renew']),
    'not eligible',
    'terminated lease decision'
);

// cross-tenant decision blocked
plAssertFails(
    fn () => $service->recordLeaseDecision($portalUser, $account, 99, ['decision' => 'renew']),
    'does not belong',
    'cross-tenant lease decision'
);

// =============================================================================
// ACQUISITIONS — list + approve/reject
// =============================================================================

$out = $service->listAcquisitions($account);
$acqIds = array_column($out['data'], 'id');
sort($acqIds);
if ($acqIds !== [20, 21]) {
    fwrite(STDERR, "FAIL: acquisition list returned " . json_encode($acqIds) . "\n");
    exit(1);
}

// portal_actions surface only when status=quoted
foreach ($out['data'] as $row) {
    if ($row['status'] === AssetAcquisition::STATUS_QUOTED && $row['portal_actions'] !== ['approve', 'reject']) {
        fwrite(STDERR, "FAIL: quoted acquisition should expose approve+reject portal_actions\n");
        exit(1);
    }
    if ($row['status'] === AssetAcquisition::STATUS_DRAFT && $row['portal_actions'] !== []) {
        fwrite(STDERR, "FAIL: draft acquisition should expose no portal_actions\n");
        exit(1);
    }
}

// approve transitions through the staff service (matrix check fires)
$out = $service->approveAcquisition($portalUser, $account, 20, ['note' => 'go ahead']);
if ($out['status'] !== AssetAcquisition::STATUS_APPROVED) {
    fwrite(STDERR, "FAIL: approve did not transition to approved (got {$out['status']})\n");
    exit(1);
}
if (empty($out['customer_approved_at'])) {
    fwrite(STDERR, "FAIL: approve did not stamp customer_approved_at\n");
    exit(1);
}

// approve from non-quoted blocked
plAssertFails(
    fn () => $service->approveAcquisition($portalUser, $account, 21, []),
    "status 'draft'",
    'approve from draft'
);

// audit: should have BOTH the staff transition entry AND the portal-origin entry
$portalAcqAudits = array_filter($audit->entries, static fn ($e) => $e->event === 'acquisition.portal_approved');
$staffAcqAudits = array_filter(
    $audit->entries,
    static fn ($e) => $e->event === 'acquisition.transitioned'
        && ($e->context['to'] ?? null) === AssetAcquisition::STATUS_APPROVED
);
if (count($portalAcqAudits) !== 1) {
    fwrite(STDERR, "FAIL: portal_approved audit not written exactly once\n");
    exit(1);
}
if (count($staffAcqAudits) !== 1) {
    fwrite(STDERR, "FAIL: staff transition audit not written for portal-driven approve\n");
    exit(1);
}

// reject path on a fresh row
$acqRepo->seed(['id' => 22, 'customer_id' => 102, 'status' => AssetAcquisition::STATUS_QUOTED]);
$out = $service->rejectAcquisition($portalUser, $account, 22, ['reason' => 'too expensive']);
if ($out['status'] !== AssetAcquisition::STATUS_REJECTED || $out['customer_rejection_reason'] !== 'too expensive') {
    fwrite(STDERR, "FAIL: reject did not stamp status/reason\n");
    exit(1);
}
plAssertFails(
    fn () => $service->rejectAcquisition($portalUser, $account, 22, ['reason' => 'x']),
    "status 'rejected'",
    'reject already-rejected'
);
plAssertFails(
    fn () => $service->rejectAcquisition($portalUser, $account, 21, ['reason' => '']),
    'reason is required',
    'reject without reason'
);

// cross-tenant acquisition invisible
plAssertFails(
    fn () => $service->getAcquisition($account, 88),
    'does not belong',
    'cross-tenant acquisition'
);

// =============================================================================
// DECOMMISSIONS — read-only, scoped
// =============================================================================

$out = $service->listDecommissions($account);
if (count($out['data']) !== 1 || $out['data'][0]['id'] !== 30) {
    fwrite(STDERR, "FAIL: decommission list scoping broken\n");
    exit(1);
}
plAssertFails(
    fn () => $service->getDecommission($account, 77),
    'does not belong',
    'cross-tenant decommission'
);

// =============================================================================
// LEASE SERIALIZER — days_remaining math + decision_eligible flag
// =============================================================================

$leases->seed(['id' => 12, 'customer_id' => 101, 'end_date' => '2030-01-01']);
$out = $service->getLease($account, 12);
if (!is_int($out['days_remaining']) || $out['days_remaining'] < 1000) {
    fwrite(STDERR, "FAIL: days_remaining math wrong (got " . var_export($out['days_remaining'], true) . ")\n");
    exit(1);
}
if ($out['decision_eligible'] !== true) {
    fwrite(STDERR, "FAIL: active no-decision lease should be decision_eligible\n");
    exit(1);
}
// after recording a decision it should flip to ineligible
$service->recordLeaseDecision($portalUser, $account, 12, ['decision' => 'renew']);
$out = $service->getLease($account, 12);
if ($out['decision_eligible'] !== false) {
    fwrite(STDERR, "FAIL: lease with recorded decision should NOT be decision_eligible\n");
    exit(1);
}

echo "PortalLifecycleServiceTest: OK\n";
