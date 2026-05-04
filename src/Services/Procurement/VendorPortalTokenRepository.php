<?php

namespace App\Services\Procurement;

use App\Database\Connection;
use App\Models\VendorPortalToken;
use PDO;
use RuntimeException;

/**
 * Phase 18 / C1 — persistence for vendor self-service portal tokens.
 *
 * Tokens are hashed at rest (sha256 of the random secret); the plaintext is
 * NEVER stored. The plaintext is only available in-process at issue time so
 * the controller can return it once to the staff user issuing the credential.
 */
class VendorPortalTokenRepository
{
    private const COLUMNS = 'id, vendor_id, token_hash, label, expires_at,
        last_used_at, last_used_ip, revoked_at, revoked_reason,
        created_by_user_id, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    public function findByPlaintext(string $plaintext): ?VendorPortalToken
    {
        if ($plaintext === '') {
            return null;
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM vendor_portal_tokens
             WHERE token_hash = :h LIMIT 1'
        );
        $stmt->execute(['h' => self::hash($plaintext)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new VendorPortalToken($row) : null;
    }

    public function findById(int $id): ?VendorPortalToken
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM vendor_portal_tokens
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new VendorPortalToken($row) : null;
    }

    /**
     * @return array<int, VendorPortalToken>
     */
    public function listForVendor(int $vendorId, bool $includeRevoked = false): array
    {
        $where = ['vendor_id = :v'];
        if (!$includeRevoked) {
            $where[] = 'revoked_at IS NULL';
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM vendor_portal_tokens
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY created_at DESC, id DESC LIMIT 100';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['v' => $vendorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new VendorPortalToken($r), $rows);
    }

    /**
     * Insert a token row. Caller hands us the *plaintext* — we hash it before
     * storage and return the persisted record. The plaintext is NOT echoed
     * back; the controller already has it.
     */
    public function create(
        int $vendorId,
        string $plaintext,
        ?string $label,
        ?string $expiresAt,
        ?int $createdByUserId
    ): VendorPortalToken {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO vendor_portal_tokens
             (vendor_id, token_hash, label, expires_at, created_by_user_id)
             VALUES (:v, :h, :l, :e, :u)'
        );
        $stmt->execute([
            'v' => $vendorId,
            'h' => self::hash($plaintext),
            'l' => $label === null || $label === '' ? null : $label,
            'e' => $expiresAt === null || $expiresAt === '' ? null : $expiresAt,
            'u' => $createdByUserId,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('vendor_portal_tokens insert did not return a row');
        }
        return $found;
    }

    public function revoke(int $id, ?string $reason = null): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE vendor_portal_tokens
             SET revoked_at = NOW(), revoked_reason = :r
             WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'id' => $id,
            'r' => $reason === null || $reason === '' ? null : substr($reason, 0, 255),
        ]);
    }

    public function recordUse(int $id, ?string $ip): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE vendor_portal_tokens
             SET last_used_at = NOW(), last_used_ip = :ip
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $id,
            'ip' => $ip === null || $ip === '' ? null : substr($ip, 0, 45),
        ]);
    }
}
