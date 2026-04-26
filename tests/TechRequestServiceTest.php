<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "TechRequestServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\User;
use App\Models\WorkorderAdditionalTech;
use App\Models\WorkorderTechRequest;
use App\Services\Workorder\TechRequestController;
use App\Services\Workorder\TechRequestRepository;
use App\Services\Workorder\TechRequestService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 10.3 of docs/expansion-plan.md — additional-tech request workflow.
 *
 * Covers: request CRUD, lifecycle (pending → approved → fulfilled, decline,
 * cancel), illegal-transition rejection, fulfilment auto-creates an
 * additional-tech assignment, UNIQUE-active enforcement (same user cannot be
 * active twice on the same WO), direct add (no request) path, soft-removal
 * pattern, owner-only edit, owner-self-cancel, permission denials, controller
 * envelope shape.
 */

class TrInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function trSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE workorders (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'open')");
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");

    $pdo->exec("CREATE TABLE workorder_tech_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        requested_by_user_id INTEGER NOT NULL,
        request_type TEXT NOT NULL DEFAULT 'extra_hands',
        reason TEXT NOT NULL,
        estimated_hours REAL NULL,
        skills_needed TEXT NULL,
        urgency TEXT NOT NULL DEFAULT 'normal',
        status TEXT NOT NULL DEFAULT 'pending',
        requested_at TEXT NULL,
        approved_by_user_id INTEGER NULL,
        approved_at TEXT NULL,
        declined_at TEXT NULL,
        cancelled_at TEXT NULL,
        fulfilled_at TEXT NULL,
        fulfilled_user_id INTEGER NULL,
        rejection_reason TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE workorder_additional_techs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        request_id INTEGER NULL,
        tech_role TEXT NOT NULL DEFAULT 'secondary_tech',
        added_at TEXT NULL,
        added_by_user_id INTEGER NULL,
        removed_at TEXT NULL,
        removed_by_user_id INTEGER NULL,
        removal_reason TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    return $pdo;
}

class TrPermissiveGate extends AccessGate
{
    /** @var array<string, bool> */
    public array $denials = [];
    public function __construct()
    {
    }
    public function can(User $user, string $permission, mixed $resource = null): bool
    {
        return empty($this->denials[$permission]);
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (!empty($this->denials[$permission])) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}

function makeTrFixture(): array
{
    $pdo = trSetUpDatabase();
    $conn = new TrInMemoryConnection($pdo);
    $gate = new TrPermissiveGate();
    $repo = new TechRequestRepository($conn);
    $service = new TechRequestService($repo, $gate);
    $controller = new TechRequestController($service);

    $pdo->exec("INSERT INTO workorders (id, status) VALUES (100, 'open'), (101, 'open')");
    $pdo->exec("INSERT INTO users (id, name) VALUES (7, 'Manager Bob'), (8, 'Tech Carol'), (9, 'Tech Dave'), (10, 'Tech Eve')");

    return compact('pdo', 'conn', 'gate', 'repo', 'service', 'controller');
}

function trAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function trAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function trAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new RuntimeException("FAIL {$msg}: got " . get_class($e) . " expected {$expectedClass}");
        }
        return;
    }
    throw new RuntimeException("FAIL {$msg}: no exception thrown (expected {$expectedClass})");
}

function makeTrUser(int $id = 7, string $role = 'manager'): User
{
    $u = new User();
    $u->id = $id;
    $u->role = $role;
    return $u;
}

function makeTrPendingRequest(array $f, int $workorderId = 100, int $requestor = 8): WorkorderTechRequest
{
    return $f['service']->createRequest(makeTrUser($requestor, 'technician'), $workorderId, [
        'request_type' => WorkorderTechRequest::TYPE_EXTRA_HANDS,
        'reason' => 'Need a second pair of hands to lift the transmission',
        'estimated_hours' => 2.0,
        'urgency' => WorkorderTechRequest::URGENCY_NORMAL,
    ]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Model constants & state machine ────

$tests['request_status_constants'] = function () {
    trAssertSame(
        ['pending', 'approved', 'declined', 'cancelled', 'fulfilled'],
        WorkorderTechRequest::STATUSES
    );
};

$tests['allowed_transitions_published'] = function () {
    trAssertSame(
        ['pending'],
        WorkorderTechRequest::ALLOWED_TRANSITIONS[WorkorderTechRequest::STATUS_APPROVED]
    );
    trAssertSame(
        ['approved'],
        WorkorderTechRequest::ALLOWED_TRANSITIONS[WorkorderTechRequest::STATUS_FULFILLED]
    );
    trAssertSame(
        ['pending', 'approved'],
        WorkorderTechRequest::ALLOWED_TRANSITIONS[WorkorderTechRequest::STATUS_CANCELLED]
    );
};

$tests['additional_tech_roles_published'] = function () {
    trAssertSame(
        ['secondary_tech', 'specialist', 'shadow', 'apprentice'],
        WorkorderAdditionalTech::ROLES
    );
};

// ──── Request creation + validation ────

$tests['create_request_requires_reason'] = function () {
    $f = makeTrFixture();
    trAssertThrows(
        fn() => $f['service']->createRequest(makeTrUser(8), 100, []),
        InvalidArgumentException::class
    );
};

$tests['create_request_rejects_unknown_type'] = function () {
    $f = makeTrFixture();
    trAssertThrows(
        fn() => $f['service']->createRequest(makeTrUser(8), 100, [
            'reason' => 'x', 'request_type' => 'bogus',
        ]),
        InvalidArgumentException::class
    );
};

$tests['create_request_rejects_unknown_urgency'] = function () {
    $f = makeTrFixture();
    trAssertThrows(
        fn() => $f['service']->createRequest(makeTrUser(8), 100, [
            'reason' => 'x', 'urgency' => 'mega',
        ]),
        InvalidArgumentException::class
    );
};

$tests['create_request_rejects_zero_estimated_hours'] = function () {
    $f = makeTrFixture();
    trAssertThrows(
        fn() => $f['service']->createRequest(makeTrUser(8), 100, [
            'reason' => 'x', 'estimated_hours' => 0,
        ]),
        InvalidArgumentException::class
    );
};

$tests['create_request_stamps_requested_by_actor_and_pending_status'] = function () {
    $f = makeTrFixture();
    $now = new DateTimeImmutable('2026-04-24 10:00:00');
    $req = $f['service']->createRequest(makeTrUser(8, 'technician'), 100, [
        'reason' => 'Need help', 'urgency' => 'high',
    ], $now);
    trAssertSame(8, $req->requested_by_user_id);
    trAssertSame('pending', $req->status);
    trAssertSame(100, $req->workorder_id);
    trAssertSame('high', $req->urgency);
    trAssertSame('2026-04-24 10:00:00', $req->requested_at);
};

// ──── Lifecycle ────

$tests['approve_moves_pending_to_approved'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    $now = new DateTimeImmutable('2026-04-24 11:00:00');
    $approved = $f['service']->approveRequest(makeTrUser(7), $req->id, $now);
    trAssertSame('approved', $approved->status);
    trAssertSame(7, $approved->approved_by_user_id);
    trAssertSame('2026-04-24 11:00:00', $approved->approved_at);
};

$tests['approve_rejected_from_already_approved'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    $f['service']->approveRequest(makeTrUser(7), $req->id);
    trAssertThrows(
        fn() => $f['service']->approveRequest(makeTrUser(7), $req->id),
        InvalidArgumentException::class,
        'cannot re-approve'
    );
};

$tests['decline_requires_reason'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    trAssertThrows(
        fn() => $f['service']->declineRequest(makeTrUser(7), $req->id, ' '),
        InvalidArgumentException::class
    );
};

$tests['decline_moves_pending_to_declined_with_reason'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    $now = new DateTimeImmutable('2026-04-24 12:00:00');
    $decl = $f['service']->declineRequest(makeTrUser(7), $req->id, 'No spare techs today', $now);
    trAssertSame('declined', $decl->status);
    trAssertSame('No spare techs today', $decl->rejection_reason);
    trAssertSame('2026-04-24 12:00:00', $decl->declined_at);
};

$tests['decline_blocked_after_approval'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    $f['service']->approveRequest(makeTrUser(7), $req->id);
    trAssertThrows(
        fn() => $f['service']->declineRequest(makeTrUser(7), $req->id, 'too late'),
        InvalidArgumentException::class
    );
};

$tests['cancel_owner_can_cancel_their_own_pending_request'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f, 100, 8);
    // Owner (user 8) has no .manage permission but should still cancel.
    $f['gate']->denials['workorder_tech_requests.manage'] = true;
    $cancelled = $f['service']->cancelRequest(makeTrUser(8, 'technician'), $req->id);
    trAssertSame('cancelled', $cancelled->status);
    trAssertTrue($cancelled->cancelled_at !== null);
};

$tests['cancel_non_owner_requires_manage_permission'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f, 100, 8);
    $f['gate']->denials['workorder_tech_requests.manage'] = true;
    trAssertThrows(
        fn() => $f['service']->cancelRequest(makeTrUser(9, 'technician'), $req->id),
        UnauthorizedException::class,
        'non-owner needs manage'
    );
};

$tests['cancel_allowed_from_approved_state'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    $f['service']->approveRequest(makeTrUser(7), $req->id);
    $cancelled = $f['service']->cancelRequest(makeTrUser(7), $req->id);
    trAssertSame('cancelled', $cancelled->status);
};

$tests['cancel_blocked_from_declined'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    $f['service']->declineRequest(makeTrUser(7), $req->id, 'no');
    trAssertThrows(
        fn() => $f['service']->cancelRequest(makeTrUser(7), $req->id),
        InvalidArgumentException::class
    );
};

// ──── Fulfilment ────

$tests['fulfil_only_from_approved_state'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    trAssertThrows(
        fn() => $f['service']->fulfilRequest(makeTrUser(7), $req->id, ['user_id' => 9]),
        InvalidArgumentException::class,
        'cannot fulfil pending — must approve first'
    );
};

$tests['fulfil_requires_user_id'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    $f['service']->approveRequest(makeTrUser(7), $req->id);
    trAssertThrows(
        fn() => $f['service']->fulfilRequest(makeTrUser(7), $req->id, []),
        InvalidArgumentException::class
    );
};

$tests['fulfil_rejects_self_assignment_to_requestor'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f, 100, 8); // Carol asked
    $f['service']->approveRequest(makeTrUser(7), $req->id);
    trAssertThrows(
        fn() => $f['service']->fulfilRequest(makeTrUser(7), $req->id, ['user_id' => 8]),
        InvalidArgumentException::class,
        'cannot fulfil with the same user who asked'
    );
};

$tests['fulfil_rejects_unknown_role'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    $f['service']->approveRequest(makeTrUser(7), $req->id);
    trAssertThrows(
        fn() => $f['service']->fulfilRequest(makeTrUser(7), $req->id, [
            'user_id' => 9, 'tech_role' => 'manager',
        ]),
        InvalidArgumentException::class
    );
};

$tests['fulfil_creates_additional_tech_and_stamps_request'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f, 100, 8);
    $f['service']->approveRequest(makeTrUser(7), $req->id);
    $now = new DateTimeImmutable('2026-04-24 13:30:00');
    $fulfilled = $f['service']->fulfilRequest(makeTrUser(7), $req->id, [
        'user_id' => 9, 'tech_role' => WorkorderAdditionalTech::ROLE_SPECIALIST,
    ], $now);
    trAssertSame('fulfilled', $fulfilled->status);
    trAssertSame(9, $fulfilled->fulfilled_user_id);
    trAssertSame('2026-04-24 13:30:00', $fulfilled->fulfilled_at);

    $techs = $f['service']->listTechsForWorkorder(makeTrUser(7), 100);
    trAssertSame(1, count($techs));
    trAssertSame(9, $techs[0]->user_id);
    trAssertSame('specialist', $techs[0]->tech_role);
    trAssertSame($req->id, $techs[0]->request_id);
    trAssertSame('2026-04-24 13:30:00', $techs[0]->added_at);
    trAssertSame(7, $techs[0]->added_by_user_id);
};

$tests['fulfil_rejects_user_already_active_on_workorder'] = function () {
    $f = makeTrFixture();
    // Direct-add Dave (user 9) first.
    $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]);
    // Now have Carol file + approve a request, try to fulfil with Dave.
    $req = makeTrPendingRequest($f, 100, 8);
    $f['service']->approveRequest(makeTrUser(7), $req->id);
    trAssertThrows(
        fn() => $f['service']->fulfilRequest(makeTrUser(7), $req->id, ['user_id' => 9]),
        InvalidArgumentException::class,
        'user 9 already on WO — cannot duplicate'
    );
};

// ──── Direct add path (no request) ────

$tests['add_tech_creates_active_assignment'] = function () {
    $f = makeTrFixture();
    $now = new DateTimeImmutable('2026-04-24 14:00:00');
    $tech = $f['service']->addTech(makeTrUser(7), 100, [
        'user_id' => 10, 'tech_role' => 'apprentice',
    ], $now);
    trAssertSame(10, $tech->user_id);
    trAssertSame('apprentice', $tech->tech_role);
    trAssertSame(null, $tech->request_id, 'direct add has no request linkage');
    trAssertSame('2026-04-24 14:00:00', $tech->added_at);
    trAssertTrue($tech->isActive(), 'newly added tech is active');
};

$tests['add_tech_rejects_duplicate_active'] = function () {
    $f = makeTrFixture();
    $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]);
    trAssertThrows(
        fn() => $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]),
        InvalidArgumentException::class,
        'cannot duplicate active assignment'
    );
};

$tests['add_tech_allows_re_add_after_removal'] = function () {
    $f = makeTrFixture();
    $tech = $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]);
    $f['service']->removeTech(makeTrUser(7), $tech->id, 'pulled to urgent job');
    // Same user can be re-added now that prior assignment is removed.
    $reAdded = $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]);
    trAssertSame(9, $reAdded->user_id);
    trAssertTrue($reAdded->isActive());

    $all = $f['service']->listTechsForWorkorder(makeTrUser(7), 100);
    trAssertSame(2, count($all), 'history shows both assignments');
    $active = $f['service']->listTechsForWorkorder(makeTrUser(7), 100, true);
    trAssertSame(1, count($active), 'only one active');
};

// ──── Soft removal ────

$tests['remove_tech_sets_removal_metadata'] = function () {
    $f = makeTrFixture();
    $tech = $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]);
    $now = new DateTimeImmutable('2026-04-24 16:00:00');
    $removed = $f['service']->removeTech(makeTrUser(7), $tech->id, 'shift ended', $now);
    trAssertSame('2026-04-24 16:00:00', $removed->removed_at);
    trAssertSame(7, $removed->removed_by_user_id);
    trAssertSame('shift ended', $removed->removal_reason);
    trAssertTrue(!$removed->isActive());
};

$tests['remove_tech_blocks_double_removal'] = function () {
    $f = makeTrFixture();
    $tech = $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]);
    $f['service']->removeTech(makeTrUser(7), $tech->id);
    trAssertThrows(
        fn() => $f['service']->removeTech(makeTrUser(7), $tech->id),
        InvalidArgumentException::class
    );
};

$tests['update_tech_changes_role_and_notes'] = function () {
    $f = makeTrFixture();
    $tech = $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9, 'tech_role' => 'shadow']);
    $updated = $f['service']->updateTech(makeTrUser(7), $tech->id, [
        'tech_role' => 'apprentice', 'notes' => 'promoted from shadow to active assist',
    ]);
    trAssertSame('apprentice', $updated->tech_role);
    trAssertSame('promoted from shadow to active assist', $updated->notes);
};

$tests['update_tech_blocked_after_removal'] = function () {
    $f = makeTrFixture();
    $tech = $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]);
    $f['service']->removeTech(makeTrUser(7), $tech->id);
    trAssertThrows(
        fn() => $f['service']->updateTech(makeTrUser(7), $tech->id, ['tech_role' => 'specialist']),
        InvalidArgumentException::class
    );
};

$tests['update_tech_strips_removal_fields'] = function () {
    $f = makeTrFixture();
    $tech = $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]);
    $updated = $f['service']->updateTech(makeTrUser(7), $tech->id, [
        'removed_at' => '2099-01-01',     // stripped
        'removed_by_user_id' => 999,      // stripped
        'removal_reason' => 'sneak',      // stripped
        'notes' => 'kept',                // accepted
    ]);
    trAssertSame(null, $updated->removed_at, 'removed_at not settable via update');
    trAssertSame(null, $updated->removed_by_user_id);
    trAssertSame('kept', $updated->notes);
};

// ──── Update request: owner + lifecycle gates ────

$tests['update_request_owner_can_edit_pending'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f, 100, 8);
    $updated = $f['service']->updateRequest(makeTrUser(8, 'technician'), $req->id, [
        'reason' => 'Updated: actually need a parts runner',
        'request_type' => WorkorderTechRequest::TYPE_EXTRA_HANDS,
    ]);
    trAssertSame('Updated: actually need a parts runner', $updated->reason);
};

$tests['update_request_blocked_after_approval'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f, 100, 8);
    $f['service']->approveRequest(makeTrUser(7), $req->id);
    trAssertThrows(
        fn() => $f['service']->updateRequest(makeTrUser(8, 'technician'), $req->id, ['reason' => 'no']),
        InvalidArgumentException::class
    );
};

$tests['update_request_strips_lifecycle_fields'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f, 100, 8);
    $updated = $f['service']->updateRequest(makeTrUser(8, 'technician'), $req->id, [
        'status' => 'fulfilled',          // stripped
        'approved_at' => '2099-01-01',    // stripped
        'fulfilled_user_id' => 999,       // stripped
        'reason' => 'updated reason',     // accepted
    ]);
    trAssertSame('pending', $updated->status, 'status untouched');
    trAssertSame(null, $updated->approved_at);
    trAssertSame(null, $updated->fulfilled_user_id);
    trAssertSame('updated reason', $updated->reason);
};

// ──── Permissions ────

$tests['view_requires_view_permission'] = function () {
    $f = makeTrFixture();
    $f['gate']->denials['workorder_tech_requests.view'] = true;
    trAssertThrows(
        fn() => $f['service']->listRequestsForWorkorder(makeTrUser(7), 100),
        UnauthorizedException::class
    );
};

$tests['create_requires_create_permission'] = function () {
    $f = makeTrFixture();
    $f['gate']->denials['workorder_tech_requests.create'] = true;
    trAssertThrows(
        fn() => $f['service']->createRequest(makeTrUser(8), 100, ['reason' => 'x']),
        UnauthorizedException::class
    );
};

$tests['approve_requires_manage_permission'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    $f['gate']->denials['workorder_tech_requests.manage'] = true;
    trAssertThrows(
        fn() => $f['service']->approveRequest(makeTrUser(7), $req->id),
        UnauthorizedException::class
    );
};

$tests['add_tech_requires_manage_permission'] = function () {
    $f = makeTrFixture();
    $f['gate']->denials['workorder_tech_requests.manage'] = true;
    trAssertThrows(
        fn() => $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]),
        UnauthorizedException::class
    );
};

// ──── Filters & list ────

$tests['list_requests_filters_by_status'] = function () {
    $f = makeTrFixture();
    $a = makeTrPendingRequest($f, 100, 8);
    $b = makeTrPendingRequest($f, 100, 9);
    $f['service']->approveRequest(makeTrUser(7), $b->id);

    $pending = $f['service']->listRequests(makeTrUser(7), ['status' => 'pending']);
    trAssertSame(1, count($pending));
    trAssertSame($a->id, $pending[0]->id);

    $approved = $f['service']->listRequests(makeTrUser(7), ['status' => 'approved']);
    trAssertSame(1, count($approved));
    trAssertSame($b->id, $approved[0]->id);
};

$tests['list_requests_filters_by_requestor'] = function () {
    $f = makeTrFixture();
    makeTrPendingRequest($f, 100, 8);
    makeTrPendingRequest($f, 101, 9);
    makeTrPendingRequest($f, 100, 8);

    $carolReqs = $f['service']->listRequests(makeTrUser(7), ['requested_by_user_id' => 8]);
    trAssertSame(2, count($carolReqs));
    $daveReqs = $f['service']->listRequests(makeTrUser(7), ['requested_by_user_id' => 9]);
    trAssertSame(1, count($daveReqs));
};

// ──── Controller envelope shape ────

$tests['controller_create_returns_data_envelope'] = function () {
    $f = makeTrFixture();
    $resp = $f['controller']->createRequest(makeTrUser(8, 'technician'), 100, [
        'reason' => 'wrap me',
    ]);
    trAssertTrue(array_key_exists('data', $resp));
    trAssertSame('wrap me', $resp['data']['reason']);
    trAssertSame('pending', $resp['data']['status']);
};

$tests['controller_decline_requires_reason_in_body'] = function () {
    $f = makeTrFixture();
    $req = makeTrPendingRequest($f);
    trAssertThrows(
        fn() => $f['controller']->declineRequest(makeTrUser(7), $req->id, []),
        InvalidArgumentException::class
    );
};

$tests['controller_list_techs_supports_active_only_filter'] = function () {
    $f = makeTrFixture();
    $tech = $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 9]);
    $f['service']->addTech(makeTrUser(7), 100, ['user_id' => 10]);
    $f['service']->removeTech(makeTrUser(7), $tech->id);

    $all = $f['controller']->listTechsForWorkorder(makeTrUser(7), 100);
    trAssertSame(2, count($all['data']));

    $active = $f['controller']->listTechsForWorkorder(makeTrUser(7), 100, ['active_only' => '1']);
    trAssertSame(1, count($active['data']), 'only active surfaced');
    trAssertSame(10, $active['data'][0]['user_id']);
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "TechRequestServiceTest\n";
$pass = 0;
$fail = 0;
$failures = [];
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  ✓ {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  ✗ {$name}: " . $e->getMessage() . "\n";
        $failures[] = $name;
        $fail++;
    }
}
echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    exit(1);
}
