<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "PortalMessagingServiceTest\n";
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
use App\Services\Portal\PortalMessagingService;
use App\Services\Tickets\TicketRepository;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 6.5 of docs/expansion-plan.md — portal messaging service.
 *
 * Uses an in-memory SQLite database against a stripped-down schema of
 * the real message_* tables (plus fake Ticket/Workorder/Customer repos)
 * so we can verify end-to-end: scope enforcement, internal-message
 * hiding, portal_account_id tagging, audit event emission, participant
 * gating, and the start-thread + post-reply + mark-read lifecycle.
 */

// ---------------------------------------------------------------------------
// SQLite-backed Connection
// ---------------------------------------------------------------------------

class PmInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner) {}
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function pmSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = [
        'CREATE TABLE message_threads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject TEXT NULL,
            ticket_id INTEGER NULL,
            workorder_id INTEGER NULL,
            created_by INTEGER NOT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )',
        'CREATE TABLE message_participants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            participant_id INTEGER NOT NULL,
            created_at TEXT NULL
        )',
        'CREATE TABLE message_messages (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            sender_id INTEGER NOT NULL,
            body TEXT NOT NULL,
            is_internal INTEGER NOT NULL DEFAULT 0,
            portal_account_id INTEGER NULL,
            created_at TEXT NULL
        )',
        'CREATE TABLE message_reads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            thread_id INTEGER NOT NULL,
            participant_id INTEGER NOT NULL,
            last_read_message_id INTEGER NULL,
            last_read_at TEXT NULL,
            created_at TEXT NULL,
            updated_at TEXT NULL
        )',
    ];
    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }
    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class PmFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct() {}
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class PmFakeTickets extends TicketRepository
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

class PmFakeWorkorders extends WorkorderRepository
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

class PmFakeCustomers extends CustomerRepository
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

// ---------------------------------------------------------------------------
// Fake PortalAuthService (only assertSiteAccess needed by the service)
// ---------------------------------------------------------------------------

function pmPortalAuthStub(): PortalAuthService
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

// ---------------------------------------------------------------------------
// Fixture
// ---------------------------------------------------------------------------

function makeMsgFixture(
    int $companyId = 10,
    bool $accountActive = true,
    ?string $revokedAt = null,
    ?array $allowedSiteIds = null,
): array {
    $pdo = pmSetUpDatabase();
    $conn = new PmInMemoryConnection($pdo);
    $audit = new PmFakeAudit();
    $tickets = new PmFakeTickets();
    $workorders = new PmFakeWorkorders();
    $customers = new PmFakeCustomers();
    $portalAuth = pmPortalAuthStub();
    $service = new PortalMessagingService(
        $conn, $tickets, $workorders, $customers, $portalAuth, $audit,
    );

    $user = new User();
    $user->id = 999;

    $account = new PortalAccount();
    $account->id = 77;
    $account->user_id = 999;
    $account->company_id = $companyId;
    $account->is_active = $accountActive;
    $account->revoked_at = $revokedAt;
    $account->allowed_site_ids = $allowedSiteIds;

    return compact('service', 'pdo', 'conn', 'tickets', 'workorders', 'customers',
        'audit', 'user', 'account');
}

function assertMsgThrows(callable $fn, string $cls, string $needle, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $cls)) {
            throw new RuntimeException(
                "{$label}: expected {$cls}, got " . $e::class . ' — ' . $e->getMessage()
            );
        }
        if ($needle !== '' && stripos($e->getMessage(), $needle) === false) {
            throw new RuntimeException(
                "{$label}: expected message [{$needle}], got [{$e->getMessage()}]"
            );
        }
        return;
    }
    throw new RuntimeException("{$label}: expected {$cls} but nothing was thrown");
}

function pmPass(string $label): void
{
    echo "  ok — {$label}\n";
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

echo "PortalMessagingServiceTest\n";

// -- startThreadForTicket ----------------------------------------------------

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    /** @var PmFakeAudit $audit */
    $audit = $fx['audit'];
    $tickets->seed(['id' => 1, 'company_id' => 10, 'site_id' => null, 'title' => 'Broken AC']);

    $out = $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'please fix soon');
    if (!isset($out['id']) || $out['ticket_id'] !== 1 || $out['workorder_id'] !== null) {
        throw new RuntimeException('thread create payload wrong: ' . json_encode($out));
    }
    if ($out['initial_message']['body'] !== 'please fix soon') {
        throw new RuntimeException('initial message body not recorded');
    }
    if ($out['initial_message']['is_from_portal'] !== true) {
        throw new RuntimeException('portal tag missing');
    }
    // Thread was created and portal user is a participant
    $rows = $fx['pdo']->query(
        'SELECT COUNT(*) FROM message_participants WHERE thread_id = ' . (int) $out['id']
    )->fetchColumn();
    if ((int) $rows !== 1) {
        throw new RuntimeException('expected 1 participant, got ' . $rows);
    }
    // portal_account_id stamped
    $row = $fx['pdo']->query(
        'SELECT portal_account_id, is_internal FROM message_messages LIMIT 1'
    )->fetch(PDO::FETCH_ASSOC);
    if ((int) $row['portal_account_id'] !== 77 || (int) $row['is_internal'] !== 0) {
        throw new RuntimeException('portal_account_id/is_internal not set: ' . json_encode($row));
    }
    // Subject defaulted to ticket title
    if ($out['subject'] !== 'Broken AC') {
        throw new RuntimeException('subject not defaulted to ticket title: ' . $out['subject']);
    }
    $ev = $audit->entries[0];
    if ($ev->event !== 'portal.message.thread_started'
        || $ev->context['portal_account_id'] !== 77
        || $ev->context['ticket_id'] !== 1
        || $ev->context['entity_type'] !== 'ticket'
    ) {
        throw new RuntimeException('audit wrong: ' . json_encode($ev->context));
    }
    pmPass('startThreadForTicket creates thread, links ticket, tags portal, audits');
})();

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    $tickets->seed(['id' => 1, 'company_id' => 11]); // wrong company
    assertMsgThrows(
        fn() => $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'hi'),
        UnauthorizedException::class, 'different company',
        'startThreadForTicket cross-company'
    );
    pmPass('startThreadForTicket rejects cross-company ticket');
})();

(function () {
    // Allowed sites = [2] only
    $fx = makeMsgFixture(10, true, null, [2]);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    $tickets->seed(['id' => 1, 'company_id' => 10, 'site_id' => 1]); // site outside whitelist
    $tickets->seed(['id' => 2, 'company_id' => 10, 'site_id' => 2]); // allowed
    assertMsgThrows(
        fn() => $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'hi'),
        UnauthorizedException::class, 'cannot access',
        'startThreadForTicket outside allowed_site_ids'
    );
    // Allowed site works
    $out = $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 2, 'hi');
    if (!isset($out['id'])) {
        throw new RuntimeException('expected thread created for allowed site');
    }
    pmPass('startThreadForTicket honors allowed_site_ids whitelist');
})();

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    $tickets->seed(['id' => 1, 'company_id' => 10]);
    assertMsgThrows(
        fn() => $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, '   '),
        InvalidArgumentException::class, 'body',
        'startThreadForTicket blank body'
    );
    assertMsgThrows(
        fn() => $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, str_repeat('a', 20001)),
        InvalidArgumentException::class, '20000',
        'startThreadForTicket oversize body'
    );
    pmPass('startThreadForTicket validates body length');
})();

// -- startThreadForWorkorder -------------------------------------------------

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeWorkorders $workorders */
    $workorders = $fx['workorders'];
    /** @var PmFakeCustomers $customers */
    $customers = $fx['customers'];
    /** @var PmFakeAudit $audit */
    $audit = $fx['audit'];

    $customers->seed(['id' => 1, 'company_id' => 10]);
    $workorders->seed(['id' => 1, 'customer_id' => 1, 'number' => '0042']);
    $out = $fx['service']->startThreadForWorkorder($fx['user'], $fx['account'], 1, 'where is it');
    if ($out['ticket_id'] !== null || $out['workorder_id'] !== 1) {
        throw new RuntimeException('WO thread wrong: ' . json_encode($out));
    }
    if ($out['subject'] !== 'WO-0042') {
        throw new RuntimeException('WO subject should default to WO-{number}, got ' . $out['subject']);
    }
    $ev = $audit->entries[0];
    if ($ev->context['workorder_id'] !== 1 || $ev->context['entity_type'] !== 'workorder') {
        throw new RuntimeException('audit wrong: ' . json_encode($ev->context));
    }
    pmPass('startThreadForWorkorder scopes via customer.company_id + audits');
})();

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeWorkorders $workorders */
    $workorders = $fx['workorders'];
    /** @var PmFakeCustomers $customers */
    $customers = $fx['customers'];
    $customers->seed(['id' => 1, 'company_id' => 11]); // wrong company
    $workorders->seed(['id' => 1, 'customer_id' => 1]);
    assertMsgThrows(
        fn() => $fx['service']->startThreadForWorkorder($fx['user'], $fx['account'], 1, 'x'),
        UnauthorizedException::class, 'different company',
        'startThreadForWorkorder cross-company'
    );
    pmPass('startThreadForWorkorder rejects WO whose customer is in another company');
})();

// -- postMessage -------------------------------------------------------------

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    /** @var PmFakeAudit $audit */
    $audit = $fx['audit'];
    $tickets->seed(['id' => 1, 'company_id' => 10]);

    $thread = $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'initial');
    $audit->entries = []; // reset

    $reply = $fx['service']->postMessage($fx['user'], $fx['account'], $thread['id'], 'follow-up');
    if ($reply['body'] !== 'follow-up' || $reply['is_from_portal'] !== true) {
        throw new RuntimeException('reply serialization wrong: ' . json_encode($reply));
    }
    $ev = $audit->entries[0];
    if ($ev->event !== 'portal.message.posted'
        || $ev->context['portal_account_id'] !== 77
        || $ev->context['ticket_id'] !== 1
    ) {
        throw new RuntimeException('posted audit wrong: ' . json_encode($ev->context));
    }
    pmPass('postMessage tags portal_account_id and audits portal.message.posted');
})();

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    $tickets->seed(['id' => 1, 'company_id' => 10]);
    $thread = $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'initial');
    assertMsgThrows(
        fn() => $fx['service']->postMessage($fx['user'], $fx['account'], $thread['id'], ''),
        InvalidArgumentException::class, 'body',
        'postMessage blank body'
    );
    pmPass('postMessage requires non-blank body');
})();

(function () {
    // Thread that the portal user is NOT a participant in must reject.
    $fx = makeMsgFixture(10);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    $tickets->seed(['id' => 1, 'company_id' => 10]);
    $fx['pdo']->exec(
        "INSERT INTO message_threads (subject, ticket_id, created_by, created_at)
         VALUES ('Staff-only', 1, 500, '2026-01-01')"
    );
    $threadId = (int) $fx['pdo']->lastInsertId();
    $fx['pdo']->exec(
        "INSERT INTO message_participants (thread_id, participant_id, created_at)
         VALUES ({$threadId}, 500, '2026-01-01')"
    );
    assertMsgThrows(
        fn() => $fx['service']->postMessage($fx['user'], $fx['account'], $threadId, 'hi'),
        UnauthorizedException::class, 'not a participant',
        'postMessage where portal user is not a participant'
    );
    assertMsgThrows(
        fn() => $fx['service']->listMessages($fx['user'], $fx['account'], $threadId),
        UnauthorizedException::class, 'not a participant',
        'listMessages where portal user is not a participant'
    );
    pmPass('loadScopedThread rejects threads where portal user is not a participant');
})();

(function () {
    // Thread with no ticket/WO link — staff-only — portal must not read.
    $fx = makeMsgFixture(10);
    $fx['pdo']->exec(
        "INSERT INTO message_threads (subject, ticket_id, workorder_id, created_by, created_at)
         VALUES ('Unlinked', NULL, NULL, 999, '2026-01-01')"
    );
    $threadId = (int) $fx['pdo']->lastInsertId();
    $fx['pdo']->exec(
        "INSERT INTO message_participants (thread_id, participant_id, created_at)
         VALUES ({$threadId}, 999, '2026-01-01')"
    );
    assertMsgThrows(
        fn() => $fx['service']->listMessages($fx['user'], $fx['account'], $threadId),
        UnauthorizedException::class, 'not linked',
        'listMessages on unlinked thread'
    );
    pmPass('loadScopedThread rejects threads with no ticket/WO link');
})();

// -- listMessages: internal filter -------------------------------------------

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    $tickets->seed(['id' => 1, 'company_id' => 10]);
    $thread = $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'hello');

    // Staff inserts an internal-only note directly
    $fx['pdo']->exec(
        "INSERT INTO message_messages
            (thread_id, sender_id, body, is_internal, portal_account_id, created_at)
         VALUES ({$thread['id']}, 500, 'Staff only: watch for scope creep', 1, NULL, '2026-01-02')"
    );
    // Plus a non-internal staff reply
    $fx['pdo']->exec(
        "INSERT INTO message_messages
            (thread_id, sender_id, body, is_internal, portal_account_id, created_at)
         VALUES ({$thread['id']}, 500, 'On it today', 0, NULL, '2026-01-03')"
    );

    $messages = $fx['service']->listMessages($fx['user'], $fx['account'], $thread['id']);
    if (count($messages) !== 2) {
        throw new RuntimeException('expected 2 portal-visible messages (initial + staff reply), got ' . count($messages));
    }
    foreach ($messages as $m) {
        if (stripos($m['body'], 'Staff only') !== false) {
            throw new RuntimeException('internal message leaked to portal: ' . json_encode($m));
        }
    }
    // Initial is portal-tagged, staff reply isn't
    $initial = array_values(array_filter($messages, fn($m) => $m['body'] === 'hello'))[0];
    $staff = array_values(array_filter($messages, fn($m) => $m['body'] === 'On it today'))[0];
    if ($initial['is_from_portal'] !== true || $staff['is_from_portal'] !== false) {
        throw new RuntimeException('portal tagging wrong: ' . json_encode(['initial' => $initial, 'staff' => $staff]));
    }
    pmPass('listMessages filters is_internal=1 and carries is_from_portal flag');
})();

// -- listThreadsForTicket / Workorder ---------------------------------------

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    $tickets->seed(['id' => 1, 'company_id' => 10]);

    $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'first');
    $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'second');

    // Another thread linked to a DIFFERENT ticket must not appear
    $tickets->seed(['id' => 2, 'company_id' => 10]);
    $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 2, 'other');

    // A thread on ticket 1 that this portal user isn't in
    $fx['pdo']->exec(
        "INSERT INTO message_threads (subject, ticket_id, created_by, created_at)
         VALUES ('hidden', 1, 500, '2026-01-01')"
    );
    $hiddenId = (int) $fx['pdo']->lastInsertId();
    $fx['pdo']->exec(
        "INSERT INTO message_participants (thread_id, participant_id) VALUES ({$hiddenId}, 500)"
    );

    $threads = $fx['service']->listThreadsForTicket($fx['user'], $fx['account'], 1);
    if (count($threads) !== 2) {
        throw new RuntimeException('expected 2 threads for ticket 1 (participant), got ' . count($threads));
    }
    foreach ($threads as $t) {
        if ($t['ticket_id'] !== 1) {
            throw new RuntimeException('wrong ticket_id in results: ' . json_encode($t));
        }
    }
    pmPass('listThreadsForTicket returns only participating threads for that ticket');
})();

// -- markRead ----------------------------------------------------------------

(function () {
    $fx = makeMsgFixture(10);
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    $tickets->seed(['id' => 1, 'company_id' => 10]);
    $thread = $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'first');

    $fx['pdo']->exec(
        "INSERT INTO message_messages
            (thread_id, sender_id, body, is_internal, created_at)
         VALUES ({$thread['id']}, 500, 'staff reply', 0, '2026-01-02')"
    );
    $fx['service']->markRead($fx['user'], $fx['account'], $thread['id']);
    $row = $fx['pdo']->query(
        'SELECT last_read_message_id FROM message_reads WHERE thread_id = ' . (int) $thread['id']
    )->fetch(PDO::FETCH_ASSOC);
    if (!$row || $row['last_read_message_id'] === null) {
        throw new RuntimeException('read receipt not recorded: ' . json_encode($row));
    }
    // Calling again should update, not duplicate
    $fx['service']->markRead($fx['user'], $fx['account'], $thread['id']);
    $count = (int) $fx['pdo']->query(
        'SELECT COUNT(*) FROM message_reads WHERE thread_id = ' . (int) $thread['id']
    )->fetchColumn();
    if ($count !== 1) {
        throw new RuntimeException('markRead should upsert not insert-duplicate, got ' . $count);
    }
    pmPass('markRead records + upserts participant read receipt');
})();

// -- Revoked account block ---------------------------------------------------

(function () {
    $fx = makeMsgFixture(10, true, '2026-04-01 00:00:00');
    /** @var PmFakeTickets $tickets */
    $tickets = $fx['tickets'];
    /** @var PmFakeWorkorders $workorders */
    $workorders = $fx['workorders'];
    /** @var PmFakeCustomers $customers */
    $customers = $fx['customers'];
    $tickets->seed(['id' => 1, 'company_id' => 10]);
    $customers->seed(['id' => 1, 'company_id' => 10]);
    $workorders->seed(['id' => 1, 'customer_id' => 1]);

    assertMsgThrows(
        fn() => $fx['service']->listThreadsForTicket($fx['user'], $fx['account'], 1),
        UnauthorizedException::class, 'not usable',
        'listThreadsForTicket revoked'
    );
    assertMsgThrows(
        fn() => $fx['service']->listThreadsForWorkorder($fx['user'], $fx['account'], 1),
        UnauthorizedException::class, 'not usable',
        'listThreadsForWorkorder revoked'
    );
    assertMsgThrows(
        fn() => $fx['service']->startThreadForTicket($fx['user'], $fx['account'], 1, 'x'),
        UnauthorizedException::class, 'not usable',
        'startThreadForTicket revoked'
    );
    assertMsgThrows(
        fn() => $fx['service']->startThreadForWorkorder($fx['user'], $fx['account'], 1, 'x'),
        UnauthorizedException::class, 'not usable',
        'startThreadForWorkorder revoked'
    );
    // For list/post/markRead on existing thread we need one to target
    // — but revocation halts at loadScopedThread assertUsable first.
    assertMsgThrows(
        fn() => $fx['service']->listMessages($fx['user'], $fx['account'], 999),
        UnauthorizedException::class, 'not usable',
        'listMessages revoked'
    );
    assertMsgThrows(
        fn() => $fx['service']->postMessage($fx['user'], $fx['account'], 999, 'x'),
        UnauthorizedException::class, 'not usable',
        'postMessage revoked'
    );
    assertMsgThrows(
        fn() => $fx['service']->markRead($fx['user'], $fx['account'], 999),
        UnauthorizedException::class, 'not usable',
        'markRead revoked'
    );
    pmPass('revoked portal_account is blocked from every messaging action');
})();

// -- Invalid ids -------------------------------------------------------------

(function () {
    $fx = makeMsgFixture(10);
    assertMsgThrows(
        fn() => $fx['service']->listThreadsForTicket($fx['user'], $fx['account'], 0),
        InvalidArgumentException::class, 'ticket id',
        'listThreadsForTicket id=0'
    );
    assertMsgThrows(
        fn() => $fx['service']->postMessage($fx['user'], $fx['account'], 0, 'hi'),
        InvalidArgumentException::class, 'thread id',
        'postMessage id=0'
    );
    assertMsgThrows(
        fn() => $fx['service']->listMessages($fx['user'], $fx['account'], 9999),
        InvalidArgumentException::class, 'not found',
        'listMessages unknown id'
    );
    pmPass('non-positive + unknown ids are rejected');
})();

echo "\nAll PortalMessagingServiceTest cases passed.\n";
