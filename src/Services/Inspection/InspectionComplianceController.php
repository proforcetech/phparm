<?php

namespace App\Services\Inspection;

use App\Models\User;

/**
 * Phase 8.1 — thin HTTP facade for inspection compliance tag
 * vocabulary + template bindings. All behavior lives in
 * InspectionComplianceService.
 */
class InspectionComplianceController
{
    public function __construct(private readonly InspectionComplianceService $service)
    {
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createTag(User $actor, array $body): array
    {
        return ['data' => $this->service->createTag($actor, $body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateTag(User $actor, int $id, array $body): array
    {
        return ['data' => $this->service->updateTag($actor, $id, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteTag(User $actor, int $id): array
    {
        $this->service->deleteTag($actor, $id);
        return ['data' => ['deleted' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    public function listTags(User $actor, ?int $divisionId, bool $activeOnly): array
    {
        return ['data' => $this->service->listTags($actor, $divisionId, $activeOnly)];
    }

    /**
     * @param array{tag_ids?: array<int, int|string>} $body
     * @return array<string, mixed>
     */
    public function setTemplateTags(User $actor, int $templateId, array $body): array
    {
        $tagIds = [];
        if (isset($body['tag_ids']) && is_array($body['tag_ids'])) {
            foreach ($body['tag_ids'] as $raw) {
                $tagIds[] = (int) $raw;
            }
        }
        return ['data' => $this->service->setTagsForTemplate($actor, $templateId, $tagIds)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listTemplateTags(User $actor, int $templateId): array
    {
        return ['data' => $this->service->listTagsForTemplate($actor, $templateId)];
    }

    /**
     * @param array<int, string> $tagCodes
     * @return array<string, mixed>
     */
    public function listTemplatesByCompliance(
        User $actor,
        ?int $divisionId,
        array $tagCodes,
        bool $activeOnly,
    ): array {
        return ['data' => $this->service->listTemplatesByCompliance($actor, $divisionId, $tagCodes, $activeOnly)];
    }
}
