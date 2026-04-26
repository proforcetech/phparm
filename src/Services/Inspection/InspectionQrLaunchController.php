<?php

namespace App\Services\Inspection;

use App\Models\User;

/**
 * Phase 8.5 — thin HTTP facade for the QR launch service. All
 * behavior lives in InspectionQrLaunchService.
 */
class InspectionQrLaunchController
{
    public function __construct(private readonly InspectionQrLaunchService $service)
    {
    }

    /**
     * @param array<string, mixed>|null $clientMeta
     * @return array<string, mixed>
     */
    public function preview(User $actor, string $token, ?array $clientMeta = null): array
    {
        return ['data' => $this->service->previewByToken($actor, $token, $clientMeta)];
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed>|null $clientMeta
     * @return array<string, mixed>
     */
    public function launch(
        User $actor,
        string $token,
        int $templateId,
        array $payload = [],
        ?array $clientMeta = null,
    ): array {
        return ['data' => $this->service->launchFromToken($actor, $token, $templateId, $payload, $clientMeta)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForAsset(User $actor, int $assetId, int $limit): array
    {
        return ['data' => $this->service->listForAsset($actor, $assetId, $limit)];
    }

    /**
     * @return array<string, mixed>
     */
    public function findForReport(User $actor, int $reportId): array
    {
        return ['data' => $this->service->findForReport($actor, $reportId)];
    }
}
