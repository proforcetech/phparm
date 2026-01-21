<?php

namespace App\Services\Messaging;

use App\Database\Connection;
use App\Services\Notification\PushNotificationService;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use PDO;

class MessagingService
{
    private Connection $connection;
    private ?PushNotificationService $pushNotifications;

    public function __construct(Connection $connection, ?PushNotificationService $pushNotifications = null)
    {
        $this->connection = $connection;
        $this->pushNotifications = $pushNotifications;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listThreads(int $participantId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT t.id, t.subject, t.created_by, t.created_at, t.updated_at,
                (SELECT m.body FROM message_messages m WHERE m.thread_id = t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message,
                (SELECT m.created_at FROM message_messages m WHERE m.thread_id = t.id ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message_at,
                (SELECT COUNT(*)
                    FROM message_messages m
                    LEFT JOIN message_reads r ON r.thread_id = t.id AND r.participant_id = :participant_id
                    WHERE m.thread_id = t.id
                      AND m.sender_id != :participant_id
                      AND (r.last_read_message_id IS NULL OR m.id > r.last_read_message_id)
                ) AS unread_count
            FROM message_threads t
            JOIN message_participants p ON p.thread_id = t.id
            WHERE p.participant_id = :participant_id
            ORDER BY COALESCE(last_message_at, t.created_at) DESC'
        );

        $stmt->execute(['participant_id' => $participantId]);
        $threads = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($threads === []) {
            return [];
        }

        $threadIds = array_map(static fn ($thread) => (int) $thread['id'], $threads);
        $participantsByThread = $this->participantsForThreads($threadIds);

        foreach ($threads as &$thread) {
            $threadId = (int) $thread['id'];
            $thread['participants'] = $participantsByThread[$threadId] ?? [];
        }
        unset($thread);

        return $threads;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMessages(int $threadId, int $participantId): array
    {
        $this->assertParticipant($threadId, $participantId);

        $stmt = $this->connection->pdo()->prepare(
            'SELECT m.id, m.thread_id, m.sender_id, m.body, m.created_at,
                    u.first_name, u.last_name
             FROM message_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.thread_id = :thread_id
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->execute(['thread_id' => $threadId]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if ($messages === []) {
            return [];
        }

        $messageIds = array_map(static fn ($message) => (int) $message['id'], $messages);
        $attachmentsByMessage = $this->attachmentsForMessages($messageIds);
        $participants = $this->participantsForThreads([$threadId])[$threadId] ?? [];
        $readStatus = $this->readStatusForThread($threadId);

        foreach ($messages as &$message) {
            $messageId = (int) $message['id'];
            $senderId = (int) $message['sender_id'];
            $recipientCount = 0;
            $readBy = [];

            foreach ($participants as $participant) {
                if ((int) $participant['id'] === $senderId) {
                    continue;
                }

                $recipientCount++;
                $lastReadMessageId = $readStatus[(int) $participant['id']]['last_read_message_id'] ?? 0;
                if ($lastReadMessageId >= $messageId) {
                    $readBy[] = [
                        'id' => (int) $participant['id'],
                        'name' => $participant['name'] ?? trim(($participant['first_name'] ?? '') . ' ' . ($participant['last_name'] ?? '')),
                    ];
                }
            }

            $message['attachments'] = $attachmentsByMessage[$messageId] ?? [];
            $message['recipient_count'] = $recipientCount;
            $message['read_count'] = count($readBy);
            $message['read_by'] = $readBy;
        }
        unset($message);

        return $messages;
    }

    /**
     * @param array<int, int> $participantIds
     * @return array<string, mixed>
     */
    public function createThread(int $creatorId, array $participantIds, ?string $subject = null, ?string $initialMessage = null): array
    {
        if ($participantIds === []) {
            throw new InvalidArgumentException('At least one participant is required');
        }

        $normalizedParticipants = array_values(array_unique(array_map('intval', $participantIds)));
        if (!in_array($creatorId, $normalizedParticipants, true)) {
            $normalizedParticipants[] = $creatorId;
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        $stmt = $pdo->prepare(
            'INSERT INTO message_threads (subject, created_by, created_at) VALUES (:subject, :created_by, NOW())'
        );
        $stmt->execute([
            'subject' => $subject,
            'created_by' => $creatorId,
        ]);

        $threadId = (int) $pdo->lastInsertId();

        $participantStmt = $pdo->prepare(
            'INSERT INTO message_participants (thread_id, participant_id, created_at) VALUES (:thread_id, :participant_id, NOW())'
        );

        foreach ($normalizedParticipants as $participantId) {
            $participantStmt->execute([
                'thread_id' => $threadId,
                'participant_id' => $participantId,
            ]);
        }

        if ($initialMessage !== null && $initialMessage !== '') {
            $this->insertMessage($threadId, $creatorId, $initialMessage);
        }

        $pdo->commit();

        return $this->getThread($threadId, $creatorId);
    }

    /**
     * @return array<string, mixed>
     */
    public function postMessage(int $threadId, int $senderId, string $body, bool $sendPush = true): array
    {
        if (trim($body) === '') {
            throw new InvalidArgumentException('Message body is required');
        }

        $this->assertParticipant($threadId, $senderId);

        $this->insertMessage($threadId, $senderId, $body);

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE message_threads SET updated_at = NOW() WHERE id = :thread_id'
        );
        $stmt->execute(['thread_id' => $threadId]);

        $messageStmt = $this->connection->pdo()->prepare(
            'SELECT m.id, m.thread_id, m.sender_id, m.body, m.created_at,
                    u.first_name, u.last_name
             FROM message_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.thread_id = :thread_id
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT 1'
        );
        $messageStmt->execute(['thread_id' => $threadId]);

        $message = $messageStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        if ($sendPush) {
            $this->notifyChatParticipants($threadId, $senderId, $body);
        }

        return $message;
    }

    /**
     * @param array<int, array<string, mixed>> $attachments
     * @return array<string, mixed>
     */
    public function postMessageWithAttachments(
        int $threadId,
        int $senderId,
        ?string $body,
        array $attachments,
        bool $sendPush = true
    ): array {
        $body = $body !== null ? trim($body) : '';
        if ($body === '' && $attachments === []) {
            throw new InvalidArgumentException('Message body or attachments are required');
        }

        $this->assertParticipant($threadId, $senderId);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        $messageBody = $body === '' ? '[Attachment]' : $body;
        $this->insertMessage($threadId, $senderId, $messageBody);

        $messageStmt = $pdo->prepare(
            'SELECT m.id, m.thread_id, m.sender_id, m.body, m.created_at,
                    u.first_name, u.last_name
             FROM message_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.thread_id = :thread_id
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT 1'
        );
        $messageStmt->execute(['thread_id' => $threadId]);
        $message = $messageStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $messageId = (int) ($message['id'] ?? 0);

        if ($messageId > 0 && $attachments !== []) {
            $this->storeAttachments($messageId, $attachments);
        }

        $updateStmt = $pdo->prepare('UPDATE message_threads SET updated_at = NOW() WHERE id = :thread_id');
        $updateStmt->execute(['thread_id' => $threadId]);

        $pdo->commit();

        if ($sendPush) {
            $this->notifyChatParticipants($threadId, $senderId, $messageBody);
        }

        return $message;
    }

    public function markRead(int $threadId, int $participantId): void
    {
        $this->assertParticipant($threadId, $participantId);

        $messageStmt = $this->connection->pdo()->prepare(
            'SELECT id, created_at FROM message_messages WHERE thread_id = :thread_id ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $messageStmt->execute(['thread_id' => $threadId]);
        $latest = $messageStmt->fetch(PDO::FETCH_ASSOC);

        $lastReadMessageId = $latest['id'] ?? null;
        $lastReadAt = $latest['created_at'] ?? null;

        $existingStmt = $this->connection->pdo()->prepare(
            'SELECT id FROM message_reads WHERE thread_id = :thread_id AND participant_id = :participant_id'
        );
        $existingStmt->execute([
            'thread_id' => $threadId,
            'participant_id' => $participantId,
        ]);

        $existingId = $existingStmt->fetchColumn();

        if ($existingId) {
            $updateStmt = $this->connection->pdo()->prepare(
                'UPDATE message_reads
                 SET last_read_message_id = :last_read_message_id, last_read_at = :last_read_at, updated_at = NOW()
                 WHERE id = :id'
            );
            $updateStmt->execute([
                'last_read_message_id' => $lastReadMessageId,
                'last_read_at' => $lastReadAt,
                'id' => $existingId,
            ]);
            return;
        }

        $insertStmt = $this->connection->pdo()->prepare(
            'INSERT INTO message_reads (thread_id, participant_id, last_read_message_id, last_read_at, created_at)
             VALUES (:thread_id, :participant_id, :last_read_message_id, :last_read_at, NOW())'
        );
        $insertStmt->execute([
            'thread_id' => $threadId,
            'participant_id' => $participantId,
            'last_read_message_id' => $lastReadMessageId,
            'last_read_at' => $lastReadAt,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function unreadCounts(int $participantId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT t.id AS thread_id,
                SUM(CASE
                    WHEN m.sender_id IS NULL THEN 0
                    WHEN m.sender_id = :participant_id THEN 0
                    WHEN r.last_read_message_id IS NULL THEN 1
                    WHEN m.id > r.last_read_message_id THEN 1
                    ELSE 0
                END) AS unread_count
             FROM message_threads t
             JOIN message_participants p ON p.thread_id = t.id
             LEFT JOIN message_reads r ON r.thread_id = t.id AND r.participant_id = :participant_id
             LEFT JOIN message_messages m ON m.thread_id = t.id
             WHERE p.participant_id = :participant_id
             GROUP BY t.id'
        );

        $stmt->execute(['participant_id' => $participantId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $total = 0;
        $threads = [];
        foreach ($rows as $row) {
            $count = (int) ($row['unread_count'] ?? 0);
            $threads[] = [
                'thread_id' => (int) $row['thread_id'],
                'unread_count' => $count,
            ];
            $total += $count;
        }

        return [
            'total' => $total,
            'threads' => $threads,
        ];
    }

    /**
     * @param array<int, int> $threadIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function participantsForThreads(array $threadIds): array
    {
        if ($threadIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($threadIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT p.thread_id, u.id, u.first_name, u.last_name, u.role
             FROM message_participants p
             JOIN users u ON u.id = p.participant_id
             WHERE p.thread_id IN (' . $placeholders . ')
             ORDER BY u.first_name ASC, u.last_name ASC'
        );
        $stmt->execute($threadIds);

        $participants = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $threadId = (int) $row['thread_id'];
            $participants[$threadId][] = [
                'id' => (int) $row['id'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'role' => $row['role'],
                'name' => trim($row['first_name'] . ' ' . $row['last_name']),
            ];
        }

        return $participants;
    }

    /**
     * @return array<string, mixed>
     */
    private function getThread(int $threadId, int $participantId): array
    {
        $threads = $this->listThreads($participantId);
        foreach ($threads as $thread) {
            if ((int) $thread['id'] === $threadId) {
                return $thread;
            }
        }

        return [
            'id' => $threadId,
            'participants' => $this->participantsForThreads([$threadId])[$threadId] ?? [],
        ];
    }

    private function insertMessage(int $threadId, int $senderId, string $body): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO message_messages (thread_id, sender_id, body, created_at) VALUES (:thread_id, :sender_id, :body, NOW())'
        );
        $stmt->execute([
            'thread_id' => $threadId,
            'sender_id' => $senderId,
            'body' => $body,
        ]);
    }

    private function notifyChatParticipants(int $threadId, int $senderId, string $message): void
    {
        if ($this->pushNotifications === null) {
            return;
        }

        $participants = $this->participantsForThreads([$threadId])[$threadId] ?? [];
        if ($participants === []) {
            return;
        }

        $recipientIds = [];
        $senderName = '';

        foreach ($participants as $participant) {
            $participantId = (int) $participant['id'];
            if ($participantId === $senderId) {
                $senderName = (string) ($participant['name'] ?? '');
                continue;
            }
            $recipientIds[] = $participantId;
        }

        if ($recipientIds === []) {
            return;
        }

        if ($senderName === '') {
            $senderName = $this->resolveUserName($senderId);
        }

        $this->pushNotifications->sendChatMessageNotification(
            $recipientIds,
            $threadId,
            $senderId,
            $senderName,
            $message
        );
    }

    private function resolveUserName(int $userId): string
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT first_name, last_name, name FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return 'Someone';
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? ''));
        }

        return $name !== '' ? $name : 'Someone';
    }

    /**
     * @param array<int, int> $messageIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function attachmentsForMessages(array $messageIds): array
    {
        if ($messageIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($messageIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, message_id, file_name, file_path, mime_type, size_bytes, created_at
             FROM message_attachments
             WHERE message_id IN (' . $placeholders . ')
             ORDER BY id ASC'
        );
        $stmt->execute($messageIds);

        $attachments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $messageId = (int) $row['message_id'];
            $attachments[$messageId][] = [
                'id' => (int) $row['id'],
                'file_name' => (string) $row['file_name'],
                'file_path' => (string) $row['file_path'],
                'mime_type' => (string) $row['mime_type'],
                'size_bytes' => $row['size_bytes'] !== null ? (int) $row['size_bytes'] : null,
                'created_at' => $row['created_at'],
            ];
        }

        return $attachments;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readStatusForThread(int $threadId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT participant_id, last_read_message_id, last_read_at
             FROM message_reads
             WHERE thread_id = :thread_id'
        );
        $stmt->execute(['thread_id' => $threadId]);

        $reads = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $reads[(int) $row['participant_id']] = [
                'last_read_message_id' => $row['last_read_message_id'] !== null ? (int) $row['last_read_message_id'] : 0,
                'last_read_at' => $row['last_read_at'],
            ];
        }

        return $reads;
    }

    /**
     * @param array<int, array<string, mixed>> $attachments
     */
    private function storeAttachments(int $messageId, array $attachments): void
    {
        if ($attachments === []) {
            return;
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO message_attachments (message_id, file_name, file_path, mime_type, size_bytes, created_at)
             VALUES (:message_id, :file_name, :file_path, :mime_type, :size_bytes, NOW())'
        );

        foreach ($attachments as $attachment) {
            $stmt->execute([
                'message_id' => $messageId,
                'file_name' => $attachment['file_name'],
                'file_path' => $attachment['file_path'],
                'mime_type' => $attachment['mime_type'],
                'size_bytes' => $attachment['size_bytes'],
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function threadState(int $threadId, int $participantId): array
    {
        $this->assertParticipant($threadId, $participantId);

        $messageStmt = $this->connection->pdo()->prepare(
            'SELECT id, created_at FROM message_messages WHERE thread_id = :thread_id ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $messageStmt->execute(['thread_id' => $threadId]);
        $message = $messageStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        $readStmt = $this->connection->pdo()->prepare(
            'SELECT MAX(updated_at) AS last_read_update FROM message_reads WHERE thread_id = :thread_id'
        );
        $readStmt->execute(['thread_id' => $threadId]);
        $readUpdate = $readStmt->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'last_message_id' => isset($message['id']) ? (int) $message['id'] : 0,
            'last_message_at' => $message['created_at'] ?? null,
            'last_read_update' => $readUpdate['last_read_update'] ?? null,
        ];
    }

    private function assertParticipant(int $threadId, int $participantId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM message_participants WHERE thread_id = :thread_id AND participant_id = :participant_id'
        );
        $stmt->execute([
            'thread_id' => $threadId,
            'participant_id' => $participantId,
        ]);

        if (!$stmt->fetchColumn()) {
            throw new UnauthorizedException('User is not a participant in this thread');
        }
    }
}
