<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Services\Workorder\WorkorderTimelineService;

class TimelineCompatMemoryConnection extends Connection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

function timelineCompatAssert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$pdo->exec('CREATE TABLE workorders (
    id INTEGER PRIMARY KEY,
    estimate_id INTEGER NULL
)');
$pdo->exec('CREATE TABLE estimates (
    id INTEGER PRIMARY KEY,
    number TEXT NOT NULL,
    estimate_type TEXT NOT NULL,
    workorder_id INTEGER NULL
)');
$pdo->exec('CREATE TABLE users (
    id INTEGER PRIMARY KEY,
    name TEXT NOT NULL
)');
$pdo->exec('CREATE TABLE workorder_status_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    workorder_id INTEGER NOT NULL,
    from_status TEXT NULL,
    to_status TEXT NOT NULL,
    changed_by INTEGER NULL,
    notes TEXT NULL,
    created_at TEXT NOT NULL
)');

$pdo->exec("INSERT INTO workorders (id, estimate_id) VALUES (5, NULL)");
$pdo->exec("INSERT INTO users (id, name) VALUES (7, 'Alex Tech')");
$pdo->exec("
    INSERT INTO workorder_status_history (workorder_id, from_status, to_status, changed_by, notes, created_at)
    VALUES (5, 'pending', 'in_progress', 7, 'Started work', '2026-05-20 12:00:00')
");

$service = new WorkorderTimelineService(new TimelineCompatMemoryConnection($pdo));
$timeline = $service->build(5);

timelineCompatAssert(count($timeline) === 1, 'Timeline should skip missing optional tables and keep status events.');
timelineCompatAssert($timeline[0]['type'] === 'status', 'Timeline event should be status.');
timelineCompatAssert(
    $timeline[0]['title'] === 'Status changed to In Progress',
    'Timeline should format the status event title.'
);

echo "All workorder timeline compatibility tests passed." . PHP_EOL;
