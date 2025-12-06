<?php

namespace App\Services\Reminder;

use App\Database\Connection;
use App\Models\ReminderPreference;
use DateTimeImmutable;
use PDO;

class ReminderPreferenceService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function findByCustomer(int $customerId): ?ReminderPreference
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM reminder_preferences WHERE customer_id = :customer_id');
        $stmt->execute(['customer_id' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['is_active'] = (bool) $row['is_active'];

        return new ReminderPreference($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function upsertForCustomer(int $customerId, array $payload): ReminderPreference
    {
        $timezone = $payload['timezone'] ?? 'UTC';
        $preferredChannel = $this->normalizeChannel((string) ($payload['preferred_channel'] ?? 'both'));
        $leadDays = isset($payload['lead_days']) ? max(0, (int) $payload['lead_days']) : 3;
        $preferredHour = isset($payload['preferred_hour']) ? max(0, min(23, (int) $payload['preferred_hour'])) : 9;
        $isActive = isset($payload['is_active']) ? (bool) $payload['is_active'] : false;

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO reminder_preferences (customer_id, email, phone, timezone, preferred_channel, lead_days, preferred_hour, is_active, source, created_at, updated_at)
             VALUES (:customer_id, :email, :phone, :timezone, :preferred_channel, :lead_days, :preferred_hour, :is_active, :source, :created_at, :updated_at)
             ON DUPLICATE KEY UPDATE email = VALUES(email), phone = VALUES(phone), timezone = VALUES(timezone), preferred_channel = VALUES(preferred_channel),
                 lead_days = VALUES(lead_days), preferred_hour = VALUES(preferred_hour), is_active = VALUES(is_active), source = VALUES(source), updated_at = VALUES(updated_at)'
        );

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        $stmt->execute([
            'customer_id' => $customerId,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'timezone' => $timezone,
            'preferred_channel' => $preferredChannel,
            'lead_days' => $leadDays,
            'preferred_hour' => $preferredHour,
            'is_active' => $isActive ? 1 : 0,
            'source' => $payload['source'] ?? 'customer_portal',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $this->findByCustomer($customerId) ?? new ReminderPreference([
            'customer_id' => $customerId,
            'email' => $payload['email'] ?? null,
            'phone' => $payload['phone'] ?? null,
            'timezone' => $timezone,
            'preferred_channel' => $preferredChannel,
            'lead_days' => $leadDays,
            'preferred_hour' => $preferredHour,
            'is_active' => $isActive,
            'source' => $payload['source'] ?? 'customer_portal',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function isSubscribed(int $customerId, string $channel): bool
    {
        $preference = $this->findByCustomer($customerId);

        if ($preference === null || $preference->is_active === false || $preference->preferred_channel === 'none') {
            return false;
        }

        $preferredChannel = $this->normalizeChannel($preference->preferred_channel);

        if ($preferredChannel === 'both') {
            return true;
        }

        $requestedChannel = $this->normalizeChannel($channel);

        return $preferredChannel === $requestedChannel;
    }

    public function setPreference(int $customerId, string $channel, bool $enabled): void
    {
        $existing = $this->findByCustomer($customerId);
        $currentChannel = $existing?->preferred_channel ?? 'none';

        $requested = $this->normalizeChannel($channel);
        $nextChannel = $currentChannel;

        if ($enabled) {
            if ($currentChannel === 'none') {
                $nextChannel = $requested;
            } elseif ($currentChannel !== $requested) {
                $nextChannel = 'both';
            }
        } else {
            if ($currentChannel === 'both') {
                $nextChannel = $requested === 'mail' ? 'sms' : 'mail';
            } elseif ($currentChannel === $requested) {
                $nextChannel = 'none';
            }
        }

        $this->upsertForCustomer($customerId, [
            'email' => $existing?->email,
            'phone' => $existing?->phone,
            'timezone' => $existing?->timezone ?? 'UTC',
            'preferred_channel' => $nextChannel,
            'lead_days' => $existing?->lead_days ?? 3,
            'preferred_hour' => $existing?->preferred_hour ?? 9,
            'is_active' => $enabled ? ($existing?->is_active ?? true) : false,
            'source' => $existing?->source ?? 'customer_portal',
        ]);
    }

    public function unsubscribe(int $customerId, string $channel): void
    {
        $this->setPreference($customerId, $channel, false);
    }

    private function normalizeChannel(string $channel): string
    {
        return match ($channel) {
            'email' => 'mail',
            'sms' => 'sms',
            'both' => 'both',
            'none' => 'none',
            default => $channel === 'mail' ? 'mail' : 'sms',
        };
    }
}
