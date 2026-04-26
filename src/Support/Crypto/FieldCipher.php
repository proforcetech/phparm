<?php

namespace App\Support\Crypto;

use RuntimeException;

/**
 * Thin wrapper over libsodium's authenticated secretbox for encrypting
 * short, sensitive field values (alarm codes, gate codes, PINs) at rest.
 *
 * Key management:
 *   - The key is 32 raw bytes, base64-encoded, loaded from the env variable
 *     set on the constructor (default: SITE_CODES_ENCRYPTION_KEY).
 *   - If the key is missing, every encrypt/decrypt call throws. This is
 *     intentional — the caller MUST decide whether the data is safe to write
 *     in plaintext fallback (it isn't).
 *
 * Storage format: base64(nonce || ciphertext). Decryption verifies the
 * authentication tag; tampered values raise a RuntimeException.
 */
class FieldCipher
{
    private ?string $key;

    public function __construct(string $envVar = 'SITE_CODES_ENCRYPTION_KEY')
    {
        $raw = (string) ($_ENV[$envVar] ?? getenv($envVar) ?: '');
        if ($raw === '') {
            $this->key = null;
            return;
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                "{$envVar} must be a base64-encoded 32-byte key"
            );
        }
        $this->key = $decoded;
    }

    public function isAvailable(): bool
    {
        return $this->key !== null;
    }

    public function encrypt(string $plaintext): string
    {
        if ($this->key === null) {
            throw new RuntimeException('FieldCipher key is not configured');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return base64_encode($nonce . $cipher);
    }

    public function decrypt(string $encoded): string
    {
        if ($this->key === null) {
            throw new RuntimeException('FieldCipher key is not configured');
        }
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            throw new RuntimeException('Encrypted field payload is malformed');
        }
        $nonce = substr($bytes, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = substr($bytes, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);

        $plain = sodium_crypto_secretbox_open($cipher, $nonce, $this->key);
        if ($plain === false) {
            throw new RuntimeException('Encrypted field failed authentication');
        }

        return $plain;
    }
}
