<?php

declare(strict_types=1);

namespace App\Services\Financial {
    class FinancialEntryService
    {
        public function __construct(...$args)
        {
        }

        public function create(array $payload, int $actorId): void
        {
        }
    }
}

namespace {
    require __DIR__ . '/test_bootstrap.php';

    use App\Database\Connection;
    use App\Services\Invoice\InvoiceService;

    class InvoiceMemoryConnection extends Connection
    {
        public function __construct(private \PDO $pdo)
        {
            parent::__construct([]);
        }

        public function pdo(): \PDO
        {
            return $this->pdo;
        }
    }

    function buildInvoiceService(\PDO $pdo): InvoiceService
    {
        $reflection = new \ReflectionClass(InvoiceService::class);
        /** @var InvoiceService $service */
        $service = $reflection->newInstanceWithoutConstructor();

        $connectionProperty = $reflection->getProperty('connection');
        $connectionProperty->setAccessible(true);
        $connectionProperty->setValue($service, new InvoiceMemoryConnection($pdo));

        $auditProperty = $reflection->getProperty('audit');
        $auditProperty->setAccessible(true);
        $auditProperty->setValue($service, null);

        return $service;
    }

    function setupInvoicePaymentDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        \registerMysqlCompatFunctions($pdo);

        $pdo->exec('CREATE TABLE invoices (
            id INTEGER PRIMARY KEY,
            customer_id INTEGER NULL,
            status TEXT NOT NULL,
            total REAL NOT NULL,
            amount_paid REAL NOT NULL DEFAULT 0,
            balance_due REAL NOT NULL DEFAULT 0
        )');

        $pdo->exec('CREATE TABLE payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            gateway TEXT NULL,
            method TEXT NULL,
            transaction_id TEXT NOT NULL,
            amount REAL NOT NULL,
            reference TEXT NULL,
            status TEXT NOT NULL,
            metadata TEXT NULL,
            paid_at TEXT NOT NULL,
            created_at TEXT NULL
        )');

        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "SKIPPED: pdo_sqlite extension is not available." . PHP_EOL);
        exit(0);
    }

    $pdo = setupInvoicePaymentDatabase();
    $service = buildInvoiceService($pdo);

    $pdo->exec("INSERT INTO invoices (id, customer_id, status, total, amount_paid, balance_due) VALUES (1, 1, 'pending', 100, 0, 100)");

    $successful = $service->recordPayment(1, [
        'amount' => 40.0,
        'method' => 'card',
    ]);

    $failed = $service->recordPayment(1, [
        'amount' => 25.0,
        'method' => 'cash',
        'status' => 'failed',
        'reference' => 'cash-attempt-1',
    ]);

    $rows = $pdo->query('SELECT gateway, method, transaction_id, reference, status, amount FROM payments ORDER BY id ASC')
        ->fetchAll(\PDO::FETCH_ASSOC);
    $invoice = $pdo->query('SELECT amount_paid, balance_due, status FROM invoices WHERE id = 1')
        ->fetch(\PDO::FETCH_ASSOC) ?: [];

    $firstPayment = $rows[0] ?? [];
    $secondPayment = $rows[1] ?? [];

    $scenarios = [
        [
            'scenario' => 'manual succeeded payment writes schema-valid gateway and transaction fields',
            'passed' => ($successful->id ?? 0) > 0
                && ($firstPayment['gateway'] ?? '') === 'card'
                && ($firstPayment['method'] ?? '') === 'card'
                && str_starts_with((string) ($firstPayment['transaction_id'] ?? ''), 'manual_')
                && ($firstPayment['reference'] ?? '') === ($firstPayment['transaction_id'] ?? '')
                && ($firstPayment['status'] ?? '') === 'succeeded',
        ],
        [
            'scenario' => 'failed manual payment preserves invoice balance and status semantics',
            'passed' => ($failed->id ?? 0) > 0
                && ($secondPayment['gateway'] ?? '') === 'cash'
                && ($secondPayment['status'] ?? '') === 'failed'
                && (float) ($invoice['amount_paid'] ?? 0) === 40.0
                && (float) ($invoice['balance_due'] ?? 0) === 60.0
                && ($invoice['status'] ?? '') === 'partial',
        ],
    ];

    $failures = array_filter($scenarios, static fn (array $row) => $row['passed'] === false);
    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
        }
        exit(1);
    }

    fwrite(STDOUT, "All invoice manual payment consistency tests passed." . PHP_EOL);
}
