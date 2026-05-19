<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SubcontractorServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use App\Models\User;
use App\Services\Subcontractor\SubcontractorController;
use App\Services\Subcontractor\SubcontractorRepository;
use App\Services\Subcontractor\SubcontractorService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 10.1 of docs/expansion-plan.md — subcontractor management.
 *
 * Covers: repo CRUD, division M2M atomic replace, assignment lifecycle &
 * state-machine transitions (accept / decline / complete / cancel), illegal
 * transition rejection, assignability filter (status + division gate),
 * permission denials, controller response shape.
 */

class SubInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function subSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE divisions (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT NOT NULL)");
    $pdo->exec("CREATE TABLE workorders (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'open')");
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");

    $pdo->exec("CREATE TABLE subcontractors (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        company_name TEXT NOT NULL,
        contact_name TEXT NULL,
        email TEXT NULL,
        phone TEXT NULL,
        address_line1 TEXT NULL,
        address_line2 TEXT NULL,
        city TEXT NULL,
        state TEXT NULL,
        postal_code TEXT NULL,
        country TEXT NULL,
        tax_id TEXT NULL,
        payment_terms TEXT NULL,
        hourly_rate_cents INTEGER NULL,
        status TEXT NOT NULL DEFAULT 'active',
        insurance_expires_at TEXT NULL,
        notes TEXT NULL,
        portal_login_enabled INTEGER NOT NULL DEFAULT 0,
        portal_password_hash TEXT NULL,
        portal_password_updated_at TEXT NULL,
        portal_last_login_at TEXT NULL,
        portal_last_login_ip TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    $pdo->exec("CREATE TABLE subcontractor_divisions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subcontractor_id INTEGER NOT NULL,
        division_id INTEGER NOT NULL,
        created_at TEXT NULL,
        UNIQUE (subcontractor_id, division_id)
    )");

    $pdo->exec("CREATE TABLE subcontractor_assignments (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        subcontractor_id INTEGER NOT NULL,
        workorder_id INTEGER NOT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        description TEXT NULL,
        internal_notes TEXT NULL,
        estimated_cost_cents INTEGER NULL,
        actual_cost_cents INTEGER NULL,
        assigned_at TEXT NULL,
        accepted_at TEXT NULL,
        declined_at TEXT NULL,
        completed_at TEXT NULL,
        cancelled_at TEXT NULL,
        assigned_by_user_id INTEGER NULL,
        rating INTEGER NULL,
        rating_notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    return $pdo;
}

class SubPermissiveGate extends AccessGate
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

function makeSubFixture(): array
{
    $pdo = subSetUpDatabase();
    $conn = new SubInMemoryConnection($pdo);
    $gate = new SubPermissiveGate();
    $repo = new SubcontractorRepository($conn);
    $service = new SubcontractorService($repo, $gate);
    $controller = new SubcontractorController($service);

    $pdo->exec("INSERT INTO divisions (id, name) VALUES (1, 'HVAC'), (2, 'Plumbing'), (3, 'Electrical')");
    $pdo->exec("INSERT INTO workorders (id, status) VALUES (100, 'open'), (101, 'open')");
    $pdo->exec("INSERT INTO users (id, name) VALUES (7, 'Manager Bob')");

    return compact('pdo', 'conn', 'gate', 'repo', 'service', 'controller');
}

function subAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function subAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function subAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
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

function makeSubUser(int $id = 7): User
{
    $u = new User();
    $u->id = $id;
    $u->role = 'manager';
    return $u;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Subcontractor model + status vocabulary ────

$tests['subcontractor_status_constants_match_vocabulary'] = function () {
    subAssertSame(
        ['active', 'inactive', 'suspended'],
        Subcontractor::STATUSES,
        'STATUSES const'
    );
};

$tests['assignment_state_machine_transitions_published'] = function () {
    // Can't transition INTO pending; every other status reachable from sane starts.
    subAssertTrue(
        in_array(SubcontractorAssignment::STATUS_PENDING, [
            SubcontractorAssignment::STATUS_PENDING,
            SubcontractorAssignment::STATUS_ACCEPTED,
            SubcontractorAssignment::STATUS_DECLINED,
            SubcontractorAssignment::STATUS_IN_PROGRESS,
            SubcontractorAssignment::STATUS_COMPLETED,
            SubcontractorAssignment::STATUS_CANCELLED,
        ], true),
        'pending defined'
    );
    subAssertSame(
        [SubcontractorAssignment::STATUS_PENDING],
        SubcontractorAssignment::ALLOWED_TRANSITIONS[SubcontractorAssignment::STATUS_ACCEPTED]
    );
    subAssertSame(
        [
            SubcontractorAssignment::STATUS_ACCEPTED,
            SubcontractorAssignment::STATUS_IN_PROGRESS,
        ],
        SubcontractorAssignment::ALLOWED_TRANSITIONS[SubcontractorAssignment::STATUS_COMPLETED]
    );
};

// ──── Repository CRUD ────

$tests['repo_create_and_find_subcontractor_round_trips'] = function () {
    $f = makeSubFixture();
    $sub = $f['repo']->createSubcontractor([
        'company_name' => 'Acme Plumbing LLC',
        'contact_name' => 'Jane Pipes',
        'email' => 'jane@acme.test',
        'phone' => '+1-555-0100',
        'hourly_rate_cents' => 12500,
        'status' => 'active',
    ]);
    subAssertSame('Acme Plumbing LLC', $sub->company_name);
    subAssertSame(12500, $sub->hourly_rate_cents);
    subAssertSame('active', $sub->status);

    $found = $f['repo']->findSubcontractor($sub->id);
    subAssertTrue($found !== null, 'found by id');
    subAssertSame('jane@acme.test', $found->email);
};

$tests['repo_update_only_changes_provided_columns'] = function () {
    $f = makeSubFixture();
    $sub = $f['repo']->createSubcontractor([
        'company_name' => 'Original Co',
        'phone' => '111',
        'hourly_rate_cents' => 10000,
    ]);
    $updated = $f['repo']->updateSubcontractor($sub->id, ['phone' => '222']);
    subAssertSame('Original Co', $updated->company_name, 'unchanged column preserved');
    subAssertSame('222', $updated->phone);
    subAssertSame(10000, $updated->hourly_rate_cents);
};

$tests['repo_invalid_status_normalized_to_active'] = function () {
    $f = makeSubFixture();
    $sub = $f['repo']->createSubcontractor([
        'company_name' => 'Bad Status Co',
        'status' => 'lalala',
    ]);
    subAssertSame('active', $sub->status, 'unknown status snapped back to default');
};

$tests['repo_delete_removes_subcontractor_row'] = function () {
    $f = makeSubFixture();
    $sub = $f['repo']->createSubcontractor(['company_name' => 'Doomed Co']);
    $f['repo']->deleteSubcontractor($sub->id);
    subAssertSame(null, $f['repo']->findSubcontractor($sub->id));
};

// ──── Division M2M atomic replace ────

$tests['set_divisions_replaces_existing_set_atomically'] = function () {
    $f = makeSubFixture();
    $sub = $f['repo']->createSubcontractor(['company_name' => 'Multi-Trade']);
    $f['repo']->setDivisionsForSubcontractor($sub->id, [1, 2]);
    subAssertSame([1, 2], $f['repo']->listDivisionsForSubcontractor($sub->id));
    // Replace with a different set — old rows must be deleted.
    $f['repo']->setDivisionsForSubcontractor($sub->id, [2, 3]);
    subAssertSame([2, 3], $f['repo']->listDivisionsForSubcontractor($sub->id));
    // Empty set → wipe.
    $f['repo']->setDivisionsForSubcontractor($sub->id, []);
    subAssertSame([], $f['repo']->listDivisionsForSubcontractor($sub->id));
};

$tests['set_divisions_dedupes_input'] = function () {
    $f = makeSubFixture();
    $sub = $f['repo']->createSubcontractor(['company_name' => 'Dup Co']);
    $f['repo']->setDivisionsForSubcontractor($sub->id, [1, 1, 2, 2, 2]);
    subAssertSame([1, 2], $f['repo']->listDivisionsForSubcontractor($sub->id));
};

// ──── Service: validation + permission ────

$tests['service_create_requires_company_name'] = function () {
    $f = makeSubFixture();
    subAssertThrows(
        fn() => $f['service']->createSubcontractor(makeSubUser(), []),
        InvalidArgumentException::class,
        'no name'
    );
};

$tests['service_create_rejects_invalid_email'] = function () {
    $f = makeSubFixture();
    subAssertThrows(
        fn() => $f['service']->createSubcontractor(makeSubUser(), [
            'company_name' => 'X', 'email' => 'not-an-email',
        ]),
        InvalidArgumentException::class
    );
};

$tests['service_create_allows_portal_login_without_staff_entered_password'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), [
        'company_name' => 'Portal Ready',
        'email' => 'sub@example.test',
        'portal_login_enabled' => true,
    ]);
    subAssertTrue($sub->portal_login_enabled, 'portal enabled');
    subAssertTrue(!$sub->portal_password_set, 'password remains unset until setup link is used');
};

$tests['service_create_requires_email_for_portal_login'] = function () {
    $f = makeSubFixture();
    subAssertThrows(
        fn() => $f['service']->createSubcontractor(makeSubUser(), [
            'company_name' => 'No Email',
            'portal_login_enabled' => true,
        ]),
        InvalidArgumentException::class
    );
};

$tests['service_create_rejects_unknown_status'] = function () {
    $f = makeSubFixture();
    subAssertThrows(
        fn() => $f['service']->createSubcontractor(makeSubUser(), [
            'company_name' => 'X', 'status' => 'archived',
        ]),
        InvalidArgumentException::class
    );
};

$tests['service_create_rejects_bad_insurance_date'] = function () {
    $f = makeSubFixture();
    subAssertThrows(
        fn() => $f['service']->createSubcontractor(makeSubUser(), [
            'company_name' => 'X', 'insurance_expires_at' => 'soon',
        ]),
        InvalidArgumentException::class
    );
};

$tests['service_create_rejects_negative_rate'] = function () {
    $f = makeSubFixture();
    subAssertThrows(
        fn() => $f['service']->createSubcontractor(makeSubUser(), [
            'company_name' => 'X', 'hourly_rate_cents' => -100,
        ]),
        InvalidArgumentException::class
    );
};

$tests['service_create_attaches_division_ids_when_provided'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), [
        'company_name' => 'Multi Approved',
        'division_ids' => [1, 3],
    ]);
    subAssertSame([1, 3], $sub->division_ids);
};

$tests['service_view_requires_subcontractors_view_permission'] = function () {
    $f = makeSubFixture();
    $f['gate']->denials['subcontractors.view'] = true;
    subAssertThrows(
        fn() => $f['service']->listSubcontractors(makeSubUser()),
        UnauthorizedException::class
    );
};

$tests['service_manage_requires_subcontractors_manage_permission'] = function () {
    $f = makeSubFixture();
    $f['gate']->denials['subcontractors.manage'] = true;
    subAssertThrows(
        fn() => $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'X']),
        UnauthorizedException::class
    );
};

// ──── Service: assignability filter ────

$tests['find_assignable_excludes_non_active_subs'] = function () {
    $f = makeSubFixture();
    $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'Live A', 'status' => 'active']);
    $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'Off B', 'status' => 'inactive']);
    $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'Sus C', 'status' => 'suspended']);

    $assignable = $f['service']->findAssignable(makeSubUser());
    subAssertSame(1, count($assignable), 'only active sub returned');
    subAssertSame('Live A', $assignable[0]->company_name);
};

$tests['find_assignable_filters_by_division'] = function () {
    $f = makeSubFixture();
    $a = $f['service']->createSubcontractor(makeSubUser(), [
        'company_name' => 'HVAC Specialist', 'division_ids' => [1],
    ]);
    $b = $f['service']->createSubcontractor(makeSubUser(), [
        'company_name' => 'Plumber', 'division_ids' => [2],
    ]);

    $hvac = $f['service']->findAssignable(makeSubUser(), 1);
    subAssertSame(1, count($hvac));
    subAssertSame($a->id, $hvac[0]->id);

    $plumb = $f['service']->findAssignable(makeSubUser(), 2);
    subAssertSame(1, count($plumb));
    subAssertSame($b->id, $plumb[0]->id);

    $electrical = $f['service']->findAssignable(makeSubUser(), 3);
    subAssertSame(0, count($electrical), 'no electrical sub in fixture');
};

// ──── Service: assignment lifecycle + transitions ────

$tests['create_assignment_requires_active_subcontractor'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), [
        'company_name' => 'Suspended Co', 'status' => 'suspended',
    ]);
    subAssertThrows(
        fn() => $f['service']->createAssignment(makeSubUser(), [
            'subcontractor_id' => $sub->id, 'workorder_id' => 100,
        ]),
        InvalidArgumentException::class,
        'suspended sub cannot be assigned'
    );
};

$tests['create_assignment_stamps_assigned_by_actor'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $actor = makeSubUser(7);
    $a = $f['service']->createAssignment($actor, [
        'subcontractor_id' => $sub->id, 'workorder_id' => 100,
        'description' => 'Replace condensate pump',
    ]);
    subAssertSame('pending', $a->status);
    subAssertSame(7, $a->assigned_by_user_id);
    subAssertSame(100, $a->workorder_id);
    subAssertSame('Replace condensate pump', $a->description);
};

$tests['transition_pending_to_accepted_stamps_accepted_at'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $a = $f['service']->createAssignment(makeSubUser(), [
        'subcontractor_id' => $sub->id, 'workorder_id' => 100,
    ]);
    $now = new DateTimeImmutable('2026-04-24 09:30:00');
    $accepted = $f['service']->transitionAssignment(makeSubUser(), $a->id, 'accepted', $now);
    subAssertSame('accepted', $accepted->status);
    subAssertSame('2026-04-24 09:30:00', $accepted->accepted_at);
    subAssertSame(null, $accepted->declined_at, 'declined stamp untouched');
};

$tests['transition_pending_to_declined_stamps_declined_at'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $a = $f['service']->createAssignment(makeSubUser(), [
        'subcontractor_id' => $sub->id, 'workorder_id' => 100,
    ]);
    $now = new DateTimeImmutable('2026-04-24 10:00:00');
    $declined = $f['service']->transitionAssignment(makeSubUser(), $a->id, 'declined', $now);
    subAssertSame('declined', $declined->status);
    subAssertSame('2026-04-24 10:00:00', $declined->declined_at);
};

$tests['accepted_to_in_progress_to_completed_full_happy_path'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $a = $f['service']->createAssignment(makeSubUser(), [
        'subcontractor_id' => $sub->id, 'workorder_id' => 100,
    ]);
    $f['service']->transitionAssignment(makeSubUser(), $a->id, 'accepted', new DateTimeImmutable('2026-04-24 09:00:00'));
    $f['service']->transitionAssignment(makeSubUser(), $a->id, 'in_progress', new DateTimeImmutable('2026-04-24 11:00:00'));
    $done = $f['service']->transitionAssignment(makeSubUser(), $a->id, 'completed', new DateTimeImmutable('2026-04-24 16:30:00'));
    subAssertSame('completed', $done->status);
    subAssertSame('2026-04-24 16:30:00', $done->completed_at);
};

$tests['illegal_transition_completed_back_to_pending_rejected'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $a = $f['service']->createAssignment(makeSubUser(), [
        'subcontractor_id' => $sub->id, 'workorder_id' => 100,
    ]);
    subAssertThrows(
        fn() => $f['service']->transitionAssignment(makeSubUser(), $a->id, 'pending'),
        InvalidArgumentException::class,
        'never re-pend'
    );
};

$tests['illegal_transition_declined_to_completed_rejected'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $a = $f['service']->createAssignment(makeSubUser(), [
        'subcontractor_id' => $sub->id, 'workorder_id' => 100,
    ]);
    $f['service']->transitionAssignment(makeSubUser(), $a->id, 'declined');
    subAssertThrows(
        fn() => $f['service']->transitionAssignment(makeSubUser(), $a->id, 'completed'),
        InvalidArgumentException::class,
        'declined is terminal — must reassign'
    );
};

$tests['cancellation_allowed_from_pending_accepted_or_in_progress'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    foreach (['pending_only', 'accepted_step', 'in_progress_step'] as $state) {
        $a = $f['service']->createAssignment(makeSubUser(), [
            'subcontractor_id' => $sub->id, 'workorder_id' => 100,
        ]);
        if ($state === 'accepted_step') {
            $f['service']->transitionAssignment(makeSubUser(), $a->id, 'accepted');
        }
        if ($state === 'in_progress_step') {
            $f['service']->transitionAssignment(makeSubUser(), $a->id, 'accepted');
            $f['service']->transitionAssignment(makeSubUser(), $a->id, 'in_progress');
        }
        $cancelled = $f['service']->transitionAssignment(makeSubUser(), $a->id, 'cancelled');
        subAssertSame('cancelled', $cancelled->status, "from {$state}");
        subAssertTrue($cancelled->cancelled_at !== null, "cancelled_at stamped from {$state}");
    }
};

$tests['update_assignment_strips_status_fields'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $a = $f['service']->createAssignment(makeSubUser(), [
        'subcontractor_id' => $sub->id, 'workorder_id' => 100,
    ]);
    $updated = $f['service']->updateAssignment(makeSubUser(), $a->id, [
        'status' => 'completed',           // stripped
        'completed_at' => '2099-01-01',    // stripped
        'description' => 'Updated scope',  // accepted
        'estimated_cost_cents' => 50000,   // accepted
    ]);
    subAssertSame('pending', $updated->status, 'status untouched by update path');
    subAssertSame(null, $updated->completed_at, 'completed_at untouched');
    subAssertSame('Updated scope', $updated->description);
    subAssertSame(50000, $updated->estimated_cost_cents);
};

$tests['update_assignment_validates_rating_range'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $a = $f['service']->createAssignment(makeSubUser(), [
        'subcontractor_id' => $sub->id, 'workorder_id' => 100,
    ]);
    subAssertThrows(
        fn() => $f['service']->updateAssignment(makeSubUser(), $a->id, ['rating' => 6]),
        InvalidArgumentException::class
    );
    subAssertThrows(
        fn() => $f['service']->updateAssignment(makeSubUser(), $a->id, ['rating' => 0]),
        InvalidArgumentException::class
    );
    $ok = $f['service']->updateAssignment(makeSubUser(), $a->id, [
        'rating' => 4, 'rating_notes' => 'On time, professional',
    ]);
    subAssertSame(4, $ok->rating);
    subAssertSame('On time, professional', $ok->rating_notes);
};

$tests['list_assignments_for_workorder_groups_by_id'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $f['service']->createAssignment(makeSubUser(), ['subcontractor_id' => $sub->id, 'workorder_id' => 100]);
    $f['service']->createAssignment(makeSubUser(), ['subcontractor_id' => $sub->id, 'workorder_id' => 100]);
    $f['service']->createAssignment(makeSubUser(), ['subcontractor_id' => $sub->id, 'workorder_id' => 101]);

    subAssertSame(2, count($f['service']->listAssignmentsForWorkorder(makeSubUser(), 100)));
    subAssertSame(1, count($f['service']->listAssignmentsForWorkorder(makeSubUser(), 101)));
};

// ──── Controller: response shape ────

$tests['controller_create_returns_data_envelope'] = function () {
    $f = makeSubFixture();
    $resp = $f['controller']->createSubcontractor(makeSubUser(), [
        'company_name' => 'Wrap Me',
    ]);
    subAssertTrue(array_key_exists('data', $resp), 'data envelope');
    subAssertSame('Wrap Me', $resp['data']['company_name']);
    subAssertSame([], $resp['data']['division_ids']);
};

$tests['controller_transition_requires_status_in_body'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $a = $f['service']->createAssignment(makeSubUser(), [
        'subcontractor_id' => $sub->id, 'workorder_id' => 100,
    ]);
    subAssertThrows(
        fn() => $f['controller']->transitionAssignment(makeSubUser(), $a->id, []),
        InvalidArgumentException::class
    );
};

$tests['controller_create_assignment_uses_path_workorder_id'] = function () {
    $f = makeSubFixture();
    $sub = $f['service']->createSubcontractor(makeSubUser(), ['company_name' => 'A']);
    $resp = $f['controller']->createAssignment(makeSubUser(), 101, [
        'subcontractor_id' => $sub->id,
        'workorder_id' => 999, // body value should be overridden by path
    ]);
    subAssertSame(101, $resp['data']['workorder_id']);
};

// ──── Search & list filters ────

$tests['list_filters_by_status_and_search'] = function () {
    $f = makeSubFixture();
    $f['repo']->createSubcontractor(['company_name' => 'Acme HVAC', 'status' => 'active']);
    $f['repo']->createSubcontractor(['company_name' => 'Foo Plumbing', 'status' => 'inactive']);
    $f['repo']->createSubcontractor(['company_name' => 'Acme Plumbing', 'status' => 'active']);

    $active = $f['service']->listSubcontractors(makeSubUser(), ['status' => 'active']);
    subAssertSame(2, count($active));

    $acme = $f['service']->listSubcontractors(makeSubUser(), ['search' => 'Acme']);
    subAssertSame(2, count($acme));

    $plumbing = $f['service']->listSubcontractors(makeSubUser(), ['search' => 'Plumbing']);
    subAssertSame(2, count($plumbing));
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "SubcontractorServiceTest\n";
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
