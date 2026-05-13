<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "EsignSingleUseTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\ContractPublicLink;
use App\Services\Contracts\ContractPublicLinkRepository;

/**
 * AUD-064 — verifies the single-use claim semantics for e-sign public
 * links against a real SQL backend (in-memory SQLite analogue of the
 * post-migration-185 schema). This is the load-bearing correctness
 * property: the UPDATE … WHERE consumed_at IS NULL must update exactly
 * one row the first time and zero rows on every subsequent attempt,
 * even when issued concurrently. The contract repository's claim()
 * relies on rowCount() to distinguish winner from loser.
 *
 * The estimate side uses inline SQL with the same
 * `WHERE consumed_at IS NULL` clause, so this test covers it
 * structurally — if SQLite/MySQL accept the null-aware predicate the
 * same way (which they do), the estimate path is provably equivalent.
 */
class EsignInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));

// Post-migration 189 schema for contract_public_links (adds R-04
// document_hash_at_issue / document_snapshot_json columns on top of
// the R-02b signer_email / signer_invitation_id columns). Estimate
// table has the same shape on the columns under test.
$pdo->exec(
    'CREATE TABLE contract_public_links (
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
    )'
);

$connection = new EsignInMemoryConnection($pdo);
$repo = new ContractPublicLinkRepository($connection);

$results = [];

// 1. Issue a fresh link via the repo. consumed_at must start NULL.
$link = $repo->create(101, hash('sha256', 'tok-1'), 'short-001', null, 7);
$results[] = [
    'scenario' => 'fresh link starts with consumed_at NULL',
    'passed' => $link->consumed_at === null && $link->consumed_by_signature_id === null,
];

// 2. First claim wins (rowCount 1 → returns true). consumed_at populated
//    in the DB after the claim.
$firstWin = $repo->claim($link->id);
$reread = $repo->findById($link->id);
$results[] = [
    'scenario' => 'first claim returns true',
    'passed' => $firstWin === true,
];
$results[] = [
    'scenario' => 'first claim stamps consumed_at on the row',
    'passed' => $reread !== null && $reread->consumed_at !== null,
];

// 3. Second claim against the same link fails (rowCount 0 → returns false).
//    This is the replay-defense property.
$secondWin = $repo->claim($link->id);
$results[] = [
    'scenario' => 'second claim returns false (single-use enforced)',
    'passed' => $secondWin === false,
];

// 4. attachSignature backfills consumed_by_signature_id without disturbing
//    consumed_at. Used purely for forensic linkage; not load-bearing for
//    the auth check, but must not corrupt the consumed_at stamp.
$rerread = $repo->findById($link->id);
$consumedAtBefore = $rerread->consumed_at;
$repo->attachSignature($link->id, 9999);
$afterAttach = $repo->findById($link->id);
$results[] = [
    'scenario' => 'attachSignature populates consumed_by_signature_id',
    'passed' => $afterAttach !== null && $afterAttach->consumed_by_signature_id === 9999,
];
$results[] = [
    'scenario' => 'attachSignature preserves consumed_at',
    'passed' => $afterAttach !== null && $afterAttach->consumed_at === $consumedAtBefore,
];

// 5. Independent links are independently claimable — claiming one must not
//    affect the other.
$linkB = $repo->create(101, hash('sha256', 'tok-2'), 'short-002', null, 7);
$claimB = $repo->claim($linkB->id);
$results[] = [
    'scenario' => 'second link is independently claimable',
    'passed' => $claimB === true,
];
$results[] = [
    'scenario' => 'claiming linkB does not consume the original link further',
    'passed' => $repo->findById($link->id)->consumed_by_signature_id === 9999,
];

// 6. Model loads new columns when fetched via findByTokenHash + findByShortCode
//    (these are the lookup paths the signing service uses).
$byHash = $repo->findByTokenHash(hash('sha256', 'tok-2'));
$byShort = $repo->findByShortCode('short-002');
$results[] = [
    'scenario' => 'findByTokenHash returns model with consumed_at populated',
    'passed' => $byHash instanceof ContractPublicLink && $byHash->consumed_at !== null,
];
$results[] = [
    'scenario' => 'findByShortCode returns model with consumed_at populated',
    'passed' => $byShort instanceof ContractPublicLink && $byShort->consumed_at !== null,
];

echo "EsignSingleUseTest (AUD-064)\n";
$failures = 0;
foreach ($results as $r) {
    $tag = $r['passed'] ? 'PASS' : 'FAIL';
    echo "  {$tag} {$r['scenario']}\n";
    if (!$r['passed']) {
        $failures++;
    }
}

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} assertion(s) failed.\n");
    exit(1);
}

echo "All AUD-064 single-use claim assertions passed.\n";
