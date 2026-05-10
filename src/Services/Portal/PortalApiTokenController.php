<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;

class PortalApiTokenController
{
    public function __construct(private readonly PortalApiTokenService $service)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAccount(User $user, PortalAccount $account): array
    {
        return $this->service->listForAccount($user, $account);
    }

    /**
     * Returns the persisted row plus the plaintext token (only place it
     * is ever exposed). Caller is responsible for showing it to the user
     * once and discarding it.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function issue(User $user, PortalAccount $account, array $body): array
    {
        $issued = $this->service->issue($user, $account, $body);
        return array_merge(
            $this->service->serialize($issued['token']),
            ['plaintext_token' => $issued['plaintext']],
        );
    }

    public function revoke(User $user, PortalAccount $account, int $tokenId): void
    {
        $this->service->revoke($user, $account, $tokenId);
    }
}
