<?php

namespace App\Support\Auth;

use App\Database\Connection;
use PDO;

class UserSessionManager
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function recordSession(int $userId, string $sessionId, ?string $ipAddress, ?string $userAgent): void
    {
        $deviceLabel = $this->resolveDeviceLabel($userAgent);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO user_sessions (user_id, session_id, ip_address, user_agent, device_label, last_seen_at, created_at) '
            . 'VALUES (:user_id, :session_id, :ip_address, :user_agent, :device_label, NOW(), NOW()) '
            . 'ON DUPLICATE KEY UPDATE ip_address = :ip_address, user_agent = :user_agent, device_label = :device_label, '
            . 'last_seen_at = NOW(), revoked_at = NULL, revoked_by = NULL'
        );

        $stmt->execute([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_label' => $deviceLabel,
        ]);
    }

    public function ensureSessionActive(
        int $userId,
        string $sessionId,
        ?string $ipAddress,
        ?string $userAgent
    ): bool {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, revoked_at FROM user_sessions WHERE user_id = :user_id AND session_id = :session_id LIMIT 1'
        );
        $stmt->execute([
            'user_id' => $userId,
            'session_id' => $sessionId,
        ]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            $this->recordSession($userId, $sessionId, $ipAddress, $userAgent);
            return true;
        }

        if (!empty($row['revoked_at'])) {
            return false;
        }

        $deviceLabel = $this->resolveDeviceLabel($userAgent);
        $update = $this->connection->pdo()->prepare(
            'UPDATE user_sessions SET last_seen_at = NOW(), '
            . 'ip_address = COALESCE(:ip_address, ip_address), '
            . 'user_agent = COALESCE(:user_agent, user_agent), '
            . 'device_label = COALESCE(:device_label, device_label) '
            . 'WHERE id = :id'
        );
        $update->execute([
            'id' => $row['id'],
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_label' => $deviceLabel,
        ]);

        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSessions(int $userId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, session_id, ip_address, user_agent, device_label, last_seen_at, created_at, revoked_at '
            . 'FROM user_sessions WHERE user_id = :user_id ORDER BY created_at DESC'
        );
        $stmt->execute(['user_id' => $userId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function revokeSessionById(int $userId, int $sessionRowId, ?int $revokedBy = null): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, session_id, revoked_at FROM user_sessions WHERE id = :id AND user_id = :user_id LIMIT 1'
        );
        $stmt->execute([
            'id' => $sessionRowId,
            'user_id' => $userId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        if (empty($row['revoked_at'])) {
            $update = $this->connection->pdo()->prepare(
                'UPDATE user_sessions SET revoked_at = NOW(), revoked_by = :revoked_by WHERE id = :id'
            );
            $update->execute([
                'id' => $sessionRowId,
                'revoked_by' => $revokedBy,
            ]);
        }

        return [
            'id' => (int) $row['id'],
            'session_id' => (string) $row['session_id'],
            'revoked_at' => $row['revoked_at'],
        ];
    }

    public function revokeSessionBySessionId(int $userId, string $sessionId, ?int $revokedBy = null): bool
    {
        $update = $this->connection->pdo()->prepare(
            'UPDATE user_sessions SET revoked_at = NOW(), revoked_by = :revoked_by '
            . 'WHERE user_id = :user_id AND session_id = :session_id AND revoked_at IS NULL'
        );
        $update->execute([
            'user_id' => $userId,
            'session_id' => $sessionId,
            'revoked_by' => $revokedBy,
        ]);

        return $update->rowCount() > 0;
    }

    private function resolveDeviceLabel(?string $userAgent): ?string
    {
        if (!$userAgent) {
            return null;
        }

        $ua = strtolower($userAgent);

        $platform = match (true) {
            str_contains($ua, 'windows') => 'Windows',
            str_contains($ua, 'mac os x') || str_contains($ua, 'macintosh') => 'macOS',
            str_contains($ua, 'iphone') => 'iPhone',
            str_contains($ua, 'ipad') => 'iPad',
            str_contains($ua, 'android') => 'Android',
            str_contains($ua, 'linux') => 'Linux',
            default => 'Unknown OS',
        };

        $browser = match (true) {
            str_contains($ua, 'edg/') => 'Edge',
            str_contains($ua, 'chrome/') && !str_contains($ua, 'chromium') => 'Chrome',
            str_contains($ua, 'safari/') && !str_contains($ua, 'chrome/') => 'Safari',
            str_contains($ua, 'firefox/') => 'Firefox',
            str_contains($ua, 'opera') || str_contains($ua, 'opr/') => 'Opera',
            default => 'Unknown Browser',
        };

        return sprintf('%s on %s', $browser, $platform);
    }
}
