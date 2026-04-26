<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;
use InvalidArgumentException;

/**
 * Phase 6.6 of docs/expansion-plan.md — HTTP facade for the portal
 * upload surface. Thin: pulls $_FILES['file'] off the Request, hands
 * it to the service, and formats the response.
 */
class PortalUploadController
{
    public function __construct(
        private readonly PortalUploadService $service,
    ) {
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    public function uploadForTicket(User $user, PortalAccount $account, int $ticketId, ?array $file): array
    {
        return ['data' => $this->service->uploadForTicket(
            $user, $account, $ticketId, $this->requireFile($file),
        )];
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    public function uploadForWorkorder(User $user, PortalAccount $account, int $workorderId, ?array $file): array
    {
        return ['data' => $this->service->uploadForWorkorder(
            $user, $account, $workorderId, $this->requireFile($file),
        )];
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    public function uploadForThread(User $user, PortalAccount $account, int $threadId, ?array $file): array
    {
        return ['data' => $this->service->uploadForThread(
            $user, $account, $threadId, $this->requireFile($file),
        )];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForTicket(User $user, PortalAccount $account, int $ticketId): array
    {
        return ['data' => $this->service->listForTicket($user, $account, $ticketId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForWorkorder(User $user, PortalAccount $account, int $workorderId): array
    {
        return ['data' => $this->service->listForWorkorder($user, $account, $workorderId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForThread(User $user, PortalAccount $account, int $threadId): array
    {
        return ['data' => $this->service->listForThread($user, $account, $threadId)];
    }

    public function deleteUpload(User $user, PortalAccount $account, int $uploadId): void
    {
        $this->service->deleteUpload($user, $account, $uploadId);
    }

    /**
     * @param array<string, mixed>|null $file
     * @return array<string, mixed>
     */
    private function requireFile(?array $file): array
    {
        if ($file === null) {
            throw new InvalidArgumentException(
                'file is required — upload as multipart/form-data with field name "file"'
            );
        }
        return $file;
    }
}
