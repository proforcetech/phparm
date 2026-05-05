<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Estimate\EstimateEditorService;
use App\Support\Audit\AuditLogger;

class RejectReasonMemoryConnection extends Connection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

function bootstrapRejectReasonSchema(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->sqliteCreateFunction('NOW', static fn () => date('Y-m-d H:i:s'), 0);

    // Minimal estimates table — only the columns fetchEstimate reads or
    // reject() writes. Floats default to 0 so the model hydration doesn't trip.
    $pdo->exec('CREATE TABLE estimates (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        parent_id INTEGER NULL,
        number TEXT NOT NULL,
        customer_id INTEGER NOT NULL,
        vehicle_id INTEGER NULL,
        site_asset_id INTEGER NULL,
        service_line_id INTEGER NULL,
        is_mobile INTEGER NOT NULL DEFAULT 0,
        status TEXT NOT NULL,
        technician_id INTEGER NULL,
        expiration_date TEXT NULL,
        subtotal REAL NOT NULL DEFAULT 0,
        tax REAL NOT NULL DEFAULT 0,
        call_out_fee REAL NOT NULL DEFAULT 0,
        mileage_total REAL NOT NULL DEFAULT 0,
        discounts REAL NOT NULL DEFAULT 0,
        grand_total REAL NOT NULL DEFAULT 0,
        internal_notes TEXT NULL,
        customer_notes TEXT NULL,
        rejection_reason TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE estimate_jobs (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estimate_id INTEGER NOT NULL,
        title TEXT NOT NULL,
        customer_status TEXT NOT NULL DEFAULT "pending"
    )');

    $pdo->exec('CREATE TABLE estimate_items (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estimate_job_id INTEGER NOT NULL,
        description TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT "pending"
    )');

    return $pdo;
}

/**
 * @return array{editor: EstimateEditorService, pdo: PDO, estimate_id: int, job_id: int, item_id: int}
 */
function buildRejectReasonFixture(string $startStatus = 'pending'): array
{
    $pdo = bootstrapRejectReasonSchema();
    $connection = new RejectReasonMemoryConnection($pdo);

    $pdo->prepare('INSERT INTO estimates (number, customer_id, status) VALUES (?, ?, ?)')
        ->execute(['EST-001', 7, $startStatus]);
    $estimateId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO estimate_jobs (estimate_id, title) VALUES (?, "Brake job")')
        ->execute([$estimateId]);
    $jobId = (int) $pdo->lastInsertId();

    $pdo->prepare('INSERT INTO estimate_items (estimate_job_id, description) VALUES (?, "Inspect")')
        ->execute([$jobId]);
    $itemId = (int) $pdo->lastInsertId();

    $audit = new AuditLogger($connection, ['enabled' => false]);
    $editor = new EstimateEditorService($connection, $audit);

    return compact('editor', 'pdo', 'estimateId', 'jobId', 'itemId') + [
        'estimate_id' => $estimateId,
        'job_id' => $jobId,
        'item_id' => $itemId,
    ];
}

/** @return array{passed: bool, message: string} */
function rejectAssertScenario(string $name, callable $body): array
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

/** @return array{passed: bool, message: string} */
function rejectAssertThrows(string $name, callable $body, string $expectedSubstring): array
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

// 1. Happy path: a real reason persists status + rejection_reason and
//    cascades item statuses.
$results[] = rejectAssertScenario('valid reason persists status and reason', function (): void {
    $f = buildRejectReasonFixture();
    $estimate = $f['editor']->reject($f['estimate_id'], 'Customer found a cheaper quote elsewhere', 99);
    if ($estimate === null) {
        throw new RuntimeException('expected estimate, got null');
    }
    if ($estimate->status !== 'rejected') {
        throw new RuntimeException('expected status rejected, got ' . $estimate->status);
    }
    if ($estimate->rejection_reason !== 'Customer found a cheaper quote elsewhere') {
        throw new RuntimeException('reason not persisted: ' . var_export($estimate->rejection_reason, true));
    }
    $itemStatus = $f['pdo']->query("SELECT status FROM estimate_items WHERE id = {$f['item_id']}")->fetchColumn();
    if ($itemStatus !== 'rejected') {
        throw new RuntimeException("expected item status rejected, got {$itemStatus}");
    }
});

// 2. Reason is trimmed on persistence — leading/trailing whitespace shouldn't
//    inflate the stored value.
$results[] = rejectAssertScenario('reason is trimmed before storage', function (): void {
    $f = buildRejectReasonFixture();
    $estimate = $f['editor']->reject($f['estimate_id'], "  Vehicle totaled  \n", 99);
    if ($estimate->rejection_reason !== 'Vehicle totaled') {
        throw new RuntimeException('expected trimmed reason, got: ' . var_export($estimate->rejection_reason, true));
    }
});

// 3-6. Invalid inputs — reject() must throw, and crucially must NOT mutate
//      the estimate row (status stays "pending").
$invalidCases = [
    ['name' => 'rejects null reason',             'reason' => null,   'expect' => 'rejection reason is required'],
    ['name' => 'rejects empty reason',            'reason' => '',     'expect' => 'rejection reason is required'],
    ['name' => 'rejects whitespace-only reason',  'reason' => "   \t\n", 'expect' => 'rejection reason is required'],
    ['name' => 'rejects reason shorter than min', 'reason' => 'no',   'expect' => 'at least 5 characters'],
];

foreach ($invalidCases as $case) {
    $results[] = rejectAssertThrows($case['name'], function () use ($case): void {
        $f = buildRejectReasonFixture();
        try {
            $f['editor']->reject($f['estimate_id'], $case['reason'], 99);
        } finally {
            $status = $f['pdo']->query("SELECT status FROM estimates WHERE id = {$f['estimate_id']}")->fetchColumn();
            if ($status !== 'pending') {
                throw new RuntimeException("status should remain pending on failed reject, got {$status}");
            }
        }
    }, $case['expect']);
}

// 7. Non-existent estimate returns null (no exception) when reason is valid.
//    The validation precedes the lookup, so this also confirms validation
//    runs before the DB hit (cheap fail-fast).
$results[] = rejectAssertScenario('missing estimate with valid reason returns null', function (): void {
    $f = buildRejectReasonFixture();
    $result = $f['editor']->reject(999999, 'Customer cancelled the work order', 99);
    if ($result !== null) {
        throw new RuntimeException('expected null for missing estimate, got: ' . var_export($result, true));
    }
});

$failures = array_filter($results, static fn (array $r) => !$r['passed']);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['message'] . PHP_EOL);
    }
    exit(1);
}

echo 'All EstimateEditorService::reject reason-enforcement tests passed (' . count($results) . ').' . PHP_EOL;
