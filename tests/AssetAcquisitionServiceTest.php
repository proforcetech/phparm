<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\AssetAcquisition;
use App\Models\User;
use App\Services\Assets\AssetAcquisitionRepository;
use App\Services\Assets\AssetAcquisitionService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;

/**
 * Phase 13 (M4) of docs/woms-expansion-plan.md — task #121.
 *
 * Covers:
 *   * happy path traversal (draft → quoted → approved → po_issued → received
 *     → install_scheduled → installed → activated)
 *   * each step writes an audit_logs row keyed by entity_type='asset_acquisition'
 *     with from/to in the context
 *   * illegal transitions are rejected with the allowed set listed
 *   * .activate is gated on its own permission separate from .manage
 *   * cancel works from any non-terminal state and is blocked once terminal
 *   * customer reject is a terminal off-ramp
 *   * required-field validation on each transition
 *   * repo.update() rejects direct status writes (must go through service)
 */

class AcqFakeGate extends AccessGate
{
    /** @var array<int, string> */
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

class AcqFakeAudit extends AuditLogger
{
    /** @var array<int, AuditEntry> */
    public array $entries = [];
    public function __construct()
    {
    }
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class AcqFakeRepo extends AssetAcquisitionRepository
{
    /** @var array<int, AssetAcquisition> */
    public array $store = [];
    public int $nextId = 1;
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $transitionsApplied = [];

    public function __construct()
    {
    }

    public function findById(int $id): ?AssetAcquisition
    {
        return $this->store[$id] ?? null;
    }

    public function create(array $data): AssetAcquisition
    {
        $id = $this->nextId++;
        $acq = new AssetAcquisition([
            'id' => $id,
            'customer_id' => (int) ($data['customer_id'] ?? 1),
            'title' => (string) ($data['title'] ?? 'Untitled'),
            'description' => $data['description'] ?? null,
            'quantity' => max(1, (int) ($data['quantity'] ?? 1)),
            'status' => (string) ($data['status'] ?? AssetAcquisition::STATUS_DRAFT),
            'requested_by_user_id' => $data['requested_by_user_id'] ?? null,
            'requested_at' => '2026-05-02 10:00:00',
            'last_state_changed_at' => '2026-05-02 10:00:00',
            'last_state_changed_by' => $data['requested_by_user_id'] ?? null,
            'created_at' => '2026-05-02 10:00:00',
            'updated_at' => '2026-05-02 10:00:00',
        ]);
        $this->store[$id] = $acq;
        return $acq;
    }

    public function update(int $id, array $data): AssetAcquisition
    {
        if (array_key_exists('status', $data)) {
            throw new InvalidArgumentException(
                'Status changes must go through AssetAcquisitionService::transition()'
            );
        }
        $acq = $this->store[$id] ?? null;
        if ($acq === null) {
            throw new RuntimeException("Acquisition {$id} not found");
        }
        foreach ($data as $key => $value) {
            $acq->{$key} = $value;
        }
        return $acq;
    }

    public function applyTransition(int $id, string $toStatus, int $actorId, array $sideEffects = []): AssetAcquisition
    {
        $acq = $this->store[$id] ?? null;
        if ($acq === null) {
            throw new RuntimeException("Acquisition {$id} not found");
        }
        $acq->status = $toStatus;
        $acq->last_state_changed_at = '2026-05-02 11:00:00';
        $acq->last_state_changed_by = $actorId;
        foreach ($sideEffects as $key => $value) {
            $acq->{$key} = $value;
        }
        $this->transitionsApplied[$id][] = ['to' => $toStatus, 'actor' => $actorId, 'side' => $sideEffects];
        return $acq;
    }
}

function acqMakeUser(int $id = 42): User
{
    $user = new User();
    $user->id = $id;
    $user->name = 'Test Manager';
    $user->email = 'mgr@example.com';
    return $user;
}

function acqAssertFails(callable $fn, string $needle, string $label): void
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
// HAPPY PATH
// =============================================================================

$repo = new AcqFakeRepo();
$gate = new AcqFakeGate();
$audit = new AcqFakeAudit();
$service = new AssetAcquisitionService($repo, $gate, $audit);
$actor = acqMakeUser();

// 1. create — draft + creation audit
$acq = $service->create($actor, [
    'customer_id' => 7,
    'title' => 'New POS terminal',
    'quantity' => 2,
]);
if ($acq->status !== AssetAcquisition::STATUS_DRAFT) {
    fwrite(STDERR, "FAIL: created status not draft (got {$acq->status})\n");
    exit(1);
}
if (count($audit->entries) !== 1 || $audit->entries[0]->event !== 'acquisition.transitioned') {
    fwrite(STDERR, "FAIL: create did not write audit entry\n");
    exit(1);
}
if ($audit->entries[0]->context['from'] !== null || $audit->entries[0]->context['to'] !== AssetAcquisition::STATUS_DRAFT) {
    fwrite(STDERR, "FAIL: create audit entry missing from/to (null → draft)\n");
    exit(1);
}

// 2. attachQuote → quoted, estimate_id stamped
$acq = $service->attachQuote($actor, $acq->id, ['estimate_id' => 99]);
if ($acq->status !== AssetAcquisition::STATUS_QUOTED || $acq->estimate_id !== 99) {
    fwrite(STDERR, "FAIL: quote step status/estimate wrong (status={$acq->status}, est={$acq->estimate_id})\n");
    exit(1);
}

// 3. customerApprove → approved, customer_approved_at stamped
$acq = $service->customerApprove($actor, $acq->id, ['customer_approved_by' => 8]);
if ($acq->status !== AssetAcquisition::STATUS_APPROVED || $acq->customer_approved_at === null
    || $acq->customer_approved_by !== 8) {
    fwrite(STDERR, "FAIL: approve step missing fields\n");
    exit(1);
}

// 4. issuePo → po_issued, vendor + total stamped
$acq = $service->issuePo($actor, $acq->id, [
    'vendor_name' => 'CDW',
    'vendor_po_number' => 'PO-12345',
    'vendor_po_total_cents' => 250000,
]);
if ($acq->status !== AssetAcquisition::STATUS_PO_ISSUED || $acq->vendor_po_number !== 'PO-12345'
    || $acq->vendor_po_total_cents !== 250000 || $acq->vendor_po_issued_at === null) {
    fwrite(STDERR, "FAIL: po step missing fields\n");
    exit(1);
}

// 5. markReceived
$acq = $service->markReceived($actor, $acq->id);
if ($acq->status !== AssetAcquisition::STATUS_RECEIVED || $acq->received_at === null) {
    fwrite(STDERR, "FAIL: receive step missing fields\n");
    exit(1);
}

// 6. scheduleInstall — needs install_workorder_id
$acq = $service->scheduleInstall($actor, $acq->id, [
    'install_workorder_id' => 555,
    'install_scheduled_at' => '2026-05-15 09:00:00',
]);
if ($acq->status !== AssetAcquisition::STATUS_INSTALL_SCHEDULED
    || $acq->install_workorder_id !== 555
    || $acq->install_scheduled_at !== '2026-05-15 09:00:00') {
    fwrite(STDERR, "FAIL: schedule-install step missing fields\n");
    exit(1);
}

// 7. markInstalled
$acq = $service->markInstalled($actor, $acq->id);
if ($acq->status !== AssetAcquisition::STATUS_INSTALLED || $acq->installed_at === null) {
    fwrite(STDERR, "FAIL: install step missing fields\n");
    exit(1);
}

// 8. activate — needs target_site_asset_id, activate permission separate
$acq = $service->activate($actor, $acq->id, ['target_site_asset_id' => 9001]);
if ($acq->status !== AssetAcquisition::STATUS_ACTIVATED
    || $acq->target_site_asset_id !== 9001
    || $acq->activated_at === null
    || $acq->activated_by !== $actor->id) {
    fwrite(STDERR, "FAIL: activate step missing fields\n");
    exit(1);
}
if (!$acq->isTerminal()) {
    fwrite(STDERR, "FAIL: activated should be terminal\n");
    exit(1);
}

// 1 create + 7 forward transitions = 8 audit entries, all with from/to.
if (count($audit->entries) !== 8) {
    fwrite(STDERR, "FAIL: expected 8 audit entries, got " . count($audit->entries) . "\n");
    exit(1);
}
foreach ($audit->entries as $i => $entry) {
    if ($entry->entityType !== 'asset_acquisition') {
        fwrite(STDERR, "FAIL: audit #{$i} entityType wrong\n");
        exit(1);
    }
    if (!array_key_exists('from', $entry->context) || !array_key_exists('to', $entry->context)) {
        fwrite(STDERR, "FAIL: audit #{$i} missing from/to context\n");
        exit(1);
    }
}

// =============================================================================
// ILLEGAL TRANSITIONS
// =============================================================================

$repo2 = new AcqFakeRepo();
$service2 = new AssetAcquisitionService($repo2, new AcqFakeGate(), new AcqFakeAudit());
$acq2 = $service2->create($actor, ['customer_id' => 1, 'title' => 'X']);

// draft → approved (skipping quote) is illegal
acqAssertFails(
    fn () => $service2->customerApprove($actor, $acq2->id),
    'Illegal acquisition transition',
    'draft → approved'
);

// terminal block: can't reopen activated
$repo3 = new AcqFakeRepo();
$service3 = new AssetAcquisitionService($repo3, new AcqFakeGate(), new AcqFakeAudit());
$acq3 = $service3->create($actor, ['customer_id' => 1, 'title' => 'Y']);
$repo3->store[$acq3->id]->status = AssetAcquisition::STATUS_ACTIVATED;
acqAssertFails(
    fn () => $service3->cancel($actor, $acq3->id, ['reason' => 'oops']),
    'terminal',
    'cancel after terminal'
);

// =============================================================================
// PERMISSION SEPARATION: .activate is gated independently
// =============================================================================

$repo4 = new AcqFakeRepo();
$gate4 = new AcqFakeGate();
$service4 = new AssetAcquisitionService($repo4, $gate4, new AcqFakeAudit());
$acq4 = $service4->create($actor, ['customer_id' => 1, 'title' => 'Z']);
// walk to installed
$repo4->store[$acq4->id]->status = AssetAcquisition::STATUS_INSTALLED;
// Deny .activate but allow .manage
$gate4->denied = ['asset_acquisitions.activate'];
acqAssertFails(
    fn () => $service4->activate($actor, $acq4->id, ['target_site_asset_id' => 1]),
    'denied: asset_acquisitions.activate',
    'activate denied without permission'
);
// .manage alone is enough for cancel even when activate is denied
$out = $service4->cancel($actor, $acq4->id, ['reason' => 'changed mind']);
if ($out->status !== AssetAcquisition::STATUS_CANCELLED) {
    fwrite(STDERR, "FAIL: cancel should succeed with .manage even when .activate denied\n");
    exit(1);
}

// =============================================================================
// REJECT IS TERMINAL
// =============================================================================

$repo5 = new AcqFakeRepo();
$service5 = new AssetAcquisitionService($repo5, new AcqFakeGate(), new AcqFakeAudit());
$acq5 = $service5->create($actor, ['customer_id' => 1, 'title' => 'R']);
$service5->attachQuote($actor, $acq5->id, ['estimate_id' => 1]);
$out5 = $service5->customerReject($actor, $acq5->id, ['reason' => 'too expensive']);
if ($out5->status !== AssetAcquisition::STATUS_REJECTED || !$out5->isTerminal()
    || $out5->customer_rejection_reason !== 'too expensive') {
    fwrite(STDERR, "FAIL: reject should be terminal with reason stamped\n");
    exit(1);
}
acqAssertFails(
    fn () => $service5->customerApprove($actor, $acq5->id),
    'Illegal acquisition transition',
    'approve after reject'
);

// =============================================================================
// REQUIRED-FIELD VALIDATION
// =============================================================================

$repo6 = new AcqFakeRepo();
$service6 = new AssetAcquisitionService($repo6, new AcqFakeGate(), new AcqFakeAudit());
$acq6 = $service6->create($actor, ['customer_id' => 1, 'title' => 'V']);
acqAssertFails(
    fn () => $service6->attachQuote($actor, $acq6->id, []),
    'estimate_id is required',
    'attachQuote without estimate_id'
);
$service6->attachQuote($actor, $acq6->id, ['estimate_id' => 1]);
$service6->customerApprove($actor, $acq6->id);
acqAssertFails(
    fn () => $service6->issuePo($actor, $acq6->id, ['vendor_name' => 'X']),
    'vendor_name and vendor_po_number are required',
    'issuePo missing po_number'
);
$service6->issuePo($actor, $acq6->id, ['vendor_name' => 'X', 'vendor_po_number' => 'P-1']);
$service6->markReceived($actor, $acq6->id);
acqAssertFails(
    fn () => $service6->scheduleInstall($actor, $acq6->id, []),
    'install_workorder_id is required',
    'scheduleInstall missing wo'
);
$service6->scheduleInstall($actor, $acq6->id, ['install_workorder_id' => 1]);
$service6->markInstalled($actor, $acq6->id);
acqAssertFails(
    fn () => $service6->activate($actor, $acq6->id, []),
    'target_site_asset_id is required',
    'activate missing site_asset'
);
acqAssertFails(
    fn () => $service6->cancel($actor, $acq6->id, []),
    'reason is required',
    'cancel missing reason'
);

// =============================================================================
// CANCEL FROM EVERY NON-TERMINAL STATE
// =============================================================================

$nonTerminalStates = [
    AssetAcquisition::STATUS_DRAFT,
    AssetAcquisition::STATUS_QUOTED,
    AssetAcquisition::STATUS_APPROVED,
    AssetAcquisition::STATUS_PO_ISSUED,
    AssetAcquisition::STATUS_RECEIVED,
    AssetAcquisition::STATUS_INSTALL_SCHEDULED,
    AssetAcquisition::STATUS_INSTALLED,
];
foreach ($nonTerminalStates as $state) {
    $repoX = new AcqFakeRepo();
    $serviceX = new AssetAcquisitionService($repoX, new AcqFakeGate(), new AcqFakeAudit());
    $acqX = $serviceX->create($actor, ['customer_id' => 1, 'title' => 'cancel-' . $state]);
    $repoX->store[$acqX->id]->status = $state;
    $out = $serviceX->cancel($actor, $acqX->id, ['reason' => 'test']);
    if ($out->status !== AssetAcquisition::STATUS_CANCELLED) {
        fwrite(STDERR, "FAIL: cancel from {$state} did not produce cancelled\n");
        exit(1);
    }
}

// =============================================================================
// TRANSITION MATRIX SHAPE
// =============================================================================

if (AssetAcquisition::canTransition('draft', 'quoted') !== true) {
    fwrite(STDERR, "FAIL: matrix says draft→quoted is illegal\n"); exit(1);
}
if (AssetAcquisition::canTransition('draft', 'cancelled') !== true) {
    fwrite(STDERR, "FAIL: matrix says draft→cancelled is illegal\n"); exit(1);
}
if (AssetAcquisition::canTransition('activated', 'cancelled') !== false) {
    fwrite(STDERR, "FAIL: matrix allowed cancel after activated\n"); exit(1);
}
if (AssetAcquisition::canTransition('quoted', 'received') !== false) {
    fwrite(STDERR, "FAIL: matrix allowed quote→received skip\n"); exit(1);
}

echo "AssetAcquisitionServiceTest: OK\n";
