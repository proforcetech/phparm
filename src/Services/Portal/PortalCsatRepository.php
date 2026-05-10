<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalCsatResponse;
use PDO;
use RuntimeException;

class PortalCsatRepository
{
    private const COLUMNS = 'id, portal_account_id, workorder_id, rating, comment,
        public_token, requested_at, responded_at, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?PortalCsatResponse
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_csat_responses WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PortalCsatResponse($row) : null;
    }

    public function findByAccountAndWorkorder(int $accountId, int $workorderId): ?PortalCsatResponse
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_csat_responses
             WHERE portal_account_id = :a AND workorder_id = :w LIMIT 1'
        );
        $stmt->execute(['a' => $accountId, 'w' => $workorderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PortalCsatResponse($row) : null;
    }

    public function findByPublicToken(string $token): ?PortalCsatResponse
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_csat_responses
             WHERE public_token = :t LIMIT 1'
        );
        $stmt->execute(['t' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PortalCsatResponse($row) : null;
    }

    /**
     * Insert a "we asked" row with no rating yet. Idempotent via the
     * (portal_account_id, workorder_id) UNIQUE — if the row already
     * exists we return the existing one instead of erroring.
     */
    public function request(int $accountId, int $workorderId, ?string $publicToken = null): PortalCsatResponse
    {
        $existing = $this->findByAccountAndWorkorder($accountId, $workorderId);
        if ($existing !== null) {
            return $existing;
        }
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO portal_csat_responses
             (portal_account_id, workorder_id, public_token, requested_at, created_at, updated_at)
             VALUES (:a, :w, :pt, NOW(), NOW(), NOW())'
        );
        $stmt->execute(['a' => $accountId, 'w' => $workorderId, 'pt' => $publicToken]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("portal_csat_responses id={$id} vanished after insert");
        }
        return $row;
    }

    public function recordResponse(int $id, int $rating, ?string $comment): PortalCsatResponse
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE portal_csat_responses
             SET rating = :r, comment = :c, responded_at = NOW(), updated_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'r' => $rating, 'c' => $comment]);
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("portal_csat_responses id={$id} vanished after recordResponse");
        }
        return $row;
    }

    /**
     * @return array<int, PortalCsatResponse>
     */
    public function listForAccount(int $accountId, bool $answeredOnly = false): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM portal_csat_responses
                WHERE portal_account_id = :a';
        if ($answeredOnly) {
            $sql .= ' AND responded_at IS NOT NULL';
        }
        $sql .= ' ORDER BY COALESCE(responded_at, requested_at) DESC LIMIT 100';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['a' => $accountId]);
        return array_map(
            fn(array $r) => new PortalCsatResponse($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }
}
