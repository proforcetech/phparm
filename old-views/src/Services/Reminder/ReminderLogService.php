<?php

namespace App\Services\Reminder;

use App\Database\Connection;
use App\Models\ReminderLog;
use DateTimeImmutable;
use PDO;

class ReminderLogService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function record(
        int $campaignId,
        int $customerId,
        string $channel,
        string $status,
        ?string $body = null,
        ?int $preferenceId = null,
        ?string $scheduledFor = null,
        ?string $error = null
    ): ReminderLog {
        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO reminder_logs (campaign_id, preference_id, customer_id, channel, status, scheduled_for, sent_at, body, error, created_at)
            VALUES (:campaign_id, :preference_id, :customer_id, :channel, :status, :scheduled_for, :sent_at, :body, :error, :created_at)
        SQL);

        $stmt->execute([
            'campaign_id' => $campaignId,
            'preference_id' => $preferenceId,
            'customer_id' => $customerId,
            'channel' => $channel,
            'status' => $status,
            'scheduled_for' => $scheduledFor,
            'sent_at' => $status === 'sent' ? $now : null,
            'body' => $body,
            'error' => $error,
            'created_at' => $now,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        return $this->find($id) ?? new ReminderLog([
            'id' => $id,
            'campaign_id' => $campaignId,
            'preference_id' => $preferenceId,
            'customer_id' => $customerId,
            'channel' => $channel,
            'status' => $status,
            'scheduled_for' => $scheduledFor,
            'sent_at' => $status === 'sent' ? $now : null,
            'body' => $body,
            'error' => $error,
            'created_at' => $now,
        ]);
    }

    public function existsForSchedule(
        int $campaignId,
        ?int $preferenceId,
        int $customerId,
        string $channel,
        string $scheduledFor
    ): bool {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT id FROM reminder_logs
            WHERE campaign_id = :campaign_id
              AND customer_id = :customer_id
              AND channel = :channel
              AND scheduled_for = :scheduled_for
              AND (
                (preference_id IS NULL AND :preference_id IS NULL)
                OR preference_id = :preference_id
              )
            LIMIT 1
        SQL);

        $stmt->execute([
            'campaign_id' => $campaignId,
            'customer_id' => $customerId,
            'channel' => $channel,
            'scheduled_for' => $scheduledFor,
            'preference_id' => $preferenceId,
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
    }

    public function updateStatus(int $logId, string $status, ?string $body = null, ?string $error = null): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            UPDATE reminder_logs
            SET status = :status,
                body = COALESCE(:body, body),
                error = :error,
                sent_at = CASE WHEN :status = 'sent' THEN NOW() ELSE sent_at END
            WHERE id = :id
        SQL);

        $stmt->execute([
            'id' => $logId,
            'status' => $status,
            'body' => $body,
            'error' => $error,
        ]);
    }

    public function find(int $logId): ?ReminderLog
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM reminder_logs WHERE id = :id');
        $stmt->execute(['id' => $logId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new ReminderLog($row);
    }

    /**
     * @return array<int, ReminderLog>
     */
    public function forCampaign(int $campaignId, int $limit = 50): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT * FROM reminder_logs
            WHERE campaign_id = :campaign_id
            ORDER BY id DESC
            LIMIT :limit
        SQL);
        $stmt->bindValue('campaign_id', $campaignId, PDO::PARAM_INT);
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(static fn (array $row) => new ReminderLog($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }
}
