<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 6.3 of docs/expansion-plan.md — HTTP facade for the portal
 * approval center. Route handlers stay trivially thin; the service owns
 * scoping, state transitions, and audit.
 */
class PortalApprovalController
{
    public function __construct(
        private readonly PortalApprovalService $approvals,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listPending(User $user, PortalAccount $account): array
    {
        return ['data' => $this->approvals->listPending($user, $account)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function approveEstimate(
        User $user,
        PortalAccount $account,
        int $estimateId,
        array $body,
    ): array {
        $note = $this->stringOrNull($body['note'] ?? null);
        $estimate = $this->approvals->approveEstimate($user, $account, $estimateId, $note);
        return ['data' => $estimate->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function rejectEstimate(
        User $user,
        PortalAccount $account,
        int $estimateId,
        array $body,
    ): array {
        $reason = $this->stringOrNull($body['reason'] ?? null);
        if ($reason === null) {
            throw new InvalidArgumentException('reason is required');
        }
        $estimate = $this->approvals->rejectEstimate($user, $account, $estimateId, $reason);
        return ['data' => $estimate->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function approveContract(
        User $user,
        PortalAccount $account,
        int $contractId,
        array $body,
        ?string $ipAddress,
        ?string $userAgent,
    ): array {
        $note = $this->stringOrNull($body['note'] ?? null);
        $contract = $this->approvals->approveContract(
            $user,
            $account,
            $contractId,
            $note,
            $ipAddress,
            $userAgent,
        );
        return ['data' => $contract->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function rejectContract(
        User $user,
        PortalAccount $account,
        int $contractId,
        array $body,
    ): array {
        $reason = $this->stringOrNull($body['reason'] ?? null);
        if ($reason === null) {
            throw new InvalidArgumentException('reason is required');
        }
        $contract = $this->approvals->rejectContract($user, $account, $contractId, $reason);
        return ['data' => $contract->toArray()];
    }

    private function stringOrNull(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
