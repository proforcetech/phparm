<?php

namespace App\Services\Messaging;

use App\Database\Connection;
use App\Services\Notification\PushNotificationService;
use App\Support\Auth\UnauthorizedException;
use App\Support\Realtime\PusherBroadcaster;
use InvalidArgumentException;
use PDO;

class MessagingService
{
    private Connection $connection;
    private ?PushNotificationService $pushNotifications;
    private PusherBroadcaster $realtime;

    public function __construct(
        Connection $connection,
        ?PushNotificationService $pushNotifications = null,
        ?PusherBroadcaster $realtime = null
    )
    {
        $this->connection = $connection;
        $this->pushNotifications = $pushNotifications;
        $this->realtime = $realtime ?? new PusherBroadcaster();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listThreads(int $participantId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT t.id, t.subject, t.ticket_id, t.workorder_id, t.created_by, t.created_at, t.updated_at,
                mnt.scope_type, mnt.scope_id,
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
            LEFT JOIN message_notification_threads mnt ON mnt.thread_id = t.id
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
                    u.name, u.email
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

        return $this->hydrateMessages($threadId, $messages);
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
        $this->assertStaffParticipants($normalizedParticipants);

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

        $thread = $this->getThread($threadId, $creatorId);
        if ($initialMessage !== null && $initialMessage !== '') {
            $message = $this->latestMessageForThread($threadId);
            if ($message !== []) {
                $this->broadcastMessageCreated($threadId, $message);
            }
        }

        return $thread;
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

        $message = $this->latestMessageForThread($threadId);

        if ($sendPush) {
            $this->notifyChatParticipants($threadId, $senderId, $body);
        }

        $this->broadcastMessageCreated($threadId, $message);

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
                    u.name, u.email
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

        $message = $this->latestMessageForThread($threadId);

        if ($sendPush) {
            $this->notifyChatParticipants($threadId, $senderId, $messageBody);
        }

        if ($message !== []) {
            $this->broadcastMessageCreated($threadId, $message);
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
            $this->broadcastReadState($threadId, $participantId);
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

        $this->broadcastReadState($threadId, $participantId);
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
     * @return array<int, array<string, mixed>>
     */
    public function listAvailableParticipants(int $requesterId, ?string $query = null): array
    {
        $where = [
            'id != :requester_id',
            'active = 1',
            "role NOT IN ('customer', 'portal_user')",
        ];
        $params = [
            'requester_id' => $requesterId,
        ];

        $query = trim((string) $query);
        if ($query !== '') {
            $where[] = '(LOWER(name) LIKE :query OR LOWER(email) LIKE :query OR LOWER(role) LIKE :query)';
            $params['query'] = '%' . strtolower($query) . '%';
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, email, role
             FROM users
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY name ASC, email ASC
             LIMIT 50'
        );
        $stmt->execute($params);

        return array_map(
            static fn ($row) => [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?: $row['email']),
                'email' => (string) $row['email'],
                'role' => (string) $row['role'],
            ],
            $stmt->fetchAll(PDO::FETCH_ASSOC)
        );
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
            'SELECT p.thread_id, u.id, u.name, u.email, u.role
             FROM message_participants p
             JOIN users u ON u.id = p.participant_id
             WHERE p.thread_id IN (' . $placeholders . ')
             ORDER BY u.name ASC, u.email ASC'
        );
        $stmt->execute($threadIds);

        $participants = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $threadId = (int) $row['thread_id'];
            $participants[$threadId][] = [
                'id' => (int) $row['id'],
                'name' => (string) ($row['name'] ?: $row['email']),
                'email' => (string) $row['email'],
                'role' => $row['role'],
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

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    private function hydrateMessages(int $threadId, array $messages): array
    {
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
                        'name' => $participant['name'] ?? $participant['email'] ?? 'Staff',
                    ];
                }
            }

            $message['id'] = $messageId;
            $message['thread_id'] = (int) $message['thread_id'];
            $message['sender_id'] = $senderId;
            $message['attachments'] = $attachmentsByMessage[$messageId] ?? [];
            $message['recipient_count'] = $recipientCount;
            $message['read_count'] = count($readBy);
            $message['read_by'] = $readBy;
        }
        unset($message);

        return $messages;
    }

    /**
     * @return array<string, mixed>
     */
    private function latestMessageForThread(int $threadId): array
    {
        $messageStmt = $this->connection->pdo()->prepare(
            'SELECT m.id, m.thread_id, m.sender_id, m.body, m.created_at,
                    u.name, u.email
             FROM message_messages m
             JOIN users u ON u.id = m.sender_id
             WHERE m.thread_id = :thread_id
             ORDER BY m.created_at DESC, m.id DESC
             LIMIT 1'
        );
        $messageStmt->execute(['thread_id' => $threadId]);
        $message = $messageStmt->fetch(PDO::FETCH_ASSOC);
        if (!$message) {
            return [];
        }

        return $this->hydrateMessages($threadId, [$message])[0] ?? [];
    }

    /**
     * @param array<string, mixed> $message
     */
    private function broadcastMessageCreated(int $threadId, array $message): void
    {
        if ($message === [] || !$this->realtime->isConfigured()) {
            return;
        }

        $participants = $this->participantsForThreads([$threadId])[$threadId] ?? [];
        foreach ($participants as $participant) {
            $participantId = (int) $participant['id'];
            $this->realtime->trigger(
                [$this->userChannel($participantId)],
                'message.created',
                [
                    'thread_id' => $threadId,
                    'thread' => $this->getThread($threadId, $participantId),
                    'message' => $message,
                    'unread' => $this->unreadCounts($participantId),
                ]
            );
        }
    }

    private function broadcastReadState(int $threadId, int $participantId): void
    {
        if (!$this->realtime->isConfigured()) {
            return;
        }

        $participants = $this->participantsForThreads([$threadId])[$threadId] ?? [];
        foreach ($participants as $participant) {
            $recipientId = (int) $participant['id'];
            $this->realtime->trigger(
                [$this->userChannel($recipientId)],
                'message.read',
                [
                    'thread_id' => $threadId,
                    'participant_id' => $participantId,
                    'state' => $this->threadState($threadId, $recipientId),
                    'unread' => $this->unreadCounts($recipientId),
                ]
            );
        }
    }

    private function userChannel(int $userId): string
    {
        return 'private-messages-user-' . $userId;
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
            'SELECT name, email FROM users WHERE id = :id'
        );
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return 'Someone';
        }

        $name = trim((string) ($row['name'] ?? ''));
        if ($name === '') {
            $name = trim((string) ($row['email'] ?? ''));
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

    /**
     * @param array<int, int> $participantIds
     */
    private function assertStaffParticipants(array $participantIds): void
    {
        $participantIds = array_values(array_unique(array_filter(array_map('intval', $participantIds))));
        if ($participantIds === []) {
            throw new InvalidArgumentException('At least one participant is required');
        }

        $placeholders = implode(',', array_fill(0, count($participantIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT id
             FROM users
             WHERE active = 1
               AND role NOT IN ('customer', 'portal_user')
               AND id IN (" . $placeholders . ')'
        );
        $stmt->execute($participantIds);
        $allowed = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        $missing = array_diff($participantIds, $allowed);

        if ($missing !== []) {
            throw new InvalidArgumentException('All message participants must be active internal users.');
        }
    }
}
