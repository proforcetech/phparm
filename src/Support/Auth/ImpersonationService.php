<?php

namespace App\Support\Auth;

use App\Database\Connection;
use App\Models\User;
use PDO;

/**
 * Service for managing user impersonation with database-backed validation.
 *
 * All impersonation sessions are stored in the database for audit logging
 * and validation on every authenticated request.
 */
class ImpersonationService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Start an impersonation session.
     *
     * @param User $impersonator The admin user starting impersonation
     * @param User $target The user being impersonated
     * @param string|null $ipAddress Client IP address
     * @param string|null $userAgent Client user agent
     * @return string The session token
     * @throws \RuntimeException If impersonation cannot be started
     */
    public function startImpersonation(
        User $impersonator,
        User $target,
        ?string $ipAddress = null,
        ?string $userAgent = null
    ): string {
        // Only admins can impersonate
        if ($impersonator->role !== 'admin') {
            throw new \RuntimeException('Only administrators can impersonate users');
        }

        // Cannot impersonate yourself
        if ($impersonator->id === $target->id) {
            throw new \RuntimeException('Cannot impersonate yourself');
        }

        // End any existing active impersonation sessions for this impersonator
        $this->endActiveSessionsForImpersonator($impersonator->id);

        // Generate secure session token
        $sessionToken = bin2hex(random_bytes(32));

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO impersonation_sessions
            (impersonator_id, impersonated_id, session_token, ip_address, user_agent, started_at, is_active)
            VALUES (:impersonator_id, :impersonated_id, :session_token, :ip_address, :user_agent, NOW(), TRUE)'
        );

        $stmt->execute([
            'impersonator_id' => $impersonator->id,
            'impersonated_id' => $target->id,
            'session_token' => $sessionToken,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return $sessionToken;
    }

    /**
     * Validate an active impersonation session.
     *
     * @param string $sessionToken The session token to validate
     * @return array|null Session data if valid, null if invalid
     */
    public function validateSession(string $sessionToken): ?array
    {
        if (empty($sessionToken)) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                impersonator_id,
                impersonated_id,
                started_at,
                ip_address
            FROM impersonation_sessions
            WHERE session_token = :session_token
            AND is_active = TRUE
            AND ended_at IS NULL
            LIMIT 1'
        );

        $stmt->execute(['session_token' => $sessionToken]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Update last activity
        $this->touchSession($sessionToken);

        return $row;
    }

    /**
     * End an impersonation session.
     *
     * @param string $sessionToken The session token
     * @return bool True if session was ended, false if not found
     */
    public function endSession(string $sessionToken): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE impersonation_sessions
            SET ended_at = NOW(), is_active = FALSE
            WHERE session_token = :session_token AND is_active = TRUE'
        );

        $stmt->execute(['session_token' => $sessionToken]);

        return $stmt->rowCount() > 0;
    }

    /**
     * End all active impersonation sessions for a user (as impersonator).
     *
     * @param int $impersonatorId The impersonator user ID
     * @return int Number of sessions ended
     */
    public function endActiveSessionsForImpersonator(int $impersonatorId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE impersonation_sessions
            SET ended_at = NOW(), is_active = FALSE
            WHERE impersonator_id = :impersonator_id AND is_active = TRUE'
        );

        $stmt->execute(['impersonator_id' => $impersonatorId]);

        return $stmt->rowCount();
    }

    /**
     * End all active impersonation sessions for a user being impersonated.
     *
     * @param int $impersonatedId The impersonated user ID
     * @return int Number of sessions ended
     */
    public function endActiveSessionsForImpersonated(int $impersonatedId): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE impersonation_sessions
            SET ended_at = NOW(), is_active = FALSE
            WHERE impersonated_id = :impersonated_id AND is_active = TRUE'
        );

        $stmt->execute(['impersonated_id' => $impersonatedId]);

        return $stmt->rowCount();
    }

    /**
     * Get active impersonation session for an impersonator.
     *
     * @param int $impersonatorId The impersonator user ID
     * @return array|null Session data if active, null otherwise
     */
    public function getActiveSessionForImpersonator(int $impersonatorId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                session_token,
                impersonator_id,
                impersonated_id,
                started_at,
                ip_address
            FROM impersonation_sessions
            WHERE impersonator_id = :impersonator_id
            AND is_active = TRUE
            AND ended_at IS NULL
            ORDER BY started_at DESC
            LIMIT 1'
        );

        $stmt->execute(['impersonator_id' => $impersonatorId]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get impersonation audit log for a user.
     *
     * @param int $userId User ID to get logs for
     * @param int $limit Maximum number of records
     * @param int $offset Offset for pagination
     * @return array Audit log records
     */
    public function getAuditLog(int $userId, int $limit = 50, int $offset = 0): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                i.id,
                i.session_token,
                i.impersonator_id,
                i.impersonated_id,
                i.started_at,
                i.ended_at,
                i.ip_address,
                i.is_active,
                impersonator.name as impersonator_name,
                impersonator.email as impersonator_email,
                impersonated.name as impersonated_name,
                impersonated.email as impersonated_email
            FROM impersonation_sessions i
            JOIN users impersonator ON impersonator.id = i.impersonator_id
            JOIN users impersonated ON impersonated.id = i.impersonated_id
            WHERE i.impersonator_id = :user_id OR i.impersonated_id = :user_id2
            ORDER BY i.started_at DESC
            LIMIT :limit OFFSET :offset'
        );

        $stmt->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':user_id2', $userId, PDO::PARAM_INT);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Update last activity timestamp for a session.
     *
     * @param string $sessionToken The session token
     * @return void
     */
    private function touchSession(string $sessionToken): void
    {
        // We use the started_at as the reference, but could add a last_activity column
        // For now, this is a placeholder for future enhancement
    }
}
