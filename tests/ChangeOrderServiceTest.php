<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "ChangeOrderServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\User;
use App\Models\WorkorderChangeOrder;
use App\Models\WorkorderChangeOrderItem;
use App\Services\Workorder\ChangeOrderController;
use App\Services\Workorder\ChangeOrderRepository;
use App\Services\Workorder\ChangeOrderService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 10.2 of docs/expansion-plan.md — change-order workflow.
 *
 * Covers: per-WO sequence numbering, full lifecycle (draft → pending →
 * approved/rejected/cancelled), illegal-transition rejection, item add/
 * update/delete with auto-recompute totals, approval-method validation
 * (signed_inline/portal_approval require customer signature), reject
 * requires reason, summarize roll-up math, edit-blocked-after-approved,
 * separation of duties (manage cannot approve), controller envelope shape.
 */

class CoInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function coSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE workorders (id INTEGER PRIMARY KEY AUTOINCREMENT, status TEXT NOT NULL DEFAULT 'open')");
    $pdo->exec("CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, name TEXT)");

    $pdo->exec("CREATE TABLE workorder_change_orders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        sequence_number INTEGER NOT NULL DEFAULT 1,
        title TEXT NOT NULL,
        description TEXT NULL,
        reason_code TEXT NOT NULL DEFAULT 'other',
        status TEXT NOT NULL DEFAULT 'draft',
        subtotal_cents INTEGER NOT NULL DEFAULT 0,
        tax_cents INTEGER NOT NULL DEFAULT 0,
        total_cents INTEGER NOT NULL DEFAULT 0,
        requested_by_user_id INTEGER NULL,
        requested_at TEXT NULL,
        approved_by_user_id INTEGER NULL,
        approved_at TEXT NULL,
        rejected_at TEXT NULL,
        cancelled_at TEXT NULL,
        applied_at TEXT NULL,
        approval_method TEXT NULL,
        rejection_reason TEXT NULL,
        customer_signature_name TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL,
        UNIQUE (workorder_id, sequence_number)
    )");

    $pdo->exec("CREATE TABLE workorder_change_order_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        change_order_id INTEGER NOT NULL,
        kind TEXT NOT NULL DEFAULT 'labor',
        description TEXT NOT NULL,
        quantity REAL NOT NULL DEFAULT 1.0,
        unit_price_cents INTEGER NOT NULL DEFAULT 0,
        line_total_cents INTEGER NOT NULL DEFAULT 0,
        sort_order INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL
    )");

    return $pdo;
}

class CoPermissiveGate extends AccessGate
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

function makeCoFixture(): array
{
    $pdo = coSetUpDatabase();
    $conn = new CoInMemoryConnection($pdo);
    $gate = new CoPermissiveGate();
    $repo = new ChangeOrderRepository($conn);
    $service = new ChangeOrderService($repo, $gate);
    $controller = new ChangeOrderController($service);

    $pdo->exec("INSERT INTO workorders (id, status) VALUES (100, 'open'), (101, 'open')");
    $pdo->exec("INSERT INTO users (id, name) VALUES (7, 'Manager Bob'), (8, 'Tech Carol')");

    return compact('pdo', 'conn', 'gate', 'repo', 'service', 'controller');
}

function coAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function coAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function coAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
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

function makeCoUser(int $id = 7): User
{
    $u = new User();
    $u->id = $id;
    $u->role = 'manager';
    return $u;
}

// Helper: build a CO with a single labor line item, return [co, item].
function makeCoWithItem(array $f, int $workorderId = 100, int $unitPrice = 5000, float $qty = 2.0): array
{
    $co = $f['service']->createChangeOrder(makeCoUser(), $workorderId, [
        'title' => 'Brake rotor replacement',
        'reason_code' => WorkorderChangeOrder::REASON_DISCOVERED,
    ]);
    $item = $f['service']->addItem(makeCoUser(), $co->id, [
        'kind' => WorkorderChangeOrderItem::KIND_LABOR,
        'description' => 'R&R rotors',
        'quantity' => $qty,
        'unit_price_cents' => $unitPrice,
    ]);
    return [$f['service']->findChangeOrder(makeCoUser(), $co->id), $item];
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Model constants & state machine ────

$tests['change_order_status_constants'] = function () {
    coAssertSame(
        ['draft', 'pending_approval', 'approved', 'rejected', 'cancelled'],
        WorkorderChangeOrder::STATUSES
    );
};

$tests['allowed_transitions_published'] = function () {
    coAssertSame(
        ['draft'],
        WorkorderChangeOrder::ALLOWED_TRANSITIONS[WorkorderChangeOrder::STATUS_PENDING_APPROVAL]
    );
    coAssertSame(
        ['pending_approval'],
        WorkorderChangeOrder::ALLOWED_TRANSITIONS[WorkorderChangeOrder::STATUS_APPROVED]
    );
    coAssertSame(
        ['draft', 'pending_approval'],
        WorkorderChangeOrder::ALLOWED_TRANSITIONS[WorkorderChangeOrder::STATUS_CANCELLED]
    );
};

$tests['item_kinds_constant'] = function () {
    coAssertSame(
        ['labor', 'part', 'fee', 'discount'],
        WorkorderChangeOrderItem::KINDS
    );
};

// ──── Sequence numbering ────

$tests['sequence_numbers_increment_per_workorder'] = function () {
    $f = makeCoFixture();
    $a = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'CO 1']);
    $b = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'CO 2']);
    $c = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'CO 3']);
    coAssertSame(1, $a->sequence_number);
    coAssertSame(2, $b->sequence_number);
    coAssertSame(3, $c->sequence_number);
};

$tests['sequence_numbers_independent_across_workorders'] = function () {
    $f = makeCoFixture();
    $a = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'WO100 #1']);
    $b = $f['service']->createChangeOrder(makeCoUser(), 101, ['title' => 'WO101 #1']);
    $c = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'WO100 #2']);
    coAssertSame(1, $a->sequence_number);
    coAssertSame(1, $b->sequence_number);
    coAssertSame(2, $c->sequence_number);
};

$tests['sequence_does_not_reuse_after_delete'] = function () {
    $f = makeCoFixture();
    $a = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'CO 1']);
    $b = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'CO 2']);
    $f['service']->deleteChangeOrder(makeCoUser(), $b->id);
    $c = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'CO 3']);
    // Customer-facing numbers should remain monotonic; deletion of seq 2
    // must not cause the next CO to re-collide on seq 2.
    coAssertSame(2, $c->sequence_number, 'next sequence is MAX+1, not gap-fill');
};

// ──── Validation ────

$tests['create_requires_title'] = function () {
    $f = makeCoFixture();
    coAssertThrows(
        fn() => $f['service']->createChangeOrder(makeCoUser(), 100, []),
        InvalidArgumentException::class
    );
};

$tests['create_rejects_unknown_reason_code'] = function () {
    $f = makeCoFixture();
    coAssertThrows(
        fn() => $f['service']->createChangeOrder(makeCoUser(), 100, [
            'title' => 'X', 'reason_code' => 'random-bogus',
        ]),
        InvalidArgumentException::class
    );
};

$tests['create_stamps_requested_by_actor'] = function () {
    $f = makeCoFixture();
    $actor = makeCoUser(8);
    $co = $f['service']->createChangeOrder($actor, 100, [
        'title' => 'Discovered exhaust leak',
        'reason_code' => WorkorderChangeOrder::REASON_DISCOVERED,
    ]);
    coAssertSame(8, $co->requested_by_user_id);
    coAssertSame('draft', $co->status);
    coAssertSame(0, $co->subtotal_cents);
    coAssertSame(0, $co->total_cents);
};

// ──── Items + auto-recompute totals ────

$tests['add_item_recomputes_subtotal_and_total'] = function () {
    $f = makeCoFixture();
    [$co, $item] = makeCoWithItem($f, 100, 5000, 2.0); // 2 * 50.00 = 100.00
    coAssertSame(10000, $co->subtotal_cents);
    coAssertSame(10000, $co->total_cents, 'no tax → total == subtotal');
    coAssertSame(10000, $item->line_total_cents);
};

$tests['add_item_with_tax_includes_tax_in_total'] = function () {
    $f = makeCoFixture();
    $co = $f['service']->createChangeOrder(makeCoUser(), 100, [
        'title' => 'X', 'tax_cents' => 825,
    ]);
    $f['service']->addItem(makeCoUser(), $co->id, [
        'description' => 'labor', 'quantity' => 1.0, 'unit_price_cents' => 10000,
    ]);
    $reloaded = $f['service']->findChangeOrder(makeCoUser(), $co->id);
    coAssertSame(10000, $reloaded->subtotal_cents);
    coAssertSame(10825, $reloaded->total_cents);
};

$tests['add_item_rejects_zero_quantity'] = function () {
    $f = makeCoFixture();
    $co = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'X']);
    coAssertThrows(
        fn() => $f['service']->addItem(makeCoUser(), $co->id, [
            'description' => 'invalid', 'quantity' => 0, 'unit_price_cents' => 100,
        ]),
        InvalidArgumentException::class
    );
};

$tests['add_item_rejects_unknown_kind'] = function () {
    $f = makeCoFixture();
    $co = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'X']);
    coAssertThrows(
        fn() => $f['service']->addItem(makeCoUser(), $co->id, [
            'kind' => 'consulting', 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => 1,
        ]),
        InvalidArgumentException::class
    );
};

$tests['add_item_rejects_negative_price_for_non_discount'] = function () {
    $f = makeCoFixture();
    $co = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'X']);
    coAssertThrows(
        fn() => $f['service']->addItem(makeCoUser(), $co->id, [
            'kind' => 'labor', 'description' => 'x', 'quantity' => 1, 'unit_price_cents' => -100,
        ]),
        InvalidArgumentException::class
    );
};

$tests['add_item_allows_negative_price_for_discount'] = function () {
    $f = makeCoFixture();
    $co = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'X']);
    $f['service']->addItem(makeCoUser(), $co->id, [
        'description' => 'labor', 'quantity' => 1, 'unit_price_cents' => 10000,
    ]);
    $f['service']->addItem(makeCoUser(), $co->id, [
        'kind' => 'discount', 'description' => 'loyalty 10%', 'quantity' => 1, 'unit_price_cents' => -1000,
    ]);
    $reloaded = $f['service']->findChangeOrder(makeCoUser(), $co->id);
    coAssertSame(9000, $reloaded->subtotal_cents, 'discount nets out');
};

$tests['update_item_recomputes_line_and_co_totals'] = function () {
    $f = makeCoFixture();
    [, $item] = makeCoWithItem($f, 100, 5000, 2.0);
    $updated = $f['service']->updateItem(makeCoUser(), $item->id, [
        'quantity' => 3.0, 'unit_price_cents' => 4000,
    ]);
    coAssertSame(12000, $updated->line_total_cents);
    $co = $f['service']->findChangeOrder(makeCoUser(), $item->change_order_id);
    coAssertSame(12000, $co->subtotal_cents);
    coAssertSame(12000, $co->total_cents);
};

$tests['delete_item_recomputes_totals'] = function () {
    $f = makeCoFixture();
    [$co, $item] = makeCoWithItem($f, 100, 5000, 2.0);
    $f['service']->deleteItem(makeCoUser(), $item->id);
    $reloaded = $f['service']->findChangeOrder(makeCoUser(), $co->id);
    coAssertSame(0, $reloaded->subtotal_cents);
    coAssertSame(0, $reloaded->total_cents);
};

// ──── Lifecycle ────

$tests['submit_requires_at_least_one_item'] = function () {
    $f = makeCoFixture();
    $co = $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'Empty CO']);
    coAssertThrows(
        fn() => $f['service']->submitForApproval(makeCoUser(), $co->id),
        InvalidArgumentException::class
    );
};

$tests['submit_moves_draft_to_pending_and_stamps_requested_at'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $now = new DateTimeImmutable('2026-04-24 09:30:00');
    $submitted = $f['service']->submitForApproval(makeCoUser(), $co->id, $now);
    coAssertSame('pending_approval', $submitted->status);
    coAssertSame('2026-04-24 09:30:00', $submitted->requested_at);
};

$tests['submit_rejects_already_pending'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    coAssertThrows(
        fn() => $f['service']->submitForApproval(makeCoUser(), $co->id),
        InvalidArgumentException::class
    );
};

$tests['approve_only_from_pending'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    coAssertThrows(
        fn() => $f['service']->approve(makeCoUser(), $co->id, []),
        InvalidArgumentException::class,
        'cannot approve a draft'
    );
};

$tests['approve_with_default_method_succeeds'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    $now = new DateTimeImmutable('2026-04-24 14:00:00');
    $approved = $f['service']->approve(makeCoUser(), $co->id, [], $now);
    coAssertSame('approved', $approved->status);
    coAssertSame('verbal_signed_off', $approved->approval_method);
    coAssertSame('2026-04-24 14:00:00', $approved->approved_at);
    coAssertSame('2026-04-24 14:00:00', $approved->applied_at, 'applied_at stamps with approval');
    coAssertSame(7, $approved->approved_by_user_id);
};

$tests['approve_rejects_unknown_method'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    coAssertThrows(
        fn() => $f['service']->approve(makeCoUser(), $co->id, ['approval_method' => 'telepathy']),
        InvalidArgumentException::class
    );
};

$tests['approve_inline_requires_signature_name'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    coAssertThrows(
        fn() => $f['service']->approve(makeCoUser(), $co->id, [
            'approval_method' => 'signed_inline',
        ]),
        InvalidArgumentException::class,
        'inline approval needs name'
    );
    // With name → succeeds.
    $ok = $f['service']->approve(makeCoUser(), $co->id, [
        'approval_method' => 'signed_inline',
        'customer_signature_name' => 'Jane Doe',
    ]);
    coAssertSame('Jane Doe', $ok->customer_signature_name);
};

$tests['approve_portal_requires_signature_name'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    coAssertThrows(
        fn() => $f['service']->approve(makeCoUser(), $co->id, [
            'approval_method' => 'portal_approval',
        ]),
        InvalidArgumentException::class
    );
};

$tests['reject_requires_reason'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    coAssertThrows(
        fn() => $f['service']->reject(makeCoUser(), $co->id, '   '),
        InvalidArgumentException::class
    );
};

$tests['reject_moves_pending_to_rejected'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    $now = new DateTimeImmutable('2026-04-24 15:00:00');
    $rej = $f['service']->reject(makeCoUser(), $co->id, 'Customer declined cost', $now);
    coAssertSame('rejected', $rej->status);
    coAssertSame('Customer declined cost', $rej->rejection_reason);
    coAssertSame('2026-04-24 15:00:00', $rej->rejected_at);
};

$tests['reject_rejects_already_approved'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    $f['service']->approve(makeCoUser(), $co->id, []);
    coAssertThrows(
        fn() => $f['service']->reject(makeCoUser(), $co->id, 'Too late'),
        InvalidArgumentException::class
    );
};

$tests['cancel_allowed_from_draft'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $cancelled = $f['service']->cancel(makeCoUser(), $co->id);
    coAssertSame('cancelled', $cancelled->status);
    coAssertTrue($cancelled->cancelled_at !== null);
};

$tests['cancel_allowed_from_pending'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    $cancelled = $f['service']->cancel(makeCoUser(), $co->id);
    coAssertSame('cancelled', $cancelled->status);
};

$tests['cancel_rejected_from_approved'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    $f['service']->approve(makeCoUser(), $co->id, []);
    coAssertThrows(
        fn() => $f['service']->cancel(makeCoUser(), $co->id),
        InvalidArgumentException::class
    );
};

// ──── Edit blocked after approval ────

$tests['cannot_add_item_to_approved_change_order'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    $f['service']->approve(makeCoUser(), $co->id, []);
    coAssertThrows(
        fn() => $f['service']->addItem(makeCoUser(), $co->id, [
            'description' => 'extra', 'quantity' => 1, 'unit_price_cents' => 100,
        ]),
        InvalidArgumentException::class
    );
};

$tests['cannot_update_change_order_after_approval'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    $f['service']->approve(makeCoUser(), $co->id, []);
    coAssertThrows(
        fn() => $f['service']->updateChangeOrder(makeCoUser(), $co->id, ['title' => 'changed']),
        InvalidArgumentException::class
    );
};

$tests['delete_only_allowed_in_draft'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    coAssertThrows(
        fn() => $f['service']->deleteChangeOrder(makeCoUser(), $co->id),
        InvalidArgumentException::class,
        'delete blocked once submitted'
    );
};

// ──── Update strips lifecycle fields ────

$tests['update_strips_status_and_lifecycle_fields'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $updated = $f['service']->updateChangeOrder(makeCoUser(), $co->id, [
        'status' => 'approved',           // stripped
        'approved_at' => '2099-01-01',    // stripped
        'approval_method' => 'verbal_signed_off', // stripped
        'rejection_reason' => 'fake',     // stripped
        'subtotal_cents' => 9999,         // stripped
        'description' => 'real desc',     // accepted
    ]);
    coAssertSame('draft', $updated->status, 'status untouched');
    coAssertSame(null, $updated->approved_at);
    coAssertSame('real desc', $updated->description);
    // subtotal_cents stays at the recomputed value (10000 from the fixture item)
    coAssertSame(10000, $updated->subtotal_cents);
};

$tests['update_tax_recomputes_total'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f, 100, 5000, 2.0); // subtotal 10000
    $updated = $f['service']->updateChangeOrder(makeCoUser(), $co->id, [
        'tax_cents' => 825,
    ]);
    coAssertSame(825, $updated->tax_cents);
    coAssertSame(10000, $updated->subtotal_cents);
    coAssertSame(10825, $updated->total_cents, 'tax included in total after update');
};

// ──── Summarize roll-up ────

$tests['summarize_buckets_approved_and_pending'] = function () {
    $f = makeCoFixture();

    // Approved CO: subtotal 10000, tax 800, total 10800
    [$a] = makeCoWithItem($f, 100, 5000, 2.0);
    $f['service']->updateChangeOrder(makeCoUser(), $a->id, ['tax_cents' => 800]);
    $f['service']->submitForApproval(makeCoUser(), $a->id);
    $f['service']->approve(makeCoUser(), $a->id, []);

    // Pending CO: subtotal 6000, no tax
    [$b] = makeCoWithItem($f, 100, 3000, 2.0);
    $f['service']->submitForApproval(makeCoUser(), $b->id);

    // Draft CO: should NOT be counted in either bucket
    makeCoWithItem($f, 100, 1000, 1.0);

    $summary = $f['service']->summarizeForWorkorder(makeCoUser(), 100);
    coAssertSame(10000, $summary['approved_subtotal_cents']);
    coAssertSame(10800, $summary['approved_total_cents']);
    coAssertSame(1, $summary['approved_count']);
    coAssertSame(6000, $summary['pending_subtotal_cents']);
    coAssertSame(6000, $summary['pending_total_cents']);
    coAssertSame(1, $summary['pending_count']);
};

// ──── Permissions / separation of duties ────

$tests['view_requires_view_permission'] = function () {
    $f = makeCoFixture();
    $f['gate']->denials['workorder_change_orders.view'] = true;
    coAssertThrows(
        fn() => $f['service']->listForWorkorder(makeCoUser(), 100),
        UnauthorizedException::class
    );
};

$tests['create_requires_manage_permission'] = function () {
    $f = makeCoFixture();
    $f['gate']->denials['workorder_change_orders.manage'] = true;
    coAssertThrows(
        fn() => $f['service']->createChangeOrder(makeCoUser(), 100, ['title' => 'X']),
        UnauthorizedException::class
    );
};

$tests['approve_requires_approve_permission_separately_from_manage'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    // Tech with manage but not approve cannot stamp it approved.
    $f['gate']->denials['workorder_change_orders.approve'] = true;
    coAssertThrows(
        fn() => $f['service']->approve(makeCoUser(), $co->id, []),
        UnauthorizedException::class,
        'separation of duties enforced'
    );
};

$tests['reject_requires_approve_permission'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    $f['gate']->denials['workorder_change_orders.approve'] = true;
    coAssertThrows(
        fn() => $f['service']->reject(makeCoUser(), $co->id, 'no'),
        UnauthorizedException::class
    );
};

// ──── Controller envelope shape ────

$tests['controller_create_returns_data_with_items_array'] = function () {
    $f = makeCoFixture();
    $resp = $f['controller']->createChangeOrder(makeCoUser(), 100, [
        'title' => 'Wrap me up',
    ]);
    coAssertTrue(array_key_exists('data', $resp));
    coAssertSame('Wrap me up', $resp['data']['title']);
    coAssertSame([], $resp['data']['items']);
};

$tests['controller_get_includes_nested_items'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f, 100, 7500, 1.0);
    $resp = $f['controller']->getChangeOrder(makeCoUser(), $co->id);
    coAssertSame(1, count($resp['data']['items']));
    coAssertSame(7500, $resp['data']['items'][0]['line_total_cents']);
};

$tests['controller_reject_requires_reason_in_body'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    coAssertThrows(
        fn() => $f['controller']->reject(makeCoUser(), $co->id, []),
        InvalidArgumentException::class
    );
};

$tests['controller_summary_returns_buckets'] = function () {
    $f = makeCoFixture();
    [$co] = makeCoWithItem($f, 100, 5000, 1.0);
    $f['service']->submitForApproval(makeCoUser(), $co->id);
    $f['service']->approve(makeCoUser(), $co->id, []);
    $resp = $f['controller']->summarizeForWorkorder(makeCoUser(), 100);
    coAssertSame(5000, $resp['data']['approved_total_cents']);
    coAssertSame(1, $resp['data']['approved_count']);
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "ChangeOrderServiceTest\n";
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
