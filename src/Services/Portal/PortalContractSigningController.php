<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;

class PortalContractSigningController
{
    public function __construct(private readonly PortalContractSigningService $service)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function sign(
        User $user,
        PortalAccount $account,
        int $contractId,
        array $body,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        $signature = $this->service->sign(
            $user,
            $account,
            $contractId,
            $body,
            $ipAddress,
            $userAgent,
        );
        return $signature->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listSignatures(User $user, PortalAccount $account, int $contractId): array
    {
        return array_map(
            static fn($s) => $s->toArray(),
            $this->service->listSignatures($user, $account, $contractId),
        );
    }
}
