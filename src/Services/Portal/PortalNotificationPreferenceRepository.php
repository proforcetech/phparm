<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalNotificationPreference;
use PDO;

class PortalNotificationPreferenceRepository
{
    private const COLUMNS = 'id, portal_account_id, pref_key, channel, enabled, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, PortalNotificationPreference>
     */
    public function listForAccount(int $accountId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_notification_preferences
             WHERE portal_account_id = :a
             ORDER BY pref_key ASC, channel ASC'
        );
        $stmt->execute(['a' => $accountId]);
        return array_map(
            fn(array $r) => new PortalNotificationPreference($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findOne(int $accountId, string $prefKey, string $channel): ?PortalNotificationPreference
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM portal_notification_preferences
             WHERE portal_account_id = :a AND pref_key = :k AND channel = :c LIMIT 1'
        );
        $stmt->execute(['a' => $accountId, 'k' => $prefKey, 'c' => $channel]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PortalNotificationPreference($row) : null;
    }

    /**
     * UPSERT one (account, key, channel) row. Returns the row after the
     * write so callers can re-emit it to the client.
     */
    public function upsert(int $accountId, string $prefKey, string $channel, bool $enabled): PortalNotificationPreference
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO portal_notification_preferences
             (portal_account_id, pref_key, channel, enabled, created_at, updated_at)
             VALUES (:a, :k, :c, :e, NOW(), NOW())
             ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_at = NOW()'
        );
        $stmt->execute([
            'a' => $accountId,
            'k' => $prefKey,
            'c' => $channel,
            'e' => $enabled ? 1 : 0,
        ]);
        $found = $this->findOne($accountId, $prefKey, $channel);
        if ($found === null) {
            // The unique key guarantees a row exists after the insert; this
            // only fires if the connection lost our row between writes.
            throw new \RuntimeException('failed to reload notification preference after upsert');
        }
        return $found;
    }
}
