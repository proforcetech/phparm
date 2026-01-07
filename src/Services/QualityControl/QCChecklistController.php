<?php

namespace App\Services\QualityControl;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Controller for QC Checklist API endpoints.
 */
class QCChecklistController
{
    private QCChecklistService $service;
    private AccessGate $gate;

    public function __construct(QCChecklistService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    // -------------------------------------------------------------------------
    // Template Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /api/qc/templates
     * @return array<string, mixed>
     */
    public function listTemplates(User $user, bool $includeInactive = false): array
    {
        $this->gate->assert($user, 'qc.view');

        return [
            'data' => $this->service->listTemplates($includeInactive),
        ];
    }

    /**
     * GET /api/qc/templates/{id}
     * @return array<string, mixed>
     */
    public function showTemplate(User $user, int $id): array
    {
        $this->gate->assert($user, 'qc.view');

        $template = $this->service->getTemplate($id);
        if ($template === null) {
            throw new InvalidArgumentException('QC template not found.');
        }

        return $template;
    }

    /**
     * POST /api/qc/templates
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createTemplate(User $user, array $data): array
    {
        $this->gate->assert($user, 'qc.manage');

        if (empty($data['name'])) {
            throw new InvalidArgumentException('Template name is required.');
        }

        return $this->service->createTemplate($data, $user->id);
    }

    // -------------------------------------------------------------------------
    // Check Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /api/workorders/{id}/qc-check
     * @return array<string, mixed>
     */
    public function getWorkorderCheck(User $user, int $workorderId): array
    {
        $this->gate->assert($user, 'qc.view');

        $check = $this->service->getCheckForWorkorder($workorderId);

        if ($check === null) {
            return [
                'status' => 'not_initialized',
                'message' => 'QC check has not been initialized for this workorder.',
                'can_initialize' => true,
            ];
        }

        return $check;
    }

    /**
     * POST /api/workorders/{id}/qc-check/initialize
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function initializeCheck(User $user, int $workorderId, array $data = []): array
    {
        $this->gate->assert($user, 'qc.manage');

        $templateId = isset($data['template_id']) ? (int) $data['template_id'] : null;

        return $this->service->initializeCheck($workorderId, $templateId, $user->id);
    }

    /**
     * PATCH /api/qc/checks/{id}/items
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateCheckItems(User $user, int $checkId, array $data): array
    {
        $this->gate->assert($user, 'qc.manage');

        if (empty($data['items']) || !is_array($data['items'])) {
            throw new InvalidArgumentException('Items array is required.');
        }

        return $this->service->updateCheckItems($checkId, $data['items'], $user->id);
    }

    /**
     * PATCH /api/qc/checks/{checkId}/items/{itemId}
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateCheckItem(User $user, int $checkId, int $itemId, array $data): array
    {
        $this->gate->assert($user, 'qc.manage');

        if (!isset($data['passed'])) {
            throw new InvalidArgumentException('passed field is required.');
        }

        return $this->service->updateCheckItem(
            $itemId,
            (bool) $data['passed'],
            $data['notes'] ?? null,
            $user->id
        );
    }

    /**
     * POST /api/qc/checks/{id}/complete
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function completeCheck(User $user, int $checkId, array $data = []): array
    {
        $this->gate->assert($user, 'qc.manage');

        return $this->service->completeCheck(
            $checkId,
            $data['notes'] ?? null,
            $user->id
        );
    }

    /**
     * GET /api/workorders/{id}/qc-status
     * Returns simplified QC status for workflow decisions.
     * @return array<string, mixed>
     */
    public function getQCStatus(User $user, int $workorderId): array
    {
        $this->gate->assert($user, 'qc.view');

        $check = $this->service->getCheckForWorkorder($workorderId);
        $isEnabled = $this->service->isQCEnabled();
        $isRequired = $this->service->isQCRequiredForInvoicing();

        return [
            'qc_enabled' => $isEnabled,
            'qc_required_for_invoice' => $isRequired,
            'has_qc_check' => $check !== null,
            'qc_status' => $check['status'] ?? null,
            'qc_passed' => $this->service->hasPassedQC($workorderId),
            'can_convert_to_invoice' => !$isRequired || $this->service->hasPassedQC($workorderId),
        ];
    }
}
