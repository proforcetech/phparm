<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Support\Auth\EmailVerificationRepository;
use App\Support\Auth\PasswordResetRepository;

class AuthTokenMemoryConnection extends Connection
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

function setUpAuthTokenDatabase(): PDO
{
    if (!extension_loaded('pdo_sqlite')) {
        fwrite(STDOUT, "SKIPPED: pdo_sqlite extension is not available." . PHP_EOL);
        exit(0);
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    registerMysqlCompatFunctions($pdo);

    $pdo->exec('CREATE TABLE password_resets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        email TEXT NOT NULL,
        token TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NULL,
        used_at TEXT NULL
    )');

    $pdo->exec('CREATE TABLE email_verifications (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER NOT NULL,
        token TEXT NOT NULL,
        expires_at TEXT NOT NULL,
        created_at TEXT NULL,
        used_at TEXT NULL
    )');

    return $pdo;
}

$pdo = setUpAuthTokenDatabase();
$connection = new AuthTokenMemoryConnection($pdo);

$passwordResets = new PasswordResetRepository($connection, 60);
$verifications = new EmailVerificationRepository($connection, 48);

$resetToken = $passwordResets->createToken('test@example.com');
$storedResetToken = (string) $pdo->query('SELECT token FROM password_resets LIMIT 1')->fetchColumn();

$verificationToken = $verifications->createToken(42);
$storedVerificationToken = (string) $pdo->query('SELECT token FROM email_verifications LIMIT 1')->fetchColumn();

$legacyResetRaw = str_repeat('a', 64);
$legacyVerificationRaw = str_repeat('b', 64);
$pdo->exec("INSERT INTO password_resets (email, token, expires_at, created_at) VALUES ('legacy@example.com', '{$legacyResetRaw}', '2099-01-01 00:00:00', '2026-01-01 00:00:00')");
$pdo->exec("INSERT INTO email_verifications (user_id, token, expires_at, created_at) VALUES (99, '{$legacyVerificationRaw}', '2099-01-01 00:00:00', '2026-01-01 00:00:00')");

$results = [
    [
        'scenario' => 'password reset token is stored hashed at rest',
        'passed' => $storedResetToken !== $resetToken->token && $storedResetToken === hash('sha256', $resetToken->token),
    ],
    [
        'scenario' => 'email verification token is stored hashed at rest',
        'passed' => $storedVerificationToken !== $verificationToken->token && $storedVerificationToken === hash('sha256', $verificationToken->token),
    ],
    [
        'scenario' => 'new hashed password reset token remains redeemable',
        'passed' => $passwordResets->findValidToken($resetToken->token)?->email === 'test@example.com',
    ],
    [
        'scenario' => 'new hashed email verification token remains redeemable',
        'passed' => $verifications->findValidToken($verificationToken->token)?->user_id === 42,
    ],
    [
        'scenario' => 'legacy plaintext password reset token remains redeemable during transition',
        'passed' => $passwordResets->findValidToken($legacyResetRaw)?->email === 'legacy@example.com',
    ],
    [
        'scenario' => 'legacy plaintext email verification token remains redeemable during transition',
        'passed' => $verifications->findValidToken($legacyVerificationRaw)?->user_id === 99,
    ],
];

$passwordResets->markUsed($resetToken->token);
$verifications->markUsed($verificationToken->token);

$results[] = [
    'scenario' => 'mark used works for hashed password reset token',
    'passed' => (string) $pdo->query("SELECT used_at FROM password_resets WHERE email = 'test@example.com'")->fetchColumn() !== '',
];

$results[] = [
    'scenario' => 'mark used works for hashed email verification token',
    'passed' => (string) $pdo->query('SELECT used_at FROM email_verifications WHERE user_id = 42')->fetchColumn() !== '',
];

$failures = array_filter($results, static fn (array $row): bool => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All auth token repository security tests passed." . PHP_EOL;
