<?php

namespace App\Services\Inspection;

use App\Models\User;

/**
 * Phase 8.3 — thin HTTP facade for inspection escalation rules +
 * escalation record lifecycle. All behavior lives in
 * InspectionEscalationService.
 */
class InspectionEscalationController
{
    public function __construct(private readonly InspectionEscalationService $service)
    {
    }

    // ── Rules ────────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createRule(User $actor, array $body): array
    {
        return ['data' => $this->service->createRule($actor, $body)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateRule(User $actor, int $id, array $body): array
    {
        return ['data' => $this->service->updateRule($actor, $id, $body)];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteRule(User $actor, int $id): array
    {
        $this->service->deleteRule($actor, $id);
        return ['data' => ['deleted' => true]];
    }

    /**
     * @return array<string, mixed>
     */
    public function listRules(User $actor, ?int $divisionId, bool $activeOnly): array
    {
        return ['data' => $this->service->listRules($actor, $divisionId, $activeOnly)];
    }

    // ── Escalations ──────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function evaluateReport(User $actor, int $reportId): array
    {
        return ['data' => $this->service->evaluateReport($actor, $reportId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listForReport(User $actor, int $reportId): array
    {
        return ['data' => $this->service->listEscalationsForReport($actor, $reportId)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listMyOpen(User $actor): array
    {
        return ['data' => $this->service->listOpenForMe($actor)];
    }

    /**
     * @return array<string, mixed>
     */
    public function listOpenForRole(User $actor, string $role): array
    {
        return ['data' => $this->service->listOpenForRole($actor, $role)];
    }

    /**
     * @return array<string, mixed>
     */
    public function acknowledge(User $actor, int $id): array
    {
        return ['data' => $this->service->acknowledge($actor, $id)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function resolve(User $actor, int $id, array $body): array
    {
        $note = isset($body['note']) && is_string($body['note']) ? $body['note'] : null;
        return ['data' => $this->service->resolve($actor, $id, $note)];
    }
}
