<?php

namespace App\Support\Crypto;

use RuntimeException;

/**
 * Authenticated encryption for short, sensitive field values (alarm codes,
 * gate codes, third-party API credentials) at rest.
 *
 * Two on-disk envelope formats are supported (R-06 / AUD-071):
 *
 *   v0 (legacy, decrypt-only): base64( nonce[24] || ct )
 *     - Primitive: libsodium `crypto_secretbox` (XSalsa20-Poly1305).
 *     - No AAD, no version byte, no key id. The original 2025 format.
 *     - New writes never produce v0; legacy rows decrypt through the
 *       v0 path until the rewrap script (`bin/crypto/rewrap_secrets.php`)
 *       upgrades them in place.
 *
 *   v1 (current): base64( 0x01 || key_id_u32 || nonce[24] || ct )
 *     - Primitive: libsodium `crypto_aead_xchacha20poly1305_ietf_*`.
 *     - AAD binds the domain string ("site_codes" / "integration_credentials")
 *       so a ciphertext encrypted under one domain cannot be substituted
 *       into another column without authentication failing.
 *     - The embedded `key_id_u32` resolves to an env-var name via
 *       {@see KEY_REGISTRY}, so future key rotation can ship a new
 *       key_id alongside the old one and let decrypt walk the registry
 *       without any column-shape changes.
 *
 * Domain → key_id mapping is intentionally tiny and code-resident so
 * it ships with the application; the env vars hold only the keys
 * themselves. A leak of one env var compromises only its domain.
 *
 * Decrypt is format-detecting: v1 envelopes are tried first when the
 * leading byte is the v1 version marker; on AEAD failure (or when the
 * leading byte cannot be a v1 marker) the v0 path is attempted as a
 * fallback. Both paths raise `RuntimeException` on tamper / wrong key.
 */
class FieldCipher
{
    public const DOMAIN_SITE_CODES = 'site_codes';
    public const DOMAIN_INTEGRATION_CREDENTIALS = 'integration_credentials';

    private const VERSION_V1 = 0x01;

    /**
     * Stable u32 ids embedded in the v1 envelope. Add new ids for key
     * rotations; never reuse or remove an id while ciphertext under it
     * still exists somewhere on disk.
     */
    private const KEY_ID_SITE_CODES = 1;
    private const KEY_ID_INTEGRATION_CREDENTIALS = 2;

    /**
     * @var array<int, string> key_id → env var holding the 32-byte key.
     */
    private const KEY_REGISTRY = [
        self::KEY_ID_SITE_CODES => 'SITE_CODES_ENCRYPTION_KEY',
        self::KEY_ID_INTEGRATION_CREDENTIALS => 'INTEGRATION_CREDENTIALS_ENCRYPTION_KEY',
    ];

    /**
     * @var array<string, int> domain string → key_id used for new writes.
     */
    private const DOMAIN_REGISTRY = [
        self::DOMAIN_SITE_CODES => self::KEY_ID_SITE_CODES,
        self::DOMAIN_INTEGRATION_CREDENTIALS => self::KEY_ID_INTEGRATION_CREDENTIALS,
    ];

    private string $domain;
    private int $keyId;
    private ?string $key;

    /**
     * @param string $domain                One of DOMAIN_* constants. Determines
     *                                      the env var used for new writes and
     *                                      the AAD bound to the v1 envelope.
     * @param string|null $envVarOverride   Test seam — pin a specific env var
     *                                      name regardless of domain. Production
     *                                      callers should never set this.
     */
    public function __construct(
        string $domain = self::DOMAIN_SITE_CODES,
        ?string $envVarOverride = null
    ) {
        if (!isset(self::DOMAIN_REGISTRY[$domain])) {
            throw new RuntimeException("Unknown FieldCipher domain: {$domain}");
        }
        $this->domain = $domain;
        $this->keyId = self::DOMAIN_REGISTRY[$domain];

        $envVar = $envVarOverride ?? self::KEY_REGISTRY[$this->keyId];
        $this->key = self::loadKey($envVar);

        // Backward compatibility — a deployment that has only the legacy
        // SITE_CODES_ENCRYPTION_KEY but expects integration credentials
        // to keep working should not silently fail. Fall back to the
        // legacy key for the integration_credentials domain on read so
        // existing v0 ciphertext still decrypts; new writes will fail
        // loudly until the operator provisions the per-domain key.
        if ($this->key === null && $domain === self::DOMAIN_INTEGRATION_CREDENTIALS) {
            $this->key = self::loadKey('SITE_CODES_ENCRYPTION_KEY');
        }
    }

    public function isAvailable(): bool
    {
        return $this->key !== null;
    }

    public function domain(): string
    {
        return $this->domain;
    }

    public function keyId(): int
    {
        return $this->keyId;
    }

    public function encrypt(string $plaintext): string
    {
        if ($this->key === null) {
            throw new RuntimeException('FieldCipher key is not configured');
        }
        $nonce = random_bytes(SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES);
        $ct = sodium_crypto_aead_xchacha20poly1305_ietf_encrypt(
            $plaintext,
            $this->domain,
            $nonce,
            $this->key
        );

        return base64_encode(
            chr(self::VERSION_V1) . pack('N', $this->keyId) . $nonce . $ct
        );
    }

    public function decrypt(string $encoded): string
    {
        $bytes = base64_decode($encoded, true);
        if ($bytes === false || $bytes === '') {
            throw new RuntimeException('Encrypted field payload is malformed');
        }

        $minV1 = 1 + 4 + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES
            + SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_ABYTES;
        if (ord($bytes[0]) === self::VERSION_V1 && strlen($bytes) >= $minV1) {
            try {
                return $this->decryptV1($bytes);
            } catch (RuntimeException $e) {
                // Fall through to v0 only if the leading byte happened
                // to be 0x01 by chance on a legacy ciphertext.
            }
        }

        return $this->decryptV0($bytes);
    }

    private function decryptV1(string $bytes): string
    {
        $keyId = unpack('N', substr($bytes, 1, 4))[1] ?? 0;
        if (!isset(self::KEY_REGISTRY[$keyId])) {
            throw new RuntimeException("Unknown FieldCipher key id: {$keyId}");
        }

        // For self-domain writes the cached key is reused; cross-domain
        // decrypt (e.g. rewrap script) loads the foreign key on demand.
        $key = $keyId === $this->keyId
            ? $this->key
            : self::loadKey(self::KEY_REGISTRY[$keyId]);
        if ($key === null) {
            throw new RuntimeException(
                'Required encryption key '
                . self::KEY_REGISTRY[$keyId]
                . ' is not configured'
            );
        }

        $nonceLen = SODIUM_CRYPTO_AEAD_XCHACHA20POLY1305_IETF_NPUBBYTES;
        $nonce = substr($bytes, 5, $nonceLen);
        $cipher = substr($bytes, 5 + $nonceLen);

        $plain = sodium_crypto_aead_xchacha20poly1305_ietf_decrypt(
            $cipher,
            $this->domain,
            $nonce,
            $key
        );
        if ($plain === false) {
            throw new RuntimeException('Encrypted field failed authentication');
        }

        return $plain;
    }

    private function decryptV0(string $bytes): string
    {
        if ($this->key === null) {
            throw new RuntimeException('FieldCipher key is not configured');
        }
        if (strlen($bytes) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
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

    private static function loadKey(string $envVar): ?string
    {
        $raw = (string) ($_ENV[$envVar] ?? getenv($envVar) ?: '');
        if ($raw === '') {
            return null;
        }
        $decoded = base64_decode($raw, true);
        if ($decoded === false || strlen($decoded) !== SODIUM_CRYPTO_SECRETBOX_KEYBYTES) {
            throw new RuntimeException(
                "{$envVar} must be a base64-encoded 32-byte key"
            );
        }
        return $decoded;
    }
}
