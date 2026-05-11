<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Contract;
use App\Models\ContractAmendment;
use App\Models\Customer;
use App\Models\Estimate;
use App\Models\PortalAccount;
use App\Models\User;
use App\Services\Contracts\ContractAmendmentRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Customer\CustomerRepository;
use App\Services\Estimate\EstimateRepository;
use App\Services\Portal\PortalApprovalService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 6.3 of docs/expansion-plan.md — customer-portal approval center.
 *
 * Covers scope + transition + audit invariants for both estimates and
 * contracts. Key concerns:
 *   * scope via customer.company_id for estimates (the legacy estimates
 *     table has no company_id column), and contracts.company_id for
 *     contracts — both must match the portal_account;
 *   * only pending statuses can be acted on (no double-approve, no
 *     re-cancel);
 *   * approving a renewal writes ContractAmendment kind='renew';
 *     approving a fresh contract writes kind='change_scope'; rejecting
 *     either writes kind='terminate';
 *   * revoked portal_accounts cannot act even if they somehow reach the
 *     service past middleware;
 *   * audit events are emitted with portal_account_id for every action.
 */

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class ApprFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct() {}
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class ApprFakeEstimates extends EstimateRepository
{
    /** @var array<int, Estimate> */
    public array $store = [];
    public int $nextId = 1;
    /** @var array<int, array{status: string, reason: ?string}> */
    public array $statusCalls = [];
    public function __construct() {}
    public function find(int $id): ?Estimate
    {
        return $this->store[$id] ?? null;
    }
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $out = [];
        $status = $filters['status'] ?? null;
        foreach ($this->store as $e) {
            if ($status !== null && $e->status !== $status) {
                continue;
            }
            $out[] = $e;
        }
        return $out;
    }
    public function updateStatus(int $id, string $status, ?int $actorId = null, ?string $reason = null): ?Estimate
    {
        $this->statusCalls[] = ['id' => $id, 'status' => $status, 'reason' => $reason];
        if (!isset($this->store[$id])) {
            return null;
        }
        $this->store[$id]->status = $status;
        return $this->store[$id];
    }
    public function seed(array $row): Estimate
    {
        $e = new Estimate();
        $e->id = $row['id'] ?? $this->nextId++;
        $e->number = $row['number'] ?? ('EST-' . $e->id);
        $e->customer_id = (int) ($row['customer_id'] ?? 0);
        $e->vehicle_id = (int) ($row['vehicle_id'] ?? 0);
        $e->status = $row['status'] ?? 'pending';
        $e->estimate_type = $row['estimate_type'] ?? 'standard';
        $e->grand_total = (float) ($row['grand_total'] ?? 0);
        $this->store[$e->id] = $e;
        return $e;
    }
}

class ApprFakeCustomers extends CustomerRepository
{
    /** @var array<int, Customer> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function find(int $id): ?Customer
    {
        return $this->store[$id] ?? null;
    }
    public function listIdsForCompany(int $companyId): array
    {
        $out = [];
        foreach ($this->store as $c) {
            if ((int) ($c->company_id ?? 0) === $companyId) {
                $out[] = (int) $c->id;
            }
        }
        return $out;
    }
    public function seed(array $row): Customer
    {
        $c = new Customer();
        $c->id = $row['id'] ?? $this->nextId++;
        $c->first_name = $row['first_name'] ?? 'A';
        $c->last_name = $row['last_name'] ?? 'B';
        $c->email = $row['email'] ?? 'x@y.z';
        $c->phone = $row['phone'] ?? '555';
        $c->company_id = $row['company_id'] ?? null;
        $this->store[$c->id] = $c;
        return $c;
    }
}

class ApprFakeContracts extends ContractRepository
{
    /** @var array<int, Contract> */
    public array $store = [];
    public int $nextId = 1;
    /** @var array<int, array{id: int, data: array<string, mixed>}> */
    public array $updateCalls = [];
    public function __construct() {}
    public function findById(int $id): ?Contract
    {
        return $this->store[$id] ?? null;
    }
    public function search(array $filters = []): array
    {
        $companyId = (int) ($filters['company_id'] ?? 0);
        $status = $filters['status'] ?? null;
        $out = [];
        foreach ($this->store as $c) {
            if ($companyId !== 0 && $c->company_id !== $companyId) {
                continue;
            }
            if ($status !== null && $c->status !== $status) {
                continue;
            }
            $out[] = $c;
        }
        return ['data' => $out, 'total' => count($out)];
    }
    public function update(int $id, array $data): Contract
    {
        $this->updateCalls[] = ['id' => $id, 'data' => $data];
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
    public function seed(array $row): Contract
    {
        $c = new Contract();
        $c->id = $row['id'] ?? $this->nextId++;
        $c->contract_number = $row['contract_number'] ?? ('C-' . $c->id);
        $c->company_id = (int) ($row['company_id'] ?? 0);
        $c->status = $row['status'] ?? 'draft';
        $c->renewed_from_contract_id = $row['renewed_from_contract_id'] ?? null;
        $c->title = $row['title'] ?? 'Test';
        $c->start_date = $row['start_date'] ?? '2026-01-01';
        $c->end_date = $row['end_date'] ?? '2027-01-01';
        $this->store[$c->id] = $c;
        return $c;
    }
}

class ApprFakeAmendments extends ContractAmendmentRepository
{
    /** @var array<int, ContractAmendment> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function create(array $data): ContractAmendment
    {
        $a = new ContractAmendment();
        $a->id = $this->nextId++;
        $a->contract_id = (int) $data['contract_id'];
        $a->amendment_kind = (string) $data['amendment_kind'];
        $a->effective_date = (string) $data['effective_date'];
        $a->summary = (string) $data['summary'];
        $delta = $data['delta_json'] ?? null;
        $a->delta_json = is_array($delta) ? json_encode($delta) : $delta;
        $a->created_by_user_id = $data['created_by_user_id'] ?? null;
        $this->store[$a->id] = $a;
        return $a;
    }
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function makeApprovalFixture(
    int $companyId = 10,
    bool $accountActive = true,
    ?string $revokedAt = null,
): array {
    $audit = new ApprFakeAudit();
    $estimates = new ApprFakeEstimates();
    $customers = new ApprFakeCustomers();
    $contracts = new ApprFakeContracts();
    $amendments = new ApprFakeAmendments();
    $service = new PortalApprovalService(
        $estimates, $customers, $contracts, $amendments, $audit,
    );

    $user = new User();
    $user->id = 999;

    $account = new PortalAccount();
    $account->id = 77;
    $account->user_id = 999;
    $account->company_id = $companyId;
    $account->is_active = $accountActive;
    $account->revoked_at = $revokedAt;

    return compact('service', 'estimates', 'customers', 'contracts', 'amendments', 'audit', 'user', 'account');
}

function assert_throws(callable $fn, string $exceptionClass, string $msgNeedle, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $exceptionClass)) {
            throw new RuntimeException(
                "{$label}: expected {$exceptionClass}, got " . $e::class . ' — ' . $e->getMessage()
            );
        }
        if ($msgNeedle !== '' && stripos($e->getMessage(), $msgNeedle) === false) {
            throw new RuntimeException(
                "{$label}: expected message to contain [{$msgNeedle}], got [{$e->getMessage()}]"
            );
        }
        return;
    }
    throw new RuntimeException("{$label}: expected {$exceptionClass} but nothing was thrown");
}

function pass(string $label): void
{
    echo "  ok — {$label}\n";
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

echo "PortalApprovalServiceTest\n";

// -- listPending -------------------------------------------------------------

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeEstimates $estimates */
    $estimates = $fx['estimates'];
    /** @var ApprFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];

    $customers->seed(['id' => 1, 'company_id' => 10]);
    $customers->seed(['id' => 2, 'company_id' => 11]); // different company
    $customers->seed(['id' => 3, 'company_id' => null]); // unscoped legacy

    $estimates->seed(['id' => 100, 'customer_id' => 1, 'status' => 'pending']);
    $estimates->seed(['id' => 101, 'customer_id' => 1, 'status' => 'sent']);
    $estimates->seed(['id' => 102, 'customer_id' => 2, 'status' => 'pending']); // wrong company
    $estimates->seed(['id' => 103, 'customer_id' => 3, 'status' => 'pending']); // null company
    $estimates->seed(['id' => 104, 'customer_id' => 1, 'status' => 'approved']); // not pending
    $estimates->seed(['id' => 105, 'customer_id' => 999, 'status' => 'pending']); // missing customer

    $contracts->seed(['id' => 200, 'company_id' => 10, 'status' => 'draft']);
    $contracts->seed(['id' => 201, 'company_id' => 10, 'status' => 'pending_signature']);
    $contracts->seed(['id' => 202, 'company_id' => 11, 'status' => 'draft']); // wrong company
    $contracts->seed(['id' => 203, 'company_id' => 10, 'status' => 'active']); // not pending

    $out = $fx['service']->listPending($fx['user'], $fx['account']);

    $estIds = array_map(fn($e) => $e['id'], $out['estimates']);
    sort($estIds);
    if ($estIds !== [100, 101]) {
        throw new RuntimeException('expected estimates [100,101], got ' . json_encode($estIds));
    }
    $ctrIds = array_map(fn($c) => $c['id'], $out['contracts']);
    sort($ctrIds);
    if ($ctrIds !== [200, 201]) {
        throw new RuntimeException('expected contracts [200,201], got ' . json_encode($ctrIds));
    }

    // Contract payload identifies renewals vs new
    foreach ($out['contracts'] as $c) {
        if ($c['kind'] !== 'new') {
            throw new RuntimeException('expected kind=new for non-renewal, got ' . $c['kind']);
        }
    }
    pass('listPending scopes estimates by customer.company_id and contracts by contracts.company_id');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];
    $contracts->seed([
        'id' => 300, 'company_id' => 10, 'status' => 'draft',
        'renewed_from_contract_id' => 299,
    ]);
    $out = $fx['service']->listPending($fx['user'], $fx['account']);
    if ($out['contracts'][0]['kind'] !== 'renewal') {
        throw new RuntimeException('expected kind=renewal for successor contract');
    }
    pass('serializeContract marks successor contracts as renewal');
})();

(function () {
    $fx = makeApprovalFixture(10, false); // revoked
    assert_throws(
        fn() => $fx['service']->listPending($fx['user'], $fx['account']),
        UnauthorizedException::class, 'not usable',
        'listPending with revoked account'
    );
    pass('listPending rejects revoked account');
})();

// -- approveEstimate ---------------------------------------------------------

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeEstimates $estimates */
    $estimates = $fx['estimates'];
    /** @var ApprFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var ApprFakeAudit $audit */
    $audit = $fx['audit'];

    $customers->seed(['id' => 1, 'company_id' => 10]);
    $estimates->seed(['id' => 100, 'customer_id' => 1, 'status' => 'pending']);

    $updated = $fx['service']->approveEstimate($fx['user'], $fx['account'], 100, 'looks good');
    if ($updated->status !== 'approved') {
        throw new RuntimeException('expected status=approved, got ' . $updated->status);
    }
    $call = $estimates->statusCalls[0];
    if ($call['status'] !== 'approved' || stripos($call['reason'], 'looks good') === false) {
        throw new RuntimeException('expected approved + note in reason, got ' . json_encode($call));
    }
    $ev = $audit->entries[0];
    if ($ev->event !== 'portal.approval.estimate_approved'
        || $ev->entityId !== 100
        || ($ev->context['portal_account_id'] ?? null) !== 77
    ) {
        throw new RuntimeException('audit entry malformed: ' . json_encode($ev->toArray()));
    }
    pass('approveEstimate transitions to approved and emits audit');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var ApprFakeEstimates $estimates */
    $estimates = $fx['estimates'];

    $customers->seed(['id' => 1, 'company_id' => 11]);
    $estimates->seed(['id' => 100, 'customer_id' => 1, 'status' => 'pending']);

    assert_throws(
        fn() => $fx['service']->approveEstimate($fx['user'], $fx['account'], 100),
        UnauthorizedException::class, 'different company',
        'approveEstimate on cross-company customer'
    );
    pass('approveEstimate rejects cross-company estimate');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeEstimates $estimates */
    $estimates = $fx['estimates'];
    $estimates->seed(['id' => 100, 'customer_id' => 1, 'status' => 'pending']);

    assert_throws(
        fn() => $fx['service']->approveEstimate($fx['user'], $fx['account'], 100),
        UnauthorizedException::class, 'different company',
        'approveEstimate with missing customer'
    );
    pass('approveEstimate rejects estimate whose customer is missing');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var ApprFakeEstimates $estimates */
    $estimates = $fx['estimates'];

    $customers->seed(['id' => 1, 'company_id' => 10]);
    $estimates->seed(['id' => 100, 'customer_id' => 1, 'status' => 'approved']);

    assert_throws(
        fn() => $fx['service']->approveEstimate($fx['user'], $fx['account'], 100),
        InvalidArgumentException::class, 'not awaiting approval',
        'approveEstimate double-approve'
    );
    pass('approveEstimate rejects already-approved estimate');
})();

(function () {
    $fx = makeApprovalFixture(10);
    assert_throws(
        fn() => $fx['service']->approveEstimate($fx['user'], $fx['account'], 999),
        InvalidArgumentException::class, 'not found',
        'approveEstimate unknown id'
    );
    pass('approveEstimate rejects unknown estimate id');
})();

// -- rejectEstimate ----------------------------------------------------------

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var ApprFakeEstimates $estimates */
    $estimates = $fx['estimates'];
    /** @var ApprFakeAudit $audit */
    $audit = $fx['audit'];

    $customers->seed(['id' => 1, 'company_id' => 10]);
    $estimates->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent']);

    $updated = $fx['service']->rejectEstimate($fx['user'], $fx['account'], 100, 'too expensive');
    if ($updated->status !== 'rejected') {
        throw new RuntimeException('expected status=rejected, got ' . $updated->status);
    }
    $call = $estimates->statusCalls[0];
    if (stripos($call['reason'], 'too expensive') === false) {
        throw new RuntimeException('expected reason to carry user text, got ' . $call['reason']);
    }
    $ev = $audit->entries[0];
    if ($ev->event !== 'portal.approval.estimate_rejected') {
        throw new RuntimeException('audit event = ' . $ev->event);
    }
    pass('rejectEstimate transitions + audits');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var ApprFakeEstimates $estimates */
    $estimates = $fx['estimates'];
    $customers->seed(['id' => 1, 'company_id' => 10]);
    $estimates->seed(['id' => 100, 'customer_id' => 1, 'status' => 'sent']);

    assert_throws(
        fn() => $fx['service']->rejectEstimate($fx['user'], $fx['account'], 100, '   '),
        InvalidArgumentException::class, 'reason',
        'rejectEstimate with blank reason'
    );
    pass('rejectEstimate requires non-blank reason');
})();

// -- approveContract ---------------------------------------------------------

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];
    /** @var ApprFakeAmendments $amendments */
    $amendments = $fx['amendments'];
    /** @var ApprFakeAudit $audit */
    $audit = $fx['audit'];

    $contracts->seed(['id' => 200, 'company_id' => 10, 'status' => 'draft']);
    $updated = $fx['service']->approveContract(
        $fx['user'], $fx['account'], 200, 'we accept', '1.2.3.4', 'Mozilla/5.0'
    );

    if ($updated->status !== 'active') {
        throw new RuntimeException('expected status=active, got ' . $updated->status);
    }
    $updateCall = $contracts->updateCalls[0]['data'];
    if (($updateCall['signer_ip'] ?? null) !== '1.2.3.4'
        || ($updateCall['signer_user_agent'] ?? null) !== 'Mozilla/5.0'
        || empty($updateCall['signed_at'])
    ) {
        throw new RuntimeException('signer fields missing: ' . json_encode($updateCall));
    }

    $amendment = array_values($amendments->store)[0];
    if ($amendment->amendment_kind !== 'change_scope') {
        throw new RuntimeException('expected amendment_kind=change_scope for new contract, got ' . $amendment->amendment_kind);
    }
    $ev = $audit->entries[0];
    if ($ev->event !== 'portal.approval.contract_approved'
        || ($ev->context['kind'] ?? null) !== 'new'
    ) {
        throw new RuntimeException('audit wrong: ' . json_encode($ev->toArray()));
    }
    pass('approveContract (new) transitions + writes change_scope amendment');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];
    /** @var ApprFakeAmendments $amendments */
    $amendments = $fx['amendments'];
    /** @var ApprFakeAudit $audit */
    $audit = $fx['audit'];

    $contracts->seed([
        'id' => 300, 'company_id' => 10, 'status' => 'pending_signature',
        'renewed_from_contract_id' => 200,
    ]);

    $updated = $fx['service']->approveContract($fx['user'], $fx['account'], 300);
    if ($updated->status !== 'active') {
        throw new RuntimeException('expected status=active');
    }
    $amendment = array_values($amendments->store)[0];
    if ($amendment->amendment_kind !== 'renew') {
        throw new RuntimeException('expected amendment_kind=renew for renewal, got ' . $amendment->amendment_kind);
    }
    $ev = $audit->entries[0];
    if (($ev->context['kind'] ?? null) !== 'renewal') {
        throw new RuntimeException('audit kind != renewal');
    }
    pass('approveContract (renewal) writes renew amendment and audit kind=renewal');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];
    $contracts->seed(['id' => 200, 'company_id' => 11, 'status' => 'draft']);
    assert_throws(
        fn() => $fx['service']->approveContract($fx['user'], $fx['account'], 200),
        UnauthorizedException::class, 'different company',
        'approveContract cross-company'
    );
    pass('approveContract rejects cross-company contract');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];
    $contracts->seed(['id' => 200, 'company_id' => 10, 'status' => 'active']);
    assert_throws(
        fn() => $fx['service']->approveContract($fx['user'], $fx['account'], 200),
        InvalidArgumentException::class, 'not awaiting approval',
        'approveContract on active'
    );
    pass('approveContract rejects non-pending status');
})();

(function () {
    $fx = makeApprovalFixture(10);
    assert_throws(
        fn() => $fx['service']->approveContract($fx['user'], $fx['account'], 999),
        InvalidArgumentException::class, 'not found',
        'approveContract unknown id'
    );
    pass('approveContract rejects unknown contract id');
})();

// -- rejectContract ----------------------------------------------------------

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];
    /** @var ApprFakeAmendments $amendments */
    $amendments = $fx['amendments'];
    /** @var ApprFakeAudit $audit */
    $audit = $fx['audit'];

    $contracts->seed(['id' => 200, 'company_id' => 10, 'status' => 'draft']);
    $updated = $fx['service']->rejectContract($fx['user'], $fx['account'], 200, 'scope changed');

    if ($updated->status !== 'cancelled') {
        throw new RuntimeException('expected status=cancelled, got ' . $updated->status);
    }
    $updateCall = $contracts->updateCalls[0]['data'];
    if (stripos((string) ($updateCall['cancellation_reason'] ?? ''), 'scope changed') === false) {
        throw new RuntimeException('cancellation reason missing user text');
    }
    $amendment = array_values($amendments->store)[0];
    if ($amendment->amendment_kind !== 'terminate') {
        throw new RuntimeException('expected terminate, got ' . $amendment->amendment_kind);
    }
    $ev = $audit->entries[0];
    if ($ev->event !== 'portal.approval.contract_rejected'
        || ($ev->context['reason'] ?? null) !== 'scope changed'
    ) {
        throw new RuntimeException('audit wrong: ' . json_encode($ev->toArray()));
    }
    pass('rejectContract cancels + writes terminate amendment + audits');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];
    $contracts->seed(['id' => 200, 'company_id' => 10, 'status' => 'draft']);
    assert_throws(
        fn() => $fx['service']->rejectContract($fx['user'], $fx['account'], 200, ''),
        InvalidArgumentException::class, 'reason',
        'rejectContract blank reason'
    );
    pass('rejectContract requires non-blank reason');
})();

(function () {
    $fx = makeApprovalFixture(10);
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];
    $contracts->seed(['id' => 200, 'company_id' => 10, 'status' => 'cancelled']);
    assert_throws(
        fn() => $fx['service']->rejectContract($fx['user'], $fx['account'], 200, 'nope'),
        InvalidArgumentException::class, 'not awaiting approval',
        'rejectContract on already-cancelled'
    );
    pass('rejectContract rejects non-pending status');
})();

// -- Revoked account -----------------------------------------------------------

(function () {
    $fx = makeApprovalFixture(10, true, '2026-04-01 00:00:00');
    /** @var ApprFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var ApprFakeEstimates $estimates */
    $estimates = $fx['estimates'];
    /** @var ApprFakeContracts $contracts */
    $contracts = $fx['contracts'];
    $customers->seed(['id' => 1, 'company_id' => 10]);
    $estimates->seed(['id' => 100, 'customer_id' => 1, 'status' => 'pending']);
    $contracts->seed(['id' => 200, 'company_id' => 10, 'status' => 'draft']);

    assert_throws(
        fn() => $fx['service']->approveEstimate($fx['user'], $fx['account'], 100),
        UnauthorizedException::class, 'not usable',
        'approveEstimate on revoked account'
    );
    assert_throws(
        fn() => $fx['service']->rejectEstimate($fx['user'], $fx['account'], 100, 'x'),
        UnauthorizedException::class, 'not usable',
        'rejectEstimate on revoked account'
    );
    assert_throws(
        fn() => $fx['service']->approveContract($fx['user'], $fx['account'], 200),
        UnauthorizedException::class, 'not usable',
        'approveContract on revoked account'
    );
    assert_throws(
        fn() => $fx['service']->rejectContract($fx['user'], $fx['account'], 200, 'x'),
        UnauthorizedException::class, 'not usable',
        'rejectContract on revoked account'
    );
    pass('revoked portal_account is blocked from every approval action');
})();

// -- Invalid ids -------------------------------------------------------------

(function () {
    $fx = makeApprovalFixture(10);
    assert_throws(
        fn() => $fx['service']->approveEstimate($fx['user'], $fx['account'], 0),
        InvalidArgumentException::class, 'estimate id',
        'approveEstimate id=0'
    );
    assert_throws(
        fn() => $fx['service']->approveContract($fx['user'], $fx['account'], -1),
        InvalidArgumentException::class, 'contract id',
        'approveContract id=-1'
    );
    pass('non-positive ids are rejected before any repo lookup');
})();

echo "\nAll PortalApprovalServiceTest cases passed.\n";
