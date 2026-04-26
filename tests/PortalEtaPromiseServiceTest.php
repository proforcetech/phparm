<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "PortalEtaPromiseServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\Customer;
use App\Models\PortalAccount;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workorder;
use App\Services\Customer\CustomerRepository;
use App\Services\Portal\PortalAuthService;
use App\Services\Portal\PortalEtaPromiseRepository;
use App\Services\Portal\PortalEtaPromiseService;
use App\Services\Tickets\TicketRepository;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 6.7 of docs/expansion-plan.md — portal ETA promises.
 *
 * SQLite-in-memory covers the append-only chain, concurrent supersede
 * detection, validation surface, staff-side permission gating, and the
 * portal three-layer scope.
 */

// ---------------------------------------------------------------------------
// SQLite-backed Connection + schema
// ---------------------------------------------------------------------------

class EtaInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner) {}
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function etaSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec(
        'CREATE TABLE portal_eta_promises (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            company_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            window_start_at TEXT NOT NULL,
            window_end_at TEXT NOT NULL,
            source TEXT NOT NULL DEFAULT "manual",
            confidence INTEGER NULL,
            note TEXT NULL,
            created_by_user_id INTEGER NOT NULL,
            created_at TEXT NULL,
            superseded_at TEXT NULL,
            superseded_by_id INTEGER NULL
        )'
    );
    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class EtaFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct() {}
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class EtaFakeTickets extends TicketRepository
{
    /** @var array<int, Ticket> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function findById(int $id): ?Ticket
    {
        return $this->store[$id] ?? null;
    }
    public function seed(array $row): Ticket
    {
        $t = new Ticket();
        $t->id = $row['id'] ?? $this->nextId++;
        $t->ticket_number = $row['ticket_number'] ?? ('T-' . $t->id);
        $t->title = $row['title'] ?? 'Test Ticket';
        $t->company_id = $row['company_id'] ?? null;
        $t->site_id = $row['site_id'] ?? null;
        $t->status = $row['status'] ?? 'new';
        $this->store[$t->id] = $t;
        return $t;
    }
}

class EtaFakeWorkorders extends WorkorderRepository
{
    /** @var array<int, Workorder> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function find(int $id): ?Workorder
    {
        return $this->store[$id] ?? null;
    }
    public function seed(array $row): Workorder
    {
        $w = new Workorder();
        $w->id = $row['id'] ?? $this->nextId++;
        $w->number = $row['number'] ?? ('WO-' . $w->id);
        $w->estimate_id = (int) ($row['estimate_id'] ?? 0);
        $w->customer_id = (int) ($row['customer_id'] ?? 0);
        $w->vehicle_id = (int) ($row['vehicle_id'] ?? 0);
        $w->status = $row['status'] ?? 'pending';
        $this->store[$w->id] = $w;
        return $w;
    }
}

class EtaFakeCustomers extends CustomerRepository
{
    /** @var array<int, Customer> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function find(int $id): ?Customer
    {
        return $this->store[$id] ?? null;
    }
    public function seed(array $row): Customer
    {
        $c = new Customer();
        $c->id = $row['id'] ?? $this->nextId++;
        $c->first_name = $row['first_name'] ?? 'A';
        $c->last_name = $row['last_name'] ?? 'B';
        $c->email = $row['email'] ?? 'x@y.z';
        $c->phone = $row['phone'] ?? '555';
        $c->company_id = $row['company_id'] ?? null;
        $this->store[$c->id] = $c;
        return $c;
    }
}

function etaPortalAuthStub(): PortalAuthService
{
    return new class extends PortalAuthService {
        public function __construct() {}
        public function assertSiteAccess(PortalAccount $account, int $siteId): void
        {
            if (!$account->allowsSite($siteId)) {
                throw new UnauthorizedException("portal_account cannot access site {$siteId}");
            }
        }
    };
}

/**
 * AccessGate subclass that defaults to GRANTING every permission check
 * so we can flip only the specific permission a test is probing.
 */
class EtaPermissiveGate extends AccessGate
{
    /** @var array<string, bool> */
    public array $denials = [];
    public function __construct() {}
    public function can(User $user, string $permission, mixed $resource = null): bool
    {
        return !isset($this->denials[$permission]) || $this->denials[$permission] === false
            ? !isset($this->denials[$permission])
            : false;
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (!empty($this->denials[$permission])) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------

function makeEtaFixture(
    int $companyId = 10,
    bool $accountActive = true,
    ?string $revokedAt = null,
    ?array $allowedSiteIds = null,
): array {
    $pdo = etaSetUpDatabase();
    $conn = new EtaInMemoryConnection($pdo);
    $audit = new EtaFakeAudit();
    $promiseRepo = new PortalEtaPromiseRepository($conn);
    $tickets = new EtaFakeTickets();
    $workorders = new EtaFakeWorkorders();
    $customers = new EtaFakeCustomers();
    $portalAuth = etaPortalAuthStub();
    $gate = new EtaPermissiveGate();

    $service = new PortalEtaPromiseService(
        $conn, $promiseRepo, $tickets, $workorders, $customers,
        $portalAuth, $gate, $audit,
    );

    $staff = new User();
    $staff->id = 42;

    $portalUser = new User();
    $portalUser->id = 999;

    $account = new PortalAccount();
    $account->id = 77;
    $account->user_id = 999;
    $account->company_id = $companyId;
    $account->is_active = $accountActive;
    $account->revoked_at = $revokedAt;
    $account->allowed_site_ids = $allowedSiteIds;

    return compact(
        'service', 'pdo', 'conn', 'promiseRepo', 'tickets', 'workorders',
        'customers', 'audit', 'gate', 'staff', 'portalUser', 'account',
    );
}

function assertEtaThrows(callable $fn, string $cls, string $needle, string $label): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if (!($e instanceof $cls)) {
            throw new RuntimeException(
                "[{$label}] expected {$cls}, got " . get_class($e) . ': ' . $e->getMessage()
            );
        }
        if ($needle !== '' && stripos($e->getMessage(), $needle) === false) {
            throw new RuntimeException(
                "[{$label}] message '{$e->getMessage()}' missing '{$needle}'"
            );
        }
        return;
    }
    throw new RuntimeException("[{$label}] expected {$cls} — none thrown");
}

function etaAssert(bool $cond, string $label): void
{
    if (!$cond) {
        throw new RuntimeException("[{$label}] assertion failed");
    }
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$passed = 0;
$failed = 0;
$only = $argv[1] ?? null;

function testEta(string $name, callable $fn, ?string $only, int &$passed, int &$failed): void
{
    if ($only !== null && stripos($name, $only) === false) {
        return;
    }
    try {
        $fn();
        echo "  ✓ {$name}\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "  ✗ {$name}\n    " . $e->getMessage() . "\n";
        $failed++;
    }
}

echo "PortalEtaPromiseServiceTest\n";

// 1. publishForTicket creates first promise, no supersede
testEta('publishForTicket creates initial promise', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $out = $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-01 14:00:00',
        'window_end_at' => '2026-05-01 16:00:00',
        'source' => 'manual',
        'confidence' => 80,
        'note' => 'Tech dispatched',
    ]);
    etaAssert($out['current']['id'] > 0, 'id positive');
    etaAssert($out['current']['window_start_at'] === '2026-05-01 14:00:00', 'start');
    etaAssert($out['current']['window_end_at'] === '2026-05-01 16:00:00', 'end');
    etaAssert($out['current']['source'] === 'manual', 'source');
    etaAssert($out['current']['confidence'] === 80, 'confidence');
    etaAssert($out['current']['note'] === 'Tech dispatched', 'note');
    etaAssert($out['current']['is_current'] === true, 'is_current');
    etaAssert($out['current']['superseded_at'] === null, 'no supersede');
    etaAssert($out['superseded_promise_id'] === null, 'no prior');
    etaAssert(count($f['audit']->entries) === 1, 'one audit');
    etaAssert($f['audit']->entries[0]->event === 'portal.eta.published', 'event');
    etaAssert($f['audit']->entries[0]->context['superseded_promise_id'] === null, 'audit no prior');
}, $only, $passed, $failed);

// 2. Second publish supersedes first
testEta('second publish supersedes prior promise', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $first = $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-01 14:00:00',
        'window_end_at' => '2026-05-01 16:00:00',
    ]);
    $second = $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-02 09:00:00',
        'window_end_at' => '2026-05-02 11:00:00',
        'note' => 'Parts delayed',
    ]);
    etaAssert($second['superseded_promise_id'] === $first['current']['id'], 'supersede chain');
    etaAssert($second['current']['id'] !== $first['current']['id'], 'distinct id');

    $history = $f['service']->readForTicketPortal(
        $f['portalUser'], $f['account'], 1,
    );
    etaAssert(count($history['history']) === 2, 'two in history');
    etaAssert($history['current']['id'] === $second['current']['id'], 'current is latest');
    etaAssert($history['current']['note'] === 'Parts delayed', 'current note');
    // History is newest-first.
    etaAssert($history['history'][0]['id'] === $second['current']['id'], 'history newest first');
    etaAssert($history['history'][1]['id'] === $first['current']['id'], 'history older second');
    etaAssert($history['history'][1]['is_current'] === false, 'older not current');
    etaAssert($history['history'][1]['superseded_by_id'] === $second['current']['id'], 'older superseded_by');
}, $only, $passed, $failed);

// 3. Validation: missing windows
testEta('publish rejects missing window_start_at', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_end_at' => '2026-05-01 16:00:00',
        ]),
        InvalidArgumentException::class, 'window_start_at', 'missing start',
    );
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_start_at' => '2026-05-01 14:00:00',
        ]),
        InvalidArgumentException::class, 'window_end_at', 'missing end',
    );
}, $only, $passed, $failed);

// 4. Validation: end before start
testEta('publish rejects end < start', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_start_at' => '2026-05-01 16:00:00',
            'window_end_at' => '2026-05-01 14:00:00',
        ]),
        InvalidArgumentException::class, '>=', 'end before start',
    );
}, $only, $passed, $failed);

// 5. Validation: span too large
testEta('publish rejects window span > 168h', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_start_at' => '2026-05-01 00:00:00',
            'window_end_at' => '2026-05-10 00:00:00',  // ~216h
        ]),
        InvalidArgumentException::class, 'span', 'span guard',
    );
}, $only, $passed, $failed);

// 6. Validation: source enum + confidence range
testEta('publish validates source + confidence', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_start_at' => '2026-05-01 14:00:00',
            'window_end_at' => '2026-05-01 16:00:00',
            'source' => 'bogus',
        ]),
        InvalidArgumentException::class, 'source', 'bad source',
    );
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_start_at' => '2026-05-01 14:00:00',
            'window_end_at' => '2026-05-01 16:00:00',
            'confidence' => 150,
        ]),
        InvalidArgumentException::class, '0 and 100', 'confidence range',
    );
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_start_at' => '2026-05-01 14:00:00',
            'window_end_at' => '2026-05-01 16:00:00',
            'confidence' => 'abc',
        ]),
        InvalidArgumentException::class, 'numeric', 'confidence non-numeric',
    );
}, $only, $passed, $failed);

// 7. Staff gate: tickets.manage
testEta('publishForTicket requires tickets.manage', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $f['gate']->denials['tickets.manage'] = true;
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_start_at' => '2026-05-01 14:00:00',
            'window_end_at' => '2026-05-01 16:00:00',
        ]),
        UnauthorizedException::class, 'tickets.manage', 'gate denies',
    );
}, $only, $passed, $failed);

// 8. Staff gate: workorders.manage
testEta('publishForWorkorder requires workorders.manage', function (): void {
    $f = makeEtaFixture();
    $f['customers']->seed(['id' => 5, 'company_id' => 10]);
    $f['workorders']->seed(['id' => 100, 'customer_id' => 5]);
    $f['gate']->denials['workorders.manage'] = true;
    assertEtaThrows(
        fn() => $f['service']->publishForWorkorder($f['staff'], 100, [
            'window_start_at' => '2026-05-01 14:00:00',
            'window_end_at' => '2026-05-01 16:00:00',
        ]),
        UnauthorizedException::class, 'workorders.manage', 'wo gate',
    );
}, $only, $passed, $failed);

// 9. Workorder publish resolves company via customer bridge
testEta('publishForWorkorder resolves company via customer bridge', function (): void {
    $f = makeEtaFixture();
    $f['customers']->seed(['id' => 5, 'company_id' => 10]);
    $f['workorders']->seed(['id' => 100, 'customer_id' => 5]);
    $out = $f['service']->publishForWorkorder($f['staff'], 100, [
        'window_start_at' => '2026-05-01 14:00:00',
        'window_end_at' => '2026-05-01 16:00:00',
    ]);
    etaAssert($out['current']['entity_type'] === 'workorder', 'entity type');
    etaAssert($out['current']['entity_id'] === 100, 'entity id');
    etaAssert($f['audit']->entries[0]->context['company_id'] === 10, 'company id in audit');
}, $only, $passed, $failed);

// 10. Workorder publish rejects WO without company-bound customer
testEta('publishForWorkorder rejects WO with no company binding', function (): void {
    $f = makeEtaFixture();
    $f['customers']->seed(['id' => 5, 'company_id' => null]);
    $f['workorders']->seed(['id' => 100, 'customer_id' => 5]);
    assertEtaThrows(
        fn() => $f['service']->publishForWorkorder($f['staff'], 100, [
            'window_start_at' => '2026-05-01 14:00:00',
            'window_end_at' => '2026-05-01 16:00:00',
        ]),
        InvalidArgumentException::class, 'company binding', 'orphan WO',
    );
}, $only, $passed, $failed);

// 11. cancelCurrent stamps superseded_at with null successor
testEta('cancelCurrentForTicket supersedes without successor', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $first = $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-01 14:00:00',
        'window_end_at' => '2026-05-01 16:00:00',
    ]);
    $f['service']->cancelCurrentForTicket($f['staff'], 1, 'customer postponed');
    $history = $f['service']->readForTicketPortal($f['portalUser'], $f['account'], 1);
    etaAssert($history['current'] === null, 'no current after cancel');
    etaAssert(count($history['history']) === 1, 'history retained');
    etaAssert($history['history'][0]['is_current'] === false, 'cancelled not current');
    etaAssert($history['history'][0]['superseded_at'] !== null, 'superseded stamped');
    etaAssert($history['history'][0]['superseded_by_id'] === null, 'no successor');
    $cancelAudits = array_filter(
        $f['audit']->entries,
        fn(AuditEntry $e) => $e->event === 'portal.eta.cancelled',
    );
    etaAssert(count($cancelAudits) === 1, 'one cancel audit');
    etaAssert(array_values($cancelAudits)[0]->context['reason'] === 'customer postponed', 'reason flows');
}, $only, $passed, $failed);

// 12. cancelCurrent rejects when no current
testEta('cancelCurrent rejects when no current promise', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    assertEtaThrows(
        fn() => $f['service']->cancelCurrentForTicket($f['staff'], 1, null),
        InvalidArgumentException::class, 'no current', 'nothing to cancel',
    );
}, $only, $passed, $failed);

// 13. Portal scope: cross-company ticket
testEta('portal read rejects cross-company ticket', function (): void {
    $f = makeEtaFixture(companyId: 10);
    $f['tickets']->seed(['id' => 1, 'company_id' => 99]);
    assertEtaThrows(
        fn() => $f['service']->readForTicketPortal($f['portalUser'], $f['account'], 1),
        UnauthorizedException::class, 'different company', 'cross-company',
    );
}, $only, $passed, $failed);

// 14. Portal scope: site whitelist
testEta('portal read honors allowed_site_ids whitelist', function (): void {
    $f = makeEtaFixture(companyId: 10, allowedSiteIds: [50]);
    $f['tickets']->seed(['id' => 1, 'company_id' => 10, 'site_id' => 50]);
    $f['tickets']->seed(['id' => 2, 'company_id' => 10, 'site_id' => 51]);
    $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-01 14:00:00',
        'window_end_at' => '2026-05-01 16:00:00',
    ]);
    // Allowed site succeeds.
    $ok = $f['service']->readForTicketPortal($f['portalUser'], $f['account'], 1);
    etaAssert($ok['current'] !== null, 'allowed site reads');
    // Out-of-whitelist site rejects.
    assertEtaThrows(
        fn() => $f['service']->readForTicketPortal($f['portalUser'], $f['account'], 2),
        UnauthorizedException::class, 'site 51', 'site whitelist',
    );
}, $only, $passed, $failed);

// 15. Portal scope: revoked account blocked
testEta('revoked portal_account blocked from read', function (): void {
    $f = makeEtaFixture(companyId: 10, revokedAt: '2026-04-01 00:00:00');
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    assertEtaThrows(
        fn() => $f['service']->readForTicketPortal($f['portalUser'], $f['account'], 1),
        UnauthorizedException::class, 'not usable', 'revoked blocked',
    );
}, $only, $passed, $failed);

// 16. Portal scope: workorder via customer bridge
testEta('portal read scopes workorder via customer.company_id', function (): void {
    $f = makeEtaFixture(companyId: 10);
    $f['customers']->seed(['id' => 5, 'company_id' => 10]);
    $f['customers']->seed(['id' => 6, 'company_id' => 99]);
    $f['workorders']->seed(['id' => 100, 'customer_id' => 5]);
    $f['workorders']->seed(['id' => 200, 'customer_id' => 6]);
    $f['service']->publishForWorkorder($f['staff'], 100, [
        'window_start_at' => '2026-05-01 14:00:00',
        'window_end_at' => '2026-05-01 16:00:00',
    ]);
    $out = $f['service']->readForWorkorderPortal($f['portalUser'], $f['account'], 100);
    etaAssert($out['current'] !== null, 'same-company WO reads');
    assertEtaThrows(
        fn() => $f['service']->readForWorkorderPortal($f['portalUser'], $f['account'], 200),
        UnauthorizedException::class, 'different company', 'cross-company WO',
    );
}, $only, $passed, $failed);

// 17. Unknown ticket/WO rejects
testEta('unknown entity ids reject before repo write', function (): void {
    $f = makeEtaFixture();
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 0, [
            'window_start_at' => '2026-05-01 14:00:00',
            'window_end_at' => '2026-05-01 16:00:00',
        ]),
        InvalidArgumentException::class, 'ticket id', 'non-positive id',
    );
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 9999, [
            'window_start_at' => '2026-05-01 14:00:00',
            'window_end_at' => '2026-05-01 16:00:00',
        ]),
        InvalidArgumentException::class, 'not found', 'unknown id',
    );
}, $only, $passed, $failed);

// 18. Concurrent supersede detection — mimic two staff members racing
testEta('concurrent supersede throws loudly', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $first = $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-01 14:00:00',
        'window_end_at' => '2026-05-01 16:00:00',
    ]);
    // Manually mark the prior row superseded between our read and write.
    $f['pdo']->exec(
        'UPDATE portal_eta_promises SET superseded_at = "2026-05-01 13:59:00" WHERE id = ' . $first['current']['id']
    );
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_start_at' => '2026-05-01 15:00:00',
            'window_end_at' => '2026-05-01 17:00:00',
        ]),
        RuntimeException::class, 'concurrent', 'concurrent guard',
    );
}, $only, $passed, $failed);

// 19. Staff read — gate + history
testEta('listHistoryForTicketStaff requires tickets.view', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-01 14:00:00',
        'window_end_at' => '2026-05-01 16:00:00',
    ]);
    $f['gate']->denials['tickets.view'] = true;
    assertEtaThrows(
        fn() => $f['service']->listHistoryForTicketStaff($f['staff'], 1),
        UnauthorizedException::class, 'tickets.view', 'view gate',
    );
    $f['gate']->denials = [];
    $history = $f['service']->listHistoryForTicketStaff($f['staff'], 1);
    etaAssert(count($history['history']) === 1, 'staff sees history');
    etaAssert($history['current']['is_current'] === true, 'current flagged');
}, $only, $passed, $failed);

// 20. Portal read returns empty history for entity with no promises
testEta('portal read returns empty history when no promises', function (): void {
    $f = makeEtaFixture(companyId: 10);
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $out = $f['service']->readForTicketPortal($f['portalUser'], $f['account'], 1);
    etaAssert($out['current'] === null, 'no current');
    etaAssert($out['history'] === [], 'empty history');
    etaAssert($out['entity_type'] === 'ticket', 'entity type in shell');
    etaAssert($out['entity_id'] === 1, 'entity id in shell');
}, $only, $passed, $failed);

// 21. Note validation
testEta('publish rejects note > 1000 chars', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    assertEtaThrows(
        fn() => $f['service']->publishForTicket($f['staff'], 1, [
            'window_start_at' => '2026-05-01 14:00:00',
            'window_end_at' => '2026-05-01 16:00:00',
            'note' => str_repeat('x', 1001),
        ]),
        InvalidArgumentException::class, '1000', 'note length',
    );
}, $only, $passed, $failed);

// 22. Only the latest unsuperseded row is "current"
testEta('findCurrentForEntity returns only unsuperseded', function (): void {
    $f = makeEtaFixture();
    $f['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-01 14:00:00',
        'window_end_at' => '2026-05-01 16:00:00',
    ]);
    $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-02 09:00:00',
        'window_end_at' => '2026-05-02 11:00:00',
    ]);
    $third = $f['service']->publishForTicket($f['staff'], 1, [
        'window_start_at' => '2026-05-03 09:00:00',
        'window_end_at' => '2026-05-03 11:00:00',
    ]);
    $current = $f['promiseRepo']->findCurrentForEntity('ticket', 1);
    etaAssert($current !== null, 'current exists');
    etaAssert($current->id === $third['current']['id'], 'current is third');
    $f['service']->cancelCurrentForTicket($f['staff'], 1, null);
    etaAssert(
        $f['promiseRepo']->findCurrentForEntity('ticket', 1) === null,
        'no current after cancel'
    );
}, $only, $passed, $failed);

echo "\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
exit($failed === 0 ? 0 : 1);
