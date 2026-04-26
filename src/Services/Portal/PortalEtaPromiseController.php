<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;

/**
 * Phase 6.7 of docs/expansion-plan.md — HTTP facade for portal ETA
 * promises. Staff-write endpoints and portal-read endpoints both route
 * through the same underlying service; the controller just splits by
 * surface so routes stay thin.
 */
class PortalEtaPromiseController
{
    public function __construct(
        private readonly PortalEtaPromiseService $service,
    ) {
    }

    // ── Staff surface (tickets.manage / workorders.manage) ──────────────

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function publishForTicket(User $actor, int $ticketId, array $body): array
    {
        return ['data' => $this->service->publishForTicket($actor, $ticketId, $body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function publishForWorkorder(User $actor, int $workorderId, array $body): array
    {
        return ['data' => $this->service->publishForWorkorder($actor, $workorderId, $body)];
    }

    /**
     * @param array<string, mixed> $body
     */
    public function cancelCurrentForTicket(User $actor, int $ticketId, array $body): void
    {
        $reason = null;
        if (isset($body['reason']) && is_string($body['reason'])) {
            $reason = $body['reason'];
        }
        $this->service->cancelCurrentForTicket($actor, $ticketId, $reason);
    }

    /**
     * @param array<string, mixed> $body
     */
    public function cancelCurrentForWorkorder(User $actor, int $workorderId, array $body): void
    {
        $reason = null;
        if (isset($body['reason']) && is_string($body['reason'])) {
            $reason = $body['reason'];
        }
        $this->service->cancelCurrentForWorkorder($actor, $workorderId, $reason);
    }

    /**
     * @return array<string, mixed>
     */
    public function listHistoryForTicketStaff(User $actor, int $ticketId): array
    {
        return ['data' => $this->service->listHistoryForTicketStaff($actor, $ticketId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listHistoryForWorkorderStaff(User $actor, int $workorderId): array
    {
        return ['data' => $this->service->listHistoryForWorkorderStaff($actor, $workorderId)];
    }

    // ── Portal surface (portal_account scope) ───────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function readForTicketPortal(User $user, PortalAccount $account, int $ticketId): array
    {
        return ['data' => $this->service->readForTicketPortal($user, $account, $ticketId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function readForWorkorderPortal(User $user, PortalAccount $account, int $workorderId): array
    {
        return ['data' => $this->service->readForWorkorderPortal($user, $account, $workorderId)];
    }
}
