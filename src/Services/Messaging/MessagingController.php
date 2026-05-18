<?php

namespace App\Services\Messaging;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use App\Support\Realtime\PusherBroadcaster;
use InvalidArgumentException;

class MessagingController
{
    private MessagingService $service;
    private AccessGate $gate;
    private PusherBroadcaster $realtime;

    public function __construct(MessagingService $service, AccessGate $gate, ?PusherBroadcaster $realtime = null)
    {
        $this->service = $service;
        $this->gate = $gate;
        $this->realtime = $realtime ?? new PusherBroadcaster();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function threads(User $user): array
    {
        $this->assertInternalUser($user);

        return $this->service->listThreads($user->id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messages(User $user, int $threadId): array
    {
        $this->assertInternalUser($user);

        return $this->service->listMessages($threadId, $user->id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createThread(User $user, array $payload): array
    {
        $this->assertInternalUser($user);

        $participants = $payload['participant_ids'] ?? null;
        if (!is_array($participants) || $participants === []) {
            throw new InvalidArgumentException('participant_ids is required');
        }

        $subject = isset($payload['subject']) ? (string) $payload['subject'] : null;
        $initialMessage = isset($payload['message']) ? (string) $payload['message'] : null;

        return $this->service->createThread($user->id, $participants, $subject, $initialMessage);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function postMessage(User $user, int $threadId, array $payload): array
    {
        $this->assertInternalUser($user);

        if (!isset($payload['body'])) {
            throw new InvalidArgumentException('body is required');
        }

        return $this->service->postMessage($threadId, $user->id, (string) $payload['body']);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $files
     * @return array<string, mixed>
     */
    public function postMessageWithAttachments(User $user, int $threadId, array $payload, array $files): array
    {
        $this->assertInternalUser($user);

        $body = isset($payload['body']) ? (string) $payload['body'] : null;
        $attachments = $this->storeAttachments($threadId, $files);

        return $this->service->postMessageWithAttachments($threadId, $user->id, $body, $attachments);
    }

    /**
     * @return array<string, mixed>
     */
    public function markRead(User $user, int $threadId): array
    {
        $this->assertInternalUser($user);

        $this->service->markRead($threadId, $user->id);

        return [
            'status' => 'ok',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function unreadCounts(User $user): array
    {
        $this->assertInternalUser($user);

        return $this->service->unreadCounts($user->id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{auth: string}
     */
    public function realtimeAuth(User $user, array $payload): array
    {
        $this->assertInternalUser($user);

        $socketId = trim((string) ($payload['socket_id'] ?? ''));
        $channelName = trim((string) ($payload['channel_name'] ?? ''));
        $expectedChannel = 'private-messages-user-' . (int) $user->id;

        if ($socketId === '' || $channelName === '') {
            throw new InvalidArgumentException('socket_id and channel_name are required');
        }

        if ($channelName !== $expectedChannel) {
            throw new UnauthorizedException('Cannot subscribe to this messaging channel');
        }

        $auth = $this->realtime->authenticatePrivateChannel($socketId, $channelName);
        if ($auth === null) {
            throw new InvalidArgumentException('Realtime messaging is not configured.');
        }

        return $auth;
    }

    /**
     * @return array<string, mixed>
     */
    public function threadState(User $user, int $threadId): array
    {
        $this->assertInternalUser($user);

        return $this->service->threadState($threadId, $user->id);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function participants(User $user, array $filters = []): array
    {
        $this->assertInternalUser($user);

        $query = isset($filters['query']) ? (string) $filters['query'] : null;

        return $this->service->listAvailableParticipants($user->id, $query);
    }

    /**
     * @param array<string, mixed> $files
     * @return array<int, array<string, mixed>>
     */
    private function storeAttachments(int $threadId, array $files): array
    {
        $normalizedFiles = $this->normalizeFiles($files);
        if ($normalizedFiles === []) {
            return [];
        }

        $allowed = ['pdf', 'png', 'jpg', 'jpeg', 'gif'];
        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/messages';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $attachments = [];
        foreach ($normalizedFiles as $file) {
            if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
                continue;
            }

            $extension = strtolower(pathinfo((string) ($file['name'] ?? 'upload'), PATHINFO_EXTENSION));
            if (!in_array($extension, $allowed, true)) {
                continue;
            }

            $filename = sprintf('thread_%d_%s.%s', $threadId, uniqid(), $extension);
            $destination = $uploadDir . '/' . $filename;

            if (!move_uploaded_file($file['tmp_name'], $destination)) {
                continue;
            }

            $attachments[] = [
                'file_name' => (string) ($file['name'] ?? $filename),
                'file_path' => '/uploads/messages/' . $filename,
                'mime_type' => (string) ($file['type'] ?? 'application/octet-stream'),
                'size_bytes' => isset($file['size']) ? (int) $file['size'] : null,
            ];
        }

        return $attachments;
    }

    /**
     * @param array<string, mixed> $files
     * @return array<int, array<string, mixed>>
     */
    private function normalizeFiles(array $files): array
    {
        if ($files === []) {
            return [];
        }

        if (isset($files['tmp_name']) && !is_array($files['tmp_name'])) {
            return [$files];
        }

        $normalized = [];
        $fileCount = isset($files['tmp_name']) && is_array($files['tmp_name']) ? count($files['tmp_name']) : 0;
        for ($i = 0; $i < $fileCount; $i++) {
            $normalized[] = [
                'name' => $files['name'][$i] ?? null,
                'type' => $files['type'][$i] ?? null,
                'tmp_name' => $files['tmp_name'][$i] ?? null,
                'error' => $files['error'][$i] ?? null,
                'size' => $files['size'][$i] ?? null,
            ];
        }

        return $normalized;
    }

    private function assertInternalUser(User $user): void
    {
        $role = strtolower((string) $user->role);
        if ($role === 'customer' || $role === 'portal_user') {
            throw new UnauthorizedException('Cannot access internal messages');
        }
    }
}
