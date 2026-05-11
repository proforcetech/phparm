<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Contract;
use App\Models\ContractPublicLink;
use App\Models\ContractSignature;
use App\Models\User;
use App\Services\Contracts\ContractPublicLinkRepository;
use App\Services\Contracts\ContractRepository;
use App\Services\Contracts\ContractSignatureRepository;
use App\Services\Contracts\ContractSigningService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;

/**
 * Phase 4.2 of docs/expansion-plan.md: contract e-sign. Exercises the
 * signing service end-to-end against in-memory fakes — link issuance,
 * token resolution, expiry/revocation guards, signature capture, and
 * the draft/pending_signature → active transition on first signature.
 */

class SignFakeAccessGate extends AccessGate
{
    public array $denied = [];

    public function __construct()
    {
    }

    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (in_array($permission, $this->denied, true)) {
            throw new RuntimeException("denied: {$permission}");
        }
    }
}

class SignFakeAuditLogger extends AuditLogger
{
    public array $entries = [];

    public function __construct()
    {
    }

    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class SignFakeContracts extends ContractRepository
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
        $c->contract_number = $data['contract_number'] ?? ('C-TEST-' . $c->id);
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

class SignFakeLinks extends ContractPublicLinkRepository
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
        ?int $createdByUserId
    ): ContractPublicLink {
        $l = new ContractPublicLink();
        $l->id = $this->nextId++;
        $l->contract_id = $contractId;
        $l->token_hash = $tokenHash;
        $l->short_code = $shortCode;
        $l->expires_at = $expiresAt;
        $l->created_by_user_id = $createdByUserId;
        $this->store[$l->id] = $l;
        return $l;
    }

    public function findById(int $id): ?ContractPublicLink
    {
        return $this->store[$id] ?? null;
    }

    public function findByTokenHash(string $tokenHash): ?ContractPublicLink
    {
        foreach ($this->store as $link) {
            if ($link->token_hash === $tokenHash) {
                return $link;
            }
        }
        return null;
    }

    public function findByShortCode(string $shortCode): ?ContractPublicLink
    {
        foreach ($this->store as $link) {
            if ($link->short_code === $shortCode) {
                return $link;
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
        if (isset($this->store[$id])) {
            $this->store[$id]->last_accessed_at = date('Y-m-d H:i:s');
        }
    }

    public function revoke(int $id): void
    {
        if (isset($this->store[$id])) {
            $this->store[$id]->revoked_at = date('Y-m-d H:i:s');
        }
    }

    public function claim(int $id): bool
    {
        if (!isset($this->store[$id])) {
            return false;
        }
        if ($this->store[$id]->consumed_at !== null) {
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

class SignFakeSignatures extends ContractSignatureRepository
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
        $s->signer_title = $data['signer_title'] ?? null;
        $s->signature_data = (string) $data['signature_data'];
        $s->ip_address = $data['ip_address'] ?? null;
        $s->user_agent = $data['user_agent'] ?? null;
        $s->device_fingerprint = $data['device_fingerprint'] ?? null;
        $s->document_hash = $data['document_hash'] ?? null;
        $s->signature_hash = $data['signature_hash'] ?? null;
        $s->legal_consent = !empty($data['legal_consent']);
        $s->consent_text = $data['consent_text'] ?? null;
        $s->comment = $data['comment'] ?? null;
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

function signEnv(string $status = 'draft'): array
{
    $contracts = new SignFakeContracts();
    $links = new SignFakeLinks();
    $sigs = new SignFakeSignatures();
    $gate = new SignFakeAccessGate();
    $audit = new SignFakeAuditLogger();
    $service = new ContractSigningService($contracts, $links, $sigs, $gate, $audit);

    $contract = $contracts->create([
        'company_id' => 10,
        'title' => 'Test Contract',
        'start_date' => '2026-04-01',
        'end_date' => '2027-03-31',
        'status' => $status,
    ]);

    return compact('contracts', 'links', 'sigs', 'gate', 'audit', 'service', 'contract');
}

function user(int $id = 1): User
{
    $u = new User();
    $u->id = $id;
    return $u;
}

function expectOk(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $e) {
        echo "  FAIL {$label}: " . $e->getMessage() . "\n";
        exit(1);
    }
}

function expectFail(callable $fn, string $label, string $needle = ''): void
{
    try {
        $fn();
        echo "  FAIL {$label}: expected exception\n";
        exit(1);
    } catch (Throwable $e) {
        if ($needle !== '' && !str_contains(strtolower($e->getMessage()), strtolower($needle))) {
            echo "  FAIL {$label}: message '{$e->getMessage()}' lacks '{$needle}'\n";
            exit(1);
        }
        echo "  PASS {$label}\n";
    }
}

echo "Phase 4.2 — contract signing service\n";

// 1. issueLink emits token + short URL and audits.
$e1 = signEnv();
expectOk(function () use ($e1) {
    $result = $e1['service']->issueLink(user(), $e1['contract']->id, 'https://shop.example');
    if (empty($result['token']) || !str_contains($result['short_url'], '/c/')) {
        throw new RuntimeException('missing token or short_url');
    }
    if (!str_contains($result['secure_url'], '?token=')) {
        throw new RuntimeException('secure_url missing token');
    }
    if (empty($e1['audit']->entries) || $e1['audit']->entries[0]->event !== 'contract.link_issued') {
        throw new RuntimeException('link_issued audit not emitted');
    }
}, 'issueLink returns token + audits link_issued');

// 2. issueLink stores only hash, not plaintext.
expectOk(function () use ($e1) {
    $result = $e1['service']->issueLink(user(), $e1['contract']->id, 'https://shop.example');
    $linkRow = $e1['links']->findById($result['link']->id);
    if ($linkRow->token_hash === $result['token']) {
        throw new RuntimeException('token stored in plaintext');
    }
    if (hash('sha256', $result['token']) !== $linkRow->token_hash) {
        throw new RuntimeException('hash mismatch');
    }
}, 'only hash stored, not plaintext');

// 3. issueLink blocked on cancelled contract.
$e2 = signEnv('cancelled');
expectFail(function () use ($e2) {
    $e2['service']->issueLink(user(), $e2['contract']->id, 'https://shop.example');
}, 'issueLink blocked on cancelled', 'cancelled');

// 4. issueLink blocked without contracts.sign permission.
$e3 = signEnv();
$e3['gate']->denied = ['contracts.sign'];
expectFail(function () use ($e3) {
    $e3['service']->issueLink(user(), $e3['contract']->id, 'https://shop.example');
}, 'issueLink denied without permission', 'denied');

// 5. fetchPublicView resolves by token and touches last_accessed_at.
$e4 = signEnv();
expectOk(function () use ($e4) {
    $issued = $e4['service']->issueLink(user(), $e4['contract']->id, 'https://shop.example');
    $view = $e4['service']->fetchPublicView($issued['token']);
    if ($view['contract']->id !== $e4['contract']->id) {
        throw new RuntimeException('wrong contract resolved');
    }
    if ($view['link']->last_accessed_at === null) {
        throw new RuntimeException('last_accessed_at not touched');
    }
}, 'fetchPublicView resolves + touches last_accessed_at');

// 6. fetchPublicView by short_code.
$e5 = signEnv();
expectOk(function () use ($e5) {
    $issued = $e5['service']->issueLink(user(), $e5['contract']->id, 'https://shop.example');
    $view = $e5['service']->fetchPublicView(null, $issued['link']->short_code);
    if ($view['contract']->id !== $e5['contract']->id) {
        throw new RuntimeException('wrong contract');
    }
}, 'fetchPublicView resolves by short_code');

// 7. fetchPublicView rejects without token or short_code.
$e6 = signEnv();
expectFail(function () use ($e6) {
    $e6['service']->fetchPublicView(null, null);
}, 'fetchPublicView rejects missing inputs', 'required');

// 8. fetchPublicView rejects unknown token.
expectFail(function () use ($e6) {
    $e6['service']->fetchPublicView('not-a-real-token');
}, 'fetchPublicView rejects unknown token', 'invalid or unknown');

// 9. Revoked link cannot be resolved.
$e7 = signEnv();
$issued = $e7['service']->issueLink(user(), $e7['contract']->id, 'https://shop.example');
$e7['service']->revokeLink(user(), $e7['contract']->id, $issued['link']->id);
expectFail(function () use ($e7, $issued) {
    $e7['service']->fetchPublicView($issued['token']);
}, 'revoked link rejected', 'revoked');

// 10. Expired link rejected.
$e8 = signEnv();
$issued8 = $e8['service']->issueLink(
    user(),
    $e8['contract']->id,
    'https://shop.example',
    date('Y-m-d H:i:s', time() - 3600)
);
expectFail(function () use ($e8, $issued8) {
    $e8['service']->fetchPublicView($issued8['token']);
}, 'expired link rejected', 'expired');

// 11. Happy-path sign: draft → active, signed_at set, audit recorded.
$e9 = signEnv('draft');
$issued9 = $e9['service']->issueLink(user(), $e9['contract']->id, 'https://shop.example');
expectOk(function () use ($e9, $issued9) {
    $sig = $e9['service']->captureSignature(
        $issued9['token'],
        [
            'signer_name' => 'Jane Doe',
            'signer_email' => 'jane@co.example',
            'signature_data' => 'data:image/png;base64,AAAA',
            'legal_consent' => true,
        ],
        '203.0.113.5',
        'Mozilla/5.0 Test'
    );
    if ($sig->signer_name !== 'Jane Doe') {
        throw new RuntimeException('signer_name not stored');
    }
    if ($sig->document_hash === null || $sig->signature_hash === null) {
        throw new RuntimeException('integrity hashes not computed');
    }
    $contract = $e9['contracts']->findById($e9['contract']->id);
    if ($contract->status !== 'active') {
        throw new RuntimeException("expected active status, got {$contract->status}");
    }
    if ($contract->signed_at === null) {
        throw new RuntimeException('signed_at not stamped');
    }
    if ($contract->signer_ip !== '203.0.113.5') {
        throw new RuntimeException('signer_ip not stamped');
    }
    $events = array_map(fn($e) => $e->event, $e9['audit']->entries);
    if (!in_array('contract.signed', $events, true)) {
        throw new RuntimeException('contract.signed not audited');
    }
}, 'first signature activates + stamps signer');

// 12. Sign rejected on cancelled contract (issueLink was blocked, but test
//     the guard directly by flipping status after issuance).
$e10 = signEnv('draft');
$issued10 = $e10['service']->issueLink(user(), $e10['contract']->id, 'https://shop.example');
$e10['contract']->status = 'cancelled';
expectFail(function () use ($e10, $issued10) {
    $e10['service']->captureSignature(
        $issued10['token'],
        ['signer_name' => 'x', 'signature_data' => 'sig', 'legal_consent' => true]
    );
}, 'captureSignature blocked on cancelled', 'cannot be signed');

// 13. legal_consent required.
$e11 = signEnv('draft');
$issued11 = $e11['service']->issueLink(user(), $e11['contract']->id, 'https://shop.example');
expectFail(function () use ($e11, $issued11) {
    $e11['service']->captureSignature(
        $issued11['token'],
        ['signer_name' => 'X', 'signature_data' => 'sig', 'legal_consent' => false]
    );
}, 'signature without legal_consent rejected', 'legal_consent');

// 14. Missing signer_name rejected.
expectFail(function () use ($e11, $issued11) {
    $e11['service']->captureSignature(
        $issued11['token'],
        ['signer_name' => '', 'signature_data' => 'sig', 'legal_consent' => true]
    );
}, 'signature without signer_name rejected', 'signer_name');

// 15. Missing signature_data rejected.
expectFail(function () use ($e11, $issued11) {
    $e11['service']->captureSignature(
        $issued11['token'],
        ['signer_name' => 'X', 'signature_data' => '', 'legal_consent' => true]
    );
}, 'signature without signature_data rejected', 'signature_data');

// 16. AUD-064 — single-use link: a second captureSignature call on the same
//     link is rejected, the link's consumed_at is stamped, and the signature
//     row count stays at 1. Multi-party co-signing is now expressed by
//     issuing one link per signer (see docs/audit-v2-recommendations.md).
$e12 = signEnv('draft');
$issued12 = $e12['service']->issueLink(user(), $e12['contract']->id, 'https://shop.example');
$firstSig = $e12['service']->captureSignature(
    $issued12['token'],
    ['signer_name' => 'First', 'signature_data' => 'sig1', 'legal_consent' => true]
);
$linkAfter = $e12['links']->findById($issued12['link']->id);
if ($linkAfter->consumed_at === null) {
    echo "  FAIL link.consumed_at not stamped after first signature\n";
    exit(1);
}
if ($linkAfter->consumed_by_signature_id !== $firstSig->id) {
    echo "  FAIL link.consumed_by_signature_id not backfilled\n";
    exit(1);
}
expectFail(function () use ($e12, $issued12) {
    $e12['service']->captureSignature(
        $issued12['token'],
        ['signer_name' => 'Replay', 'signature_data' => 'sig2', 'legal_consent' => true]
    );
}, 'replay against consumed link rejected', 'already been used');
if ($e12['sigs']->countForContract($e12['contract']->id) !== 1) {
    echo "  FAIL replay produced an extra signature row\n";
    exit(1);
}
echo "  PASS link is single-use after first signature\n";

// 16b. AUD-064 — short_code path also rejects replay against a consumed link.
$e12b = signEnv('draft');
$issued12b = $e12b['service']->issueLink(user(), $e12b['contract']->id, 'https://shop.example');
$e12b['service']->captureSignature(
    null,
    ['signer_name' => 'First', 'signature_data' => 'sig1', 'legal_consent' => true],
    null,
    null,
    $issued12b['link']->short_code
);
expectFail(function () use ($e12b, $issued12b) {
    $e12b['service']->captureSignature(
        null,
        ['signer_name' => 'Replay', 'signature_data' => 'sig2', 'legal_consent' => true],
        null,
        null,
        $issued12b['link']->short_code
    );
}, 'short_code replay against consumed link rejected', 'already been used');

// 16c. AUD-064 — captureInternalSignature is unaffected by link consumption
//     (it doesn't touch a public link at all).
$e12c = signEnv('draft');
expectOk(function () use ($e12c) {
    $e12c['service']->captureInternalSignature(
        user(7),
        $e12c['contract']->id,
        ['signer_name' => 'Internal', 'signature_data' => 'sig', 'legal_consent' => true]
    );
    $e12c['service']->captureInternalSignature(
        user(7),
        $e12c['contract']->id,
        ['signer_name' => 'Internal Again', 'signature_data' => 'sig', 'legal_consent' => true]
    );
}, 'internal signing path is independent of link consumption');

// 17. pending_signature → active transition on first sign.
$e13 = signEnv('pending_signature');
$issued13 = $e13['service']->issueLink(user(), $e13['contract']->id, 'https://shop.example');
expectOk(function () use ($e13, $issued13) {
    $e13['service']->captureSignature(
        $issued13['token'],
        ['signer_name' => 'P', 'signature_data' => 'sig', 'legal_consent' => true]
    );
    $contract = $e13['contracts']->findById($e13['contract']->id);
    if ($contract->status !== 'active') {
        throw new RuntimeException("expected active, got {$contract->status}");
    }
}, 'pending_signature transitions to active on first sign');

// 18. Internal (authenticated) signature flow.
$e14 = signEnv('draft');
expectOk(function () use ($e14) {
    $sig = $e14['service']->captureInternalSignature(
        user(42),
        $e14['contract']->id,
        [
            'signer_name' => 'In-shop Signer',
            'signature_data' => 'sig',
            'legal_consent' => true,
        ],
        '198.51.100.1',
        'ShopTablet/1.0'
    );
    if ($sig->id <= 0) {
        throw new RuntimeException('internal signature not persisted');
    }
    $contract = $e14['contracts']->findById($e14['contract']->id);
    if ($contract->status !== 'active') {
        throw new RuntimeException("expected active, got {$contract->status}");
    }
}, 'captureInternalSignature activates contract');

// 19. revokeLink denies wrong contract.
$e15 = signEnv();
$issued15 = $e15['service']->issueLink(user(), $e15['contract']->id, 'https://shop.example');
expectFail(function () use ($e15, $issued15) {
    $e15['service']->revokeLink(user(), 9999, $issued15['link']->id);
}, 'revokeLink rejects wrong contract', 'not found');

// 20. listLinks requires contracts.view (denied when gate blocks it).
$e16 = signEnv();
$e16['service']->issueLink(user(), $e16['contract']->id, 'https://shop.example');
$e16['gate']->denied = ['contracts.view'];
expectFail(function () use ($e16) {
    $e16['service']->listLinks(user(), $e16['contract']->id);
}, 'listLinks denied without permission', 'denied');

echo "All Phase 4.2 contract-signing tests passed.\n";
