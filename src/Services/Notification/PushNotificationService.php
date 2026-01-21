<?php

namespace App\Services\Notification;

use App\Database\Connection;

class PushNotificationService
{
    private const DEFAULT_ENDPOINT = 'https://fcm.googleapis.com/fcm/send';
    private const TOKEN_CHUNK_SIZE = 1000;

    private Connection $connection;
    private ?string $serverKey;
    private string $endpoint;

    public function __construct(Connection $connection, ?string $serverKey = null, ?string $endpoint = null)
    {
        $this->connection = $connection;
        $this->serverKey = $serverKey ?? env('FCM_SERVER_KEY');
        $this->endpoint = $endpoint ?? self::DEFAULT_ENDPOINT;
    }

    /**
     * @param array<string, mixed> $offer
     */
    public function sendJobOfferNotification(int $driverProfileId, array $offer): void
    {
        $title = 'New Job Offer';
        $body = 'You have a new job offer ready to review.';

        $this->sendToDriverProfiles([
            $driverProfileId,
        ], $title, $body, [
            'type' => 'job_offer',
            'offer_id' => (string) ($offer['id'] ?? ''),
            'job_reference' => (string) ($offer['job_reference'] ?? ''),
            'job_type' => (string) ($offer['job_type'] ?? ''),
        ]);
    }

    public function sendWorkorderAssignedNotification(int $technicianId, int $workorderId, string $workorderNumber): void
    {
        $driverProfileId = $this->resolveDriverProfileIdByUser($technicianId);
        if ($driverProfileId === null) {
            return;
        }

        $title = 'Work Order Assigned';
        $body = sprintf('Work order #%s has been assigned to you.', $workorderNumber);

        $this->sendToDriverProfiles([
            $driverProfileId,
        ], $title, $body, [
            'type' => 'workorder_assigned',
            'workorder_id' => (string) $workorderId,
            'workorder_number' => (string) $workorderNumber,
        ]);
    }

    /**
     * @param array<int, int> $recipientIds
     */
    public function sendChatMessageNotification(array $recipientIds, int $threadId, int $senderId, string $senderName, string $message): void
    {
        $driverProfileIds = $this->resolveDriverProfileIdsByUsers($recipientIds);
        if ($driverProfileIds === []) {
            return;
        }

        $title = $senderName !== '' ? sprintf('Message from %s', $senderName) : 'New Message';
        $body = $message !== '' ? $message : 'New message received.';

        $this->sendToDriverProfiles($driverProfileIds, $title, $body, [
            'type' => 'chat_message',
            'thread_id' => (string) $threadId,
            'sender_id' => (string) $senderId,
            'sender_name' => $senderName,
            'message' => $message,
        ]);
    }

    /**
     * @param array<int, int> $driverProfileIds
     * @param array<string, mixed> $data
     */
    private function sendToDriverProfiles(array $driverProfileIds, string $title, string $body, array $data = []): void
    {
        if ($driverProfileIds === []) {
            return;
        }

        $tokens = $this->fetchTokensByDriverProfiles($driverProfileIds);
        if ($tokens === []) {
            return;
        }

        foreach (array_chunk($tokens, self::TOKEN_CHUNK_SIZE) as $chunk) {
            $this->sendToTokens($chunk, $title, $body, $data);
        }
    }

    /**
     * @param array<int, int> $driverProfileIds
     * @return array<int, string>
     */
    private function fetchTokensByDriverProfiles(array $driverProfileIds): array
    {
        $placeholders = implode(',', array_fill(0, count($driverProfileIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT token FROM driver_push_tokens WHERE driver_profile_id IN (' . $placeholders . ')'
        );
        $stmt->execute($driverProfileIds);

        $tokens = array_map('strval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
        $tokens = array_values(array_unique(array_filter($tokens)));

        return $tokens;
    }

    /**
     * @param array<int, int> $userIds
     * @return array<int, int>
     */
    private function resolveDriverProfileIdsByUsers(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM driver_profiles WHERE user_id IN (' . $placeholders . ')'
        );
        $stmt->execute($userIds);

        return array_map('intval', $stmt->fetchAll(\PDO::FETCH_COLUMN));
    }

    private function resolveDriverProfileIdByUser(int $userId): ?int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id FROM driver_profiles WHERE user_id = :user_id');
        $stmt->execute(['user_id' => $userId]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (int) $value : null;
    }

    /**
     * @param array<int, string> $tokens
     * @param array<string, mixed> $data
     */
    private function sendToTokens(array $tokens, string $title, string $body, array $data = []): void
    {
        if ($tokens === [] || !$this->serverKey) {
            return;
        }

        $payload = [
            'registration_ids' => array_values($tokens),
            'priority' => 'high',
            'notification' => [
                'title' => $title,
                'body' => $body,
            ],
            'data' => $data,
        ];

        try {
            $bodyPayload = json_encode($payload, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            error_log('FCM payload error: ' . $exception->getMessage());
            return;
        }

        $ch = curl_init($this->endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 3,
            CURLOPT_HTTPHEADER => [
                'Authorization: key=' . $this->serverKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => $bodyPayload,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            $message = $error ?: ('HTTP ' . $httpCode);
            error_log('FCM push failed: ' . $message);
        }
    }
}
