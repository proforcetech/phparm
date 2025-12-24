<?php

namespace App\Services\Reminder;

use App\Database\Connection;
use App\Models\ReminderCampaign;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use DateTimeImmutable;
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
            INSERT INTO reminder_campaigns (
                name,
                description,
                channel,
                frequency,
                frequency_unit,
                frequency_interval,
                status,
                service_type_filter,
                email_subject,
                email_body,
                sms_body,
                last_run_at,
                next_run_at,
                created_at,
                updated_at
            )
            VALUES (
                :name,
                :description,
                :channel,
                :frequency,
                :frequency_unit,
                :frequency_interval,
                :status,
                :service_type_filter,
                :email_subject,
                :email_body,
                :sms_body,
                NULL,
                :next_run_at,
                NOW(),
                NOW()
            )
        SQL);

        $stmt->execute([
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'channel' => $data['channel'],
            'frequency' => $data['frequency'],
            'frequency_unit' => $data['frequency_unit'] ?? 'day',
            'frequency_interval' => (int) ($data['frequency_interval'] ?? 1),
            'status' => $data['status'] ?? 'draft',
            'service_type_filter' => $data['service_type_filter'] ?? null,
            'email_subject' => $data['email_subject'] ?? null,
            'email_body' => $data['email_body'] ?? null,
            'sms_body' => $data['sms_body'] ?? null,
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
                description = COALESCE(:description, description),
                channel = COALESCE(:channel, channel),
                frequency = COALESCE(:frequency, frequency),
                frequency_unit = COALESCE(:frequency_unit, frequency_unit),
                frequency_interval = COALESCE(:frequency_interval, frequency_interval),
                status = COALESCE(:status, status),
                service_type_filter = COALESCE(:service_type_filter, service_type_filter),
                email_subject = COALESCE(:email_subject, email_subject),
                email_body = COALESCE(:email_body, email_body),
                sms_body = COALESCE(:sms_body, sms_body),
                next_run_at = COALESCE(:next_run_at, next_run_at),
                updated_at = NOW()
            WHERE id = :id
        SQL);

        $stmt->execute([
            'id' => $campaignId,
            'name' => $data['name'] ?? null,
            'description' => $data['description'] ?? null,
            'channel' => $data['channel'] ?? null,
            'frequency' => $data['frequency'] ?? null,
            'frequency_unit' => $data['frequency_unit'] ?? null,
            'frequency_interval' => isset($data['frequency_interval']) ? (int) $data['frequency_interval'] : null,
            'status' => $data['status'] ?? null,
            'service_type_filter' => $data['service_type_filter'] ?? null,
            'email_subject' => $data['email_subject'] ?? null,
            'email_body' => $data['email_body'] ?? null,
            'sms_body' => $data['sms_body'] ?? null,
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
    public function list(): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM reminder_campaigns ORDER BY id DESC');
        $stmt->execute();

        return array_map(static fn (array $row) => new ReminderCampaign($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
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

    public function activate(int $campaignId, int $actorId): ?ReminderCampaign
    {
        $nextRun = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        return $this->update($campaignId, ['status' => 'active', 'next_run_at' => $nextRun], $actorId);
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

        if (isset($data['channel']) && !in_array($data['channel'], ['mail', 'sms', 'both'], true)) {
            throw new InvalidArgumentException('Reminder campaigns support mail, sms, or both channels.');
        }

        if (isset($data['frequency_interval']) && (int) $data['frequency_interval'] < 1) {
            throw new InvalidArgumentException('Reminder campaign frequency interval must be at least 1.');
        }

        if (isset($data['frequency_unit']) && !in_array($data['frequency_unit'], ['day', 'week', 'month'], true)) {
            throw new InvalidArgumentException('Reminder campaign frequency unit must be day, week, or month.');
        }

        if (isset($data['status']) && !in_array($data['status'], ['draft', 'active', 'paused', 'archived'], true)) {
            throw new InvalidArgumentException('Reminder campaign status must be draft, active, paused, or archived.');
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
