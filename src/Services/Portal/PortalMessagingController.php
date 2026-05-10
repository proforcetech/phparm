<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 6.5 of docs/expansion-plan.md — HTTP facade for the portal
 * messaging surface. Mirrors the Phase 6.3/6.4 controller style: one
 * line per endpoint, with the controller trimming request strings and
 * enforcing "body required" where applicable.
 */
class PortalMessagingController
{
    public function __construct(
        private readonly PortalMessagingService $service,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listThreadsForTicket(User $user, PortalAccount $account, int $ticketId): array
    {
        return ['data' => $this->service->listThreadsForTicket($user, $account, $ticketId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listThreadsForWorkorder(User $user, PortalAccount $account, int $workorderId): array
    {
        return ['data' => $this->service->listThreadsForWorkorder($user, $account, $workorderId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listMessages(User $user, PortalAccount $account, int $threadId): array
    {
        return ['data' => $this->service->listMessages($user, $account, $threadId)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function startThreadForTicket(
        User $user,
        PortalAccount $account,
        int $ticketId,
        array $body,
    ): array {
        $messageBody = $this->requireBody($body);
        $subject = $this->optionalString($body, 'subject');
        return ['data' => $this->service->startThreadForTicket(
            $user, $account, $ticketId, $messageBody, $subject,
        )];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function startThreadForWorkorder(
        User $user,
        PortalAccount $account,
        int $workorderId,
        array $body,
    ): array {
        $messageBody = $this->requireBody($body);
        $subject = $this->optionalString($body, 'subject');
        return ['data' => $this->service->startThreadForWorkorder(
            $user, $account, $workorderId, $messageBody, $subject,
        )];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function postMessage(
        User $user,
        PortalAccount $account,
        int $threadId,
        array $body,
    ): array {
        $messageBody = $this->requireBody($body);
        return ['data' => $this->service->postMessage($user, $account, $threadId, $messageBody)];
    }

    public function markRead(User $user, PortalAccount $account, int $threadId): void
    {
        $this->service->markRead($user, $account, $threadId);
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function inbox(User $user, PortalAccount $account, array $query): array
    {
        return $this->service->inbox($user, $account, [
            'limit' => isset($query['limit']) ? (int) $query['limit'] : 50,
            'offset' => isset($query['offset']) ? (int) $query['offset'] : 0,
            'unread_only' => ((string) ($query['unread_only'] ?? '')) === '1',
        ]);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function requireBody(array $body): string
    {
        $text = trim((string) ($body['body'] ?? ''));
        if ($text === '') {
            throw new InvalidArgumentException('body is required');
        }
        return $text;
    }

    /**
     * @param array<string, mixed> $body
     */
    private function optionalString(array $body, string $key): ?string
    {
        if (!isset($body[$key]) || !is_string($body[$key])) {
            return null;
        }
        $v = trim($body[$key]);
        return $v === '' ? null : $v;
    }
}
