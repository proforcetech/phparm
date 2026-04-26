<?php

declare(strict_types=1);

namespace {
    require __DIR__ . '/test_bootstrap.php';
}

namespace App\Services\Financial {
    class FinancialEntryService
    {
        public function __construct(...$args)
        {
        }

        public function create(array $data, ?int $actorId = null): void
        {
        }
    }
}

namespace App\Services\Payment {
    class StubWebhookGateway implements PaymentGatewayInterface
    {
        /**
         * @param array<string, mixed> $webhookData
         * @param array<string, mixed> $checkoutSession
         */
        public function __construct(
            private array $webhookData,
            private array $checkoutSession = []
        )
        {
        }

        public function createCheckoutSession(array $invoiceData, array $options = []): array
        {
            return $this->checkoutSession;
        }

        public function processPayment(array $paymentData): array
        {
            return [];
        }

        public function handleWebhook(
            array $payload,
            string $signature = '',
            ?string $rawBody = null,
            ?string $requestUrl = null
        ): array {
            return $this->webhookData;
        }

        public function getTransaction(string $transactionId): array
        {
            return [];
        }

        public function refund(string $transactionId, float $amount, string $reason = ''): array
        {
            return [];
        }

        public function getName(): string
        {
            return 'stub';
        }

        public function isConfigured(): bool
        {
            return true;
        }
    }

    class StubPaymentGatewayFactory extends PaymentGatewayFactory
    {
        /** @var array<string, PaymentGatewayInterface> */
        private array $gatewaysByProvider;

        /** @param array<string, PaymentGatewayInterface> $gatewaysByProvider */
        public function __construct(array $gatewaysByProvider)
        {
            $this->gatewaysByProvider = $gatewaysByProvider;
            parent::__construct([]);
        }

        public function create(string $provider): PaymentGatewayInterface
        {
            $provider = strtolower($provider);

            if (!isset($this->gatewaysByProvider[$provider])) {
                throw new \InvalidArgumentException('Unsupported payment provider: ' . $provider);
            }

            return $this->gatewaysByProvider[$provider];
        }
    }
}

namespace {
    use App\Database\Connection;
    use App\Services\Invoice\PaymentProcessingService;
    use App\Services\Payment\StubPaymentGatewayFactory;
    use App\Services\Payment\StubWebhookGateway;

    class PaymentMemoryConnection extends Connection
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

    function setupWebhookReconciliationDatabase(): \PDO
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('CREATE TABLE invoices (
            id INTEGER PRIMARY KEY,
            customer_id INTEGER NULL,
            total REAL NOT NULL,
            amount_paid REAL NULL DEFAULT 0,
            balance_due REAL NOT NULL,
            status TEXT NOT NULL DEFAULT "pending",
            paid_at TEXT NULL
        )');

        $pdo->exec('CREATE TABLE payment_sessions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            provider TEXT NOT NULL,
            session_id TEXT NOT NULL,
            checkout_url TEXT NULL,
            metadata TEXT NULL,
            created_at TEXT NULL
        )');

        $pdo->exec('CREATE TABLE payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            gateway TEXT NOT NULL,
            method TEXT NOT NULL,
            transaction_id TEXT NULL,
            amount REAL NOT NULL,
            reference TEXT NULL,
            status TEXT NOT NULL,
            metadata TEXT NULL,
            paid_at TEXT NULL,
            created_at TEXT NULL
        )');

        $pdo->exec('CREATE TABLE refunds (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            invoice_id INTEGER NOT NULL,
            payment_reference TEXT NOT NULL,
            refund_id TEXT NOT NULL,
            amount REAL NOT NULL,
            reason TEXT NULL,
            status TEXT NOT NULL,
            metadata TEXT NULL,
            created_at TEXT NULL
        )');

        $pdo->exec('CREATE TABLE audit_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            event TEXT NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id TEXT NULL,
            actor_id INTEGER NULL,
            context TEXT NOT NULL,
            created_at TEXT NOT NULL
        )');

        $pdo->exec('CREATE TABLE payment_webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider TEXT NOT NULL,
            provider_event_id TEXT NULL,
            dedupe_key TEXT NOT NULL,
            event_type TEXT NOT NULL,
            invoice_id INTEGER NULL,
            transaction_id TEXT NULL,
            refund_id TEXT NULL,
            payment_id TEXT NULL,
            session_id TEXT NULL,
            order_id TEXT NULL,
            status TEXT NOT NULL,
            payload TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 1,
            matched_at TEXT NULL,
            processed_at TEXT NULL,
            recovered_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )');

        return $pdo;
    }

    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "SKIPPED: pdo_sqlite extension is not available." . PHP_EOL);
        exit(0);
    }

    $pdo = setupWebhookReconciliationDatabase();
    $connection = new PaymentMemoryConnection($pdo);

    $pdo->exec("INSERT INTO invoices (id, total, amount_paid, balance_due, status) VALUES (101, 50, 0, 50, 'pending')");
    $pdo->exec("INSERT INTO invoices (id, total, amount_paid, balance_due, status) VALUES (202, 30, 0, 30, 'pending')");
    $pdo->exec("INSERT INTO invoices (id, total, amount_paid, balance_due, status) VALUES (303, 20, 0, 20, 'pending')");
    $pdo->exec("INSERT INTO invoices (id, total, amount_paid, balance_due, status) VALUES (404, 40, 0, 40, 'pending')");

    $stmt = $pdo->prepare(
        'INSERT INTO payment_sessions (invoice_id, provider, session_id, metadata, created_at) VALUES (:invoice_id, :provider, :session_id, :metadata, CURRENT_TIMESTAMP)'
    );
    $stmt->execute([
        'invoice_id' => 101,
        'provider' => 'paypal',
        'session_id' => 'PAYMENT-123',
        'metadata' => json_encode(['payment_id' => 'PAYMENT-123', 'invoice_id' => 101]),
    ]);
    $stmt->execute([
        'invoice_id' => 303,
        'provider' => 'square',
        'session_id' => 'checkout-303',
        'metadata' => json_encode(['order_id' => 'ORDER-303', 'invoice_id' => 303]),
    ]);

    $paypalService = new PaymentProcessingService(
        $connection,
        new StubPaymentGatewayFactory([
            'paypal' => new StubWebhookGateway([
                'event_type' => 'payment.completed',
                'transaction_id' => 'SALE-101',
                'payment_id' => 'PAYMENT-123',
                'amount' => 50.0,
                'currency' => 'USD',
                'payment_method' => 'paypal',
                'status' => 'succeeded',
                'handled' => true,
            ]),
        ])
    );
    $paypalResult = $paypalService->handleWebhook('paypal', []);
    $paypalDuplicateResult = $paypalService->handleWebhook('paypal', []);

    $squareReferenceService = new PaymentProcessingService(
        $connection,
        new StubPaymentGatewayFactory([
            'square' => new StubWebhookGateway([
                'event_type' => 'payment.updated',
                'transaction_id' => 'SQ-202',
                'reference' => 'invoice_202',
                'amount' => 30.0,
                'currency' => 'USD',
                'payment_method' => 'CARD',
                'status' => 'succeeded',
                'handled' => true,
            ]),
        ])
    );
    $squareReferenceResult = $squareReferenceService->handleWebhook('square', []);

    $squareOrderService = new PaymentProcessingService(
        $connection,
        new StubPaymentGatewayFactory([
            'square' => new StubWebhookGateway([
                'event_type' => 'payment.updated',
                'transaction_id' => 'SQ-303',
                'order_id' => 'ORDER-303',
                'amount' => 20.0,
                'currency' => 'USD',
                'payment_method' => 'CARD',
                'status' => 'succeeded',
                'handled' => true,
            ]),
        ])
    );
    $squareOrderResult = $squareOrderService->handleWebhook('square', []);

    $refundService = new PaymentProcessingService(
        $connection,
        new StubPaymentGatewayFactory([
            'paypal' => new StubWebhookGateway([
                'event_type' => 'payment.refunded',
                'transaction_id' => 'SALE-101',
                'refund_id' => 'REFUND-101',
                'amount' => 15.0,
                'currency' => 'USD',
                'status' => 'refunded',
                'handled' => true,
            ]),
        ])
    );
    $refundResult = $refundService->handleWebhook('paypal', []);
    $refundDuplicateResult = $refundService->handleWebhook('paypal', []);

    $audit = new \App\Support\Audit\AuditLogger($connection, ['enabled' => true]);
    $audit->log(new \App\Support\Audit\AuditEntry('payment.webhook_unmatched', 'invoice', 0, null, [
        'provider' => 'paypal',
        'event_type' => 'payment.completed',
        'payment_id' => 'PAYMENT-404',
        'transaction_id' => 'SALE-404',
        'webhook_data' => [
            'event_type' => 'payment.completed',
            'transaction_id' => 'SALE-404',
            'payment_id' => 'PAYMENT-404',
            'amount' => 40.0,
            'currency' => 'USD',
            'payment_method' => 'paypal',
            'status' => 'succeeded',
            'handled' => true,
        ],
    ]));

    $recoveryService = new PaymentProcessingService(
        $connection,
        new StubPaymentGatewayFactory([
            'paypal' => new StubWebhookGateway([], [
                'checkout_url' => 'https://example.test/paypal/PAYMENT-404',
                'session_id' => 'PAYMENT-404',
                'payment_id' => 'PAYMENT-404',
                'created_at' => '2026-04-05T00:00:00Z',
            ]),
        ]),
        $audit
    );
    $recoveryService->createCheckoutSession(404, 'paypal');

    $paypalPayment = $pdo->query("SELECT invoice_id, status, amount FROM payments WHERE transaction_id = 'SALE-101' ORDER BY id ASC LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: [];
    $squareReferencePayment = $pdo->query("SELECT invoice_id, status FROM payments WHERE transaction_id = 'SQ-202'")->fetch(\PDO::FETCH_ASSOC) ?: [];
    $squareOrderPayment = $pdo->query("SELECT invoice_id, status FROM payments WHERE transaction_id = 'SQ-303'")->fetch(\PDO::FETCH_ASSOC) ?: [];
    $refundRow = $pdo->query("SELECT invoice_id, payment_reference, refund_id, status FROM refunds WHERE refund_id = 'REFUND-101' ORDER BY id ASC LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: [];
    $invoice101 = $pdo->query('SELECT amount_paid, balance_due, status FROM invoices WHERE id = 101')->fetch(\PDO::FETCH_ASSOC) ?: [];
    $invoice404 = $pdo->query('SELECT amount_paid, balance_due, status FROM invoices WHERE id = 404')->fetch(\PDO::FETCH_ASSOC) ?: [];
    $paypalPaymentCount = (int) $pdo->query("SELECT COUNT(*) FROM payments WHERE transaction_id = 'SALE-101'")->fetchColumn();
    $refundCount = (int) $pdo->query("SELECT COUNT(*) FROM refunds WHERE refund_id = 'REFUND-101'")->fetchColumn();
    $recoveredPayment = $pdo->query("SELECT invoice_id, status FROM payments WHERE transaction_id = 'SALE-404' ORDER BY id ASC LIMIT 1")->fetch(\PDO::FETCH_ASSOC) ?: [];
    $recoveryAuditCount = (int) $pdo->query("SELECT COUNT(*) FROM audit_logs WHERE event = 'payment.webhook_recovered'")->fetchColumn();
    $processedWebhookEventCount = (int) $pdo->query("SELECT COUNT(*) FROM payment_webhook_events WHERE status = 'processed'")->fetchColumn();
    $recoveredWebhookEventCount = (int) $pdo->query("SELECT COUNT(*) FROM payment_webhook_events WHERE status = 'recovered'")->fetchColumn();

    $scenarios = [
        [
            'scenario' => 'paypal webhook resolves invoice id from stored payment session',
            'passed' => ($paypalResult['invoice_id'] ?? 0) === 101
                && ($paypalDuplicateResult['invoice_id'] ?? 0) === 101
                && (int) ($paypalPayment['invoice_id'] ?? 0) === 101
                && ($paypalPayment['status'] ?? '') === 'succeeded',
        ],
        [
            'scenario' => 'square webhook resolves invoice id from reference id',
            'passed' => ($squareReferenceResult['invoice_id'] ?? 0) === 202
                && (int) ($squareReferencePayment['invoice_id'] ?? 0) === 202,
        ],
        [
            'scenario' => 'square webhook resolves invoice id from stored order id metadata',
            'passed' => ($squareOrderResult['invoice_id'] ?? 0) === 303
                && (int) ($squareOrderPayment['invoice_id'] ?? 0) === 303,
        ],
        [
            'scenario' => 'refund webhook records refund without overwriting payment record status',
            'passed' => ($refundResult['invoice_id'] ?? 0) === 101
                && ($refundDuplicateResult['invoice_id'] ?? 0) === 101
                && (int) ($refundRow['invoice_id'] ?? 0) === 101
                && ($refundRow['payment_reference'] ?? '') === 'SALE-101'
                && ($refundRow['refund_id'] ?? '') === 'REFUND-101'
                && ($refundRow['status'] ?? '') === 'refunded'
                && ($paypalPayment['status'] ?? '') === 'succeeded',
        ],
        [
            'scenario' => 'duplicate payment webhook does not double-apply invoice balance or insert duplicate payment rows',
            'passed' => (float) ($invoice101['amount_paid'] ?? 0) === 35.0
                && (float) ($paypalPayment['amount'] ?? 0) === 50.0
                && (float) ($invoice101['balance_due'] ?? 0) === 15.0
                && $paypalPaymentCount === 1,
        ],
        [
            'scenario' => 'duplicate refund webhook does not insert duplicate refund rows and restores partial invoice state',
            'passed' => $refundCount === 1
                && ($invoice101['status'] ?? '') === 'partial',
        ],
        [
            'scenario' => 'stored unmatched webhook is recovered when a matching checkout session is created',
            'passed' => (int) ($recoveredPayment['invoice_id'] ?? 0) === 404
                && ($recoveredPayment['status'] ?? '') === 'succeeded'
                && (float) ($invoice404['amount_paid'] ?? 0) === 40.0
                && (float) ($invoice404['balance_due'] ?? 0) === 0.0
                && ($invoice404['status'] ?? '') === 'paid'
                && $recoveryAuditCount === 1
                && $processedWebhookEventCount >= 3
                && $recoveredWebhookEventCount >= 1,
        ],
    ];

    $failures = array_filter($scenarios, static fn (array $row) => $row['passed'] === false);
    if ($failures !== []) {
        foreach ($failures as $failure) {
            fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
        }
        exit(1);
    }

    fwrite(STDOUT, "All payment webhook reconciliation tests passed." . PHP_EOL);
}
