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
}
