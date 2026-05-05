<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Customer\CustomerRepository;
use App\Services\Inventory\CoreReturnService;
use App\Services\Inventory\InventoryPullRequestService;
use App\Services\Inventory\InventoryStockOrderService;
use App\Services\ServiceLine\ServiceLineRepository;
use App\Services\ServiceLine\SubjectResolver;
use App\Services\Workorder\WorkorderRepository;
use App\Services\Workorder\WorkorderService;
use App\Support\Audit\AuditLogger;

class DirectWoMemoryConnection extends Connection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

/**
 * Inventory services are only touched when WO items are PART-typed; LABOR-only
 * test fixtures never invoke them, so a no-op subclass keeps the type system
 * happy without dragging in inventory schemas.
 */
class StubPullService extends InventoryPullRequestService
{
    public function __construct()
    {
    }
}

class StubStockService extends InventoryStockOrderService
{
    public function __construct()
    {
    }
}

function bootstrapDirectWoSchema(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // SQLite doesn't ship MySQL's NOW() — alias it to CURRENT_TIMESTAMP so
    // the production INSERTs run unchanged against this fixture.
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
        business_name TEXT NULL,
        email TEXT NOT NULL,
        phone TEXT NOT NULL,
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
        is_commercial INTEGER NOT NULL DEFAULT 0,
        tax_exempt INTEGER NOT NULL DEFAULT 0,
        notes TEXT NULL,
        external_reference TEXT NULL,
        company_id INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE contracts (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_number TEXT NOT NULL,
        company_id INTEGER NOT NULL,
        division_id INTEGER NULL,
        service_line_id INTEGER NULL,
        renewed_from_contract_id INTEGER NULL,
        title TEXT NOT NULL,
        description TEXT NULL,
        contract_type TEXT NOT NULL,
        status TEXT NOT NULL,
        start_date TEXT NOT NULL,
        end_date TEXT NOT NULL,
        billing_frequency TEXT NULL,
        billing_amount_cents INTEGER NOT NULL DEFAULT 0,
        auto_renew INTEGER NOT NULL DEFAULT 0,
        renewal_term_months INTEGER NULL,
        renewal_notice_days INTEGER NOT NULL DEFAULT 30,
        terms_markdown TEXT NULL,
        signed_at TEXT NULL,
        signed_by_contact_id INTEGER NULL,
        signer_ip TEXT NULL,
        signer_user_agent TEXT NULL,
        signature_data TEXT NULL,
        cancelled_at TEXT NULL,
        cancellation_reason TEXT NULL,
        created_by_user_id INTEGER NULL,
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
        priority TEXT NOT NULL,
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

    return $pdo;
}

/**
 * @param array{
 *   customer_company_id?: ?int,
 *   contract_service_line_id?: ?int,
 *   contract_status?: string,
 *   contract_start?: string,
 *   contract_end?: string,
 *   skip_contract?: bool,
 * } $opts
 * @return array{service: WorkorderService, repo: WorkorderRepository, customer_id: int, service_line_id: int, other_line_id: int}
 */
function buildDirectWoFixture(array $opts = []): array
{
    $pdo = bootstrapDirectWoSchema();
    $connection = new DirectWoMemoryConnection($pdo);

    $pdo->prepare(
        "INSERT INTO service_lines (slug, name, sort_order, is_active) VALUES
         ('auto_repair', 'Auto Repair', 10, 1),
         ('it_support',  'IT Support',  60, 1)"
    )->execute();
    $autoRepairId = (int) $pdo->query("SELECT id FROM service_lines WHERE slug='auto_repair'")->fetchColumn();
    $itSupportId  = (int) $pdo->query("SELECT id FROM service_lines WHERE slug='it_support'")->fetchColumn();

    // Use array_key_exists so callers can pass an explicit null company_id; ?? would coerce that back to 42.
    $companyId = array_key_exists('customer_company_id', $opts) ? $opts['customer_company_id'] : 42;
    $pdo->prepare(
        'INSERT INTO customers (first_name, last_name, email, phone, company_id) VALUES (?, ?, ?, ?, ?)'
    )->execute(['Acme', 'Co', 'ops@acme.test', '555-0100', $companyId]);
    $customerId = (int) $pdo->lastInsertId();

    if (empty($opts['skip_contract']) && $companyId !== null) {
        $pdo->prepare(
            'INSERT INTO contracts (
                contract_number, company_id, service_line_id, title,
                contract_type, status, start_date, end_date, billing_frequency
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'C-TEST-0001',
            $companyId,
            $opts['contract_service_line_id'] ?? $autoRepairId,
            'Acme MSA',
            'service_agreement',
            $opts['contract_status'] ?? 'active',
            $opts['contract_start'] ?? '2020-01-01',
            $opts['contract_end'] ?? '2099-12-31',
            'monthly',
        ]);
    }

    $audit = new AuditLogger($connection, ['enabled' => false]);
    $coreReturns = new CoreReturnService($connection, $audit);
    $pullService = new StubPullService();
    $stockService = new StubStockService();
    $repo = new WorkorderRepository($connection);
    $subjectResolver = new SubjectResolver(new ServiceLineRepository($connection));
    $service = new WorkorderService(
        $connection,
        $repo,
        $coreReturns,
        $pullService,
        $stockService,
        null,
        null,
        new CustomerRepository($connection),
        $subjectResolver
    );

    return [
        'service' => $service,
        'repo' => $repo,
        'customer_id' => $customerId,
        'service_line_id' => $autoRepairId,
        'other_line_id' => $itSupportId,
    ];
}

/** @return array{passed: bool, message: string} */
function assertScenario(string $name, callable $body): array
{
    try {
        $body();
        return ['passed' => true, 'message' => $name];
    } catch (Throwable $e) {
        return ['passed' => false, 'message' => $name . ' — ' . $e->getMessage()];
    }
}

/** @return array{passed: bool, message: string} */
function assertThrows(string $name, callable $body, string $expectedSubstring): array
{
    try {
        $body();
        return ['passed' => false, 'message' => $name . ' — expected exception, got none'];
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), $expectedSubstring)) {
            return ['passed' => true, 'message' => $name];
        }
        return [
            'passed' => false,
            'message' => $name . ' — wrong message: ' . $e->getMessage(),
        ];
    } catch (Throwable $e) {
        return [
            'passed' => false,
            'message' => $name . ' — wrong exception ' . $e::class . ': ' . $e->getMessage(),
        ];
    }
}

$results = [];

// 1. Happy path: B2B customer with active contract on the requested line.
$results[] = assertScenario('createDirect succeeds for contracted B2B customer', function (): void {
    $f = buildDirectWoFixture();
    $wo = $f['service']->createDirect([
        'customer_id' => $f['customer_id'],
        'service_line_id' => $f['service_line_id'],
        'vehicle_id' => 7,
        'mileage_in' => 12345,
        'jobs' => [[
            'title' => 'Brake inspection',
            'items' => [[
                'type' => 'LABOR',
                'description' => 'Inspect brakes',
                'quantity' => 1,
                'unit_price' => 100,
                'taxable' => 0,
            ]],
        ]],
    ], 99);

    if ($wo === null) {
        throw new RuntimeException('expected workorder, got null');
    }
    if ($wo->estimate_id !== null) {
        throw new RuntimeException('estimate_id should be NULL on direct WO');
    }
    if ($wo->mileage_in !== 12345) {
        throw new RuntimeException('mileage_in not persisted, got: ' . var_export($wo->mileage_in, true));
    }
    if (!str_starts_with($wo->number, 'WO-D-')) {
        throw new RuntimeException('expected WO-D- prefix, got: ' . $wo->number);
    }
    if (abs($wo->grand_total - 100.0) > 0.001) {
        throw new RuntimeException('grand_total should be 100, got: ' . $wo->grand_total);
    }
});

// 2. Failure: customer has no company_id (individual customer).
$results[] = assertThrows(
    'rejects customer with no company_id',
    function (): void {
        $f = buildDirectWoFixture(['customer_company_id' => null, 'skip_contract' => true]);
        $f['service']->createDirect([
            'customer_id' => $f['customer_id'],
            'service_line_id' => $f['service_line_id'],
            'vehicle_id' => 7,
            'jobs' => [['title' => 'X', 'items' => []]],
        ], 1);
    },
    'B2B customer linked to a company'
);

// 3. Failure: contract is for a different service line.
$results[] = assertThrows(
    'rejects when contract covers a different service line',
    function (): void {
        // Contract is on auto_repair but the WO targets it_support.
        $f = buildDirectWoFixture();
        $f['service']->createDirect([
            'customer_id' => $f['customer_id'],
            'service_line_id' => $f['other_line_id'],
            'site_asset_id' => 11,
            'jobs' => [['title' => 'X', 'items' => []]],
        ], 1);
    },
    'active contract covering this service line'
);

// 4. Failure: contract has expired.
$results[] = assertThrows(
    'rejects when contract has expired',
    function (): void {
        $f = buildDirectWoFixture([
            'contract_end' => '2020-12-31', // before today
        ]);
        $f['service']->createDirect([
            'customer_id' => $f['customer_id'],
            'service_line_id' => $f['service_line_id'],
            'vehicle_id' => 7,
            'jobs' => [['title' => 'X', 'items' => []]],
        ], 1);
    },
    'active contract covering this service line'
);

$failures = array_filter($results, static fn (array $r) => !$r['passed']);
if ($failures) {
    foreach ($failures as $f) {
        fwrite(STDERR, 'FAILED: ' . $f['message'] . PHP_EOL);
    }
    exit(1);
}

echo 'All WorkorderService::createDirect tests passed (' . count($results) . ').' . PHP_EOL;
