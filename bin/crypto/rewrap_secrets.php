<?php

/**
 * R-06 / AUD-071 — One-shot rewrap script.
 *
 * Walks every column known to hold FieldCipher ciphertext, decrypts each
 * row using the legacy/v0 envelope path (or v1 already-rewrapped if a
 * prior partial run touched it), and re-encrypts under the per-domain
 * v1 envelope. Idempotent: rows that are already on v1 with the
 * current key id are detected and skipped, so the script is safe to
 * re-run after a partial failure.
 *
 * Domains and target columns:
 *   - site_codes:               sites.alarm_code_encrypted, sites.gate_code_encrypted
 *   - integration_credentials:  third_party_integrations.credentials
 *
 * Usage:
 *   php bin/crypto/rewrap_secrets.php             # dry-run report
 *   php bin/crypto/rewrap_secrets.php --apply     # write the upgrades
 *
 * Pre-flight requirements:
 *   - SITE_CODES_ENCRYPTION_KEY               (legacy + new for site codes)
 *   - INTEGRATION_CREDENTIALS_ENCRYPTION_KEY  (new for integrations)
 *
 * If only the legacy SITE_CODES_ENCRYPTION_KEY is set the script will
 * still rewrap site_codes rows, but will refuse to rewrap integration
 * credentials so the operator notices the missing per-domain key.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Support\Crypto\FieldCipher;
use App\Support\Env;

$apply = in_array('--apply', $argv, true);

$env = new Env(__DIR__ . '/../../.env');
$dbConfig = [
    'driver' => $env->get('DB_DRIVER', 'mysql'),
    'host' => $env->get('DB_HOST', '127.0.0.1'),
    'port' => (int) $env->get('DB_PORT', 3306),
    'database' => $env->get('DB_DATABASE', 'phparm'),
    'username' => $env->get('DB_USERNAME', 'root'),
    'password' => $env->get('DB_PASSWORD', ''),
    'charset' => $env->get('DB_CHARSET', 'utf8mb4'),
];
$connection = new Connection($dbConfig);
$pdo = $connection->pdo();

$mode = $apply ? 'APPLY' : 'DRY-RUN';
echo "[" . date('Y-m-d H:i:s') . "] FieldCipher rewrap — mode={$mode}\n";

$siteCodes = new FieldCipher(FieldCipher::DOMAIN_SITE_CODES);
$integrationCreds = new FieldCipher(FieldCipher::DOMAIN_INTEGRATION_CREDENTIALS);

if (!$siteCodes->isAvailable()) {
    fwrite(STDERR, "ERROR: SITE_CODES_ENCRYPTION_KEY not configured.\n");
    exit(1);
}
$canRewrapIntegrations = $integrationCreds->isAvailable()
    && getenv('INTEGRATION_CREDENTIALS_ENCRYPTION_KEY') !== false;

if (!$canRewrapIntegrations) {
    fwrite(STDERR,
        "WARNING: INTEGRATION_CREDENTIALS_ENCRYPTION_KEY not set — "
        . "integration_credentials rows will be skipped.\n"
    );
}

$tasks = [
    [
        'label' => 'sites.alarm_code_encrypted',
        'cipher' => $siteCodes,
        'select' => 'SELECT id, alarm_code_encrypted FROM sites WHERE alarm_code_encrypted IS NOT NULL AND alarm_code_encrypted <> ""',
        'update' => 'UPDATE sites SET alarm_code_encrypted = :ct WHERE id = :id',
        'col' => 'alarm_code_encrypted',
        'enabled' => true,
    ],
    [
        'label' => 'sites.gate_code_encrypted',
        'cipher' => $siteCodes,
        'select' => 'SELECT id, gate_code_encrypted FROM sites WHERE gate_code_encrypted IS NOT NULL AND gate_code_encrypted <> ""',
        'update' => 'UPDATE sites SET gate_code_encrypted = :ct WHERE id = :id',
        'col' => 'gate_code_encrypted',
        'enabled' => true,
    ],
    [
        'label' => 'third_party_integrations.credentials',
        'cipher' => $integrationCreds,
        'select' => 'SELECT id, credentials FROM third_party_integrations WHERE credentials IS NOT NULL AND credentials <> ""',
        'update' => 'UPDATE third_party_integrations SET credentials = :ct WHERE id = :id',
        'col' => 'credentials',
        'enabled' => $canRewrapIntegrations,
    ],
];

$totals = ['scanned' => 0, 'rewrapped' => 0, 'already_v1' => 0, 'failed' => 0, 'skipped' => 0];

foreach ($tasks as $task) {
    if (!$task['enabled']) {
        echo "  - {$task['label']}: SKIPPED (per-domain key missing)\n";
        continue;
    }
    $stmt = $pdo->query($task['select']);
    if ($stmt === false) {
        // Table may not exist on this install (e.g. integrations not migrated).
        echo "  - {$task['label']}: SKIPPED (table or column missing)\n";
        continue;
    }
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $localScanned = 0;
    $localRewrapped = 0;
    $localAlreadyV1 = 0;
    $localFailed = 0;

    foreach ($rows as $row) {
        $localScanned++;
        $totals['scanned']++;
        $current = (string) $row[$task['col']];
        if (rewrapIsCurrentV1($current, $task['cipher'])) {
            $localAlreadyV1++;
            $totals['already_v1']++;
            continue;
        }
        try {
            $plain = $task['cipher']->decrypt($current);
        } catch (Throwable $e) {
            $localFailed++;
            $totals['failed']++;
            fwrite(STDERR, sprintf(
                "  ! %s id=%s: decrypt failed — %s\n",
                $task['label'],
                $row['id'],
                $e->getMessage()
            ));
            continue;
        }
        $rewrapped = $task['cipher']->encrypt($plain);
        if ($apply) {
            $upd = $pdo->prepare($task['update']);
            $upd->execute(['ct' => $rewrapped, 'id' => $row['id']]);
        }
        $localRewrapped++;
        $totals['rewrapped']++;
    }

    printf(
        "  - %s: scanned=%d rewrapped=%d already_v1=%d failed=%d\n",
        $task['label'],
        $localScanned,
        $localRewrapped,
        $localAlreadyV1,
        $localFailed
    );
}

echo "\n[Totals] scanned={$totals['scanned']} rewrapped={$totals['rewrapped']} "
    . "already_v1={$totals['already_v1']} failed={$totals['failed']}\n";

if (!$apply && $totals['rewrapped'] > 0) {
    echo "\nDry-run only — re-run with --apply to write the upgrades.\n";
}

exit($totals['failed'] > 0 ? 2 : 0);

/**
 * Detect a v1 envelope already encrypted with the cipher's current
 * key_id. We don't try to verify the AEAD tag here (that would require
 * a needless decrypt) — version byte + key_id match is enough to know
 * a re-rewrap would be a no-op.
 */
function rewrapIsCurrentV1(string $encoded, FieldCipher $cipher): bool
{
    $bytes = base64_decode($encoded, true);
    if ($bytes === false || strlen($bytes) < 5) {
        return false;
    }
    if (ord($bytes[0]) !== 0x01) {
        return false;
    }
    $keyId = unpack('N', substr($bytes, 1, 4))[1] ?? 0;
    return $keyId === $cipher->keyId();
}
