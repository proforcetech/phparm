<?php

namespace App\Services\Reminder;

use App\Database\Connection;
use App\Models\ReminderCampaign;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use DateInterval;
use DateTimeImmutable;
use PDO;

class ReminderScheduler
{
    private Connection $connection;
    private ReminderCampaignService $campaigns;
    private ReminderPreferenceService $preferences;
    private NotificationDispatcher $notifications;
    private ?AuditLogger $audit;

    public function __construct(
        Connection $connection,
        ReminderCampaignService $campaigns,
        ReminderPreferenceService $preferences,
        NotificationDispatcher $notifications,
        ?AuditLogger $audit = null
    ) {
        $this->connection = $connection;
        $this->campaigns = $campaigns;
        $this->preferences = $preferences;
        $this->notifications = $notifications;
        $this->audit = $audit;
    }

    public function sendDueCampaigns(int $actorId): int
    {
        $count = 0;
        foreach ($this->campaigns->listActive() as $campaign) {
            if ($campaign->next_run_at !== null && new DateTimeImmutable($campaign->next_run_at) > new DateTimeImmutable()) {
                continue;
            }

            $recipients = $this->resolveRecipients($campaign);
            foreach ($recipients as $recipient) {
                $this->dispatch($campaign, $recipient);
                $count++;
            }

            $this->markRun($campaign, $actorId);
        }

        return $count;
    }

    /**
     * @return array<int, array{customer_id:int,email?:string,phone?:string}>
     */
    private function resolveRecipients(ReminderCampaign $campaign): array
    {
        $sql = 'SELECT id as customer_id, email, phone FROM customers WHERE deleted_at IS NULL';
        $params = [];

        if ($campaign->service_type_filter !== null) {
            $sql = <<<SQL
                SELECT c.id as customer_id, c.email, c.phone
                FROM customers c
                JOIN invoices i ON i.customer_id = c.id
                WHERE i.service_type_id = :service_type
                  AND c.deleted_at IS NULL
            SQL;
            $params['service_type'] = $campaign->service_type_filter;
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_values(array_filter($rows, function (array $row) use ($campaign) {
            if ($campaign->channel === 'mail') {
                return !empty($row['email']) && $this->preferences->isSubscribed((int) $row['customer_id'], 'mail');
            }

            return !empty($row['phone']) && $this->preferences->isSubscribed((int) $row['customer_id'], 'sms');
        }));
    }

    /**
     * @param array{customer_id:int,email?:string,phone?:string} $recipient
     */
    private function dispatch(ReminderCampaign $campaign, array $recipient): void
    {
        $payload = [
            'campaign' => $campaign->name,
            'customer_id' => $recipient['customer_id'],
        ];

        if ($campaign->channel === 'mail') {
            $this->notifications->sendMail('reminder.campaign', (string) $recipient['email'], $payload, $campaign->name);
        } else {
            $this->notifications->sendSms('reminder.campaign', (string) $recipient['phone'], $payload);
        }
    }

    private function markRun(ReminderCampaign $campaign, int $actorId): void
    {
        $nextRun = $this->nextRunDate($campaign->frequency);

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE reminder_campaigns SET last_run_at = NOW(), next_run_at = :next_run_at, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $campaign->id,
            'next_run_at' => $nextRun?->format('Y-m-d H:i:s'),
        ]);

        if ($this->audit !== null) {
            $this->audit->log(new AuditEntry('reminder.campaign_run', 'reminder_campaign', (string) $campaign->id, $actorId, [
                'next_run_at' => $nextRun?->format(DATE_ATOM),
            ]));
        }
    }

    private function nextRunDate(string $frequency): ?DateTimeImmutable
    {
        $now = new DateTimeImmutable();
        return match ($frequency) {
            'daily' => $now->add(new DateInterval('P1D')),
            'weekly' => $now->add(new DateInterval('P7D')),
            'monthly' => $now->add(new DateInterval('P1M')),
            default => null,
        };
    }
}
