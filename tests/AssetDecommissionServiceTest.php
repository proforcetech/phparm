<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\AssetDecommission;
use App\Models\SiteAsset;
use App\Models\User;
use App\Services\Assets\AssetDecommissionRepository;
use App\Services\Assets\AssetDecommissionService;
use App\Services\Assets\SiteAssetRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;

/**
 * Phase 13 (M5) of docs/woms-expansion-plan.md — task #122.
 *
 * Covers:
 *   * happy path traversal w/ wipe (initiated → wipe_in_progress → wipe_complete
 *     → recovery_in_progress → recovery_complete → entitlement_updated
 *     → audited → retired)
 *   * happy path WITHOUT wipe (initiated → recovery_in_progress …)
 *   * each step writes an audit_logs row keyed by entity_type='asset_decommission'
 *     with from/to in the context
 *   * the terminal `retire` step ALSO flips site_assets.status='retired'
 *     and stamps decommissioned_at
 *   * `audited` step captures the audit_log_id back onto the row
 *   * illegal transitions are rejected with the allowed set listed
 *   * .retire is gated independently of .manage
 *   * cancel works from any non-terminal state, blocked once terminal
 *   * required-field validation
 *   * row-aware allowedTransitions filters wipe vs recovery branch
 *   * matrix shape sanity
 */

class DecFakeGate extends AccessGate
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

class DecFakeAudit extends AuditLogger
{
    /** @var array<int, AuditEntry> */
    public array $entries = [];
    public int $nextId = 1000;
    public function __construct()
    {
    }
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

class DecFakeRepo extends AssetDecommissionRepository
{
    /** @var array<int, AssetDecommission> */
    public array $store = [];
    public int $nextId = 1;
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $transitionsApplied = [];

    public function __construct()
    {
    }

    public function findById(int $id): ?AssetDecommission
    {
        return $this->store[$id] ?? null;
    }

    public function create(array $data): AssetDecommission
    {
        $id = $this->nextId++;
        $row = new AssetDecommission([
            'id' => $id,
            'site_asset_id' => (int) ($data['site_asset_id'] ?? 1),
            'customer_id' => (int) ($data['customer_id'] ?? 1),
            'requested_by_user_id' => $data['requested_by_user_id'] ?? null,
            'requested_at' => '2026-05-02 10:00:00',
            'reason' => (string) ($data['reason'] ?? 'eol'),
            'notes' => $data['notes'] ?? null,
            'requires_wipe' => !empty($data['requires_wipe']) ? 1 : 0,
            'recovery_method' => (string) ($data['recovery_method'] ?? 'none'),
            'status' => (string) ($data['status'] ?? AssetDecommission::STATUS_INITIATED),
            'last_state_changed_at' => '2026-05-02 10:00:00',
            'last_state_changed_by' => $data['requested_by_user_id'] ?? null,
            'created_at' => '2026-05-02 10:00:00',
            'updated_at' => '2026-05-02 10:00:00',
        ]);
        $this->store[$id] = $row;
        return $row;
    }

    public function update(int $id, array $data): AssetDecommission
    {
        if (array_key_exists('status', $data)) {
            throw new InvalidArgumentException(
                'Status changes must go through AssetDecommissionService::transition()'
            );
        }
        $row = $this->store[$id] ?? null;
        if ($row === null) {
            throw new RuntimeException("Decommission {$id} not found");
        }
        foreach ($data as $key => $value) {
            $row->{$key} = $value;
        }
        return $row;
    }

    public function applyTransition(int $id, string $toStatus, int $actorId, array $sideEffects = []): AssetDecommission
    {
        $row = $this->store[$id] ?? null;
        if ($row === null) {
            throw new RuntimeException("Decommission {$id} not found");
        }
        $row->status = $toStatus;
        $row->last_state_changed_at = '2026-05-02 11:00:00';
        $row->last_state_changed_by = $actorId;
        foreach ($sideEffects as $key => $value) {
            $row->{$key} = $value;
        }
        $this->transitionsApplied[$id][] = ['to' => $toStatus, 'actor' => $actorId, 'side' => $sideEffects];
        return $row;
    }

    public function setAuditLogId(int $id, int $auditLogId): void
    {
        $row = $this->store[$id] ?? null;
        if ($row === null) {
            return;
        }
        $row->audit_log_id = $auditLogId;
    }
}

/**
 * Stand-in for SiteAssetRepository — the M5 retire step calls update() with
 * status='retired' and decommissioned_at=NOW(). We capture those calls so the
 * test can assert the side effect happened.
 */
class DecFakeSiteAssets extends SiteAssetRepository
{
    /** @var array<int, SiteAsset> */
    public array $store = [];
    /** @var array<int, array<int, array<string, mixed>>> */
    public array $updates = [];

    public function __construct()
    {
    }

    public function update(int $id, array $data): SiteAsset
    {
        $asset = $this->store[$id] ?? new SiteAsset(['id' => $id, 'status' => 'active']);
        foreach ($data as $key => $value) {
            $asset->{$key} = $value;
        }
        $this->store[$id] = $asset;
        $this->updates[$id][] = $data;
        return $asset;
    }
}

function decMakeUser(int $id = 42): User
{
    $user = new User();
    $user->id = $id;
    $user->name = 'Test Manager';
    $user->email = 'mgr@example.com';
    return $user;
}

function decAssertFails(callable $fn, string $needle, string $label): void
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
// HAPPY PATH (with wipe)
// =============================================================================

$repo = new DecFakeRepo();
$assets = new DecFakeSiteAssets();
$gate = new DecFakeGate();
$audit = new DecFakeAudit();
$service = new AssetDecommissionService($repo, $assets, $gate, $audit);
$actor = decMakeUser();

// 1. create — initiated + creation audit
$row = $service->create($actor, [
    'site_asset_id' => 500,
    'customer_id' => 7,
    'reason' => 'eol',
    'requires_wipe' => 1,
    'recovery_method' => 'donate',
]);
if ($row->status !== AssetDecommission::STATUS_INITIATED) {
    fwrite(STDERR, "FAIL: created status not initiated (got {$row->status})\n");
    exit(1);
}
if (!$row->requiresWipe()) {
    fwrite(STDERR, "FAIL: requires_wipe=1 not honored\n");
    exit(1);
}
if (count($audit->entries) !== 1 || $audit->entries[0]->event !== 'decommission.transitioned') {
    fwrite(STDERR, "FAIL: create did not write audit entry\n");
    exit(1);
}
if ($audit->entries[0]->context['from'] !== null
    || $audit->entries[0]->context['to'] !== AssetDecommission::STATUS_INITIATED) {
    fwrite(STDERR, "FAIL: create audit entry missing from/to (null → initiated)\n");
    exit(1);
}

// 2. startWipe → wipe_in_progress, wipe_started_at stamped
$row = $service->startWipe($actor, $row->id);
if ($row->status !== AssetDecommission::STATUS_WIPE_IN_PROGRESS || $row->wipe_started_at === null) {
    fwrite(STDERR, "FAIL: startWipe missing fields (status={$row->status})\n");
    exit(1);
}

// 3. completeWipe → wipe_complete, certificate stamped
$row = $service->completeWipe($actor, $row->id, [
    'wipe_certificate_url' => 'https://certs.example.com/wipe-1.pdf',
    'wipe_completed_by' => 8,
]);
if ($row->status !== AssetDecommission::STATUS_WIPE_COMPLETE
    || $row->wipe_completed_at === null
    || $row->wipe_completed_by !== 8
    || $row->wipe_certificate_url !== 'https://certs.example.com/wipe-1.pdf') {
    fwrite(STDERR, "FAIL: completeWipe missing fields\n");
    exit(1);
}

// 4. startRecovery → recovery_in_progress
$row = $service->startRecovery($actor, $row->id);
if ($row->status !== AssetDecommission::STATUS_RECOVERY_IN_PROGRESS || $row->recovery_started_at === null) {
    fwrite(STDERR, "FAIL: startRecovery missing fields\n");
    exit(1);
}

// 5. completeRecovery → recovery_complete, optional refs stamped
$row = $service->completeRecovery($actor, $row->id, [
    'recovery_reference' => 'DON-99',
    'recovery_value_cents' => 5000,
]);
if ($row->status !== AssetDecommission::STATUS_RECOVERY_COMPLETE
    || $row->recovery_completed_at === null
    || $row->recovery_reference !== 'DON-99'
    || $row->recovery_value_cents !== 5000) {
    fwrite(STDERR, "FAIL: completeRecovery missing fields\n");
    exit(1);
}

// 6. updateEntitlements → entitlement_updated
$row = $service->updateEntitlements($actor, $row->id);
if ($row->status !== AssetDecommission::STATUS_ENTITLEMENT_UPDATED || $row->entitlement_updated_at === null) {
    fwrite(STDERR, "FAIL: updateEntitlements missing fields\n");
    exit(1);
}

// 7. markAudited → audited, audit_log_id captured
$row = $service->markAudited($actor, $row->id);
if ($row->status !== AssetDecommission::STATUS_AUDITED || $row->audited_at === null) {
    fwrite(STDERR, "FAIL: markAudited missing fields\n");
    exit(1);
}
if ($row->audit_log_id === null || $row->audit_log_id <= 0) {
    fwrite(STDERR, "FAIL: markAudited did not capture audit_log_id (got " . var_export($row->audit_log_id, true) . ")\n");
    exit(1);
}

// 8. retire — terminal AND flips site_asset
$row = $service->retire($actor, $row->id);
if ($row->status !== AssetDecommission::STATUS_RETIRED || $row->retired_at === null
    || $row->retired_by !== $actor->id) {
    fwrite(STDERR, "FAIL: retire missing fields\n");
    exit(1);
}
if (!$row->isTerminal()) {
    fwrite(STDERR, "FAIL: retired should be terminal\n");
    exit(1);
}
if (!isset($assets->updates[500])) {
    fwrite(STDERR, "FAIL: retire did not update site_asset 500\n");
    exit(1);
}
$assetUpdate = end($assets->updates[500]);
if (($assetUpdate['status'] ?? null) !== 'retired' || empty($assetUpdate['decommissioned_at'])) {
    fwrite(STDERR, "FAIL: site_asset retire patch wrong: " . json_encode($assetUpdate) . "\n");
    exit(1);
}

// 1 create + 7 forward transitions = 8 audit entries
if (count($audit->entries) !== 8) {
    fwrite(STDERR, "FAIL: expected 8 audit entries, got " . count($audit->entries) . "\n");
    exit(1);
}
foreach ($audit->entries as $i => $entry) {
    if ($entry->entityType !== 'asset_decommission') {
        fwrite(STDERR, "FAIL: audit #{$i} entityType wrong\n");
        exit(1);
    }
    if (!array_key_exists('from', $entry->context) || !array_key_exists('to', $entry->context)) {
        fwrite(STDERR, "FAIL: audit #{$i} missing from/to context\n");
        exit(1);
    }
}

// =============================================================================
// HAPPY PATH (no wipe — initiated jumps straight to recovery)
// =============================================================================

$repoNW = new DecFakeRepo();
$assetsNW = new DecFakeSiteAssets();
$serviceNW = new AssetDecommissionService($repoNW, $assetsNW, new DecFakeGate(), new DecFakeAudit());
$rowNW = $serviceNW->create($actor, [
    'site_asset_id' => 600,
    'customer_id' => 7,
    'requires_wipe' => 0,
    'recovery_method' => 'scrap',
]);

// startWipe should fail because requires_wipe=0
decAssertFails(
    fn () => $serviceNW->startWipe($actor, $rowNW->id),
    'requires_wipe=0',
    'startWipe blocked when requires_wipe=0'
);

// startRecovery from initiated should work
$rowNW = $serviceNW->startRecovery($actor, $rowNW->id);
if ($rowNW->status !== AssetDecommission::STATUS_RECOVERY_IN_PROGRESS) {
    fwrite(STDERR, "FAIL: no-wipe path should advance initiated → recovery_in_progress\n");
    exit(1);
}

// And the rest of the flow
$serviceNW->completeRecovery($actor, $rowNW->id);
$serviceNW->updateEntitlements($actor, $rowNW->id);
$serviceNW->markAudited($actor, $rowNW->id);
$rowNW = $serviceNW->retire($actor, $rowNW->id);
if ($rowNW->status !== AssetDecommission::STATUS_RETIRED) {
    fwrite(STDERR, "FAIL: no-wipe path didn't reach retired\n");
    exit(1);
}
if (!isset($assetsNW->updates[600])) {
    fwrite(STDERR, "FAIL: no-wipe retire didn't flip site_asset\n");
    exit(1);
}

// startRecovery from initiated WHEN requires_wipe=1 should fail
$repoBlock = new DecFakeRepo();
$serviceBlock = new AssetDecommissionService($repoBlock, new DecFakeSiteAssets(), new DecFakeGate(), new DecFakeAudit());
$rowBlock = $serviceBlock->create($actor, [
    'site_asset_id' => 700,
    'customer_id' => 1,
    'requires_wipe' => 1,
]);
decAssertFails(
    fn () => $serviceBlock->startRecovery($actor, $rowBlock->id),
    'requires_wipe=1',
    'startRecovery from initiated blocked when requires_wipe=1'
);

// =============================================================================
// ILLEGAL TRANSITIONS
// =============================================================================

$repo2 = new DecFakeRepo();
$service2 = new AssetDecommissionService($repo2, new DecFakeSiteAssets(), new DecFakeGate(), new DecFakeAudit());
$row2 = $service2->create($actor, ['site_asset_id' => 1, 'customer_id' => 1, 'requires_wipe' => 1]);

// initiated → wipe_complete (skipping wipe_in_progress) is illegal
decAssertFails(
    fn () => $service2->completeWipe($actor, $row2->id, ['wipe_certificate_url' => 'x']),
    'Illegal decommission transition',
    'initiated → wipe_complete'
);

// terminal block: can't reopen retired
$repo3 = new DecFakeRepo();
$service3 = new AssetDecommissionService($repo3, new DecFakeSiteAssets(), new DecFakeGate(), new DecFakeAudit());
$row3 = $service3->create($actor, ['site_asset_id' => 1, 'customer_id' => 1]);
$repo3->store[$row3->id]->status = AssetDecommission::STATUS_RETIRED;
decAssertFails(
    fn () => $service3->cancel($actor, $row3->id, ['reason' => 'oops']),
    'terminal',
    'cancel after retired'
);

// =============================================================================
// PERMISSION SEPARATION: .retire is gated independently
// =============================================================================

$repo4 = new DecFakeRepo();
$assets4 = new DecFakeSiteAssets();
$gate4 = new DecFakeGate();
$service4 = new AssetDecommissionService($repo4, $assets4, $gate4, new DecFakeAudit());
$row4 = $service4->create($actor, ['site_asset_id' => 800, 'customer_id' => 1, 'requires_wipe' => 0]);
// walk to audited
$repo4->store[$row4->id]->status = AssetDecommission::STATUS_AUDITED;

// Deny .retire but allow .manage
$gate4->denied = ['asset_decommissions.retire'];
decAssertFails(
    fn () => $service4->retire($actor, $row4->id),
    'denied: asset_decommissions.retire',
    'retire denied without permission'
);
// site_asset must NOT be touched if the retire was blocked
if (isset($assets4->updates[800])) {
    fwrite(STDERR, "FAIL: site_asset was retired despite blocked .retire permission\n");
    exit(1);
}
// .manage alone is enough for cancel even when retire is denied
$out = $service4->cancel($actor, $row4->id, ['reason' => 'changed mind']);
if ($out->status !== AssetDecommission::STATUS_CANCELLED) {
    fwrite(STDERR, "FAIL: cancel should succeed with .manage even when .retire denied\n");
    exit(1);
}

// =============================================================================
// REQUIRED-FIELD VALIDATION
// =============================================================================

$repo5 = new DecFakeRepo();
$service5 = new AssetDecommissionService($repo5, new DecFakeSiteAssets(), new DecFakeGate(), new DecFakeAudit());
$row5 = $service5->create($actor, ['site_asset_id' => 1, 'customer_id' => 1, 'requires_wipe' => 1]);
$service5->startWipe($actor, $row5->id);
decAssertFails(
    fn () => $service5->completeWipe($actor, $row5->id, []),
    'wipe_certificate_url is required',
    'completeWipe missing certificate'
);
decAssertFails(
    fn () => $service5->cancel($actor, $row5->id, []),
    'reason is required',
    'cancel missing reason'
);

// =============================================================================
// CANCEL FROM EVERY NON-TERMINAL STATE
// =============================================================================

$nonTerminalStates = [
    AssetDecommission::STATUS_INITIATED,
    AssetDecommission::STATUS_WIPE_IN_PROGRESS,
    AssetDecommission::STATUS_WIPE_COMPLETE,
    AssetDecommission::STATUS_RECOVERY_IN_PROGRESS,
    AssetDecommission::STATUS_RECOVERY_COMPLETE,
    AssetDecommission::STATUS_ENTITLEMENT_UPDATED,
    AssetDecommission::STATUS_AUDITED,
];
foreach ($nonTerminalStates as $state) {
    $repoX = new DecFakeRepo();
    $serviceX = new AssetDecommissionService($repoX, new DecFakeSiteAssets(), new DecFakeGate(), new DecFakeAudit());
    $rowX = $serviceX->create($actor, ['site_asset_id' => 1, 'customer_id' => 1]);
    $repoX->store[$rowX->id]->status = $state;
    $out = $serviceX->cancel($actor, $rowX->id, ['reason' => 'test']);
    if ($out->status !== AssetDecommission::STATUS_CANCELLED) {
        fwrite(STDERR, "FAIL: cancel from {$state} did not produce cancelled\n");
        exit(1);
    }
}

// =============================================================================
// ROW-AWARE allowedTransitions filters wipe vs recovery branch
// =============================================================================

$rwYes = new AssetDecommission([
    'status' => AssetDecommission::STATUS_INITIATED,
    'requires_wipe' => 1,
]);
$allowedYes = $rwYes->allowedTransitions();
if (in_array(AssetDecommission::STATUS_RECOVERY_IN_PROGRESS, $allowedYes, true)) {
    fwrite(STDERR, "FAIL: requires_wipe=1 row should NOT offer recovery_in_progress from initiated\n");
    exit(1);
}
if (!in_array(AssetDecommission::STATUS_WIPE_IN_PROGRESS, $allowedYes, true)) {
    fwrite(STDERR, "FAIL: requires_wipe=1 row SHOULD offer wipe_in_progress from initiated\n");
    exit(1);
}

$rwNo = new AssetDecommission([
    'status' => AssetDecommission::STATUS_INITIATED,
    'requires_wipe' => 0,
]);
$allowedNo = $rwNo->allowedTransitions();
if (in_array(AssetDecommission::STATUS_WIPE_IN_PROGRESS, $allowedNo, true)) {
    fwrite(STDERR, "FAIL: requires_wipe=0 row should NOT offer wipe_in_progress from initiated\n");
    exit(1);
}
if (!in_array(AssetDecommission::STATUS_RECOVERY_IN_PROGRESS, $allowedNo, true)) {
    fwrite(STDERR, "FAIL: requires_wipe=0 row SHOULD offer recovery_in_progress from initiated\n");
    exit(1);
}
// cancel always present from non-terminal
if (!in_array(AssetDecommission::STATUS_CANCELLED, $allowedYes, true)
    || !in_array(AssetDecommission::STATUS_CANCELLED, $allowedNo, true)) {
    fwrite(STDERR, "FAIL: cancel should always be available from non-terminal\n");
    exit(1);
}

// =============================================================================
// TRANSITION MATRIX SHAPE
// =============================================================================

if (AssetDecommission::canTransition('initiated', 'wipe_in_progress') !== true) {
    fwrite(STDERR, "FAIL: matrix says initiated→wipe_in_progress is illegal\n"); exit(1);
}
if (AssetDecommission::canTransition('initiated', 'recovery_in_progress') !== true) {
    fwrite(STDERR, "FAIL: matrix says initiated→recovery_in_progress is illegal\n"); exit(1);
}
if (AssetDecommission::canTransition('initiated', 'cancelled') !== true) {
    fwrite(STDERR, "FAIL: matrix says initiated→cancelled is illegal\n"); exit(1);
}
if (AssetDecommission::canTransition('retired', 'cancelled') !== false) {
    fwrite(STDERR, "FAIL: matrix allowed cancel after retired\n"); exit(1);
}
if (AssetDecommission::canTransition('initiated', 'audited') !== false) {
    fwrite(STDERR, "FAIL: matrix allowed initiated→audited skip\n"); exit(1);
}
if (AssetDecommission::canTransition('audited', 'retired') !== true) {
    fwrite(STDERR, "FAIL: matrix says audited→retired is illegal\n"); exit(1);
}

echo "AssetDecommissionServiceTest: OK\n";
