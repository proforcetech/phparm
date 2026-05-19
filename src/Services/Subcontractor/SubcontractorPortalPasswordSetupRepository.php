<?php

namespace App\Services\Subcontractor;

use App\Database\Connection;
use PDO;
use RuntimeException;

/**
 * Stores one-time subcontractor portal password setup tokens. Plaintext tokens
 * never touch the database; only a sha256 hash is persisted.
 */
class SubcontractorPortalPasswordSetupRepository
{
    private const COLUMNS = 'id, subcontractor_id, token_hash, expires_at,
        used_at, cancelled_at, created_by_user_id, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function hash(string $plaintext): string
    {
        return hash('sha256', $plaintext);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findActiveByPlaintext(string $plaintext): ?array
    {
        $clean = trim($plaintext);
        if ($clean === '') {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM subcontractor_portal_password_setup_tokens
             WHERE token_hash = :hash
               AND used_at IS NULL
               AND cancelled_at IS NULL
               AND expires_at >= NOW()
             LIMIT 1'
        );
        $stmt->execute(['hash' => self::hash($clean)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }

    public function create(
        int $subcontractorId,
        string $plaintext,
        string $expiresAt,
        ?int $createdByUserId
    ): int {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO subcontractor_portal_password_setup_tokens
             (subcontractor_id, token_hash, expires_at, created_by_user_id)
             VALUES (:subcontractor_id, :token_hash, :expires_at, :created_by_user_id)'
        );
        $stmt->execute([
            'subcontractor_id' => $subcontractorId,
            'token_hash' => self::hash($plaintext),
            'expires_at' => $expiresAt,
            'created_by_user_id' => $createdByUserId,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        if ($id <= 0) {
            throw new RuntimeException('password setup token insert did not return an id');
        }
        return $id;
    }

    public function cancelOutstandingForSubcontractor(int $subcontractorId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE subcontractor_portal_password_setup_tokens
             SET cancelled_at = NOW()
             WHERE subcontractor_id = :subcontractor_id
               AND used_at IS NULL
               AND cancelled_at IS NULL'
        );
        $stmt->execute(['subcontractor_id' => $subcontractorId]);
    }

    public function markUsed(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE subcontractor_portal_password_setup_tokens
             SET used_at = NOW()
             WHERE id = :id
               AND used_at IS NULL
               AND cancelled_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }
}
