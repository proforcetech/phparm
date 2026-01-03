<?php

namespace App\Services\Reminder;

use App\Database\Connection;
use App\Models\ReminderCampaign;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use App\Support\Notifications\TemplateEngine;
use DateInterval;
use DateTimeImmutable;
use DateTimeZone;
use PDO;
use Throwable;

class ReminderScheduler
{
    private Connection $connection;
    private ReminderCampaignService $campaigns;
    private ReminderPreferenceService $preferences;
    private NotificationDispatcher $notifications;
    private ReminderLogService $logs;
    private TemplateEngine $templates;
    private ?AuditLogger $audit;

    public function __construct(
        Connection $connection,
        ReminderCampaignService $campaigns,
        ReminderPreferenceService $preferences,
        NotificationDispatcher $notifications,
        ReminderLogService $logs,
        TemplateEngine $templates,
        ?AuditLogger $audit = null
    ) {
        $this->connection = $connection;
        $this->campaigns = $campaigns;
        $this->preferences = $preferences;
        $this->notifications = $notifications;
        $this->logs = $logs;
        $this->templates = $templates;
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
                $count += $this->dispatch($campaign, $recipient);
            }

            $this->markRun($campaign, $actorId);
        }

        return $count;
    }

    /**
     * @return array<int, array{customer_id:int,preference_id?:int,email?:string,phone?:string,preferred_channel?:string,lead_days?:int,preferred_hour?:int,timezone?:string,first_name?:string,last_name?:string}>
     */
    private function resolveRecipients(ReminderCampaign $campaign): array
    {
        $sql = <<<SQL
            SELECT
                rp.id as preference_id,
                rp.customer_id,
                COALESCE(rp.email, c.email) as email,
                COALESCE(rp.phone, c.phone) as phone,
                rp.preferred_channel,
                rp.lead_days,
                rp.preferred_hour,
                rp.timezone,
                c.first_name,
                c.last_name
            FROM reminder_preferences rp
            JOIN customers c ON c.id = rp.customer_id
            WHERE rp.is_active = 1
              AND rp.preferred_channel <> 'none'
              AND c.deleted_at IS NULL
        SQL;

        $params = [];
        if ($campaign->service_type_filter !== null) {
            $sql .= ' AND EXISTS (SELECT 1 FROM invoices i WHERE i.customer_id = rp.customer_id AND i.service_type_id = :service_type)';
            $params['service_type'] = $campaign->service_type_filter;
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static function (array $row): array {
            return [
                'preference_id' => isset($row['preference_id']) ? (int) $row['preference_id'] : null,
                'customer_id' => (int) $row['customer_id'],
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'preferred_channel' => $row['preferred_channel'] ?? 'both',
                'lead_days' => isset($row['lead_days']) ? (int) $row['lead_days'] : 0,
                'preferred_hour' => isset($row['preferred_hour']) ? (int) $row['preferred_hour'] : 9,
                'timezone' => $row['timezone'] ?? 'UTC',
                'first_name' => $row['first_name'] ?? '',
                'last_name' => $row['last_name'] ?? '',
            ];
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array{customer_id:int,email?:string,phone?:string} $recipient
     */
    private function dispatch(ReminderCampaign $campaign, array $recipient): int
    {
        $sent = 0;
        $channels = $this->resolveChannels($campaign->channel, $recipient['preferred_channel']);
        if (count($channels) === 0) {
            return 0;
        }

        $scheduledFor = $this->computeScheduledFor($campaign, $recipient);
        $context = $this->buildContext($campaign, $recipient, $scheduledFor);

        foreach ($channels as $channel) {
            $contact = $channel === 'mail' ? ($recipient['email'] ?? null) : ($recipient['phone'] ?? null);
            if ($contact === null || $contact === '') {
                $this->logs->record($campaign->id, $recipient['customer_id'], $channel, 'skipped', null, $recipient['preference_id'], $scheduledFor, 'Missing contact or opted out');
                continue;
            }

            if ($this->logs->existsForSchedule($campaign->id, $recipient['preference_id'], $recipient['customer_id'], $channel, $scheduledFor)) {
                continue;
            }

            $body = $channel === 'mail'
                ? $this->renderBody($campaign->email_body, $context, $campaign->name)
                : $this->renderBody($campaign->sms_body, $context, $campaign->name);

            $log = $this->logs->record(
                $campaign->id,
                $recipient['customer_id'],
                $channel,
                'queued',
                $body,
                $recipient['preference_id'],
                $scheduledFor
            );

            try {
                if ($channel === 'mail') {
                    $subject = $campaign->email_subject ?? $campaign->name;
                    $this->notifications->sendMail('reminder.campaign', (string) $contact, ['body' => $body], $subject);
                    $this->logs->updateStatus($log->id, 'sent', $body);
                } else {
                    $this->notifications->sendSms('reminder.campaign.sms', (string) $contact, ['body' => $body]);
                    $this->logs->updateStatus($log->id, 'pending', $body);
                }
                $sent++;
            } catch (Throwable $exception) {
                $this->logs->updateStatus($log->id, 'failed', $body, $exception->getMessage());
            }
        }

        return $sent;
    }

    private function resolveChannels(string $campaignChannel, string $preferenceChannel): array
    {
        $campaignModes = $campaignChannel === 'both' ? ['mail', 'sms'] : [$campaignChannel];

        $normalizedPreference = $preferenceChannel === 'email' ? 'mail' : $preferenceChannel;
        if ($normalizedPreference === 'none') {
            return [];
        }

        $preferenceModes = $normalizedPreference === 'both' ? ['mail', 'sms'] : [$normalizedPreference];

        return array_values(array_intersect($campaignModes, $preferenceModes));
    }

    private function computeScheduledFor(ReminderCampaign $campaign, array $recipient): string
    {
        $base = $campaign->next_run_at !== null
            ? new DateTimeImmutable($campaign->next_run_at, new DateTimeZone('UTC'))
            : new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $leadDays = max(0, (int) ($recipient['lead_days'] ?? 0));
        $preferredHour = max(0, min(23, (int) ($recipient['preferred_hour'] ?? 9)));

        $local = $base
            ->setTimezone($this->timezoneFromRecipient($recipient))
            ->modify('-' . $leadDays . ' days')
            ->setTime($preferredHour, 0);

        return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    private function buildContext(ReminderCampaign $campaign, array $recipient, string $scheduledFor): array
    {
        $timezone = $this->timezoneFromRecipient($recipient);
        $scheduledUtc = new DateTimeImmutable($scheduledFor, new DateTimeZone('UTC'));
        $scheduledLocal = $scheduledUtc->setTimezone($timezone);

        $first = (string) ($recipient['first_name'] ?? '');
        $last = (string) ($recipient['last_name'] ?? '');
        $full = trim($first . ' ' . $last);

        return [
            'campaign.name' => $campaign->name,
            'customer.id' => (string) $recipient['customer_id'],
            'customer.first_name' => $first,
            'customer.last_name' => $last,
            'customer.full_name' => $full !== '' ? $full : 'Customer',
            'scheduled_for' => $scheduledLocal->format('Y-m-d H:i'),
            'scheduled_for_utc' => $scheduledUtc->format('Y-m-d H:i:s'),
        ];
    }

    private function timezoneFromRecipient(array $recipient): DateTimeZone
    {
        try {
            return new DateTimeZone($recipient['timezone'] ?? 'UTC');
        } catch (Throwable $exception) {
            return new DateTimeZone('UTC');
        }
    }

    private function renderBody(?string $template, array $context, string $fallback): string
    {
        if ($template === null) {
            return $fallback;
        }

        return $this->templates->render($template, $context);
    }

    private function markRun(ReminderCampaign $campaign, int $actorId): void
    {
        $nextRun = $this->nextRunDate($campaign);

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

    private function nextRunDate(ReminderCampaign $campaign): ?DateTimeImmutable
    {
        $interval = max(1, $campaign->frequency_interval ?? 1);
        $start = $campaign->next_run_at !== null ? new DateTimeImmutable($campaign->next_run_at) : new DateTimeImmutable();

        return match ($campaign->frequency_unit) {
            'week' => $start->add(new DateInterval('P' . $interval . 'W')),
            'month' => $start->add(new DateInterval('P' . $interval . 'M')),
            default => $start->add(new DateInterval('P' . $interval . 'D')),
        };
    }
}
