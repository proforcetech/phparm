<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;
use InvalidArgumentException;

/**
 * HTTP edge for Phase 2f CSAT. Mirrors the thin-controller convention used
 * across the portal: validate basic shape, hand off to the service.
 */
class PortalCsatController
{
    public function __construct(private readonly PortalCsatService $service)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPending(User $user, PortalAccount $account): array
    {
        return $this->service->listPending($user, $account);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listHistory(User $user, PortalAccount $account): array
    {
        return $this->service->listHistory($user, $account);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function submit(User $user, PortalAccount $account, int $workorderId, array $body): array
    {
        $rating = isset($body['rating']) ? (int) $body['rating'] : 0;
        $comment = isset($body['comment']) && is_string($body['comment'])
            ? $body['comment']
            : null;
        $row = $this->service->submit($user, $account, $workorderId, $rating, $comment);
        return $this->service->serialize($row);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function submitPublic(string $token, array $body): array
    {
        if ($token === '') {
            throw new InvalidArgumentException('token is required');
        }
        $rating = isset($body['rating']) ? (int) $body['rating'] : 0;
        $comment = isset($body['comment']) && is_string($body['comment'])
            ? $body['comment']
            : null;
        $row = $this->service->submitByPublicToken($token, $rating, $comment);
        return $this->service->serialize($row);
    }
}
