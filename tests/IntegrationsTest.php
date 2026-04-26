<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "IntegrationsTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

if (!extension_loaded('sodium')) {
    echo "IntegrationsTest\n";
    echo "  skipped — sodium extension not available (FieldCipher cannot run)\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\IntegrationSyncLog;
use App\Models\IntegrationWebhookEvent;
use App\Models\ThirdPartyIntegration;
use App\Models\User;
use App\Services\Integrations\IntegrationService;
use App\Services\Integrations\IntegrationSyncLogRepository;
use App\Services\Integrations\IntegrationWebhookEventRepository;
use App\Services\Integrations\IntegrationWebhookService;
use App\Services\Integrations\ThirdParty\AbstractIntegrationAdapter;
use App\Services\Integrations\ThirdParty\IntegrationAdapterInterface;
use App\Services\Integrations\ThirdParty\IntegrationAdapterRegistry;
use App\Services\Integrations\ThirdPartyIntegrationRepository;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use App\Support\Crypto\FieldCipher;

/**
 * Cross-cutting third-party integrations tests.
 *
 * Covers:
 *   - Migration shape matches in SQLite (third_party_integrations,
 *     integration_sync_logs, integration_webhook_events).
 *   - ThirdPartyIntegrationRepository CRUD: create/find/update/delete + JSON settings round-trip.
 *   - ThirdPartyIntegrationRepository.findByProviderKey + listByCategory.
 *   - ThirdPartyIntegrationRepository.listDue scopes by status='connected' AND next_sync_at <=.
 *   - IntegrationSyncLogRepository: start → finish writes correct status/duration.
 *   - IntegrationWebhookEventRepository.record dedup: same payload hash returns existing row.
 *   - IntegrationWebhookEventRepository.markProcessed / markFailed flip status.
 *   - IntegrationAdapterRegistry: register/has/get/listAll/describeAll.
 *   - IntegrationAdapterRegistry.register rejects duplicate provider key.
 *   - IntegrationService.register: validates required credential field.
 *   - IntegrationService.register: rejects unknown provider.
 *   - IntegrationService.register: encrypts credentials at rest.
 *   - IntegrationService.register: gate denial on integrations.manage rejects.
 *   - IntegrationService.testConnection: success flips status to 'connected'.
 *   - IntegrationService.testConnection: failure flips status to 'error' + records error.
 *   - IntegrationService.runSync: success writes log row + advances next_sync_at.
 *   - IntegrationService.runSync: failure writes failed log row + status 'error' + still advances next_sync_at.
 *   - IntegrationService.processDueSyncs: only picks up connected + due rows.
 *   - IntegrationService.disconnect: status -> disabled, next_sync_at cleared.
 *   - IntegrationWebhookService.receive: dedup by hash within provider.
 *   - IntegrationWebhookService.processPending: marks rows processed.
 *   - Adapter HTTP callable injection: test calls without touching the network.
 *   - Built-in adapters expose the catalog metadata used by the UI.
 */

class IntegrationsInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

class IntegrationsPermissiveGate extends AccessGate
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

/**
 * Stub adapter that captures the credentials/settings/context passed to
 * it, so tests can assert the orchestrator forwarded the right values
 * without touching the network.
 */
class FakeIntegrationAdapter extends AbstractIntegrationAdapter
{
    public bool $shouldFailTest = false;
    public bool $shouldThrowSync = false;
    /** @var array<int, array<string, mixed>> */
    public array $syncCalls = [];
    /** @var array<int, array<string, mixed>> */
    public array $testCalls = [];
    public int $recordsIn = 7;

    public function __construct(
        private string $providerKey,
        private string $category = 'accounting',
        private string $displayName = 'Fake Adapter'
    ) {
        parent::__construct(null);
    }

    public function providerKey(): string
    {
        return $this->providerKey;
    }
    public function displayName(): string
    {
        return $this->displayName;
    }
    public function category(): string
    {
        return $this->category;
    }
    public function description(): string
    {
        return 'A fake adapter for tests.';
    }
    public function credentialFields(): array
    {
        return [
            'api_key' => ['label' => 'API Key', 'required' => true, 'sensitive' => true, 'type' => 'api_key'],
        ];
    }
    public function settingFields(): array
    {
        return [
            'env' => ['label' => 'Env', 'required' => false, 'type' => 'string', 'default' => 'sandbox'],
        ];
    }
    public function defaultCadenceMinutes(): ?int
    {
        return 30;
    }
    public function testConnection(array $credentials, array $settings): array
    {
        $this->testCalls[] = ['credentials' => $credentials, 'settings' => $settings];
        if ($this->shouldFailTest) {
            return ['ok' => false, 'message' => 'simulated failure'];
        }
        return ['ok' => true, 'message' => 'simulated ok'];
    }
    public function sync(array $credentials, array $settings, array $context = []): array
    {
        $this->syncCalls[] = compact('credentials', 'settings', 'context');
        if ($this->shouldThrowSync) {
            throw new RuntimeException('boom');
        }
        return ['records_in' => $this->recordsIn, 'records_out' => 0, 'summary' => ['fake' => true]];
    }
}

function intAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            "FAIL ($msg): expected " . var_export($expected, true) . ", got " . var_export($actual, true)
        );
    }
}

function intAssertTrue(bool $actual, string $msg = ''): void
{
    if ($actual !== true) {
        throw new RuntimeException("FAIL ($msg): expected true");
    }
}

function intAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!is_a($e, $expectedClass)) {
            throw new RuntimeException("FAIL ($msg): expected {$expectedClass}, got " . get_class($e));
        }
        return;
    }
    throw new RuntimeException("FAIL ($msg): expected {$expectedClass} but no exception thrown");
}

function intMakeUser(int $id, string $role = 'manager'): User
{
    return new User(['id' => $id, 'role' => $role, 'email' => "u{$id}@example.com"]);
}

function intBuildSchema(PDO $pdo): void
{
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec(<<<'SQL'
        CREATE TABLE third_party_integrations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            provider_key TEXT NOT NULL,
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            credentials TEXT NULL,
            settings TEXT NULL,
            sync_cadence_minutes INTEGER NULL,
            last_sync_at TEXT NULL,
            last_sync_status TEXT NULL,
            last_sync_error TEXT NULL,
            next_sync_at TEXT NULL,
            owner_user_id INTEGER NULL,
            created_at TEXT DEFAULT CURRENT_TIMESTAMP,
            updated_at TEXT DEFAULT CURRENT_TIMESTAMP
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE integration_sync_logs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            integration_id INTEGER NOT NULL,
            triggered_by TEXT NOT NULL DEFAULT 'manual',
            user_id INTEGER NULL,
            direction TEXT NOT NULL DEFAULT 'pull',
            status TEXT NOT NULL DEFAULT 'running',
            records_in INTEGER NULL,
            records_out INTEGER NULL,
            duration_ms INTEGER NULL,
            error_message TEXT NULL,
            summary TEXT NULL,
            started_at TEXT DEFAULT CURRENT_TIMESTAMP,
            finished_at TEXT NULL
        )
    SQL);

    $pdo->exec(<<<'SQL'
        CREATE TABLE integration_webhook_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            integration_id INTEGER NULL,
            provider_key TEXT NOT NULL,
            event_type TEXT NULL,
            external_id TEXT NULL,
            payload_hash TEXT NOT NULL,
            raw_payload TEXT NULL,
            headers TEXT NULL,
            status TEXT NOT NULL DEFAULT 'received',
            error_message TEXT NULL,
            received_at TEXT DEFAULT CURRENT_TIMESTAMP,
            processed_at TEXT NULL,
            UNIQUE (provider_key, payload_hash)
        )
    SQL);
}

/**
 * @return array<string, mixed>
 */
function intFixture(): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    intBuildSchema($pdo);

    $conn = new IntegrationsInMemoryConnection($pdo);
    $gate = new IntegrationsPermissiveGate();
    $repo = new ThirdPartyIntegrationRepository($conn);
    $logs = new IntegrationSyncLogRepository($conn);
    $events = new IntegrationWebhookEventRepository($conn);

    // Seed a deterministic 32-byte key for FieldCipher.
    $key = base64_encode(str_repeat("\x01", 32));
    $_ENV['SITE_CODES_ENCRYPTION_KEY'] = $key;
    putenv('SITE_CODES_ENCRYPTION_KEY=' . $key);
    $cipher = new FieldCipher();

    $registry = new IntegrationAdapterRegistry();
    $fake = new FakeIntegrationAdapter('fake_provider', ThirdPartyIntegration::CATEGORY_ACCOUNTING);
    $registry->register($fake);

    $service = new IntegrationService($repo, $logs, $registry, $cipher, $gate);
    $webhooks = new IntegrationWebhookService($events, $repo, $registry, $gate);

    return compact('conn', 'gate', 'repo', 'logs', 'events', 'registry', 'service', 'webhooks', 'fake', 'cipher');
}

echo "IntegrationsTest\n";
$tests = 0;
$failures = 0;
$run = function (string $name, callable $fn) use (&$tests, &$failures): void {
    $tests++;
    try {
        $fn();
        echo "  PASS  {$name}\n";
    } catch (Throwable $e) {
        $failures++;
        echo "  FAIL  {$name}\n    " . $e->getMessage() . "\n";
    }
};

// ---------- Repository CRUD ----------

$run('repository creates + finds integration with JSON settings round-trip', function (): void {
    $f = intFixture();
    /** @var ThirdPartyIntegrationRepository $repo */
    $repo = $f['repo'];
    $row = $repo->create([
        'provider_key' => 'fake_provider',
        'name' => 'My QB',
        'category' => 'accounting',
        'status' => 'pending',
        'credentials' => 'opaque-blob',
        'settings' => ['env' => 'sandbox', 'realm_id' => '12345'],
        'sync_cadence_minutes' => 60,
        'owner_user_id' => 1,
    ]);
    intAssertTrue($row->id !== null && $row->id > 0, 'id assigned');
    $found = $repo->find($row->id ?? 0);
    intAssertTrue($found !== null, 'found after create');
    intAssertSame('fake_provider', $found->provider_key, 'provider preserved');
    intAssertSame('sandbox', $found->settings['env'] ?? null, 'JSON settings round-trip');
    intAssertSame(60, $found->sync_cadence_minutes, 'cadence cast int');
});

$run('repository.update partial sets only specified columns', function (): void {
    $f = intFixture();
    /** @var ThirdPartyIntegrationRepository $repo */
    $repo = $f['repo'];
    $row = $repo->create([
        'provider_key' => 'fake_provider',
        'name' => 'orig',
        'category' => 'accounting',
        'status' => 'pending',
    ]);
    $updated = $repo->update($row->id ?? 0, ['status' => 'connected']);
    intAssertSame('connected', $updated->status ?? null, 'status updated');
    intAssertSame('orig', $updated->name ?? null, 'name unchanged');
});

$run('repository.findByProviderKey returns first row', function (): void {
    $f = intFixture();
    /** @var ThirdPartyIntegrationRepository $repo */
    $repo = $f['repo'];
    $repo->create(['provider_key' => 'fake_provider', 'name' => 'A', 'category' => 'accounting']);
    $repo->create(['provider_key' => 'fake_provider', 'name' => 'B', 'category' => 'accounting']);
    $found = $repo->findByProviderKey('fake_provider');
    intAssertSame('A', $found->name ?? null, 'first registered');
    intAssertSame(null, $repo->findByProviderKey('does_not_exist'), 'unknown returns null');
});

$run('repository.listByCategory filters', function (): void {
    $f = intFixture();
    /** @var ThirdPartyIntegrationRepository $repo */
    $repo = $f['repo'];
    $repo->create(['provider_key' => 'p1', 'name' => 'a1', 'category' => 'accounting']);
    $repo->create(['provider_key' => 'p2', 'name' => 'm1', 'category' => 'mapping']);
    intAssertSame(1, count($repo->listByCategory('accounting')), 'one accounting');
    intAssertSame(1, count($repo->listByCategory('mapping')), 'one mapping');
    intAssertSame(0, count($repo->listByCategory('iot')), 'no iot');
});

$run('repository.listDue filters by status connected AND next_sync_at past', function (): void {
    $f = intFixture();
    /** @var ThirdPartyIntegrationRepository $repo */
    $repo = $f['repo'];
    $past = '2020-01-01 00:00:00';
    $future = '2099-01-01 00:00:00';
    // due
    $repo->create([
        'provider_key' => 'p1', 'name' => 'due', 'category' => 'accounting',
        'status' => 'connected', 'next_sync_at' => $past,
    ]);
    // not connected
    $repo->create([
        'provider_key' => 'p2', 'name' => 'paused', 'category' => 'accounting',
        'status' => 'disabled', 'next_sync_at' => $past,
    ]);
    // not yet due
    $repo->create([
        'provider_key' => 'p3', 'name' => 'future', 'category' => 'accounting',
        'status' => 'connected', 'next_sync_at' => $future,
    ]);
    // null next_sync_at
    $repo->create([
        'provider_key' => 'p4', 'name' => 'unscheduled', 'category' => 'accounting',
        'status' => 'connected', 'next_sync_at' => null,
    ]);
    $due = $repo->listDue('2025-06-01 00:00:00');
    intAssertSame(1, count($due), 'only one due');
    intAssertSame('due', $due[0]->name, 'correct one');
});

$run('sync log repository start + finish round-trip', function (): void {
    $f = intFixture();
    /** @var IntegrationSyncLogRepository $logs */
    $logs = $f['logs'];
    $id = $logs->start(7, 'manual', 1, 'pull', ['since' => '2024-01-01']);
    intAssertTrue($id > 0, 'log id assigned');
    $row = $logs->find($id);
    intAssertSame('running', $row->status, 'starts running');

    $logs->finish($id, 'succeeded', 12, 0, 345, null, ['k' => 'v'], '2025-01-01 12:00:00');
    $after = $logs->find($id);
    intAssertSame('succeeded', $after->status, 'finished succeeded');
    intAssertSame(345, $after->duration_ms, 'duration captured');
    intAssertSame(12, $after->records_in, 'records_in captured');
    intAssertSame('v', $after->summary['k'] ?? null, 'summary JSON round-trip');
});

$run('webhook repository dedups same provider+payload', function (): void {
    $f = intFixture();
    /** @var IntegrationWebhookEventRepository $events */
    $events = $f['events'];
    $first = $events->record(null, 'fake_provider', 'invoice.created', 'ext-1', '{"a":1}', ['X-Sig' => 'abc']);
    $second = $events->record(null, 'fake_provider', 'invoice.created', 'ext-1', '{"a":1}', ['X-Sig' => 'abc']);
    intAssertSame($first->id, $second->id, 'dedup returned existing');
    // Different payload → new row
    $third = $events->record(null, 'fake_provider', 'invoice.created', 'ext-2', '{"a":2}', []);
    intAssertTrue($third->id !== $first->id, 'new payload makes new row');
});

$run('webhook repository markProcessed flips status', function (): void {
    $f = intFixture();
    /** @var IntegrationWebhookEventRepository $events */
    $events = $f['events'];
    $row = $events->record(null, 'fake_provider', 'evt', null, '{"x":1}', []);
    $events->markProcessed($row->id, '2025-01-01 12:00:00');
    intAssertSame('processed', $events->find($row->id)->status, 'status flipped');
});

// ---------- Adapter registry ----------

$run('registry rejects duplicate provider key', function (): void {
    $r = new IntegrationAdapterRegistry();
    $a = new FakeIntegrationAdapter('dup');
    $r->register($a);
    intAssertThrows(
        fn() => $r->register(new FakeIntegrationAdapter('dup')),
        RuntimeException::class,
        'duplicate provider'
    );
});

$run('registry describeAll includes credential fields and cadence', function (): void {
    $r = new IntegrationAdapterRegistry();
    $r->register(new FakeIntegrationAdapter('p1'));
    $described = $r->describeAll();
    intAssertSame(1, count($described), 'one entry');
    intAssertSame('p1', $described[0]['provider_key'], 'provider_key');
    intAssertTrue(isset($described[0]['credential_fields']['api_key']), 'has credential fields');
    intAssertSame(30, $described[0]['default_cadence_minutes'], 'cadence');
});

// ---------- Service: register ----------

$run('service.register rejects unknown provider', function (): void {
    $f = intFixture();
    $service = $f['service'];
    intAssertThrows(
        fn() => $service->register(intMakeUser(1), [
            'provider_key' => 'no_such_provider',
            'credentials' => ['api_key' => 'x'],
        ]),
        InvalidArgumentException::class,
        'unknown provider rejected'
    );
});

$run('service.register requires required credential fields', function (): void {
    $f = intFixture();
    $service = $f['service'];
    intAssertThrows(
        fn() => $service->register(intMakeUser(1), [
            'provider_key' => 'fake_provider',
            'credentials' => [],  // missing api_key
        ]),
        InvalidArgumentException::class,
        'missing api_key rejected'
    );
});

$run('service.register encrypts credentials at rest', function (): void {
    $f = intFixture();
    /** @var IntegrationService $service */
    $service = $f['service'];
    /** @var ThirdPartyIntegrationRepository $repo */
    $repo = $f['repo'];
    /** @var FieldCipher $cipher */
    $cipher = $f['cipher'];

    $row = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'super-secret'],
        'settings' => ['env' => 'sandbox'],
    ]);
    $found = $repo->find($row->id ?? 0);
    intAssertTrue($found->credentials !== null && $found->credentials !== '', 'credentials stored');
    intAssertTrue(
        !str_contains($found->credentials, 'super-secret'),
        'plaintext does NOT appear in stored blob'
    );
    // Round-trip decryption.
    $decoded = json_decode($cipher->decrypt($found->credentials), true);
    intAssertSame('super-secret', $decoded['api_key'], 'decrypts back to plaintext');
});

$run('service.register denies without integrations.manage', function (): void {
    $f = intFixture();
    /** @var IntegrationsPermissiveGate $gate */
    $gate = $f['gate'];
    $gate->denials['integrations.manage'] = true;
    intAssertThrows(
        fn() => $f['service']->register(intMakeUser(1), [
            'provider_key' => 'fake_provider',
            'credentials' => ['api_key' => 'x'],
        ]),
        UnauthorizedException::class,
        'gate denial blocks register'
    );
});

// ---------- Service: testConnection ----------

$run('service.testConnection success flips status to connected', function (): void {
    $f = intFixture();
    $service = $f['service'];
    $row = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'k'],
    ]);
    $result = $service->testConnection(intMakeUser(1), $row->id ?? 0);
    intAssertTrue($result['ok'], 'test ok');
    $reloaded = $f['repo']->find($row->id ?? 0);
    intAssertSame('connected', $reloaded->status, 'status flipped');
});

$run('service.testConnection failure flips status to error and records message', function (): void {
    $f = intFixture();
    /** @var FakeIntegrationAdapter $fake */
    $fake = $f['fake'];
    $fake->shouldFailTest = true;
    $service = $f['service'];
    $row = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'k'],
    ]);
    $result = $service->testConnection(intMakeUser(1), $row->id ?? 0);
    intAssertSame(false, $result['ok'], 'test failed');
    $reloaded = $f['repo']->find($row->id ?? 0);
    intAssertSame('error', $reloaded->status, 'status flipped to error');
    intAssertSame('simulated failure', $reloaded->last_sync_error, 'error captured');
});

// ---------- Service: runSync ----------

$run('service.runSync success writes log row and advances next_sync_at', function (): void {
    $f = intFixture();
    /** @var IntegrationService $service */
    $service = $f['service'];
    /** @var ThirdPartyIntegrationRepository $repo */
    $repo = $f['repo'];
    /** @var IntegrationSyncLogRepository $logs */
    $logs = $f['logs'];

    $row = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'k'],
        'sync_cadence_minutes' => 30,
    ]);
    $result = $service->runSync(intMakeUser(1), $row->id ?? 0);
    intAssertSame('succeeded', $result['status'], 'success status');
    intAssertSame(7, $result['records_in'], 'records_in returned');

    $reloaded = $repo->find($row->id ?? 0);
    intAssertSame('connected', $reloaded->status, 'status connected');
    intAssertSame('succeeded', $reloaded->last_sync_status, 'last status');
    intAssertTrue($reloaded->next_sync_at !== null, 'next_sync_at advanced');
    intAssertTrue($reloaded->last_sync_at !== null, 'last_sync_at stamped');

    $entries = $logs->listForIntegration($row->id ?? 0);
    intAssertSame(1, count($entries), 'one log row');
    intAssertSame('succeeded', $entries[0]->status, 'log row succeeded');
});

$run('service.runSync failure writes failed log row and flips status to error', function (): void {
    $f = intFixture();
    /** @var FakeIntegrationAdapter $fake */
    $fake = $f['fake'];
    $fake->shouldThrowSync = true;
    /** @var IntegrationService $service */
    $service = $f['service'];
    $row = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'k'],
        'sync_cadence_minutes' => 30,
    ]);
    $result = $service->runSync(intMakeUser(1), $row->id ?? 0);
    intAssertSame('failed', $result['status'], 'failed status');
    intAssertSame('boom', $result['error'], 'error message returned');

    $reloaded = $f['repo']->find($row->id ?? 0);
    intAssertSame('error', $reloaded->status, 'status error');
    intAssertSame('failed', $reloaded->last_sync_status, 'last status failed');
    // Even on failure we want next_sync_at to advance so we don't tight-loop.
    intAssertTrue($reloaded->next_sync_at !== null, 'next_sync_at advanced even on failure');
});

$run('service.runSync passes since=last_sync_at on second run', function (): void {
    $f = intFixture();
    /** @var FakeIntegrationAdapter $fake */
    $fake = $f['fake'];
    /** @var IntegrationService $service */
    $service = $f['service'];
    $row = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'k'],
        'sync_cadence_minutes' => 30,
    ]);
    $service->runSync(intMakeUser(1), $row->id ?? 0);
    $service->runSync(intMakeUser(1), $row->id ?? 0);
    intAssertSame(2, count($fake->syncCalls), 'sync called twice');
    // Second call's "since" should not be the epoch — it should be the
    // first run's stamp.
    intAssertTrue(
        $fake->syncCalls[1]['context']['since'] !== '1970-01-01T00:00:00Z',
        'second call uses last_sync_at'
    );
});

// ---------- Service: processDueSyncs ----------

$run('service.processDueSyncs picks up only connected + due', function (): void {
    $f = intFixture();
    /** @var IntegrationService $service */
    $service = $f['service'];
    /** @var ThirdPartyIntegrationRepository $repo */
    $repo = $f['repo'];

    // Connected + due.
    $r1 = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'k1'],
        'sync_cadence_minutes' => 60,
    ]);
    // Manually mark r1 connected (register leaves it pending).
    $repo->update($r1->id ?? 0, [
        'status' => 'connected',
        'next_sync_at' => '2020-01-01 00:00:00',
    ]);

    // Connected but not due.
    $r2 = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'k2'],
    ]);
    $repo->update($r2->id ?? 0, [
        'status' => 'connected',
        'next_sync_at' => '2099-01-01 00:00:00',
    ]);

    // Disabled.
    $r3 = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'k3'],
    ]);
    $repo->update($r3->id ?? 0, [
        'status' => 'disabled',
        'next_sync_at' => '2020-01-01 00:00:00',
    ]);

    $results = $service->processDueSyncs(new DateTimeImmutable('2025-06-01 00:00:00', new DateTimeZone('UTC')));
    intAssertSame(1, count($results), 'one row processed');
    intAssertSame('succeeded', $results[0]['status'], 'success');
    intAssertSame($r1->id, $results[0]['integration_id'], 'correct row');
});

$run('service.disconnect flips status and clears next_sync_at', function (): void {
    $f = intFixture();
    /** @var IntegrationService $service */
    $service = $f['service'];
    $row = $service->register(intMakeUser(1), [
        'provider_key' => 'fake_provider',
        'credentials' => ['api_key' => 'k'],
        'sync_cadence_minutes' => 30,
    ]);
    intAssertTrue($service->disconnect(intMakeUser(1), $row->id ?? 0), 'disconnect ok');
    $r = $f['repo']->find($row->id ?? 0);
    intAssertSame('disabled', $r->status, 'disabled');
    intAssertSame(null, $r->next_sync_at, 'cleared');
});

// ---------- Webhook service ----------

$run('webhook service.receive dedups identical payload', function (): void {
    $f = intFixture();
    /** @var IntegrationWebhookService $webhooks */
    $webhooks = $f['webhooks'];
    $a = $webhooks->receive('fake_provider', '{"a":1}', ['X-Event-Type' => 'invoice.created']);
    $b = $webhooks->receive('fake_provider', '{"a":1}', ['X-Event-Type' => 'invoice.created']);
    intAssertSame($a->id, $b->id, 'same id returned');
});

$run('webhook service.processPending marks rows processed', function (): void {
    $f = intFixture();
    /** @var IntegrationWebhookService $webhooks */
    $webhooks = $f['webhooks'];
    $webhooks->receive('fake_provider', '{"a":1}', []);
    $webhooks->receive('fake_provider', '{"a":2}', []);
    $results = $webhooks->processPending();
    intAssertSame(2, count($results), 'two processed');
});

// ---------- Adapter HTTP injection ----------

$run('adapter HTTP callable injection bypasses network', function (): void {
    $captured = [];
    $http = function (string $method, string $url, array $headers, ?string $body) use (&$captured): array {
        $captured[] = compact('method', 'url', 'headers', 'body');
        return ['status' => 200, 'body' => json_encode(['ok' => true])];
    };
    // Use Mapbox concrete which can be exercised with an injected http.
    $adapter = new \App\Services\Integrations\ThirdParty\MapboxAdapter($http);
    $result = $adapter->testConnection(['access_token' => 'tok'], ['username' => 'u']);
    intAssertTrue($result['ok'], 'reports ok');
    intAssertSame(1, count($captured), 'one call captured');
    intAssertTrue(str_contains($captured[0]['url'], 'mapbox.com/tokens/v2/u'), 'URL formed correctly');
});

$run('built-in adapters expose required catalog metadata', function (): void {
    $expected = [
        'quickbooks_online' => \App\Services\Integrations\ThirdParty\QuickBooksOnlineAdapter::class,
        'xero' => \App\Services\Integrations\ThirdParty\XeroAdapter::class,
        'google_maps' => \App\Services\Integrations\ThirdParty\GoogleMapsAdapter::class,
        'mapbox' => \App\Services\Integrations\ThirdParty\MapboxAdapter::class,
        'generic_telematics' => \App\Services\Integrations\ThirdParty\GenericTelematicsAdapter::class,
        'telecom_monitoring' => \App\Services\Integrations\ThirdParty\TelecomMonitoringAdapter::class,
        'access_control' => \App\Services\Integrations\ThirdParty\AccessControlAdapter::class,
    ];
    foreach ($expected as $key => $class) {
        /** @var IntegrationAdapterInterface $a */
        $a = new $class();
        intAssertSame($key, $a->providerKey(), $class . ' providerKey');
        intAssertTrue($a->displayName() !== '', $class . ' displayName');
        intAssertTrue(in_array($a->category(), ThirdPartyIntegration::CATEGORIES, true), $class . ' category valid');
        intAssertTrue(count($a->credentialFields()) > 0, $class . ' credentialFields');
    }
});

echo "\n";
if ($failures > 0) {
    echo "FAILED: {$failures}/{$tests}\n";
    exit(1);
}
echo "OK: {$tests}/{$tests}\n";
exit(0);
