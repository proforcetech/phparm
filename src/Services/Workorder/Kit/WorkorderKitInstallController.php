<?php

namespace App\Services\Workorder\Kit;

use App\Models\User;

class WorkorderKitInstallController
{
    public function __construct(private WorkorderKitInstallService $service)
    {
    }

    /**
     * @return array<string, mixed>
     */
    public function getInstall(User $actor, int $installId): array
    {
        return ['data' => $this->service->get($actor, $installId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForWorkorder(User $actor, int $workorderId): array
    {
        return ['data' => $this->service->listForWorkorder($actor, $workorderId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForJob(User $actor, int $jobId): array
    {
        return ['data' => $this->service->listForJob($actor, $jobId)];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listPlanned(User $actor, array $query): array
    {
        $limit = isset($query['limit']) ? max(1, min(200, (int) $query['limit'])) : 50;
        $offset = isset($query['offset']) ? max(0, (int) $query['offset']) : 0;

        return ['data' => $this->service->listPlanned($actor, $limit, $offset)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function plan(User $actor, int $workorderId, array $payload): array
    {
        $payload['workorder_id'] = $workorderId;

        return ['data' => $this->service->plan($actor, $payload)];
    }

    /**
     * @return array<string, mixed>
     */
    public function install(User $actor, int $installId): array
    {
        return ['data' => $this->service->install($actor, $installId)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function cancel(User $actor, int $installId, array $payload): array
    {
        $reason = isset($payload['reason']) ? (string) $payload['reason'] : null;

        return ['data' => $this->service->cancel($actor, $installId, $reason)];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $actor, int $installId): array
    {
        $deleted = $this->service->delete($actor, $installId);

        return ['data' => ['deleted' => $deleted]];
    }
}
