<?php

namespace App\Services\Messaging;

use App\Database\Connection;
use InvalidArgumentException;
use PDO;

class MaskedSmsService
{
    private Connection $connection;
    private MaskedSmsGateway $gateway;
    private ?string $defaultMaskedNumber;

    public function __construct(Connection $connection, MaskedSmsGateway $gateway, ?string $defaultMaskedNumber = null)
    {
        $this->connection = $connection;
        $this->gateway = $gateway;
        $this->defaultMaskedNumber = $defaultMaskedNumber;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createOrFetchSession(array $payload): array
    {
        $jobReference = (string) ($payload['job_reference'] ?? '');
        if ($jobReference === '') {
            throw new InvalidArgumentException('job_reference is required');
        }

        $jobType = (string) ($payload['job_type'] ?? 'workorder');
        $driverUserId = (int) ($payload['driver_user_id'] ?? 0);
        $customerId = (int) ($payload['customer_id'] ?? 0);
        if ($driverUserId <= 0 || $customerId <= 0) {
            throw new InvalidArgumentException('driver_user_id and customer_id are required');
        }

        $driverPhone = $this->normalizePhone((string) ($payload['driver_phone'] ?? ''));
        $customerPhone = $this->normalizePhone((string) ($payload['customer_phone'] ?? ''));
        if ($driverPhone === '' || $customerPhone === '') {
            throw new InvalidArgumentException('driver_phone and customer_phone are required');
        }

        $maskedNumber = $this->normalizePhone((string) ($payload['masked_number'] ?? $this->defaultMaskedNumber ?? ''));
        if ($maskedNumber === '') {
            throw new InvalidArgumentException('masked_number is required');
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM masked_sms_sessions
             WHERE job_reference = :job_reference
               AND job_type = :job_type
               AND driver_user_id = :driver_user_id
               AND customer_id = :customer_id
             LIMIT 1'
        );
        $stmt->execute([
            'job_reference' => $jobReference,
            'job_type' => $jobType,
            'driver_user_id' => $driverUserId,
            'customer_id' => $customerId,
        ]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            return $existing;
        }

        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO masked_sms_sessions
                (job_reference, job_type, driver_user_id, customer_id, driver_phone, customer_phone, masked_number, status, created_at)
             VALUES
                (:job_reference, :job_type, :driver_user_id, :customer_id, :driver_phone, :customer_phone, :masked_number, :status, NOW())'
        );

        $insert->execute([
            'job_reference' => $jobReference,
            'job_type' => $jobType,
            'driver_user_id' => $driverUserId,
            'customer_id' => $customerId,
            'driver_phone' => $driverPhone,
            'customer_phone' => $customerPhone,
            'masked_number' => $maskedNumber,
            'status' => 'active',
        ]);

        $sessionId = (int) $this->connection->pdo()->lastInsertId();

        return $this->getSession($sessionId);
    }

    /**
     * @return array<string, mixed>
     */
    public function sendMessage(int $sessionId, string $senderRole, string $body): array
    {
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('message body is required');
        }

        $session = $this->getSession($sessionId);
        if ($session === []) {
            throw new InvalidArgumentException('Masked SMS session not found');
        }

        if (($session['status'] ?? '') !== 'active') {
            throw new InvalidArgumentException('Masked SMS session is not active');
        }

        $fromNumber = $session['masked_number'];
        $toNumber = $this->resolveRecipientNumber($session, $senderRole);

        $this->gateway->send($toNumber, $body, $fromNumber);

        return $this->storeMessage($sessionId, 'outbound', $senderRole, $fromNumber, $toNumber, $body, null);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function receiveInbound(array $payload): array
    {
        $fromNumber = $this->normalizePhone((string) ($payload['from'] ?? $payload['From'] ?? ''));
        $toNumber = $this->normalizePhone((string) ($payload['to'] ?? $payload['To'] ?? ''));
        $body = trim((string) ($payload['body'] ?? $payload['Body'] ?? ''));
        $providerMessageId = $payload['message_id'] ?? $payload['MessageSid'] ?? null;

        if ($fromNumber === '' || $toNumber === '' || $body === '') {
            throw new InvalidArgumentException('from, to, and body are required');
        }

        $session = $this->findSessionByMaskedNumber($toNumber);
        if ($session === []) {
            throw new InvalidArgumentException('Masked SMS session not found');
        }

        $senderRole = $this->resolveSenderRole($session, $fromNumber);
        $recipient = $this->resolveRecipientNumber($session, $senderRole);

        $this->gateway->send($recipient, $body, $toNumber);

        return $this->storeMessage((int) $session['id'], 'inbound', $senderRole, $fromNumber, $recipient, $body, $providerMessageId);
    }

    /**
     * @return array<string, mixed>
     */
    private function getSession(int $sessionId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM masked_sms_sessions WHERE id = :id');
        $stmt->execute(['id' => $sessionId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array<string, mixed>
     */
    private function findSessionByMaskedNumber(string $maskedNumber): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM masked_sms_sessions WHERE masked_number = :masked_number AND status = :status ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute([
            'masked_number' => $maskedNumber,
            'status' => 'active',
        ]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @param array<string, mixed> $session
     */
    private function resolveSenderRole(array $session, string $fromNumber): string
    {
        if ($fromNumber === $session['driver_phone']) {
            return 'driver';
        }

        if ($fromNumber === $session['customer_phone']) {
            return 'customer';
        }

        throw new InvalidArgumentException('Sender phone does not match session');
    }

    /**
     * @param array<string, mixed> $session
     */
    private function resolveRecipientNumber(array $session, string $senderRole): string
    {
        return match ($senderRole) {
            'driver' => $session['customer_phone'],
            'customer' => $session['driver_phone'],
            default => $session['driver_phone'],
        };
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^\d+]/', '', $phone) ?? '';
    }

    /**
     * @return array<string, mixed>
     */
    private function storeMessage(
        int $sessionId,
        string $direction,
        string $senderRole,
        string $fromNumber,
        string $toNumber,
        string $body,
        ?string $providerMessageId
    ): array {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO masked_sms_messages
                (session_id, direction, sender_role, from_number, to_number, body, provider_message_id, created_at)
             VALUES
                (:session_id, :direction, :sender_role, :from_number, :to_number, :body, :provider_message_id, NOW())'
        );

        $stmt->execute([
            'session_id' => $sessionId,
            'direction' => $direction,
            'sender_role' => $senderRole,
            'from_number' => $fromNumber,
            'to_number' => $toNumber,
            'body' => $body,
            'provider_message_id' => $providerMessageId,
        ]);

        $messageId = (int) $this->connection->pdo()->lastInsertId();
        $fetch = $this->connection->pdo()->prepare('SELECT * FROM masked_sms_messages WHERE id = :id');
        $fetch->execute(['id' => $messageId]);
        return $fetch->fetch(PDO::FETCH_ASSOC) ?: [];
    }
}
