<?php

namespace App\Services\Reminder;

use App\Database\Connection;
use App\Models\ReminderCampaign;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class ReminderCampaignService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, int $actorId): ReminderCampaign
    {
        $this->validate($data);

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO reminder_campaigns (name, channel, frequency, status, service_type_filter, last_run_at, next_run_at, created_at, updated_at)
            VALUES (:name, :channel, :frequency, :status, :service_type_filter, NULL, :next_run_at, NOW(), NOW())
        SQL);

        $stmt->execute([
            'name' => $data['name'],
            'channel' => $data['channel'],
            'frequency' => $data['frequency'],
            'status' => $data['status'] ?? 'draft',
            'service_type_filter' => $data['service_type_filter'] ?? null,
            'next_run_at' => $data['next_run_at'] ?? null,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $campaign = $this->find($id);
        $this->log($actorId, 'reminder.campaign_created', $id, $campaign?->toArray() ?? []);

        return $campaign ?? new ReminderCampaign(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $campaignId, array $data, int $actorId): ?ReminderCampaign
    {
        $existing = $this->find($campaignId);
        if ($existing === null) {
            return null;
        }

        $this->validate($data, true);

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            UPDATE reminder_campaigns
            SET name = COALESCE(:name, name),
                channel = COALESCE(:channel, channel),
                frequency = COALESCE(:frequency, frequency),
                status = COALESCE(:status, status),
                service_type_filter = COALESCE(:service_type_filter, service_type_filter),
                next_run_at = COALESCE(:next_run_at, next_run_at),
                updated_at = NOW()
            WHERE id = :id
        SQL);

        $stmt->execute([
            'id' => $campaignId,
            'name' => $data['name'] ?? null,
            'channel' => $data['channel'] ?? null,
            'frequency' => $data['frequency'] ?? null,
            'status' => $data['status'] ?? null,
            'service_type_filter' => $data['service_type_filter'] ?? null,
            'next_run_at' => $data['next_run_at'] ?? null,
        ]);

        $updated = $this->find($campaignId);
        $this->log($actorId, 'reminder.campaign_updated', $campaignId, [
            'before' => $existing->toArray(),
            'after' => $updated?->toArray(),
        ]);

        return $updated;
    }

    public function find(int $campaignId): ?ReminderCampaign
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM reminder_campaigns WHERE id = :id');
        $stmt->execute(['id' => $campaignId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new ReminderCampaign($row);
    }

    /**
     * @return array<int, ReminderCampaign>
     */
    public function listActive(): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM reminder_campaigns WHERE status = :status ORDER BY next_run_at ASC NULLS LAST');
        $stmt->execute(['status' => 'active']);

        return array_map(static fn (array $row) => new ReminderCampaign($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validate(array $data, bool $isUpdate = false): void
    {
        $required = ['name', 'channel', 'frequency'];
        foreach ($required as $field) {
            if ($isUpdate && !array_key_exists($field, $data)) {
                continue;
            }

            if (empty($data[$field])) {
                throw new InvalidArgumentException('Reminder campaign missing required field: ' . $field);
            }
        }

        if (isset($data['channel']) && !in_array($data['channel'], ['mail', 'sms'], true)) {
            throw new InvalidArgumentException('Reminder campaigns support mail or sms channels.');
        }
    }

    private function log(int $actorId, string $event, int $campaignId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'reminder_campaign', (string) $campaignId, $actorId, $context));
    }
}
