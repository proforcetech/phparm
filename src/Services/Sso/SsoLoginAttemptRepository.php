<?php

namespace App\Services\Sso;

use App\Database\Connection;
use App\Models\SsoLoginAttempt;
use PDO;

class SsoLoginAttemptRepository
{
    private const COLUMNS = [
        'id', 'provider_id', 'state', 'nonce', 'redirect_uri', 'user_id',
        'status', 'error_message', 'completed_at', 'created_at',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?SsoLoginAttempt
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_login_attempts WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByState(string $state): ?SsoLoginAttempt
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_login_attempts WHERE state = :s'
        );
        $stmt->execute(['s' => $state]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): SsoLoginAttempt
    {
        $this->connection->pdo()->prepare(
            'INSERT INTO sso_login_attempts (provider_id, state, nonce, redirect_uri, status) '
            . 'VALUES (:p, :s, :n, :r, :st)'
        )->execute([
            'p' => (int) $payload['provider_id'],
            's' => (string) $payload['state'],
            'n' => $payload['nonce'] ?? null,
            'r' => $payload['redirect_uri'] ?? null,
            'st' => $payload['status'] ?? SsoLoginAttempt::STATUS_PENDING,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new SsoLoginAttempt(['id' => $id]);
    }

    public function complete(int $id, ?int $userId, ?string $when = null): ?SsoLoginAttempt
    {
        $this->connection->pdo()->prepare(
            'UPDATE sso_login_attempts SET status = :st, user_id = :u, completed_at = :t WHERE id = :id'
        )->execute([
            'id' => $id,
            'st' => SsoLoginAttempt::STATUS_COMPLETED,
            'u' => $userId,
            't' => $when ?? date('Y-m-d H:i:s'),
        ]);
        return $this->find($id);
    }

    public function fail(int $id, string $error, ?string $when = null): ?SsoLoginAttempt
    {
        $this->connection->pdo()->prepare(
            'UPDATE sso_login_attempts SET status = :st, error_message = :e, completed_at = :t WHERE id = :id'
        )->execute([
            'id' => $id,
            'st' => SsoLoginAttempt::STATUS_FAILED,
            'e' => $error,
            't' => $when ?? date('Y-m-d H:i:s'),
        ]);
        return $this->find($id);
    }

    /**
     * Mark every attempt older than $cutoff that is still pending as expired.
     * Called from cron alongside the trusted-device sweep.
     */
    public function expireStale(string $cutoff): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE sso_login_attempts SET status = :st, completed_at = :t '
            . 'WHERE status = :pending AND created_at < :cutoff'
        );
        $stmt->execute([
            'st' => SsoLoginAttempt::STATUS_EXPIRED,
            't' => date('Y-m-d H:i:s'),
            'pending' => SsoLoginAttempt::STATUS_PENDING,
            'cutoff' => $cutoff,
        ]);
        return $stmt->rowCount();
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SsoLoginAttempt
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new SsoLoginAttempt($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'provider_id', 'user_id' => (int) $value,
            default => $value,
        };
    }
}
