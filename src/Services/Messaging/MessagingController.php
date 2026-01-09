<?php

namespace App\Services\Messaging;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class MessagingController
{
    private MessagingService $service;
    private AccessGate $gate;

    public function __construct(MessagingService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function threads(User $user): array
    {
        if (!$this->gate->can($user, 'messages.view')) {
            throw new UnauthorizedException('Cannot view messages');
        }

        return $this->service->listThreads($user->id);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function messages(User $user, int $threadId): array
    {
        if (!$this->gate->can($user, 'messages.view')) {
            throw new UnauthorizedException('Cannot view messages');
        }

        return $this->service->listMessages($threadId, $user->id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createThread(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'messages.create')) {
            throw new UnauthorizedException('Cannot create threads');
        }

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
        if (!$this->gate->can($user, 'messages.send')) {
            throw new UnauthorizedException('Cannot send messages');
        }

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
        if (!$this->gate->can($user, 'messages.send')) {
            throw new UnauthorizedException('Cannot send messages');
        }

        $body = isset($payload['body']) ? (string) $payload['body'] : null;
        $attachments = $this->storeAttachments($threadId, $files);

        return $this->service->postMessageWithAttachments($threadId, $user->id, $body, $attachments);
    }

    /**
     * @return array<string, mixed>
     */
    public function markRead(User $user, int $threadId): array
    {
        if (!$this->gate->can($user, 'messages.view')) {
            throw new UnauthorizedException('Cannot update message status');
        }

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
        if (!$this->gate->can($user, 'messages.view')) {
            throw new UnauthorizedException('Cannot view unread counts');
        }

        return $this->service->unreadCounts($user->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function threadState(User $user, int $threadId): array
    {
        if (!$this->gate->can($user, 'messages.view')) {
            throw new UnauthorizedException('Cannot view message status');
        }

        return $this->service->threadState($threadId, $user->id);
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
}
