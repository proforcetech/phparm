<?php

namespace App\Services\Messaging;

use App\Database\Connection;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use PDO;

class MessagingService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
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

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    public function postMessage(int $threadId, int $senderId, string $body): array
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

        return $messageStmt->fetch(PDO::FETCH_ASSOC) ?: [];
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
