<?php

namespace App\Services\Dispatch;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

class TruckChecklistController
{
    private TruckChecklistService $service;
    private AccessGate $gate;

    public function __construct(TruckChecklistService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listTemplates(User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'truck_checklists.view');

        $includeInactive = filter_var($filters['include_inactive'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $type = $filters['checklist_type'] ?? null;

        return [
            'data' => $this->service->listTemplates($type, $includeInactive),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showTemplate(User $user, int $templateId): array
    {
        $this->gate->assert($user, 'truck_checklists.view');

        $template = $this->service->getTemplate($templateId);
        if ($template === null) {
            throw new InvalidArgumentException('Checklist template not found.');
        }

        return $template;
    }

    /**
     * @return array<string, mixed>
     */
    public function defaultTemplate(User $user, string $type): array
    {
        $this->gate->assert($user, 'truck_checklists.view');

        $template = $this->service->getDefaultTemplate($type);
        if ($template === null) {
            throw new InvalidArgumentException('Default checklist template not found.');
        }

        return $template;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createTemplate(User $user, array $payload): array
    {
        $this->gate->assert($user, 'truck_checklists.manage');

        if (empty($payload['name'])) {
            throw new InvalidArgumentException('Template name is required.');
        }

        if (empty($payload['checklist_type'])) {
            throw new InvalidArgumentException('Checklist type is required.');
        }

        return $this->service->createTemplate($payload, $user->id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateTemplate(User $user, int $templateId, array $payload): array
    {
        $this->gate->assert($user, 'truck_checklists.manage');

        return $this->service->updateTemplate($templateId, $payload, $user->id);
    }

    public function deleteTemplate(User $user, int $templateId): array
    {
        $this->gate->assert($user, 'truck_checklists.manage');

        $this->service->deleteTemplate($templateId);

        return ['status' => 'ok'];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listEntries(User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'truck_checklists.view');

        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = max(1, $page);
        $offset = ($page - 1) * $perPage;

        return $this->service->listEntries($filters, $perPage, $offset);
    }

    /**
     * @return array<string, mixed>
     */
    public function showEntry(User $user, int $entryId): array
    {
        $this->gate->assert($user, 'truck_checklists.view');

        $entry = $this->service->getEntry($entryId);
        if ($entry === null) {
            throw new InvalidArgumentException('Checklist entry not found.');
        }

        return $entry;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createEntry(User $user, array $payload): array
    {
        $this->gate->assert($user, 'truck_checklists.complete');

        $driverProfileId = isset($payload['driver_profile_id']) ? (int) $payload['driver_profile_id'] : 0;
        if ($driverProfileId <= 0) {
            throw new InvalidArgumentException('driver_profile_id is required.');
        }

        $templateId = isset($payload['template_id']) ? (int) $payload['template_id'] : 0;
        if ($templateId <= 0) {
            throw new InvalidArgumentException('template_id is required.');
        }

        if (empty($payload['checklist_type'])) {
            throw new InvalidArgumentException('checklist_type is required.');
        }

        if (empty($payload['items']) || !is_array($payload['items'])) {
            throw new InvalidArgumentException('items are required.');
        }

        return $this->service->createEntry(
            $driverProfileId,
            $templateId,
            $payload['checklist_type'],
            $payload['items'],
            $payload['notes'] ?? null,
            isset($payload['driver_shift_id']) ? (int) $payload['driver_shift_id'] : null,
            $user->id
        );
    }
}
