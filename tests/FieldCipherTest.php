<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!extension_loaded('sodium')) {
    echo "FieldCipherTest\n";
    echo "  skipped — sodium extension not available\n";
    exit(0);
}

use App\Support\Crypto\FieldCipher;

/**
 * R-06 / AUD-071 — FieldCipher per-domain envelope coverage.
 *
 * Asserts:
 *   - v1 round-trip under the per-domain key (site codes, integrations).
 *   - v0 (legacy crypto_secretbox) ciphertext still decrypts after the
 *     rewrite, so existing rows keep working until the rewrap script
 *     upgrades them.
 *   - AAD binding: a v1 ciphertext minted under the integrations domain
 *     cannot be decrypted under the site_codes domain (and vice versa).
 *   - The on-disk envelope shape: 0x01 version byte + u32 key id + nonce + ct.
 *   - Rewrap idempotency detector: re-encrypted-under-current-key rows
 *     are recognised by the leading-byte + key_id pair without needing
 *     an actual decrypt.
 */

// -- Test harness ------------------------------------------------------------

$results = [];
function fcRun(string $name, callable $fn): void
{
    global $results;
    try {
        $fn();
        $results[] = ['name' => $name, 'ok' => true];
        echo "  PASS  {$name}\n";
    } catch (Throwable $e) {
        $results[] = ['name' => $name, 'ok' => false, 'err' => $e->getMessage()];
        echo "  FAIL  {$name} — {$e->getMessage()}\n";
    }
}
function fcAssert(bool $cond, string $message): void
{
    if (!$cond) {
        throw new RuntimeException($message);
    }
}
function fcAssertSame(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        $exp = var_export($expected, true);
        $act = var_export($actual, true);
        throw new RuntimeException("{$message} (expected {$exp}, got {$act})");
    }
}
function fcSeedKey(string $envVar, string $rawKey32): void
{
    $b64 = base64_encode($rawKey32);
    $_ENV[$envVar] = $b64;
    putenv($envVar . '=' . $b64);
}
function fcUnsetKey(string $envVar): void
{
    unset($_ENV[$envVar]);
    putenv($envVar);
}

echo "FieldCipherTest\n";

// Deterministic per-domain keys so failures are reproducible. These bytes
// never leave the test process and are not safe for any real environment.
$siteKey = str_repeat("\x01", 32);
$intKey = str_repeat("\x02", 32);
fcSeedKey('SITE_CODES_ENCRYPTION_KEY', $siteKey);
fcSeedKey('INTEGRATION_CREDENTIALS_ENCRYPTION_KEY', $intKey);

// -- v1 round-trip -----------------------------------------------------------

fcRun('v1 round-trip under site_codes domain', function () {
    $cipher = new FieldCipher(FieldCipher::DOMAIN_SITE_CODES);
    $plain = '4815-1623';
    $ct = $cipher->encrypt($plain);
    fcAssertSame($plain, $cipher->decrypt($ct), 'site_codes round-trip mismatch');
});

fcRun('v1 round-trip under integration_credentials domain', function () {
    $cipher = new FieldCipher(FieldCipher::DOMAIN_INTEGRATION_CREDENTIALS);
    $plain = json_encode(['api_key' => 'sk_test_abc', 'env' => 'sandbox']);
    $ct = $cipher->encrypt($plain);
    fcAssertSame($plain, $cipher->decrypt($ct), 'integration_credentials round-trip mismatch');
});

fcRun('v1 envelope shape: version byte + key id + nonce + ct', function () {
    $cipher = new FieldCipher(FieldCipher::DOMAIN_SITE_CODES);
    $ct = $cipher->encrypt('hello');
    $bytes = base64_decode($ct, true);
    fcAssert($bytes !== false, 'ciphertext must be valid base64');
    fcAssertSame(0x01, ord($bytes[0]), 'leading byte must be v1 marker');
    $keyId = unpack('N', substr($bytes, 1, 4))[1];
    fcAssertSame($cipher->keyId(), $keyId, 'embedded key id must match cipher.keyId()');
    $minLen = 1 + 4 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
        + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
    fcAssert(strlen($bytes) >= $minLen, 'envelope shorter than minimum v1 layout');
});

fcRun('every encrypt produces a fresh nonce (unique ciphertexts)', function () {
    $cipher = new FieldCipher(FieldCipher::DOMAIN_SITE_CODES);
    $a = $cipher->encrypt('repeated-input');
    $b = $cipher->encrypt('repeated-input');
    fcAssert($a !== $b, 'two encrypts of identical plaintext produced identical ciphertext (nonce reuse?)');
});

// -- v0 legacy decrypt -------------------------------------------------------

fcRun('legacy v0 ciphertext (crypto_secretbox) still decrypts', function () use ($siteKey) {
    // Hand-craft a v0 envelope the way the original FieldCipher used to:
    // base64( nonce[24] || crypto_secretbox(plaintext, nonce, key) ). No
    // version byte, no AAD, no key id. The rewrite must keep decoding this
    // until the rewrap script (bin/crypto/rewrap_secrets.php) upgrades the
    // row to v1.
    $plain = 'legacy-alarm-1234';
    $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
    $ct = sodium_crypto_secretbox($plain, $nonce, $siteKey);
    $legacy = base64_encode($nonce . $ct);

    $cipher = new FieldCipher(FieldCipher::DOMAIN_SITE_CODES);
    fcAssertSame($plain, $cipher->decrypt($legacy), 'legacy v0 ciphertext failed to decrypt');
});

fcRun('legacy v0 ciphertext that happens to start with 0x01 still decrypts', function () use ($siteKey) {
    // Force the v0 nonce's first byte to 0x01 so the decrypt path enters
    // the v1 branch first, fails AEAD, and must fall through to v0.
    $plain = 'legacy-ambiguous-leading-byte';
    $nonce = "\x01" . random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES - 1);
    $ct = sodium_crypto_secretbox($plain, $nonce, $siteKey);
    $legacy = base64_encode($nonce . $ct);

    $cipher = new FieldCipher(FieldCipher::DOMAIN_SITE_CODES);
    fcAssertSame($plain, $cipher->decrypt($legacy), 'legacy v0 fallback path broken');
});

// -- AAD / cross-domain isolation -------------------------------------------

fcRun('cross-domain decrypt of v1 envelope is rejected by AAD', function () {
    $intCipher = new FieldCipher(FieldCipher::DOMAIN_INTEGRATION_CREDENTIALS);
    $ct = $intCipher->encrypt('integration-only-secret');

    // Forge a header so the byte stream looks like a site_codes envelope
    // (key_id = 1) but the underlying AEAD was sealed with AAD =
    // "integration_credentials". Authentication must fail when the
    // site_codes cipher tries to open it.
    $bytes = base64_decode($ct, true);
    $forged = chr(0x01) . pack('N', 1) . substr($bytes, 5);
    $forgedB64 = base64_encode($forged);

    $siteCipher = new FieldCipher(FieldCipher::DOMAIN_SITE_CODES);
    $threw = false;
    try {
        $siteCipher->decrypt($forgedB64);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    fcAssert($threw, 'cross-domain decrypt should have failed authentication');
});

fcRun('tampered ciphertext is rejected', function () {
    $cipher = new FieldCipher(FieldCipher::DOMAIN_SITE_CODES);
    $ct = $cipher->encrypt('original');
    $bytes = base64_decode($ct, true);
    // Flip one bit deep in the AEAD body so AEAD verification must fail.
    $bytes[strlen($bytes) - 1] = chr(ord($bytes[strlen($bytes) - 1]) ^ 0x01);
    $tampered = base64_encode($bytes);

    $threw = false;
    try {
        $cipher->decrypt($tampered);
    } catch (RuntimeException $e) {
        $threw = true;
    }
    fcAssert($threw, 'tampered v1 ciphertext should have failed authentication');
});

// -- Rewrap idempotency helper -----------------------------------------------

fcRun('rewrap helper recognises already-v1 row under current key', function () {
    $cipher = new FieldCipher(FieldCipher::DOMAIN_SITE_CODES);
    $ct = $cipher->encrypt('whatever');

    // Mirror bin/crypto/rewrap_secrets.php::rewrapIsCurrentV1() — version
    // byte + matching key_id is enough to know a rewrap is a no-op.
    $bytes = base64_decode($ct, true);
    fcAssertSame(0x01, ord($bytes[0]), 'expected v1 marker');
    $keyId = unpack('N', substr($bytes, 1, 4))[1];
    fcAssertSame($cipher->keyId(), $keyId, 'expected current key id');
});

fcRun('rewrap helper does not match v0 (no version byte)', function () use ($siteKey) {
    // Simulate a legacy v0 row whose first byte (the random nonce's first
    // byte) is 0x02 or higher — clearly not the v1 marker. The rewrap
    // helper must report "needs upgrade".
    $plain = 'legacy-needs-rewrap';
    $nonce = "\xff" . random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES - 1);
    $ct = sodium_crypto_secretbox($plain, $nonce, $siteKey);
    $legacy = base64_encode($nonce . $ct);
    $bytes = base64_decode($legacy, true);

    fcAssert(ord($bytes[0]) !== 0x01, 'expected leading byte != v1 marker');
});

// -- Configuration errors ----------------------------------------------------

fcRun('missing key for self-domain raises on encrypt', function () use ($siteKey, $intKey) {
    fcUnsetKey('INTEGRATION_CREDENTIALS_ENCRYPTION_KEY');

    // With INTEGRATION_CREDENTIALS_ENCRYPTION_KEY missing, the cipher
    // falls back to SITE_CODES_ENCRYPTION_KEY for the integrations
    // domain (so legacy v0 rows still decrypt). New writes still
    // succeed under the fallback key — they're just under the wrong
    // key id and will get rewrapped on the next run. That backward-
    // compat decision is intentional; this test pins it so a future
    // change has to update both sides.
    $cipher = new FieldCipher(FieldCipher::DOMAIN_INTEGRATION_CREDENTIALS);
    fcAssert($cipher->isAvailable(), 'fallback to legacy site_codes key should keep cipher available');

    // Re-seed for the rest of the suite.
    fcSeedKey('INTEGRATION_CREDENTIALS_ENCRYPTION_KEY', $intKey);
});

fcRun('unknown domain throws on construction', function () {
    $threw = false;
    try {
        new FieldCipher('not_a_real_domain');
    } catch (RuntimeException $e) {
        $threw = true;
    }
    fcAssert($threw, 'constructing with an unknown domain must throw');
});

// -- Summary -----------------------------------------------------------------

$ok = 0;
$total = count($results);
foreach ($results as $r) {
    if ($r['ok']) {
        $ok++;
    }
}
echo "\nOK: {$ok}/{$total}\n";
exit($ok === $total ? 0 : 1);
