<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "PortalUploadServiceTest\n";
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
use App\Services\Portal\PortalUploadRepository;
use App\Services\Portal\PortalUploadService;
use App\Services\Portal\PortalUploadStorage;
use App\Services\Portal\PortalUploadValidator;
use App\Services\Tickets\TicketRepository;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 6.6 of docs/expansion-plan.md — portal upload service.
 *
 * SQLite-backed in-memory DB covers the portal_uploads + message_threads
 * tables; fake repos cover tickets/workorders/customers; a tmpdir-
 * backed PortalUploadStorage subclass bypasses move_uploaded_file so we
 * can round-trip a real file through persist + download + unlink.
 */

class PuInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner) {}
    public function pdo(): PDO { return $this->inner; }
}

function puSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $schema = [
        'CREATE TABLE portal_uploads (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            portal_account_id INTEGER NOT NULL,
            company_id INTEGER NOT NULL,
            entity_type TEXT NOT NULL,
            entity_id INTEGER NOT NULL,
            original_name TEXT NOT NULL,
            stored_path TEXT NOT NULL,
            mime_type TEXT NOT NULL,
            size_bytes INTEGER NOT NULL,
            sha256 TEXT NOT NULL,
            uploaded_by_user_id INTEGER NOT NULL,
            created_at TEXT NULL DEFAULT CURRENT_TIMESTAMP,
            deleted_at TEXT NULL DEFAULT NULL
        )',
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
    ];
    foreach ($schema as $sql) {
        $pdo->exec($sql);
    }
    return $pdo;
}

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class PuFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct() {}
    public function log(AuditEntry $entry): void { $this->entries[] = $entry; }
}

class PuFakeTickets extends TicketRepository
{
    /** @var array<int, Ticket> */
    public array $store = [];
    public function __construct() {}
    public function findById(int $id): ?Ticket { return $this->store[$id] ?? null; }
    public function seed(array $row): Ticket
    {
        $t = new Ticket();
        $t->id = $row['id'];
        $t->ticket_number = $row['ticket_number'] ?? ('T-' . $t->id);
        $t->title = $row['title'] ?? 'Ticket';
        $t->company_id = $row['company_id'] ?? null;
        $t->site_id = $row['site_id'] ?? null;
        $t->status = $row['status'] ?? 'new';
        $this->store[$t->id] = $t;
        return $t;
    }
}

class PuFakeWorkorders extends WorkorderRepository
{
    /** @var array<int, Workorder> */
    public array $store = [];
    public function __construct() {}
    public function find(int $id): ?Workorder { return $this->store[$id] ?? null; }
    public function seed(array $row): Workorder
    {
        $w = new Workorder();
        $w->id = $row['id'];
        $w->number = $row['number'] ?? ('WO-' . $w->id);
        $w->estimate_id = (int) ($row['estimate_id'] ?? 0);
        $w->customer_id = (int) ($row['customer_id'] ?? 0);
        $w->vehicle_id = (int) ($row['vehicle_id'] ?? 0);
        $w->status = $row['status'] ?? 'pending';
        $this->store[$w->id] = $w;
        return $w;
    }
}

class PuFakeCustomers extends CustomerRepository
{
    /** @var array<int, Customer> */
    public array $store = [];
    public function __construct() {}
    public function find(int $id): ?Customer { return $this->store[$id] ?? null; }
    public function seed(array $row): Customer
    {
        $c = new Customer();
        $c->id = $row['id'];
        $c->first_name = $row['first_name'] ?? 'A';
        $c->last_name = $row['last_name'] ?? 'B';
        $c->email = $row['email'] ?? 'x@y.z';
        $c->phone = $row['phone'] ?? '555';
        $c->company_id = $row['company_id'] ?? null;
        $this->store[$c->id] = $c;
        return $c;
    }
}

/**
 * Test-friendly storage: writes to a tmpdir and doesn't require
 * is_uploaded_file. Tracks persist/unlink so tests can assert physical
 * file lifecycle without reaching into the filesystem.
 */
class PuTestStorage extends PortalUploadStorage
{
    public array $persistedAbs = [];
    public array $unlinked = [];
    public function __construct(string $root)
    {
        parent::__construct($root, '/uploads/portal');
    }
    public function persist(string $tmpPath, string $destAbsPath): void
    {
        if (!@rename($tmpPath, $destAbsPath) && !@copy($tmpPath, $destAbsPath)) {
            throw new RuntimeException('test persist failed');
        }
        $this->persistedAbs[] = $destAbsPath;
    }
    public function unlink(string $relPath): bool
    {
        $this->unlinked[] = $relPath;
        return parent::unlink($relPath);
    }
}

function puPortalAuth(): PortalAuthService
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
 * Create a tmp file with given bytes and return a $_FILES-style array
 * with is_uploaded_file bypass (validator is called with
 * requireUploadedFile=false in tests).
 */
function puFakeFile(string $bytes, string $name = 'photo.png'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'pu_test_');
    file_put_contents($tmp, $bytes);
    return [
        'tmp_name' => $tmp,
        'name' => $name,
        'size' => strlen($bytes),
        'error' => UPLOAD_ERR_OK,
        'type' => 'application/octet-stream',
    ];
}

// Tiny valid 1x1 PNG (89 bytes). finfo will detect image/png from its
// magic bytes so the MIME allowlist accepts it.
function puPng(): string
{
    return base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg=='
    );
}

function puFixture(
    int $companyId = 10,
    bool $accountActive = true,
    ?string $revokedAt = null,
    ?array $allowedSiteIds = null,
): array {
    $pdo = puSetUpDatabase();
    $conn = new PuInMemoryConnection($pdo);
    $audit = new PuFakeAudit();
    $tickets = new PuFakeTickets();
    $workorders = new PuFakeWorkorders();
    $customers = new PuFakeCustomers();
    $portalAuth = puPortalAuth();
    $tmpRoot = sys_get_temp_dir() . '/pu_root_' . uniqid();
    mkdir($tmpRoot, 0775, true);
    $storage = new PuTestStorage($tmpRoot);
    $service = new PortalUploadService(
        $conn,
        new PortalUploadRepository($conn),
        $storage,
        $tickets,
        $workorders,
        $customers,
        $portalAuth,
        $audit,
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

    return compact(
        'service', 'pdo', 'conn', 'tickets', 'workorders', 'customers',
        'audit', 'storage', 'user', 'account', 'tmpRoot'
    );
}

function puAssertThrows(callable $fn, string $cls, string $needle, string $label): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $cls)) {
            throw new RuntimeException("{$label}: expected {$cls}, got " . $e::class . ' — ' . $e->getMessage());
        }
        if ($needle !== '' && stripos($e->getMessage(), $needle) === false) {
            throw new RuntimeException("{$label}: expected [{$needle}], got [{$e->getMessage()}]");
        }
        return;
    }
    throw new RuntimeException("{$label}: expected {$cls} but nothing was thrown");
}

function puPass(string $label): void
{
    echo "  ok — {$label}\n";
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

echo "PortalUploadServiceTest\n";

// -- Validator ---------------------------------------------------------------

(function () {
    // Happy path — real PNG bytes validated
    $file = puFakeFile(puPng(), 'front.png');
    $out = PortalUploadValidator::validate($file, false);
    if ($out['mime_type'] !== 'image/png' || $out['extension'] !== 'png') {
        throw new RuntimeException('png validation wrong: ' . json_encode($out));
    }
    if ($out['original_name'] !== 'front.png' || $out['size'] !== strlen(puPng())) {
        throw new RuntimeException('validator payload wrong: ' . json_encode($out));
    }
    if (strlen($out['sha256']) !== 64) {
        throw new RuntimeException('sha256 should be 64 hex chars, got ' . $out['sha256']);
    }
    puPass('validator accepts valid PNG and captures sha256 + size');
    @unlink($file['tmp_name']);
})();

(function () {
    // Reject non-image text file — finfo will report text/plain which
    // is not in the allowlist.
    $file = puFakeFile("hello world, not a real image", 'hi.png');
    puAssertThrows(
        fn() => PortalUploadValidator::validate($file, false),
        InvalidArgumentException::class, 'not allowed',
        'validator rejects mismatched extension'
    );
    @unlink($file['tmp_name']);
    puPass('validator detects MIME from magic bytes, ignores client extension');
})();

(function () {
    // Empty file rejected
    $file = puFakeFile('', 'empty.png');
    puAssertThrows(
        fn() => PortalUploadValidator::validate($file, false),
        InvalidArgumentException::class, 'empty',
        'validator empty file'
    );
    @unlink($file['tmp_name']);
    puPass('validator rejects empty uploads');
})();

(function () {
    // Filename sanitization — path traversal attempts
    $name = PortalUploadValidator::sanitizeOriginalName('../../etc/passwd');
    if (str_contains($name, '..') || str_contains($name, '/')) {
        throw new RuntimeException('sanitizer failed to strip ../ segments: ' . $name);
    }
    $name = PortalUploadValidator::sanitizeOriginalName('');
    if ($name !== 'upload') {
        throw new RuntimeException('sanitizer should default empty to upload, got ' . $name);
    }
    $name = PortalUploadValidator::sanitizeOriginalName(str_repeat('a', 300));
    if (strlen($name) > 255) {
        throw new RuntimeException('sanitizer should cap at 255, got ' . strlen($name));
    }
    puPass('validator sanitizeOriginalName strips path separators + caps length');
})();

// -- uploadForTicket ---------------------------------------------------------

(function () {
    $fx = puFixture(10);
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $file = puFakeFile(puPng(), 'scene.png');

    $out = $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, $file, false);
    if ($out['entity_type'] !== 'ticket' || $out['entity_id'] !== 1) {
        throw new RuntimeException('entity link wrong: ' . json_encode($out));
    }
    if ($out['mime_type'] !== 'image/png' || $out['size_bytes'] !== strlen(puPng())) {
        throw new RuntimeException('upload payload wrong: ' . json_encode($out));
    }
    if ($out['portal_account_id'] !== 77 || $out['uploaded_by_user_id'] !== 999) {
        throw new RuntimeException('attribution wrong: ' . json_encode($out));
    }
    // File landed on disk and DB row points at it
    $count = (int) $fx['pdo']->query('SELECT COUNT(*) FROM portal_uploads WHERE deleted_at IS NULL')->fetchColumn();
    if ($count !== 1) {
        throw new RuntimeException('expected 1 upload row, got ' . $count);
    }
    if (count($fx['storage']->persistedAbs) !== 1) {
        throw new RuntimeException('storage should have persisted exactly one file');
    }
    if (!is_file($fx['storage']->persistedAbs[0])) {
        throw new RuntimeException('persisted file missing on disk');
    }
    // Audit
    $ev = $fx['audit']->entries[0];
    if ($ev->event !== 'portal.upload.created'
        || $ev->context['entity_type'] !== 'ticket'
        || $ev->context['ticket_id'] !== 1
        || $ev->context['portal_account_id'] !== 77
        || $ev->context['mime_type'] !== 'image/png'
    ) {
        throw new RuntimeException('audit wrong: ' . json_encode($ev->context));
    }
    puPass('uploadForTicket persists file, records row, audits portal.upload.created');
})();

(function () {
    $fx = puFixture(10);
    $fx['tickets']->seed(['id' => 1, 'company_id' => 11]); // wrong company
    $file = puFakeFile(puPng());
    puAssertThrows(
        fn() => $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, $file, false),
        UnauthorizedException::class, 'different company',
        'uploadForTicket cross-company'
    );
    @unlink($file['tmp_name']);
    puPass('uploadForTicket rejects cross-company ticket');
})();

(function () {
    $fx = puFixture(10, true, null, [2]); // allowed sites = [2]
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10, 'site_id' => 1]); // site 1 not allowed
    $file = puFakeFile(puPng());
    puAssertThrows(
        fn() => $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, $file, false),
        UnauthorizedException::class, 'cannot access',
        'uploadForTicket outside whitelist'
    );
    @unlink($file['tmp_name']);
    puPass('uploadForTicket honors allowed_site_ids whitelist');
})();

// -- uploadForWorkorder ------------------------------------------------------

(function () {
    $fx = puFixture(10);
    $fx['customers']->seed(['id' => 1, 'company_id' => 10]);
    $fx['workorders']->seed(['id' => 1, 'customer_id' => 1]);
    $file = puFakeFile(puPng(), 'wo.png');
    $out = $fx['service']->uploadForWorkorder($fx['user'], $fx['account'], 1, $file, false);
    if ($out['entity_type'] !== 'workorder' || $out['entity_id'] !== 1) {
        throw new RuntimeException('WO link wrong: ' . json_encode($out));
    }
    $ev = $fx['audit']->entries[0];
    if ($ev->context['workorder_id'] !== 1 || $ev->context['customer_id'] !== 1) {
        throw new RuntimeException('WO audit wrong: ' . json_encode($ev->context));
    }
    puPass('uploadForWorkorder bridges via customers.company_id + audits');
})();

(function () {
    $fx = puFixture(10);
    $fx['customers']->seed(['id' => 1, 'company_id' => 11]); // wrong company
    $fx['workorders']->seed(['id' => 1, 'customer_id' => 1]);
    $file = puFakeFile(puPng());
    puAssertThrows(
        fn() => $fx['service']->uploadForWorkorder($fx['user'], $fx['account'], 1, $file, false),
        UnauthorizedException::class, 'different company',
        'uploadForWorkorder cross-company'
    );
    @unlink($file['tmp_name']);
    puPass('uploadForWorkorder rejects WO whose customer is in another company');
})();

// -- uploadForThread ---------------------------------------------------------

(function () {
    $fx = puFixture(10);
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $fx['pdo']->exec(
        "INSERT INTO message_threads (subject, ticket_id, created_by, created_at)
         VALUES ('hi', 1, 999, '2026-01-01')"
    );
    $tid = (int) $fx['pdo']->lastInsertId();
    $fx['pdo']->exec("INSERT INTO message_participants (thread_id, participant_id) VALUES ({$tid}, 999)");

    $file = puFakeFile(puPng(), 't.png');
    $out = $fx['service']->uploadForThread($fx['user'], $fx['account'], $tid, $file, false);
    if ($out['entity_type'] !== 'message_thread' || $out['entity_id'] !== $tid) {
        throw new RuntimeException('thread link wrong: ' . json_encode($out));
    }
    $ev = $fx['audit']->entries[0];
    if ($ev->context['thread_id'] !== $tid || $ev->context['ticket_id'] !== 1) {
        throw new RuntimeException('thread audit wrong: ' . json_encode($ev->context));
    }
    puPass('uploadForThread respects participant gate + audits linked ticket');
})();

(function () {
    // Portal user is NOT a participant — reject
    $fx = puFixture(10);
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $fx['pdo']->exec(
        "INSERT INTO message_threads (subject, ticket_id, created_by, created_at)
         VALUES ('hi', 1, 500, '2026-01-01')"
    );
    $tid = (int) $fx['pdo']->lastInsertId();
    $fx['pdo']->exec("INSERT INTO message_participants (thread_id, participant_id) VALUES ({$tid}, 500)");

    $file = puFakeFile(puPng());
    puAssertThrows(
        fn() => $fx['service']->uploadForThread($fx['user'], $fx['account'], $tid, $file, false),
        UnauthorizedException::class, 'not a participant',
        'uploadForThread non-participant'
    );
    @unlink($file['tmp_name']);
    puPass('uploadForThread rejects non-participant');
})();

(function () {
    // Unlinked thread (no ticket + no WO) — reject
    $fx = puFixture(10);
    $fx['pdo']->exec(
        "INSERT INTO message_threads (subject, ticket_id, workorder_id, created_by, created_at)
         VALUES ('orphan', NULL, NULL, 999, '2026-01-01')"
    );
    $tid = (int) $fx['pdo']->lastInsertId();
    $fx['pdo']->exec("INSERT INTO message_participants (thread_id, participant_id) VALUES ({$tid}, 999)");
    $file = puFakeFile(puPng());
    puAssertThrows(
        fn() => $fx['service']->uploadForThread($fx['user'], $fx['account'], $tid, $file, false),
        UnauthorizedException::class, 'not linked',
        'uploadForThread unlinked'
    );
    @unlink($file['tmp_name']);
    puPass('uploadForThread rejects unlinked (staff-only) thread');
})();

// -- list --------------------------------------------------------------------

(function () {
    $fx = puFixture(10);
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $fx['tickets']->seed(['id' => 2, 'company_id' => 10]);
    $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, puFakeFile(puPng(), 'a.png'), false);
    $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, puFakeFile(puPng(), 'b.png'), false);
    $fx['service']->uploadForTicket($fx['user'], $fx['account'], 2, puFakeFile(puPng(), 'c.png'), false);

    $list = $fx['service']->listForTicket($fx['user'], $fx['account'], 1);
    if (count($list) !== 2) {
        throw new RuntimeException('expected 2 uploads for ticket 1, got ' . count($list));
    }
    foreach ($list as $u) {
        if ($u['entity_id'] !== 1 || $u['entity_type'] !== 'ticket') {
            throw new RuntimeException('wrong row in list: ' . json_encode($u));
        }
    }
    puPass('listForTicket returns uploads scoped to that ticket');
})();

// -- download + delete scope re-check ---------------------------------------

(function () {
    $fx = puFixture(10);
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $out = $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, puFakeFile(puPng(), 'x.png'), false);
    $upload = $fx['service']->getUploadForDownload($fx['user'], $fx['account'], $out['id']);
    if ($upload->id !== $out['id'] || $upload->mime_type !== 'image/png') {
        throw new RuntimeException('download resolved wrong upload: ' . $upload->id);
    }
    puPass('getUploadForDownload resolves in-scope upload');
})();

(function () {
    // Company reassignment: ticket now in a different company → download denied
    $fx = puFixture(10);
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $out = $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, puFakeFile(puPng()), false);
    // Flip ticket's company and the denormalized upload.company_id independently
    // to prove the entity re-walk catches the drift even when the denorm row
    // still matches.
    $fx['tickets']->store[1]->company_id = 11;
    puAssertThrows(
        fn() => $fx['service']->getUploadForDownload($fx['user'], $fx['account'], $out['id']),
        UnauthorizedException::class, 'different company',
        'download after ticket company reassignment'
    );
    puPass('getUploadForDownload re-runs entity scope on every download');
})();

(function () {
    // deleteUpload removes + audits + physical unlink triggered
    $fx = puFixture(10);
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $out = $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, puFakeFile(puPng()), false);
    $fx['audit']->entries = [];
    $fx['service']->deleteUpload($fx['user'], $fx['account'], $out['id']);

    $dCount = (int) $fx['pdo']->query('SELECT COUNT(*) FROM portal_uploads WHERE deleted_at IS NOT NULL')->fetchColumn();
    if ($dCount !== 1) {
        throw new RuntimeException('expected 1 soft-deleted row, got ' . $dCount);
    }
    $vCount = (int) $fx['pdo']->query('SELECT COUNT(*) FROM portal_uploads WHERE deleted_at IS NULL')->fetchColumn();
    if ($vCount !== 0) {
        throw new RuntimeException('soft-delete should hide from visible count, got ' . $vCount);
    }
    if (count($fx['storage']->unlinked) !== 1) {
        throw new RuntimeException('storage unlink should be called once on delete');
    }
    $ev = $fx['audit']->entries[0];
    if ($ev->event !== 'portal.upload.deleted' || $ev->context['entity_type'] !== 'ticket') {
        throw new RuntimeException('delete audit wrong: ' . json_encode($ev->context));
    }
    // List now excludes
    $list = $fx['service']->listForTicket($fx['user'], $fx['account'], 1);
    if (count($list) !== 0) {
        throw new RuntimeException('listForTicket should exclude soft-deleted rows, got ' . count($list));
    }
    puPass('deleteUpload soft-deletes, unlinks, audits, and hides from list');
})();

(function () {
    // Another portal account (different id) cannot delete someone else's upload
    $fx = puFixture(10);
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $out = $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, puFakeFile(puPng()), false);
    $otherAccount = clone $fx['account'];
    $otherAccount->id = 88; // different portal_account
    puAssertThrows(
        fn() => $fx['service']->deleteUpload($fx['user'], $otherAccount, $out['id']),
        UnauthorizedException::class, 'not created',
        'deleteUpload cross-account'
    );
    puPass('deleteUpload refuses uploads created by another portal_account');
})();

// -- revoked account block on all entrypoints --------------------------------

(function () {
    $fx = puFixture(10, true, '2026-04-01 00:00:00');
    $fx['tickets']->seed(['id' => 1, 'company_id' => 10]);
    $file = puFakeFile(puPng());
    puAssertThrows(
        fn() => $fx['service']->uploadForTicket($fx['user'], $fx['account'], 1, $file, false),
        UnauthorizedException::class, 'not usable',
        'uploadForTicket revoked'
    );
    puAssertThrows(
        fn() => $fx['service']->listForTicket($fx['user'], $fx['account'], 1),
        UnauthorizedException::class, 'not usable',
        'listForTicket revoked'
    );
    puAssertThrows(
        fn() => $fx['service']->getUploadForDownload($fx['user'], $fx['account'], 1),
        UnauthorizedException::class, 'not usable',
        'getUploadForDownload revoked'
    );
    puAssertThrows(
        fn() => $fx['service']->deleteUpload($fx['user'], $fx['account'], 1),
        UnauthorizedException::class, 'not usable',
        'deleteUpload revoked'
    );
    @unlink($file['tmp_name']);
    puPass('revoked portal_account is blocked from every upload action');
})();

// -- invalid ids -------------------------------------------------------------

(function () {
    $fx = puFixture(10);
    puAssertThrows(
        fn() => $fx['service']->uploadForTicket($fx['user'], $fx['account'], 0, puFakeFile(puPng()), false),
        InvalidArgumentException::class, 'ticket id',
        'uploadForTicket id=0'
    );
    puAssertThrows(
        fn() => $fx['service']->getUploadForDownload($fx['user'], $fx['account'], 0),
        InvalidArgumentException::class, 'upload id',
        'getUploadForDownload id=0'
    );
    puAssertThrows(
        fn() => $fx['service']->getUploadForDownload($fx['user'], $fx['account'], 99999),
        InvalidArgumentException::class, 'not found',
        'getUploadForDownload unknown id'
    );
    puPass('non-positive + unknown ids are rejected');
})();

echo "\nAll PortalUploadServiceTest cases passed.\n";
