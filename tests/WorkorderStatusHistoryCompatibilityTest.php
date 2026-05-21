<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Workorder\WorkorderRepository;

class StatusHistoryCompatMemoryConnection extends Connection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

function statusHistoryCompatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
registerMysqlCompatFunctions($pdo);

$pdo->exec('CREATE TABLE workorders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    number TEXT NOT NULL UNIQUE,
    estimate_id INTEGER NULL,
    customer_id INTEGER NULL,
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

$pdo->exec('CREATE TABLE workorder_status_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workorder_id INTEGER NOT NULL,
    from_status TEXT NULL,
    to_status TEXT NOT NULL,
    changed_by INTEGER NULL,
    notes TEXT NULL,
    created_at TEXT NULL
)');

$pdo->exec("
    INSERT INTO workorders (
        number, status, type, priority, subtotal, tax, call_out_fee, mileage_total,
        discounts, shop_fee, hazmat_disposal_fee, goa_fee, grand_total, created_at, updated_at
    ) VALUES (
        'WO-LEGACY-1', 'pending', 'corrective', 'normal', 0, 0, 0, 0,
        0, 0, 0, 0, 0, '2026-05-20 11:00:00', '2026-05-20 11:00:00'
    )
");

$repository = new WorkorderRepository(new StatusHistoryCompatMemoryConnection($pdo));
$workorder = $repository->updateStatus(1, 'in_progress', 9, 'Starting work', 'event-1');

statusHistoryCompatAssert($workorder !== null, 'Status update should return the workorder.');
statusHistoryCompatAssert($workorder->status === 'in_progress', 'Workorder status should update.');
statusHistoryCompatAssert(
    (int) $pdo->query('SELECT COUNT(*) FROM workorder_status_history')->fetchColumn() === 1,
    'Status history should be inserted without a client_event_id column.'
);
statusHistoryCompatAssert(
    (string) $pdo->query('SELECT to_status FROM workorder_status_history LIMIT 1')->fetchColumn() === 'in_progress',
    'Status history row should contain the destination status.'
);

echo "All workorder status-history compatibility tests passed." . PHP_EOL;
