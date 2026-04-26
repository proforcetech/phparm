<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "ReassignmentServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\User;
use App\Models\WorkorderReassignmentRequest;
use App\Services\Workorder\ReassignmentController;
use App\Services\Workorder\ReassignmentRepository;
use App\Services\Workorder\ReassignmentService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 10.4 of docs/expansion-plan.md — WO primary-tech reassignment flow.
 *
 * Covers: request CRUD, lifecycle (pending → approved → fulfilled, decline,
 * cancel), illegal-transition rejection, fulfilment atomically updates the
 * workorders row + appends a history entry, no-op fulfilment guard, owner-
 * self-cancel without manage permission, direct dispatch reassignment path,
 * permission denials, controller envelope shape, history list & filters.
 */

class RaInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function raSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE workorders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        assigned_technician_id INTEGER NULL,
        updated_at TEXT NULL,
        status TEXT NOT NULL DEFAULT 'open'
    )");
    $pdo->exec("CREATE TABLE users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT
    )");

    $pdo->exec("CREATE TABLE workorder_reassignment_requests (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        requested_by_user_id INTEGER NOT NULL,
        current_assignee_user_id INTEGER NULL,
        proposed_assignee_user_id INTEGER NULL,
        reassignment_reason TEXT NOT NULL DEFAULT 'other',
        reason TEXT NOT NULL,
        urgency TEXT NOT NULL DEFAULT 'normal',
        status TEXT NOT NULL DEFAULT 'pending',
        requested_at TEXT NULL,
        approved_by_user_id INTEGER NULL,
        approved_at TEXT NULL,
        declined_by_user_id INTEGER NULL,
        declined_at TEXT NULL,
        cancelled_by_user_id INTEGER NULL,
        cancelled_at TEXT NULL,
        fulfilled_by_user_id INTEGER NULL,
        fulfilled_at TEXT NULL,
        new_assignee_user_id INTEGER NULL,
        rejection_reason TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE workorder_reassignment_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        request_id INTEGER NULL,
        from_user_id INTEGER NULL,
        to_user_id INTEGER NOT NULL,
        reassigned_by_user_id INTEGER NULL,
        reassigned_at TEXT NOT NULL,
        reason TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL
    )");

    return $pdo;
}

class RaPermissiveGate extends AccessGate
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

function makeRaFixture(): array
{
    $pdo = raSetUpDatabase();
    $conn = new RaInMemoryConnection($pdo);
    $gate = new RaPermissiveGate();
    $repo = new ReassignmentRepository($conn);
    $service = new ReassignmentService($repo, $gate);
    $controller = new ReassignmentController($service);

    // WO 100 currently assigned to tech 8; WO 101 currently unassigned.
    $pdo->exec("INSERT INTO workorders (id, assigned_technician_id, status)
                VALUES (100, 8, 'open'), (101, NULL, 'open')");
    $pdo->exec("INSERT INTO users (id, name) VALUES
                (7, 'Manager Bob'), (8, 'Tech Carol'),
                (9, 'Tech Dave'), (10, 'Tech Eve')");

    return compact('pdo', 'conn', 'gate', 'repo', 'service', 'controller');
}

function raAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function raAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function raAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
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

function makeRaUser(int $id = 7, string $role = 'manager'): User
{
    $u = new User();
    $u->id = $id;
    $u->role = $role;
    return $u;
}

function makeRaPendingRequest(array $f, int $workorderId = 100, int $requestor = 8, array $extra = []): WorkorderReassignmentRequest
{
    return $f['service']->createRequest(makeRaUser($requestor, 'technician'), $workorderId, array_merge([
        'reassignment_reason' => WorkorderReassignmentRequest::REASON_EMERGENCY,
        'reason' => 'Family emergency, cannot finish today',
        'urgency' => WorkorderReassignmentRequest::URGENCY_HIGH,
    ], $extra));
}

function raCurrentTechOnWo(PDO $pdo, int $workorderId): ?int
{
    $stmt = $pdo->prepare('SELECT assigned_technician_id FROM workorders WHERE id = :id');
    $stmt->execute(['id' => $workorderId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row && $row['assigned_technician_id'] !== null ? (int) $row['assigned_technician_id'] : null;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Model constants & state machine ────

$tests['request_status_constants'] = function () {
    raAssertSame(
        ['pending', 'approved', 'declined', 'cancelled', 'fulfilled'],
        WorkorderReassignmentRequest::STATUSES
    );
};

$tests['allowed_transitions_published'] = function () {
    raAssertSame(
        ['pending'],
        WorkorderReassignmentRequest::ALLOWED_TRANSITIONS[WorkorderReassignmentRequest::STATUS_APPROVED]
    );
    raAssertSame(
        ['approved'],
        WorkorderReassignmentRequest::ALLOWED_TRANSITIONS[WorkorderReassignmentRequest::STATUS_FULFILLED]
    );
    raAssertSame(
        ['pending', 'approved'],
        WorkorderReassignmentRequest::ALLOWED_TRANSITIONS[WorkorderReassignmentRequest::STATUS_CANCELLED]
    );
};

$tests['reassignment_reasons_published'] = function () {
    raAssertSame(
        ['emergency_unavailable', 'skill_mismatch', 'scheduling_conflict',
            'safety_concern', 'customer_request', 'other'],
        WorkorderReassignmentRequest::REASONS
    );
};

// ──── Request creation + validation ────

$tests['create_request_requires_reason'] = function () {
    $f = makeRaFixture();
    raAssertThrows(
        fn() => $f['service']->createRequest(makeRaUser(8), 100, []),
        InvalidArgumentException::class
    );
};

$tests['create_request_rejects_unknown_reason_category'] = function () {
    $f = makeRaFixture();
    raAssertThrows(
        fn() => $f['service']->createRequest(makeRaUser(8), 100, [
            'reason' => 'x', 'reassignment_reason' => 'bogus',
        ]),
        InvalidArgumentException::class
    );
};

$tests['create_request_rejects_unknown_urgency'] = function () {
    $f = makeRaFixture();
    raAssertThrows(
        fn() => $f['service']->createRequest(makeRaUser(8), 100, [
            'reason' => 'x', 'urgency' => 'mega',
        ]),
        InvalidArgumentException::class
    );
};

$tests['create_request_snapshots_current_assignee'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    raAssertSame(8, $req->current_assignee_user_id, 'snapshot reads from workorder');
    raAssertSame('pending', $req->status);
    raAssertSame(8, $req->requested_by_user_id);
};

$tests['create_request_handles_unassigned_workorder'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 101, 9);
    raAssertSame(null, $req->current_assignee_user_id, 'no current tech on WO 101');
};

$tests['create_request_rejects_proposed_equals_current'] = function () {
    $f = makeRaFixture();
    raAssertThrows(
        fn() => $f['service']->createRequest(makeRaUser(8), 100, [
            'reason' => 'x', 'proposed_assignee_user_id' => 8,
        ]),
        InvalidArgumentException::class,
        'proposed cannot equal current'
    );
};

$tests['create_request_accepts_proposed_different_from_current'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8, ['proposed_assignee_user_id' => 9]);
    raAssertSame(9, $req->proposed_assignee_user_id);
};

$tests['create_request_stamps_requested_at'] = function () {
    $f = makeRaFixture();
    $now = new DateTimeImmutable('2026-04-24 10:00:00');
    $req = $f['service']->createRequest(makeRaUser(8), 100, [
        'reason' => 'why',
    ], $now);
    raAssertSame('2026-04-24 10:00:00', $req->requested_at);
};

// ──── Lifecycle: approve ────

$tests['approve_moves_pending_to_approved'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    $now = new DateTimeImmutable('2026-04-24 11:00:00');
    $approved = $f['service']->approveRequest(makeRaUser(7), $req->id, $now);
    raAssertSame('approved', $approved->status);
    raAssertSame(7, $approved->approved_by_user_id);
    raAssertSame('2026-04-24 11:00:00', $approved->approved_at);
};

$tests['approve_blocked_when_already_approved'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    raAssertThrows(
        fn() => $f['service']->approveRequest(makeRaUser(7), $req->id),
        InvalidArgumentException::class
    );
};

// ──── Lifecycle: decline ────

$tests['decline_requires_reason'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    raAssertThrows(
        fn() => $f['service']->declineRequest(makeRaUser(7), $req->id, ' '),
        InvalidArgumentException::class
    );
};

$tests['decline_moves_pending_to_declined_with_reason'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    $now = new DateTimeImmutable('2026-04-24 12:00:00');
    $decl = $f['service']->declineRequest(makeRaUser(7), $req->id, 'No backup tech available', $now);
    raAssertSame('declined', $decl->status);
    raAssertSame('No backup tech available', $decl->rejection_reason);
    raAssertSame(7, $decl->declined_by_user_id);
    raAssertSame('2026-04-24 12:00:00', $decl->declined_at);
};

$tests['decline_blocked_after_approval'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    raAssertThrows(
        fn() => $f['service']->declineRequest(makeRaUser(7), $req->id, 'too late'),
        InvalidArgumentException::class
    );
};

// ──── Lifecycle: cancel ────

$tests['cancel_owner_can_cancel_their_own_pending_request'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    $f['gate']->denials['workorder_reassignment_requests.manage'] = true;
    $cancelled = $f['service']->cancelRequest(makeRaUser(8, 'technician'), $req->id);
    raAssertSame('cancelled', $cancelled->status);
    raAssertSame(8, $cancelled->cancelled_by_user_id);
};

$tests['cancel_non_owner_requires_manage_permission'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    $f['gate']->denials['workorder_reassignment_requests.manage'] = true;
    raAssertThrows(
        fn() => $f['service']->cancelRequest(makeRaUser(9, 'technician'), $req->id),
        UnauthorizedException::class
    );
};

$tests['cancel_works_from_approved_state'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    $cancelled = $f['service']->cancelRequest(makeRaUser(7), $req->id);
    raAssertSame('cancelled', $cancelled->status);
};

$tests['cancel_blocked_from_fulfilled_state'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    $f['service']->fulfilRequest(makeRaUser(7), $req->id, ['new_assignee_user_id' => 9]);
    raAssertThrows(
        fn() => $f['service']->cancelRequest(makeRaUser(7), $req->id),
        InvalidArgumentException::class
    );
};

// ──── Lifecycle: fulfil ────

$tests['fulfil_requires_new_assignee_user_id'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    raAssertThrows(
        fn() => $f['service']->fulfilRequest(makeRaUser(7), $req->id, []),
        InvalidArgumentException::class
    );
};

$tests['fulfil_blocked_from_pending_state'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    raAssertThrows(
        fn() => $f['service']->fulfilRequest(makeRaUser(7), $req->id, ['new_assignee_user_id' => 9]),
        InvalidArgumentException::class,
        'must approve before fulfilling'
    );
};

$tests['fulfil_updates_workorder_assigned_technician'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    raAssertSame(8, raCurrentTechOnWo($f['pdo'], 100), 'pre-condition: WO 100 still on tech 8');

    $now = new DateTimeImmutable('2026-04-24 13:00:00');
    $fulfilled = $f['service']->fulfilRequest(makeRaUser(7), $req->id, [
        'new_assignee_user_id' => 9,
    ], $now);
    raAssertSame('fulfilled', $fulfilled->status);
    raAssertSame(9, $fulfilled->new_assignee_user_id);
    raAssertSame(7, $fulfilled->fulfilled_by_user_id);
    raAssertSame('2026-04-24 13:00:00', $fulfilled->fulfilled_at);
    raAssertSame(9, raCurrentTechOnWo($f['pdo'], 100), 'WO row was updated atomically');
};

$tests['fulfil_appends_history_row'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    $now = new DateTimeImmutable('2026-04-24 13:30:00');
    $f['service']->fulfilRequest(makeRaUser(7), $req->id, [
        'new_assignee_user_id' => 9,
        'notes' => 'Carol called out sick',
    ], $now);

    $hist = $f['service']->listHistoryForWorkorder(makeRaUser(7), 100);
    raAssertSame(1, count($hist));
    raAssertSame(8, $hist[0]->from_user_id);
    raAssertSame(9, $hist[0]->to_user_id);
    raAssertSame(7, $hist[0]->reassigned_by_user_id);
    raAssertSame($req->id, $hist[0]->request_id);
    raAssertSame('2026-04-24 13:30:00', $hist[0]->reassigned_at);
    raAssertTrue(str_contains((string) $hist[0]->reason, 'emergency_unavailable'),
        'reason carries the structured category prefix');
};

$tests['fulfil_rejects_when_new_assignee_already_current'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    // current tech on WO 100 is 8; trying to fulfil with 8 is a no-op.
    raAssertThrows(
        fn() => $f['service']->fulfilRequest(makeRaUser(7), $req->id, ['new_assignee_user_id' => 8]),
        InvalidArgumentException::class,
        'no-op reassignment must be rejected'
    );
};

$tests['fulfil_handles_drift_when_wo_was_reassigned_during_pending'] = function () {
    // Request snapshots current=8 at create, then someone else reassigns WO 100
    // to tech 10 via the direct path while the request is pending. Now the
    // request is approved + fulfilled with new=9 — fulfilment must use the
    // CURRENT (10) as from_user_id, not the stale snapshot (8).
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    $f['service']->reassignDirectly(makeRaUser(7), 100, ['new_assignee_user_id' => 10]);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    $f['service']->fulfilRequest(makeRaUser(7), $req->id, ['new_assignee_user_id' => 9]);

    $hist = $f['service']->listHistoryForWorkorder(makeRaUser(7), 100);
    raAssertSame(2, count($hist), 'two history rows: direct then fulfilment');
    raAssertSame(10, $hist[1]->from_user_id, 'fulfilment from-user reflects current at fulfil time, not snapshot');
    raAssertSame(9, $hist[1]->to_user_id);
};

// ──── Direct (no-request) reassignment path ────

$tests['reassign_directly_requires_new_assignee'] = function () {
    $f = makeRaFixture();
    raAssertThrows(
        fn() => $f['service']->reassignDirectly(makeRaUser(7), 100, []),
        InvalidArgumentException::class
    );
};

$tests['reassign_directly_rejects_no_op'] = function () {
    $f = makeRaFixture();
    // WO 100 is currently on tech 8.
    raAssertThrows(
        fn() => $f['service']->reassignDirectly(makeRaUser(7), 100, ['new_assignee_user_id' => 8]),
        InvalidArgumentException::class
    );
};

$tests['reassign_directly_updates_workorder_and_appends_history'] = function () {
    $f = makeRaFixture();
    $now = new DateTimeImmutable('2026-04-24 09:30:00');
    $hist = $f['service']->reassignDirectly(makeRaUser(7), 100, [
        'new_assignee_user_id' => 9,
        'reason' => 'emergency reroute',
    ], $now);
    raAssertSame(8, $hist->from_user_id);
    raAssertSame(9, $hist->to_user_id);
    raAssertSame(null, $hist->request_id, 'direct path leaves request_id null');
    raAssertSame('emergency reroute', $hist->reason);
    raAssertSame(9, raCurrentTechOnWo($f['pdo'], 100));
};

$tests['reassign_directly_handles_unassigned_workorder'] = function () {
    $f = makeRaFixture();
    // WO 101 starts unassigned.
    $hist = $f['service']->reassignDirectly(makeRaUser(7), 101, ['new_assignee_user_id' => 9]);
    raAssertSame(null, $hist->from_user_id, 'from-user nullable when WO had no prior tech');
    raAssertSame(9, $hist->to_user_id);
    raAssertSame(9, raCurrentTechOnWo($f['pdo'], 101));
};

// ──── Update request ────

$tests['update_request_strips_lifecycle_fields'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    $updated = $f['service']->updateRequest(makeRaUser(8, 'technician'), $req->id, [
        'status' => 'fulfilled',          // stripped
        'approved_at' => '2099-01-01',    // stripped
        'new_assignee_user_id' => 999,    // stripped
        'reason' => 'updated reason',     // accepted
        'urgency' => 'urgent',            // accepted
    ]);
    raAssertSame('pending', $updated->status, 'status untouched');
    raAssertSame(null, $updated->approved_at);
    raAssertSame(null, $updated->new_assignee_user_id);
    raAssertSame('updated reason', $updated->reason);
    raAssertSame('urgent', $updated->urgency);
};

$tests['update_request_blocked_after_approval'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    raAssertThrows(
        fn() => $f['service']->updateRequest(makeRaUser(8, 'technician'), $req->id, ['reason' => 'no']),
        InvalidArgumentException::class
    );
};

$tests['update_request_rejects_proposed_equals_current'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f, 100, 8);
    raAssertThrows(
        fn() => $f['service']->updateRequest(makeRaUser(8, 'technician'), $req->id, [
            'proposed_assignee_user_id' => 8,
        ]),
        InvalidArgumentException::class
    );
};

// ──── Permissions ────

$tests['view_requires_view_permission'] = function () {
    $f = makeRaFixture();
    $f['gate']->denials['workorder_reassignment_requests.view'] = true;
    raAssertThrows(
        fn() => $f['service']->listRequestsForWorkorder(makeRaUser(7), 100),
        UnauthorizedException::class
    );
};

$tests['create_requires_create_permission'] = function () {
    $f = makeRaFixture();
    $f['gate']->denials['workorder_reassignment_requests.create'] = true;
    raAssertThrows(
        fn() => $f['service']->createRequest(makeRaUser(8), 100, ['reason' => 'x']),
        UnauthorizedException::class
    );
};

$tests['approve_requires_manage_permission'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    $f['gate']->denials['workorder_reassignment_requests.manage'] = true;
    raAssertThrows(
        fn() => $f['service']->approveRequest(makeRaUser(7), $req->id),
        UnauthorizedException::class
    );
};

$tests['fulfil_requires_manage_permission'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    $f['service']->approveRequest(makeRaUser(7), $req->id);
    $f['gate']->denials['workorder_reassignment_requests.manage'] = true;
    raAssertThrows(
        fn() => $f['service']->fulfilRequest(makeRaUser(7), $req->id, ['new_assignee_user_id' => 9]),
        UnauthorizedException::class
    );
};

$tests['direct_reassign_requires_manage_permission'] = function () {
    $f = makeRaFixture();
    $f['gate']->denials['workorder_reassignment_requests.manage'] = true;
    raAssertThrows(
        fn() => $f['service']->reassignDirectly(makeRaUser(7), 100, ['new_assignee_user_id' => 9]),
        UnauthorizedException::class
    );
};

// ──── Filters ────

$tests['list_requests_filters_by_status'] = function () {
    $f = makeRaFixture();
    $a = makeRaPendingRequest($f, 100, 8);
    $b = makeRaPendingRequest($f, 101, 9);
    $f['service']->approveRequest(makeRaUser(7), $b->id);

    $pending = $f['service']->listRequests(makeRaUser(7), ['status' => 'pending']);
    raAssertSame(1, count($pending));
    raAssertSame($a->id, $pending[0]->id);

    $approved = $f['service']->listRequests(makeRaUser(7), ['status' => 'approved']);
    raAssertSame(1, count($approved));
    raAssertSame($b->id, $approved[0]->id);
};

$tests['list_requests_filters_by_urgency'] = function () {
    $f = makeRaFixture();
    makeRaPendingRequest($f, 100, 8, ['urgency' => 'urgent']);
    makeRaPendingRequest($f, 101, 9, ['urgency' => 'normal']);
    $urgent = $f['service']->listRequests(makeRaUser(7), ['urgency' => 'urgent']);
    raAssertSame(1, count($urgent));
};

$tests['list_requests_filters_by_reason_category'] = function () {
    $f = makeRaFixture();
    makeRaPendingRequest($f, 100, 8, ['reassignment_reason' => 'safety_concern']);
    makeRaPendingRequest($f, 101, 9, ['reassignment_reason' => 'skill_mismatch']);
    $safety = $f['service']->listRequests(makeRaUser(7), ['reassignment_reason' => 'safety_concern']);
    raAssertSame(1, count($safety));
};

// ──── History ────

$tests['history_lists_in_chronological_order'] = function () {
    $f = makeRaFixture();
    $f['service']->reassignDirectly(makeRaUser(7), 100, ['new_assignee_user_id' => 9],
        new DateTimeImmutable('2026-04-24 09:00:00'));
    $f['service']->reassignDirectly(makeRaUser(7), 100, ['new_assignee_user_id' => 10],
        new DateTimeImmutable('2026-04-24 10:00:00'));
    $hist = $f['service']->listHistoryForWorkorder(makeRaUser(7), 100);
    raAssertSame(2, count($hist));
    raAssertSame(8, $hist[0]->from_user_id, 'first hop is from original tech');
    raAssertSame(9, $hist[0]->to_user_id);
    raAssertSame(9, $hist[1]->from_user_id, 'second hop is from the previous to-user');
    raAssertSame(10, $hist[1]->to_user_id);
};

// ──── Controller envelope shape ────

$tests['controller_create_returns_data_envelope'] = function () {
    $f = makeRaFixture();
    $resp = $f['controller']->createRequest(makeRaUser(8, 'technician'), 100, [
        'reason' => 'wrap me',
    ]);
    raAssertTrue(array_key_exists('data', $resp));
    raAssertSame('wrap me', $resp['data']['reason']);
    raAssertSame('pending', $resp['data']['status']);
    raAssertSame(8, $resp['data']['current_assignee_user_id']);
};

$tests['controller_decline_requires_reason_in_body'] = function () {
    $f = makeRaFixture();
    $req = makeRaPendingRequest($f);
    raAssertThrows(
        fn() => $f['controller']->declineRequest(makeRaUser(7), $req->id, []),
        InvalidArgumentException::class
    );
};

$tests['controller_history_returns_data_envelope'] = function () {
    $f = makeRaFixture();
    $f['service']->reassignDirectly(makeRaUser(7), 100, ['new_assignee_user_id' => 9]);
    $resp = $f['controller']->listHistoryForWorkorder(makeRaUser(7), 100);
    raAssertTrue(array_key_exists('data', $resp));
    raAssertSame(1, count($resp['data']));
    raAssertSame(9, $resp['data'][0]['to_user_id']);
};

$tests['controller_reassign_now_returns_history_row'] = function () {
    $f = makeRaFixture();
    $resp = $f['controller']->reassignDirectly(makeRaUser(7), 100, [
        'new_assignee_user_id' => 9, 'reason' => 'on-call swap',
    ]);
    raAssertTrue(array_key_exists('data', $resp));
    raAssertSame(9, $resp['data']['to_user_id']);
    raAssertSame('on-call swap', $resp['data']['reason']);
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "ReassignmentServiceTest\n";
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
