<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Models\Workorder;
use App\Services\Customer\CustomerRepository;
use App\Services\Inventory\CoreReturnService;
use App\Services\Inventory\InventoryPullRequestService;
use App\Services\Inventory\InventoryStockOrderService;
use App\Services\ServiceLine\ServiceLineRepository;
use App\Services\ServiceLine\SubjectResolver;
use App\Services\Workorder\WorkorderRepository;
use App\Services\Workorder\WorkorderService;
use App\Support\Audit\AuditLogger;

class AutoInvoiceMemoryConnection extends Connection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

/** Type-stubs; LABOR-only fixtures never invoke either inventory service. */
class AutoInvoiceStubPullService extends InventoryPullRequestService
{
    public function __construct()
    {
    }
}

class AutoInvoiceStubStockService extends InventoryStockOrderService
{
    public function __construct()
    {
    }
}

function bootstrapAutoInvoiceSchema(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', static fn () => date('Y-m-d H:i:s'), 0);

    $pdo->exec('CREATE TABLE service_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        description TEXT NULL,
        icon TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE customers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        first_name TEXT NOT NULL,
        last_name TEXT NOT NULL,
        email TEXT NOT NULL,
        phone TEXT NOT NULL,
        company_id INTEGER NULL,
        is_commercial INTEGER NOT NULL DEFAULT 0,
        tax_exempt INTEGER NOT NULL DEFAULT 0,
        business_name TEXT NULL,
        street TEXT NULL,
        city TEXT NULL,
        state TEXT NULL,
        postal_code TEXT NULL,
        country TEXT NULL,
        billing_street TEXT NULL,
        billing_city TEXT NULL,
        billing_state TEXT NULL,
        billing_postal_code TEXT NULL,
        billing_country TEXT NULL,
        notes TEXT NULL,
        external_reference TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE workorders (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        number TEXT NOT NULL UNIQUE,
        estimate_id INTEGER NULL,
        customer_id INTEGER NOT NULL,
        vehicle_id INTEGER NULL,
        site_asset_id INTEGER NULL,
        fleet_unit_id INTEGER NULL,
        branch_id INTEGER NULL,
        service_line_id INTEGER NULL,
        unit_id INTEGER NULL,
        tenant_billable_party TEXT NULL,
        status TEXT NOT NULL,
        type TEXT NOT NULL DEFAULT "corrective",
        priority TEXT NOT NULL DEFAULT "medium",
        assigned_technician_id INTEGER NULL,
        started_at TEXT NULL,
        completed_at TEXT NULL,
        estimated_completion TEXT NULL,
        subtotal REAL NOT NULL DEFAULT 0,
        tax REAL NOT NULL DEFAULT 0,
        call_out_fee REAL NOT NULL DEFAULT 0,
        mileage_total REAL NOT NULL DEFAULT 0,
        discounts REAL NOT NULL DEFAULT 0,
        shop_fee REAL NOT NULL DEFAULT 0,
        hazmat_disposal_fee REAL NOT NULL DEFAULT 0,
        goa_fee REAL NOT NULL DEFAULT 0,
        goa_billing_party TEXT NULL,
        mileage_in INTEGER NULL,
        mileage_out INTEGER NULL,
        grand_total REAL NOT NULL DEFAULT 0,
        internal_notes TEXT NULL,
        customer_notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE workorder_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        branch_id INTEGER NULL,
        estimate_job_id INTEGER NULL,
        service_type_id INTEGER NULL,
        title TEXT NOT NULL,
        notes TEXT NULL,
        reference TEXT NULL,
        status TEXT NOT NULL,
        assigned_technician_id INTEGER NULL,
        subtotal REAL NOT NULL DEFAULT 0,
        tax REAL NOT NULL DEFAULT 0,
        total REAL NOT NULL DEFAULT 0,
        position INTEGER NOT NULL DEFAULT 0,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE workorder_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_job_id INTEGER NOT NULL,
        branch_id INTEGER NULL,
        type TEXT NOT NULL,
        sku TEXT NULL,
        inventory_item_id INTEGER NULL,
        description TEXT NOT NULL,
        quantity REAL NOT NULL,
        unit_price REAL NOT NULL,
        list_price REAL NULL,
        core_price REAL NULL,
        taxable INTEGER NOT NULL DEFAULT 0,
        line_total REAL NOT NULL,
        position INTEGER NOT NULL DEFAULT 0
    )');

    $pdo->exec('CREATE TABLE workorder_status_history (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        from_status TEXT NULL,
        to_status TEXT NOT NULL,
        changed_by INTEGER NULL,
        notes TEXT NULL,
        client_event_id TEXT NULL,
        created_at TEXT NULL
    )');

    // The UNIQUE on workorder_id is what production migration 175 enforces;
    // test it here too so the dup-key catch path actually fires.
    $pdo->exec('CREATE TABLE invoices (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        number TEXT NOT NULL UNIQUE,
        customer_id INTEGER NOT NULL,
        vehicle_id INTEGER NULL,
        site_asset_id INTEGER NULL,
        service_line_id INTEGER NULL,
        unit_id INTEGER NULL,
        tenant_billable_party TEXT NULL,
        estimate_id INTEGER NULL,
        workorder_id INTEGER NULL UNIQUE,
        branch_id INTEGER NULL,
        status TEXT NOT NULL,
        issue_date TEXT NOT NULL,
        due_date TEXT NULL,
        subtotal REAL NOT NULL DEFAULT 0,
        tax REAL NOT NULL DEFAULT 0,
        total REAL NOT NULL DEFAULT 0,
        shop_fee REAL NOT NULL DEFAULT 0,
        hazmat_disposal_fee REAL NOT NULL DEFAULT 0,
        amount_paid REAL NOT NULL DEFAULT 0,
        balance_due REAL NOT NULL DEFAULT 0,
        public_token TEXT NULL,
        public_token_expires_at TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE invoice_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_id INTEGER NOT NULL,
        branch_id INTEGER NULL,
        type TEXT NOT NULL,
        sku TEXT NULL,
        inventory_item_id INTEGER NULL,
        description TEXT NOT NULL,
        quantity REAL NOT NULL,
        unit_price REAL NOT NULL,
        list_price REAL NULL,
        core_price REAL NULL,
        taxable INTEGER NOT NULL DEFAULT 0,
        line_total REAL NOT NULL
    )');

    $pdo->exec('CREATE TABLE inventory_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        stock_quantity INTEGER NOT NULL DEFAULT 0,
        cost REAL NULL,
        sale_price REAL NULL,
        vendor TEXT NULL,
        is_core_eligible INTEGER NOT NULL DEFAULT 0,
        core_cost REAL NULL,
        core_price REAL NULL,
        name TEXT NULL,
        sku TEXT NULL
    )');

    // Settings drives validateQCForInvoicing; default empty = QC disabled.
    $pdo->exec('CREATE TABLE settings (
        `key` TEXT PRIMARY KEY,
        `value` TEXT NULL
    )');

    $pdo->exec('CREATE TABLE qc_checks (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NOT NULL,
        status TEXT NOT NULL,
        created_at TEXT NULL
    )');

    return $pdo;
}

/**
 * @return array{service: WorkorderService, repo: WorkorderRepository, pdo: PDO, workorder_id: int, customer_id: int}
 */
function buildAutoInvoiceFixture(): array
{
    $pdo = bootstrapAutoInvoiceSchema();
    $connection = new AutoInvoiceMemoryConnection($pdo);

    $pdo->exec("INSERT INTO service_lines (slug, name) VALUES ('auto_repair', 'Auto Repair')");

    $pdo->exec("INSERT INTO customers (first_name, last_name, email, phone, company_id)
                VALUES ('Acme', 'Corp', 'ops@acme.test', '555-0100', 42)");
    $customerId = (int) $pdo->lastInsertId();

    // Seed the workorder directly as IN_PROGRESS so transition→COMPLETED is
    // a single legal hop. (PENDING→COMPLETED is not allowed by the model.)
    $pdo->prepare(
        'INSERT INTO workorders (
            number, customer_id, status, type, priority,
            subtotal, tax, grand_total, branch_id, service_line_id
        ) VALUES (?, ?, ?, "corrective", "medium", ?, ?, ?, ?, ?)'
    )->execute([
        'WO-202605-0001',
        $customerId,
        Workorder::STATUS_IN_PROGRESS,
        100.0,
        8.0,
        108.0,
        1,
        1,
    ]);
    $workorderId = (int) $pdo->lastInsertId();

    // One job + one labor item so copyWorkorderItemsToInvoice has work to do.
    $pdo->prepare(
        'INSERT INTO workorder_jobs (workorder_id, title, status, subtotal, tax, total, position)
         VALUES (?, "Brake job", "completed", 100.0, 8.0, 108.0, 1)'
    )->execute([$workorderId]);
    $jobId = (int) $pdo->lastInsertId();

    $pdo->prepare(
        'INSERT INTO workorder_items (
            workorder_job_id, type, description, quantity, unit_price, taxable, line_total, position
        ) VALUES (?, "LABOR", "Inspect brakes", 1, 100, 1, 100, 1)'
    )->execute([$jobId]);

    $audit = new AuditLogger($connection, ['enabled' => false]);
    $coreReturns = new CoreReturnService($connection, $audit);
    $repo = new WorkorderRepository($connection);
    $subjectResolver = new SubjectResolver(new ServiceLineRepository($connection));

    $service = new WorkorderService(
        $connection,
        $repo,
        $coreReturns,
        new AutoInvoiceStubPullService(),
        new AutoInvoiceStubStockService(),
        null,
        null,
        new CustomerRepository($connection),
        $subjectResolver
    );

    return [
        'service' => $service,
        'repo' => $repo,
        'pdo' => $pdo,
        'workorder_id' => $workorderId,
        'customer_id' => $customerId,
    ];
}

function countInvoicesForWorkorder(PDO $pdo, int $workorderId): int
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM invoices WHERE workorder_id = :wid');
    $stmt->execute(['wid' => $workorderId]);
    return (int) $stmt->fetchColumn();
}

/** @return array{passed: bool, message: string} */
function autoInvoiceAssert(string $name, callable $body): array
{
    try {
        $body();
        return ['passed' => true, 'message' => $name];
    } catch (Throwable $e) {
        return [
            'passed' => false,
            'message' => $name . ' — ' . $e::class . ': ' . $e->getMessage(),
        ];
    }
}

$results = [];

// 1. Happy path: transition IN_PROGRESS → COMPLETED creates exactly one invoice.
$results[] = autoInvoiceAssert('transition to COMPLETED creates an invoice', function (): void {
    $f = buildAutoInvoiceFixture();
    $wo = $f['service']->transition($f['workorder_id'], Workorder::STATUS_COMPLETED, 99);
    if ($wo->status !== Workorder::STATUS_COMPLETED) {
        throw new RuntimeException('expected status COMPLETED, got ' . $wo->status);
    }
    $count = countInvoicesForWorkorder($f['pdo'], $f['workorder_id']);
    if ($count !== 1) {
        throw new RuntimeException("expected 1 invoice, got {$count}");
    }
    // Confirm the invoice carries the WO total + line item.
    $row = $f['pdo']->query("SELECT total, workorder_id FROM invoices WHERE workorder_id = {$f['workorder_id']}")->fetch(PDO::FETCH_ASSOC);
    if (abs((float) $row['total'] - 108.0) > 0.001) {
        throw new RuntimeException('expected invoice total 108.0, got ' . $row['total']);
    }
    $items = (int) $f['pdo']->query("SELECT COUNT(*) FROM invoice_items")->fetchColumn();
    if ($items < 1) {
        throw new RuntimeException("expected at least 1 invoice_item, got {$items}");
    }
});

// 2. Idempotency: transitioning into COMPLETED twice (sequentially) only creates one invoice.
//    The repo's status-already-set short-circuit prevents the second hook from firing,
//    but even if it did, the existence-check in autoInvoiceForCompletion would block it.
$results[] = autoInvoiceAssert('repeated transition to COMPLETED is idempotent', function (): void {
    $f = buildAutoInvoiceFixture();
    $f['service']->transition($f['workorder_id'], Workorder::STATUS_COMPLETED, 99);
    $f['service']->transition($f['workorder_id'], Workorder::STATUS_COMPLETED, 99);
    $count = countInvoicesForWorkorder($f['pdo'], $f['workorder_id']);
    if ($count !== 1) {
        throw new RuntimeException("expected 1 invoice after duplicate completion, got {$count}");
    }
});

// 3. Race-loser path: pre-seed an invoice, then transition. autoInvoiceForCompletion
//    must see it via the existence check (or the dup-key catch) and not raise.
$results[] = autoInvoiceAssert('pre-existing invoice short-circuits auto-invoice', function (): void {
    $f = buildAutoInvoiceFixture();
    $f['pdo']->prepare(
        "INSERT INTO invoices (number, customer_id, workorder_id, status, issue_date, total, balance_due)
         VALUES ('INV-PRESEEDED', ?, ?, 'pending', date('now'), 50.0, 50.0)"
    )->execute([$f['customer_id'], $f['workorder_id']]);

    $f['service']->transition($f['workorder_id'], Workorder::STATUS_COMPLETED, 99);
    $count = countInvoicesForWorkorder($f['pdo'], $f['workorder_id']);
    if ($count !== 1) {
        throw new RuntimeException("expected 1 invoice (the pre-existing one), got {$count}");
    }
    $number = $f['pdo']->query("SELECT number FROM invoices WHERE workorder_id = {$f['workorder_id']}")->fetchColumn();
    if ($number !== 'INV-PRESEEDED') {
        throw new RuntimeException("expected pre-seeded invoice to remain, got {$number}");
    }
});

// 4. Non-COMPLETED transitions do NOT create an invoice.
$results[] = autoInvoiceAssert('transition to non-COMPLETED status does not invoice', function (): void {
    $f = buildAutoInvoiceFixture();
    // Push the WO back to PENDING-then... actually it's already IN_PROGRESS.
    // Move to ON_HOLD; that should not create an invoice.
    $f['service']->transition($f['workorder_id'], Workorder::STATUS_ON_HOLD, 99);
    $count = countInvoicesForWorkorder($f['pdo'], $f['workorder_id']);
    if ($count !== 0) {
        throw new RuntimeException("expected 0 invoices for non-completion, got {$count}");
    }
});

// 5. QC gating: when QC is enabled+required and no passing qc_check exists,
//    auto-invoice silently skips (does not throw, does not invoice).
$results[] = autoInvoiceAssert('QC gating blocks auto-invoice without passing check', function (): void {
    $f = buildAutoInvoiceFixture();
    $f['pdo']->exec("INSERT INTO settings (`key`, `value`) VALUES ('qc_enabled', 'true'), ('qc_required_for_invoice', 'true')");

    $wo = $f['service']->transition($f['workorder_id'], Workorder::STATUS_COMPLETED, 99);
    if ($wo->status !== Workorder::STATUS_COMPLETED) {
        throw new RuntimeException('expected status COMPLETED, got ' . $wo->status);
    }
    $count = countInvoicesForWorkorder($f['pdo'], $f['workorder_id']);
    if ($count !== 0) {
        throw new RuntimeException("expected 0 invoices when QC gating blocks, got {$count}");
    }
});

$failures = array_filter($results, static fn (array $r) => !$r['passed']);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['message'] . PHP_EOL);
    }
    exit(1);
}

echo 'All WorkorderService::transition auto-invoice tests passed (' . count($results) . ').' . PHP_EOL;
