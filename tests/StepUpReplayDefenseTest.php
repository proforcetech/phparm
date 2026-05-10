<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "StepUpReplayDefenseTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\StepUpService;
use App\Support\Auth\TotpService;

class StepUpInMemoryConnection extends Connection
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

// SQLite analogue of the auth_step_up_verifications table after
// migrations 183 (totp_counter UNIQUE) and 184 (session_fingerprint).
$pdo->exec(
    'CREATE TABLE auth_step_up_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        verified_at TEXT NOT NULL,
        ip_address TEXT NULL,
        user_agent TEXT NULL,
        session_fingerprint TEXT NULL,
        totp_counter INTEGER NULL
    )'
);
$pdo->exec(
    'CREATE UNIQUE INDEX uniq_step_up_user_totp_counter
        ON auth_step_up_verifications (user_id, totp_counter)'
);
$pdo->exec(
    'CREATE INDEX idx_step_up_user_session
        ON auth_step_up_verifications (user_id, session_fingerprint, verified_at)'
);
// SQLite NOW() shim — matches MySQL UTC_TIMESTAMP semantics closely enough
// for freshness math; the column is a string either way.
$pdo->sqliteCreateFunction('NOW', static fn (): string => date('Y-m-d H:i:s'));

$connection = new StepUpInMemoryConnection($pdo);
$totp = new TotpService();
$stepUp = new StepUpService($connection, $totp);

$user = new User();
$user->id = 42;
$user->two_factor_secret = $totp->generateSecret();

$validCode = $totp->generateCode($user->two_factor_secret);
$sessionA = 'jwt:' . hash('sha256', 'session-a-token');
$sessionB = 'jwt:' . hash('sha256', 'session-b-token');

$results = [];

// First use of the code records a verification bound to session A.
$first = $stepUp->verify($user, $validCode, '127.0.0.1', 'phpunit', $sessionA);
$results[] = ['scenario' => 'first verify with fresh code succeeds', 'passed' => $first === true];

// Replay of the *same* code (same counter) within the validity window must
// be rejected — that is the AUD-068 fix. Pre-fix this would have inserted
// a duplicate row and granted a second step-up stamp from one captured code.
$replay = $stepUp->verify($user, $validCode, '127.0.0.1', 'phpunit', $sessionA);
$results[] = ['scenario' => 'replay of same code is rejected', 'passed' => $replay === false];

// Exactly one row should exist for that user — the replay attempt must NOT
// have produced a second stamp.
$rowCount = (int) $pdo->query('SELECT COUNT(*) FROM auth_step_up_verifications')->fetchColumn();
$results[] = ['scenario' => 'replay attempt does not insert a second row', 'passed' => $rowCount === 1];

// A bad code returns false and does not record anything.
$badResult = $stepUp->verify($user, '000000', '127.0.0.1', 'phpunit', $sessionA);
$results[] = ['scenario' => 'invalid code returns false', 'passed' => $badResult === false];
$rowCount = (int) $pdo->query('SELECT COUNT(*) FROM auth_step_up_verifications')->fetchColumn();
$results[] = ['scenario' => 'invalid code does not insert a row', 'passed' => $rowCount === 1];

// A different user replaying the same TOTP code is independent — the UNIQUE
// index is per-user, so user B getting the same digits at the same instant
// (different secret, hypothetically equal output) must still succeed.
$user2 = new User();
$user2->id = 99;
$user2->two_factor_secret = $totp->generateSecret();
$user2Code = $totp->generateCode($user2->two_factor_secret);
$independent = $stepUp->verify($user2, $user2Code, '127.0.0.1', 'phpunit', $sessionA);
$results[] = ['scenario' => 'second user verifies independently of first', 'passed' => $independent === true];

// AUD-069 session binding: the step-up performed under session A must
// satisfy isFresh for session A but NOT for session B (a different token,
// even one stolen from the same user, can't piggyback on the freshness).
$results[] = ['scenario' => 'isFresh true for the session that performed the verify', 'passed' => $stepUp->isFresh(42, $sessionA) === true];
$results[] = ['scenario' => 'isFresh false for a different session of the same user', 'passed' => $stepUp->isFresh(42, $sessionB) === false];
$results[] = ['scenario' => 'isFresh false when no session fingerprint supplied (legacy path matches only legacy rows)', 'passed' => $stepUp->isFresh(42, null) === false];
$results[] = ['scenario' => 'isFresh returns false for unknown user', 'passed' => $stepUp->isFresh(7777, $sessionA) === false];

// remainingSeconds is also session-bound.
$results[] = ['scenario' => 'remainingSeconds positive for owning session', 'passed' => $stepUp->remainingSeconds(42, $sessionA) > 0];
$results[] = ['scenario' => 'remainingSeconds zero for foreign session', 'passed' => $stepUp->remainingSeconds(42, $sessionB) === 0];

// assertFresh throws StepUpRequiredException when called from a different
// session — proves the gate route handlers rely on actually triggers.
$gateThrew = false;
try {
    $stepUp->assertFresh(42, $sessionB);
} catch (\App\Support\Auth\StepUpRequiredException) {
    $gateThrew = true;
}
$results[] = ['scenario' => 'assertFresh throws for foreign session', 'passed' => $gateThrew === true];

// Empty TOTP secret throws — caller must surface a setup-required message.
$noSecret = new User();
$noSecret->id = 5;
$noSecret->two_factor_secret = '';
$threw = false;
try {
    $stepUp->verify($noSecret, '123456', null, null, $sessionA);
} catch (InvalidArgumentException) {
    $threw = true;
}
$results[] = ['scenario' => 'verify throws when user has no TOTP secret', 'passed' => $threw === true];

$failures = array_filter($results, static fn (array $row): bool => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All step-up replay defense tests passed." . PHP_EOL;
