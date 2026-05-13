<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "ContractSignerServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\User;
use App\Services\Contracts\ContractPublicLinkRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSignatureRepository;
use App\Services\Contracts\ContractSignerRepository;
use App\Services\Contracts\ContractSignerService;
use App\Services\Contracts\ContractSigningService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\NotificationLogEntry;
use App\Support\Notifications\NotificationLogRepository;
use App\Support\Notifications\TemplateEngine;

/**
 * R-02c — first-class multi-party signing.
 *
 * Exercises the invitation roster end-to-end against in-memory SQLite
 * + the real repositories + a real ContractSigningService so we know
 * the bound-link wiring and signed-marker round-trip work as wired in
 * production. The Contract repository is faked (it has SQL that doesn't
 * port cleanly to SQLite), but the contract_public_links,
 * contract_signatures, and contract_signers tables run on the actual
 * repositories.
 */

class SignerConn extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

class SignerGate extends AccessGate
{
    public function __construct()
    {
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
    }
}

class SignerAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct()
    {
    }
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class SignerContracts extends ContractRepository
{
    /** @var array<int, Contract> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function findById(int $id): ?Contract
    {
        return $this->store[$id] ?? null;
    }
    public function create(array $data): Contract
    {
        $c = new Contract();
        $c->id = $this->nextId++;
        foreach ($data as $k => $v) {
            if (property_exists($c, $k) && $v !== null) {
                $c->{$k} = $v;
            }
        }
        if (empty($c->contract_number)) {
            $c->contract_number = 'C-TEST-' . $c->id;
        }
        if (empty($c->status)) {
            $c->status = 'draft';
        }
        $this->store[$c->id] = $c;
        return $c;
    }
    public function update(int $id, array $data): Contract
    {
        $c = $this->store[$id] ?? null;
        if ($c === null) {
            throw new RuntimeException("contract {$id} missing");
        }
        foreach ($data as $k => $v) {
            if (property_exists($c, $k)) {
                $c->{$k} = $v;
            }
        }
        return $c;
    }
}

// A no-op mailer so we can assert email_sent without setting up SMTP.
// Instances toggle to capture/fail-on-demand for individual scenarios.
class CapturingMailer extends NotificationDispatcher
{
    /** @var array<int, array<string, mixed>> */
    public array $sent = [];
    public bool $fail = false;
    public function __construct()
    {
    }
    public function sendMail(string $templateKey, string $to, array $data, ?string $subject = null): void
    {
        if ($this->fail) {
            throw new RuntimeException('mailer outage');
        }
        $this->sent[] = compact('templateKey', 'to', 'data', 'subject');
    }
}

// ---------- harness ----------

function buildEnv(string $contractStatus = 'draft'): array
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));
    $pdo->sqliteCreateFunction('CURRENT_TIMESTAMP', static fn(): string => date('Y-m-d H:i:s'));

    $pdo->exec('CREATE TABLE contract_public_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        short_code TEXT NOT NULL,
        expires_at TEXT NULL,
        last_accessed_at TEXT NULL,
        revoked_at TEXT NULL,
        consumed_at TEXT NULL,
        consumed_by_signature_id INTEGER NULL,
        signer_email TEXT NULL,
        signer_invitation_id INTEGER NULL,
        document_hash_at_issue TEXT NULL,
        document_snapshot_json TEXT NULL,
        created_by_user_id INTEGER NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE contract_signatures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id INTEGER NOT NULL,
        signer_name TEXT NOT NULL,
        signer_email TEXT NULL,
        signer_title TEXT NULL,
        signature_data TEXT NOT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        device_fingerprint TEXT NULL,
        document_hash TEXT NULL,
        document_hash_at_issue TEXT NULL,
        document_changed_accepted INTEGER NOT NULL DEFAULT 0,
        signature_hash TEXT NULL,
        legal_consent INTEGER NOT NULL DEFAULT 0,
        consent_text TEXT NULL,
        comment TEXT NULL,
        signed_at TEXT NULL,
        created_at TEXT NULL DEFAULT (CURRENT_TIMESTAMP)
    )');

    $pdo->exec('CREATE TABLE contract_signers (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        contract_id INTEGER NOT NULL,
        email TEXT NOT NULL,
        name TEXT NOT NULL,
        title TEXT NULL,
        display_order INTEGER NOT NULL DEFAULT 0,
        invited_at TEXT NOT NULL DEFAULT (CURRENT_TIMESTAMP),
        invited_by_user_id INTEGER NULL,
        revoked_at TEXT NULL,
        signed_signature_id INTEGER NULL,
        signed_at TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL DEFAULT (CURRENT_TIMESTAMP),
        updated_at TEXT NULL DEFAULT (CURRENT_TIMESTAMP)
    )');

    $conn = new SignerConn($pdo);
    $contracts = new SignerContracts();
    $gate = new SignerGate();
    $audit = new SignerAudit();
    $mailer = new CapturingMailer();

    $linkRepo = new ContractPublicLinkRepository($conn);
    $sigRepo = new ContractSignatureRepository($conn);
    $signerRepo = new ContractSignerRepository($conn);

    $signing = new ContractSigningService($contracts, $linkRepo, $sigRepo, $gate, $audit);
    $signers = new ContractSignerService($contracts, $signerRepo, $signing, $gate, $audit, $mailer);
    $signing->setSignerService($signers);

    $contract = $contracts->create([
        'company_id' => 10,
        'title' => 'Multi-Party Test',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'status' => $contractStatus,
    ]);
    $user = new User();
    $user->id = 1;

    return compact(
        'pdo',
        'conn',
        'contracts',
        'gate',
        'audit',
        'mailer',
        'linkRepo',
        'sigRepo',
        'signerRepo',
        'signing',
        'signers',
        'contract',
        'user'
    );
}

$results = [];
$failures = 0;
function r(array &$results, string $scenario, bool $passed, string $detail = ''): void
{
    $results[] = ['scenario' => $scenario, 'passed' => $passed, 'detail' => $detail];
}

// ---------------- scenarios ----------------

// 1. invite() persists a signer row, issues a bound public link, fires email,
//    and records the audit trail.
$e = buildEnv();
$result = $e['signers']->invite(
    $e['user'],
    $e['contract']->id,
    'https://shop.example',
    ['email' => 'Jane@Example.COM ', 'name' => 'Jane Doe', 'title' => 'CFO']
);
r($results, '01 invite returns signer + bound link', $result['signer'] instanceof ContractSigner);
r($results, '01a signer email normalized to lowercase', $result['signer']->email === 'jane@example.com');
r($results, '01b link bound to signer email', $result['link']->signer_email === 'jane@example.com');
r($results, '01c link bound to signer id', $result['link']->signer_invitation_id === $result['signer']->id);
r(
    $results,
    '01d short_url returned plaintext token once',
    is_string($result['token']) && strlen($result['token']) > 20
        && str_contains($result['short_url'], '/c/')
);
r($results, '01e email dispatched', $result['email_sent'] === true && count($e['mailer']->sent) === 1);
$invited = array_filter($e['audit']->entries, fn($a) => $a->event === 'contract.signer_invited');
r($results, '01f audit emitted contract.signer_invited', count($invited) === 1);

// 2. Dedupe: a second active invite with the same email is rejected.
try {
    $e['signers']->invite(
        $e['user'],
        $e['contract']->id,
        'https://shop.example',
        ['email' => 'jane@example.com', 'name' => 'Jane Again']
    );
    r($results, '02 duplicate active invite rejected', false, 'no exception');
} catch (Throwable $ex) {
    r($results, '02 duplicate active invite rejected', str_contains($ex->getMessage(), 'already exists'));
}

// 3. Different signer on the same contract → distinct display_order, own bound link.
$bob = $e['signers']->invite(
    $e['user'],
    $e['contract']->id,
    'https://shop.example',
    ['email' => 'bob@example.com', 'name' => 'Bob']
);
r(
    $results,
    '03 second signer gets next display_order',
    $bob['signer']->display_order === 1 && $result['signer']->display_order === 0
);

// 4. listForContract returns both, ordered by display_order.
$list = $e['signers']->listForContract($e['user'], $e['contract']->id);
r(
    $results,
    '04 listForContract returns both in order',
    count($list) === 2 && $list[0]->email === 'jane@example.com' && $list[1]->email === 'bob@example.com'
);

// 5. captureSignature against a bound link stamps the signer as signed.
$signature = $e['signing']->captureSignature(
    $result['token'],
    [
        'signer_name' => 'Jane Doe',
        'signer_email' => 'jane@example.com',
        'signature_data' => 'data:image/png;base64,AAA',
        'legal_consent' => true,
    ],
    '203.0.113.1',
    'TestUA'
);
$refreshed = $e['signerRepo']->findById($result['signer']->id);
r($results, '05 capture stamps signer.signed_signature_id', $refreshed->signed_signature_id === (int) $signature->id);
r($results, '05a capture stamps signer.signed_at', $refreshed->signed_at !== null);
$signed = array_filter($e['audit']->entries, fn($a) => $a->event === 'contract.signer_signed');
r($results, '05b contract.signer_signed audit emitted', count($signed) === 1);

// 6. Status derivation reflects lifecycle.
r($results, '06 jane status === signed', $refreshed->status() === 'signed');
$bobRefreshed = $e['signerRepo']->findById($bob['signer']->id);
r($results, '06a bob status === invited', $bobRefreshed->status() === 'invited');

// 7. Revoke an invited signer: signer goes revoked, bound link goes revoked too.
$e['signers']->revoke($e['user'], $e['contract']->id, $bob['signer']->id);
$bobAfter = $e['signerRepo']->findById($bob['signer']->id);
r($results, '07 revoke marks signer.revoked_at', $bobAfter->revoked_at !== null && $bobAfter->status() === 'revoked');
$bobLink = $e['linkRepo']->findById($bob['link']->id);
r($results, '07a revoke cascades to bound link', $bobLink->revoked_at !== null);

// 8. After revoke, re-inviting the same email is allowed (new active row).
$bob2 = $e['signers']->invite(
    $e['user'],
    $e['contract']->id,
    'https://shop.example',
    ['email' => 'bob@example.com', 'name' => 'Bob (re-invited)']
);
r($results, '08 revoked email can be re-invited', $bob2['signer']->id !== $bob['signer']->id);

// 9. Cannot revoke a signer who has already signed (permanence guard).
try {
    $e['signers']->revoke($e['user'], $e['contract']->id, $result['signer']->id);
    r($results, '09 cannot revoke a signed signer', false, 'no exception');
} catch (Throwable $ex) {
    r(
        $results,
        '09 cannot revoke a signed signer',
        str_contains($ex->getMessage(), 'already signed')
    );
}

// 10. Invitation against a cancelled contract is refused.
$cancelEnv = buildEnv('cancelled');
try {
    $cancelEnv['signers']->invite(
        $cancelEnv['user'],
        $cancelEnv['contract']->id,
        'https://shop.example',
        ['email' => 'x@example.com', 'name' => 'X']
    );
    r($results, '10 invite refused on cancelled contract', false, 'no exception');
} catch (Throwable $ex) {
    r($results, '10 invite refused on cancelled contract', str_contains($ex->getMessage(), 'cancelled'));
}

// 11. Mailer failure does not abort the invitation.
$mailFail = buildEnv();
$mailFail['mailer']->fail = true;
$inv = $mailFail['signers']->invite(
    $mailFail['user'],
    $mailFail['contract']->id,
    'https://shop.example',
    ['email' => 'm@example.com', 'name' => 'M']
);
r($results, '11 mailer failure does not block invite', $inv['signer']->id > 0);
r($results, '11a email_sent reports false on failure', $inv['email_sent'] === false);
r($results, '11b email_error captured', $inv['email_error'] !== null);

// 12. Invalid email shape is rejected before any DB writes.
$badEnv = buildEnv();
try {
    $badEnv['signers']->invite(
        $badEnv['user'],
        $badEnv['contract']->id,
        'https://shop.example',
        ['email' => 'not-an-email', 'name' => 'X']
    );
    r($results, '12 invalid email rejected', false, 'no exception');
} catch (Throwable $ex) {
    r($results, '12 invalid email rejected', str_contains(strtolower($ex->getMessage()), 'valid email'));
}
// No partial signer row written.
$stmt = $badEnv['pdo']->query('SELECT COUNT(*) AS n FROM contract_signers');
$row = $stmt->fetch();
r($results, '12a no orphan signer row on rejection', ((int) $row['n']) === 0);

// 13. send_email=false skips the mailer.
$skipEnv = buildEnv();
$skip = $skipEnv['signers']->invite(
    $skipEnv['user'],
    $skipEnv['contract']->id,
    'https://shop.example',
    ['email' => 'q@example.com', 'name' => 'Q', 'send_email' => false]
);
r($results, '13 send_email=false skips dispatch', count($skipEnv['mailer']->sent) === 0 && $skip['email_sent'] === false);

// 14. Mismatched signer on a bound link does NOT stamp the signer row
//     (the binding check throws before captureSignature reaches the marker).
$mmEnv = buildEnv();
$mmInvite = $mmEnv['signers']->invite(
    $mmEnv['user'],
    $mmEnv['contract']->id,
    'https://shop.example',
    ['email' => 'real@example.com', 'name' => 'Real']
);
try {
    $mmEnv['signing']->captureSignature(
        $mmInvite['token'],
        ['signer_name' => 'X', 'signer_email' => 'imposter@example.com', 'signature_data' => 'sig', 'legal_consent' => true]
    );
} catch (Throwable) {
    // expected
}
$stillInvited = $mmEnv['signerRepo']->findById($mmInvite['signer']->id);
r(
    $results,
    '14 mismatched capture does not mark signer signed',
    $stillInvited->signed_at === null && $stillInvited->status() === 'invited'
);

// ---------------- report ----------------

echo "ContractSignerServiceTest (R-02c)\n";
foreach ($results as $rr) {
    $tag = $rr['passed'] ? 'PASS' : 'FAIL';
    $detail = $rr['detail'] !== '' ? "  -- {$rr['detail']}" : '';
    echo "  {$tag} {$rr['scenario']}{$detail}\n";
    if (!$rr['passed']) {
        $failures++;
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
    exit(1);
}

echo "All R-02c contract-signer assertions passed.\n";
