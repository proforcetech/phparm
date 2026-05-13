<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Models\Contract;
use App\Models\ContractPublicLink;
use App\Models\ContractSignature;
use App\Models\Estimate;
use App\Models\EstimatePublicLink;
use App\Models\User;
use App\Services\Contracts\ContractPublicLinkRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSignatureRepository;
use App\Services\Contracts\ContractSigningService;
use App\Services\Estimate\EstimateEditorService;
use App\Services\Estimate\EstimatePublicLinkService;
use App\Services\Estimate\EstimateRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;

/**
 * R-02b — exercises signer email binding on both contract and estimate
 * public-link signing flows. Verifies that:
 *   - A link issued without signer_email accepts any signer (legacy "open"
 *     link behaviour preserved for backwards compatibility).
 *   - A link issued with signer_email accepts the bound signer only.
 *   - Comparison is case-insensitive and whitespace-tolerant.
 *   - Missing signer_email on a bound link is rejected with a clear error.
 *   - Mismatch / missing emit a *.signer_mismatch audit event with the
 *     expected vs. attempted addresses for downstream alerting.
 */

// ---------- Contract-side fakes (mirrors ContractSigningServiceTest) ----------

class BindFakeGate extends AccessGate
{
    public function __construct()
    {
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
    }
}

class BindFakeAudit extends AuditLogger
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

class BindFakeContracts extends ContractRepository
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
        $c->contract_number = $data['contract_number'] ?? ('C-BIND-' . $c->id);
        foreach ($data as $k => $v) {
            if (property_exists($c, $k) && $v !== null) {
                $c->{$k} = $v;
            }
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
            throw new RuntimeException("contract {$id} not found");
        }
        foreach ($data as $k => $v) {
            if (property_exists($c, $k)) {
                $c->{$k} = $v;
            }
        }
        return $c;
    }
}

class BindFakeLinks extends ContractPublicLinkRepository
{
    /** @var array<int, ContractPublicLink> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function create(
        int $contractId,
        string $tokenHash,
        string $shortCode,
        ?string $expiresAt,
        ?int $createdByUserId,
        ?string $signerEmail = null,
        ?int $signerInvitationId = null,
        ?string $documentHashAtIssue = null,
        ?string $documentSnapshotJson = null
    ): ContractPublicLink {
        $l = new ContractPublicLink();
        $l->id = $this->nextId++;
        $l->contract_id = $contractId;
        $l->token_hash = $tokenHash;
        $l->short_code = $shortCode;
        $l->expires_at = $expiresAt;
        $l->created_by_user_id = $createdByUserId;
        $l->signer_email = $signerEmail;
        $l->signer_invitation_id = $signerInvitationId;
        $l->document_hash_at_issue = $documentHashAtIssue;
        $l->document_snapshot_json = $documentSnapshotJson;
        $this->store[$l->id] = $l;
        return $l;
    }
    public function findById(int $id): ?ContractPublicLink
    {
        return $this->store[$id] ?? null;
    }
    public function findByTokenHash(string $tokenHash): ?ContractPublicLink
    {
        foreach ($this->store as $l) {
            if ($l->token_hash === $tokenHash) {
                return $l;
            }
        }
        return null;
    }
    public function findByShortCode(string $shortCode): ?ContractPublicLink
    {
        foreach ($this->store as $l) {
            if ($l->short_code === $shortCode) {
                return $l;
            }
        }
        return null;
    }
    public function listForContract(int $contractId): array
    {
        return array_values(array_filter(
            $this->store,
            fn(ContractPublicLink $l) => $l->contract_id === $contractId
        ));
    }
    public function touchLastAccessed(int $id): void
    {
    }
    public function revoke(int $id): void
    {
        if (isset($this->store[$id])) {
            $this->store[$id]->revoked_at = date('Y-m-d H:i:s');
        }
    }
    public function claim(int $id): bool
    {
        if (!isset($this->store[$id]) || $this->store[$id]->consumed_at !== null) {
            return false;
        }
        $this->store[$id]->consumed_at = date('Y-m-d H:i:s');
        return true;
    }
    public function attachSignature(int $linkId, int $signatureId): void
    {
        if (isset($this->store[$linkId])) {
            $this->store[$linkId]->consumed_by_signature_id = $signatureId;
        }
    }
}

class BindFakeSignatures extends ContractSignatureRepository
{
    /** @var array<int, ContractSignature> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct()
    {
    }
    public function create(array $data): ContractSignature
    {
        $s = new ContractSignature();
        $s->id = $this->nextId++;
        $s->contract_id = (int) $data['contract_id'];
        $s->signer_name = (string) $data['signer_name'];
        $s->signer_email = $data['signer_email'] ?? null;
        $s->signature_data = (string) $data['signature_data'];
        $s->legal_consent = !empty($data['legal_consent']);
        $s->signed_at = $data['signed_at'] ?? date('Y-m-d H:i:s');
        $this->store[$s->id] = $s;
        return $s;
    }
    public function findById(int $id): ?ContractSignature
    {
        return $this->store[$id] ?? null;
    }
    public function listForContract(int $contractId): array
    {
        return array_values(array_filter(
            $this->store,
            fn(ContractSignature $s) => $s->contract_id === $contractId
        ));
    }
    public function countForContract(int $contractId): int
    {
        return count($this->listForContract($contractId));
    }
}

function bindContractEnv(): array
{
    $contracts = new BindFakeContracts();
    $links = new BindFakeLinks();
    $sigs = new BindFakeSignatures();
    $gate = new BindFakeGate();
    $audit = new BindFakeAudit();
    $service = new ContractSigningService($contracts, $links, $sigs, $gate, $audit);
    $contract = $contracts->create([
        'company_id' => 10,
        'title' => 'Bound Contract',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'status' => 'draft',
    ]);
    $user = new User();
    $user->id = 1;
    return compact('contracts', 'links', 'sigs', 'audit', 'service', 'contract', 'user');
}

$failures = 0;
$results = [];

function record(array &$results, string $scenario, bool $passed, string $detail = ''): void
{
    $results[] = ['scenario' => $scenario, 'passed' => $passed, 'detail' => $detail];
}

// ----------------------- CONTRACT BINDING SCENARIOS ----------------------------

// C1. Open (NULL signer_email) link accepts any signer — legacy behaviour.
$env = bindContractEnv();
$issued = $env['service']->issueLink($env['user'], $env['contract']->id, 'https://shop.example');
try {
    $sig = $env['service']->captureSignature(
        $issued['token'],
        [
            'signer_name' => 'Anyone',
            'signer_email' => 'anyone@example.com',
            'signature_data' => 'sig',
            'legal_consent' => true,
        ]
    );
    record($results, 'C1 open link accepts any signer', $sig instanceof ContractSignature);
} catch (Throwable $e) {
    record($results, 'C1 open link accepts any signer', false, $e->getMessage());
}

// C2. Bound link with matching email → accepted.
$env = bindContractEnv();
$issued = $env['service']->issueLink(
    $env['user'],
    $env['contract']->id,
    'https://shop.example',
    null,
    'jane@example.com'
);
try {
    $sig = $env['service']->captureSignature(
        $issued['token'],
        [
            'signer_name' => 'Jane',
            'signer_email' => 'jane@example.com',
            'signature_data' => 'sig',
            'legal_consent' => true,
        ]
    );
    record($results, 'C2 bound link accepts matching signer', $sig instanceof ContractSignature);
} catch (Throwable $e) {
    record($results, 'C2 bound link accepts matching signer', false, $e->getMessage());
}

// C3. Bound link is case-insensitive + trims whitespace.
$env = bindContractEnv();
$issued = $env['service']->issueLink(
    $env['user'],
    $env['contract']->id,
    'https://shop.example',
    null,
    '  Jane@Example.COM  '
);
$linkAfter = $env['links']->findById($issued['link']->id);
record(
    $results,
    'C3a bound link stores normalized signer_email',
    $linkAfter->signer_email === 'jane@example.com',
    "stored as: {$linkAfter->signer_email}"
);
try {
    $sig = $env['service']->captureSignature(
        $issued['token'],
        [
            'signer_name' => 'Jane',
            'signer_email' => '  JANE@example.com  ',
            'signature_data' => 'sig',
            'legal_consent' => true,
        ]
    );
    record($results, 'C3b case/whitespace-insensitive match accepted', $sig instanceof ContractSignature);
} catch (Throwable $e) {
    record($results, 'C3b case/whitespace-insensitive match accepted', false, $e->getMessage());
}

// C4. Bound link rejects different email.
$env = bindContractEnv();
$issued = $env['service']->issueLink(
    $env['user'],
    $env['contract']->id,
    'https://shop.example',
    null,
    'jane@example.com'
);
try {
    $env['service']->captureSignature(
        $issued['token'],
        [
            'signer_name' => 'Imposter',
            'signer_email' => 'bob@example.com',
            'signature_data' => 'sig',
            'legal_consent' => true,
        ]
    );
    record($results, 'C4 bound link rejects wrong signer', false, 'no exception thrown');
} catch (Throwable $e) {
    record(
        $results,
        'C4 bound link rejects wrong signer',
        str_contains(strtolower($e->getMessage()), 'bound to a different signer')
    );
    $events = array_map(fn($x) => $x->event, $env['audit']->entries);
    record(
        $results,
        'C4a mismatch emits contract.signer_mismatch',
        in_array('contract.signer_mismatch', $events, true)
    );
    $mismatch = null;
    foreach ($env['audit']->entries as $e2) {
        if ($e2->event === 'contract.signer_mismatch') {
            $mismatch = $e2;
            break;
        }
    }
    record(
        $results,
        'C4b mismatch audit includes expected/attempted',
        $mismatch !== null
            && ($mismatch->context['expected_signer_email'] ?? null) === 'jane@example.com'
            && ($mismatch->context['attempted_signer_email'] ?? null) === 'bob@example.com'
            && ($mismatch->context['reason'] ?? null) === 'email_mismatch'
    );
}

// C4c. After a rejected mismatch the link must remain re-usable so the
//      legitimate signer can still complete the flow. The claim only
//      happens after binding validation passes.
$env = bindContractEnv();
$issued = $env['service']->issueLink(
    $env['user'],
    $env['contract']->id,
    'https://shop.example',
    null,
    'jane@example.com'
);
try {
    $env['service']->captureSignature(
        $issued['token'],
        ['signer_name' => 'Imposter', 'signer_email' => 'bob@example.com', 'signature_data' => 'sig', 'legal_consent' => true]
    );
} catch (Throwable) {
    // expected
}
try {
    $sig = $env['service']->captureSignature(
        $issued['token'],
        ['signer_name' => 'Jane', 'signer_email' => 'jane@example.com', 'signature_data' => 'sig', 'legal_consent' => true]
    );
    record($results, 'C4c mismatch does not consume link (legit signer still wins)', $sig instanceof ContractSignature);
} catch (Throwable $e) {
    record($results, 'C4c mismatch does not consume link (legit signer still wins)', false, $e->getMessage());
}

// C5. Bound link rejects missing signer_email.
$env = bindContractEnv();
$issued = $env['service']->issueLink(
    $env['user'],
    $env['contract']->id,
    'https://shop.example',
    null,
    'jane@example.com'
);
try {
    $env['service']->captureSignature(
        $issued['token'],
        [
            'signer_name' => 'Jane',
            'signature_data' => 'sig',
            'legal_consent' => true,
        ]
    );
    record($results, 'C5 bound link rejects missing signer_email', false, 'no exception thrown');
} catch (Throwable $e) {
    record(
        $results,
        'C5 bound link rejects missing signer_email',
        str_contains(strtolower($e->getMessage()), 'signer_email is required')
    );
    $missing = null;
    foreach ($env['audit']->entries as $e2) {
        if ($e2->event === 'contract.signer_mismatch'
            && ($e2->context['reason'] ?? null) === 'missing_signer_email'
        ) {
            $missing = $e2;
            break;
        }
    }
    record($results, 'C5a missing-email mismatch audited with reason', $missing !== null);
}

// ---------------------- ESTIMATE BINDING SCENARIOS -----------------------------
// Real sqlite + the actual service to exercise the inline SQL path that
// stores signer_email at issuance time and the captureSignature binding
// check. We only stand up the tables touched by issueLink+captureSignature.

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "SignerEmailBindingTest — skipping estimate side (pdo_sqlite unavailable)\n";
} else {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->sqliteCreateFunction('NOW', static fn(): string => date('Y-m-d H:i:s'));

    $pdo->exec('CREATE TABLE estimate_public_links (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estimate_id INTEGER NOT NULL,
        token_hash TEXT NOT NULL,
        short_code TEXT NOT NULL,
        expires_at TEXT NULL,
        last_accessed_at TEXT NULL,
        consumed_at TEXT NULL,
        consumed_by_signature_id INTEGER NULL,
        signer_email TEXT NULL,
        signer_invitation_id INTEGER NULL,
        document_hash_at_issue TEXT NULL,
        document_snapshot_json TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    // Minimal estimate_signatures schema covering the columns INSERTed by
    // EstimatePublicLinkService::captureSignature.
    $pdo->exec('CREATE TABLE estimate_signatures (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        estimate_id INTEGER NOT NULL,
        signer_name TEXT NOT NULL,
        signer_email TEXT NULL,
        signature_data TEXT NOT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        location_lat REAL NULL,
        location_lng REAL NULL,
        location_accuracy_m REAL NULL,
        location_captured_at TEXT NULL,
        browser_name TEXT NULL,
        browser_version TEXT NULL,
        os_name TEXT NULL,
        os_version TEXT NULL,
        device_fingerprint TEXT NULL,
        document_hash TEXT NULL,
        document_hash_at_issue TEXT NULL,
        document_changed_accepted INTEGER NOT NULL DEFAULT 0,
        legal_consent INTEGER NOT NULL DEFAULT 0,
        consent_text TEXT NULL,
        comment TEXT NULL,
        signed_at TEXT NULL,
        created_at TEXT NULL
    )');

    $bindConn = new class ($pdo) extends Connection {
        public function __construct(private PDO $inner)
        {
        }
        public function pdo(): PDO
        {
            return $this->inner;
        }
    };

    // Fake repo returns a fixed estimate row without hitting `estimates` table.
    $bindEstimates = new class ($bindConn) extends EstimateRepository {
        private Estimate $estimate;
        public function __construct(Connection $c)
        {
            parent::__construct($c);
            $e = new Estimate();
            $e->id = 1;
            $e->require_signature = false;
            $this->estimate = $e;
        }
        public function find(int $id): ?Estimate
        {
            return $id === 1 ? $this->estimate : null;
        }
    };

    // Editor is never touched by issueLink/captureSignature; pass through.
    $bindEditor = new class ($bindConn) extends EstimateEditorService {
        public function __construct(Connection $c)
        {
        }
    };

    $estAudit = new BindFakeAudit();
    $estService = new EstimatePublicLinkService($bindConn, $bindEstimates, $bindEditor, $estAudit, null);

    // E1. Open link accepts any signer.
    $open = $estService->issueLink(1, 'https://shop.example');
    try {
        $estService->captureSignature(
            $open['token'],
            'Anyone',
            'anyone@example.com',
            'sig',
            null,
            '127.0.0.1',
            'UA',
            null,
            true,
            'Consent v1'
        );
        record($results, 'E1 estimate open link accepts any signer', true);
    } catch (Throwable $e) {
        record($results, 'E1 estimate open link accepts any signer', false, $e->getMessage());
    }

    // E2. Bound link accepts matching email (case-insensitive, trimmed).
    $bound = $estService->issueLink(1, 'https://shop.example', null, null, '  Jane@Example.COM  ');
    // signer_email should have been normalized at issuance.
    $stmt = $pdo->prepare('SELECT signer_email FROM estimate_public_links WHERE token_hash = :h');
    $stmt->execute(['h' => hash('sha256', $bound['token'])]);
    $row = $stmt->fetch();
    record(
        $results,
        'E2a estimate stores normalized signer_email at issue',
        ($row['signer_email'] ?? null) === 'jane@example.com'
    );
    try {
        $sig = $estService->captureSignature(
            $bound['token'],
            'Jane',
            'JANE@example.com',
            'sig',
            null,
            '127.0.0.1',
            'UA',
            null,
            true,
            'Consent v1'
        );
        record($results, 'E2b case-insensitive match accepted', $sig->id > 0);
    } catch (Throwable $e) {
        record($results, 'E2b case-insensitive match accepted', false, $e->getMessage());
    }

    // E3. Bound link rejects different email + audits estimate.signer_mismatch.
    $bound2 = $estService->issueLink(1, 'https://shop.example', null, null, 'jane@example.com');
    $estAudit->entries = [];
    try {
        $estService->captureSignature(
            $bound2['token'],
            'Imposter',
            'bob@example.com',
            'sig',
            null,
            '127.0.0.1',
            'UA',
            null,
            true,
            'Consent v1'
        );
        record($results, 'E3 estimate rejects wrong signer', false, 'no exception');
    } catch (Throwable $e) {
        record(
            $results,
            'E3 estimate rejects wrong signer',
            str_contains(strtolower($e->getMessage()), 'bound to a different signer')
        );
        $events = array_map(fn($x) => $x->event, $estAudit->entries);
        record(
            $results,
            'E3a estimate.signer_mismatch emitted',
            in_array('estimate.signer_mismatch', $events, true)
        );
        $mm = null;
        foreach ($estAudit->entries as $en) {
            if ($en->event === 'estimate.signer_mismatch') {
                $mm = $en;
                break;
            }
        }
        record(
            $results,
            'E3b estimate mismatch context payload',
            $mm !== null
                && ($mm->context['expected_signer_email'] ?? null) === 'jane@example.com'
                && ($mm->context['attempted_signer_email'] ?? null) === 'bob@example.com'
                && ($mm->context['reason'] ?? null) === 'email_mismatch'
        );
    }

    // E4. Bound link rejects missing email.
    $bound3 = $estService->issueLink(1, 'https://shop.example', null, null, 'jane@example.com');
    $estAudit->entries = [];
    try {
        $estService->captureSignature(
            $bound3['token'],
            'Jane',
            null,
            'sig',
            null,
            '127.0.0.1',
            'UA',
            null,
            true,
            'Consent v1'
        );
        record($results, 'E4 estimate rejects missing signer_email', false, 'no exception');
    } catch (Throwable $e) {
        record(
            $results,
            'E4 estimate rejects missing signer_email',
            str_contains(strtolower($e->getMessage()), 'signer_email is required')
        );
        $missingEvt = null;
        foreach ($estAudit->entries as $en) {
            if ($en->event === 'estimate.signer_mismatch'
                && ($en->context['reason'] ?? null) === 'missing_signer_email'
            ) {
                $missingEvt = $en;
                break;
            }
        }
        record($results, 'E4a estimate missing-email audit reason', $missingEvt !== null);
    }
}

// ---------------------------------- REPORT ------------------------------------

echo "SignerEmailBindingTest (R-02b)\n";
foreach ($results as $r) {
    $tag = $r['passed'] ? 'PASS' : 'FAIL';
    $detail = $r['detail'] !== '' ? "  -- {$r['detail']}" : '';
    echo "  {$tag} {$r['scenario']}{$detail}\n";
    if (!$r['passed']) {
        $failures++;
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
    exit(1);
}

echo "All R-02b signer-email binding assertions passed.\n";
