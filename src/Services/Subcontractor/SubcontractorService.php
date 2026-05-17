<?php

namespace App\Services\Subcontractor;

use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 10.1 — orchestrates subcontractor master records, division
 * approvals, and per-work-order assignments.
 *
 * Persistence is delegated to SubcontractorRepository. This service owns:
 *   1) Validation & permission checks (subcontractors.view / subcontractors.manage)
 *   2) Status-transition rules for assignments (illegal jumps rejected)
 *   3) Auto-stamping the lifecycle timestamps (accepted_at / completed_at / etc)
 *      so the controller never has to.
 *   4) Composing division_ids onto returned Subcontractor records so the UI
 *      gets a single shape regardless of read path.
 *
 * Assignability rule: a sub must be 'active' AND, if a division_id is given,
 * approved for that division. This is the gate the WO routing UI hits before
 * showing a sub in the picker.
 */
class SubcontractorService
{
    public function __construct(
        private readonly SubcontractorRepository $repo,
        private readonly AccessGate $gate,
    ) {
    }

    // ─────────────────────────────────────── subcontractor lifecycle ────

    /**
     * @param array{status?: string, division_id?: int, search?: string, limit?: int, offset?: int} $filters
     * @return array<int, Subcontractor>
     */
    public function listSubcontractors(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'subcontractors.view');
        $items = $this->repo->listSubcontractors($filters);
        foreach ($items as $sub) {
            $sub->division_ids = $this->repo->listDivisionsForSubcontractor($sub->id);
        }
        return $items;
    }

    public function findSubcontractor(User $actor, int $id): Subcontractor
    {
        $this->gate->assert($actor, 'subcontractors.view');
        $sub = $this->repo->findSubcontractor($id);
        if ($sub === null) {
            throw new InvalidArgumentException("Subcontractor {$id} not found");
        }
        $sub->division_ids = $this->repo->listDivisionsForSubcontractor($sub->id);
        return $sub;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createSubcontractor(User $actor, array $data): Subcontractor
    {
        $this->gate->assert($actor, 'subcontractors.manage');
        $data = $this->prepareSubcontractorPayload($data, true);

        $sub = $this->repo->createSubcontractor($data);
        if (array_key_exists('division_ids', $data) && is_array($data['division_ids'])) {
            $this->repo->setDivisionsForSubcontractor($sub->id, $data['division_ids']);
        }
        $sub->division_ids = $this->repo->listDivisionsForSubcontractor($sub->id);
        return $sub;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSubcontractor(User $actor, int $id, array $data): Subcontractor
    {
        $this->gate->assert($actor, 'subcontractors.manage');
        $existing = $this->repo->findSubcontractor($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Subcontractor {$id} not found");
        }
        $data = $this->prepareSubcontractorPayload($data, false, $existing);

        $sub = $this->repo->updateSubcontractor($id, $data);
        if (array_key_exists('division_ids', $data) && is_array($data['division_ids'])) {
            $this->repo->setDivisionsForSubcontractor($sub->id, $data['division_ids']);
        }
        $sub->division_ids = $this->repo->listDivisionsForSubcontractor($sub->id);
        return $sub;
    }

    public function deleteSubcontractor(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'subcontractors.manage');
        if ($this->repo->findSubcontractor($id) === null) {
            throw new InvalidArgumentException("Subcontractor {$id} not found");
        }
        $this->repo->deleteSubcontractor($id);
    }

    // ──────────────────────────────────────────────── assignments ──────

    /**
     * @return array<int, SubcontractorAssignment>
     */
    public function listAssignmentsForWorkorder(User $actor, int $workorderId): array
    {
        $this->gate->assert($actor, 'subcontractors.view');
        return $this->repo->listAssignmentsForWorkorder($workorderId);
    }

    /**
     * @param array{status?: string, subcontractor_id?: int, limit?: int, offset?: int} $filters
     * @return array<int, SubcontractorAssignment>
     */
    public function listAssignments(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'subcontractors.view');
        return $this->repo->listAssignments($filters);
    }

    public function findAssignment(User $actor, int $id): SubcontractorAssignment
    {
        $this->gate->assert($actor, 'subcontractors.view');
        $a = $this->repo->findAssignment($id);
        if ($a === null) {
            throw new InvalidArgumentException("Subcontractor assignment {$id} not found");
        }
        return $a;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createAssignment(
        User $actor,
        array $data,
        ?DateTimeImmutable $now = null
    ): SubcontractorAssignment {
        $this->gate->assert($actor, 'subcontractors.manage');

        $subId = (int) ($data['subcontractor_id'] ?? 0);
        $woId = (int) ($data['workorder_id'] ?? 0);
        if ($subId <= 0) {
            throw new InvalidArgumentException('subcontractor_id is required');
        }
        if ($woId <= 0) {
            throw new InvalidArgumentException('workorder_id is required');
        }
        $sub = $this->repo->findSubcontractor($subId);
        if ($sub === null) {
            throw new InvalidArgumentException("Subcontractor {$subId} not found");
        }
        if ($sub->status !== Subcontractor::STATUS_ACTIVE) {
            throw new InvalidArgumentException(
                "Subcontractor {$subId} is not active (status: {$sub->status})"
            );
        }

        $data['assigned_by_user_id'] = $actor->id ?? null;
        $data['status'] = SubcontractorAssignment::STATUS_PENDING;
        unset($now); // assigned_at default in DDL handles wall-clock; explicit param is for tests

        return $this->repo->createAssignment($data);
    }

    /**
     * Free-form update (description / notes / cost). Status changes go through
     * transitionAssignment(), which validates the state machine.
     *
     * @param array<string, mixed> $data
     */
    public function updateAssignment(
        User $actor,
        int $id,
        array $data
    ): SubcontractorAssignment {
        $this->gate->assert($actor, 'subcontractors.manage');
        $existing = $this->repo->findAssignment($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Subcontractor assignment {$id} not found");
        }
        // Forbid status changes through this path — must use transitionAssignment.
        unset($data['status'], $data['accepted_at'], $data['declined_at'],
            $data['completed_at'], $data['cancelled_at']);

        if (array_key_exists('rating', $data) && $data['rating'] !== null && $data['rating'] !== '') {
            $r = (int) $data['rating'];
            if ($r < 1 || $r > 5) {
                throw new InvalidArgumentException('rating must be between 1 and 5');
            }
            $data['rating'] = $r;
        }

        return $this->repo->updateAssignment($id, $data);
    }

    /**
     * State-machine transition. Stamps the matching lifecycle timestamp so
     * the closeout audit trail stays clean without callers having to remember.
     */
    public function transitionAssignment(
        User $actor,
        int $id,
        string $targetStatus,
        ?DateTimeImmutable $now = null
    ): SubcontractorAssignment {
        $this->gate->assert($actor, 'subcontractors.manage');
        $existing = $this->repo->findAssignment($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Subcontractor assignment {$id} not found");
        }
        if (!in_array($targetStatus, SubcontractorAssignment::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown assignment status: {$targetStatus}");
        }
        if ($targetStatus === SubcontractorAssignment::STATUS_PENDING) {
            throw new InvalidArgumentException('Cannot transition back to pending');
        }
        $allowed = SubcontractorAssignment::ALLOWED_TRANSITIONS[$targetStatus] ?? [];
        if (!in_array($existing->status, $allowed, true)) {
            throw new InvalidArgumentException(
                "Illegal transition {$existing->status} → {$targetStatus}"
            );
        }

        $now ??= new DateTimeImmutable();
        $stamp = $now->format('Y-m-d H:i:s');
        $update = ['status' => $targetStatus];
        match ($targetStatus) {
            SubcontractorAssignment::STATUS_ACCEPTED => $update['accepted_at'] = $stamp,
            SubcontractorAssignment::STATUS_DECLINED => $update['declined_at'] = $stamp,
            SubcontractorAssignment::STATUS_COMPLETED => $update['completed_at'] = $stamp,
            SubcontractorAssignment::STATUS_CANCELLED => $update['cancelled_at'] = $stamp,
            default => null,
        };
        return $this->repo->updateAssignment($id, $update);
    }

    public function deleteAssignment(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'subcontractors.manage');
        if ($this->repo->findAssignment($id) === null) {
            throw new InvalidArgumentException("Subcontractor assignment {$id} not found");
        }
        $this->repo->deleteAssignment($id);
    }

    // ─────────────────────────────────────────────── assignability ──────

    /**
     * Filter a candidate sub list to those legally assignable to a job in the
     * given division. Used by the WO routing UI when rendering the picker.
     *
     * @param array<int, int>|null $candidateIds limit search to these ids; null = all active
     * @return array<int, Subcontractor>
     */
    public function findAssignable(
        User $actor,
        ?int $divisionId = null,
        ?array $candidateIds = null
    ): array {
        $this->gate->assert($actor, 'subcontractors.view');
        $filters = ['status' => Subcontractor::STATUS_ACTIVE];
        if ($divisionId !== null) {
            $filters['division_id'] = $divisionId;
        }
        $items = $this->repo->listSubcontractors($filters);
        if ($candidateIds !== null) {
            $set = array_flip(array_map(static fn($v) => (int) $v, $candidateIds));
            $items = array_values(array_filter($items, static fn(Subcontractor $s) => isset($set[$s->id])));
        }
        foreach ($items as $sub) {
            $sub->division_ids = $this->repo->listDivisionsForSubcontractor($sub->id);
        }
        return $items;
    }

    // ─────────────────────────────────────────────────── validation ─────

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function prepareSubcontractorPayload(
        array $data,
        bool $forCreate,
        ?Subcontractor $existing = null
    ): array {
        $data = $this->normalizeSubcontractorAliases($data);
        unset($data['portal_password_hash']);

        $portalPasswordWasProvided = array_key_exists('portal_password', $data)
            && trim((string) $data['portal_password']) !== '';
        if ($portalPasswordWasProvided) {
            $password = (string) $data['portal_password'];
            if (strlen($password) < 8) {
                throw new InvalidArgumentException('portal_password must be at least 8 characters');
            }
            $data['portal_password_hash'] = password_hash($password, PASSWORD_DEFAULT);
            $data['portal_password_updated_at'] = date('Y-m-d H:i:s');
        }
        unset($data['portal_password']);

        $this->validateSubcontractorPayload($data, $forCreate, $existing, $portalPasswordWasProvided);
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function normalizeSubcontractorAliases(array $data): array
    {
        $aliases = [
            'name' => 'company_name',
            'contact_email' => 'email',
            'contact_phone' => 'phone',
            'address' => 'address_line1',
        ];
        foreach ($aliases as $from => $to) {
            if (array_key_exists($from, $data) && !array_key_exists($to, $data)) {
                $data[$to] = $data[$from];
            }
            unset($data[$from]);
        }
        if (($data['status'] ?? null) === 'blocked') {
            $data['status'] = Subcontractor::STATUS_SUSPENDED;
        }
        return $data;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateSubcontractorPayload(
        array $data,
        bool $forCreate,
        ?Subcontractor $existing = null,
        bool $portalPasswordWasProvided = false
    ): void
    {
        if ($forCreate && empty($data['company_name'])) {
            throw new InvalidArgumentException('company_name is required');
        }
        if (array_key_exists('company_name', $data) && trim((string) $data['company_name']) === '') {
            throw new InvalidArgumentException('company_name cannot be blank');
        }
        if (!empty($data['email']) && !filter_var((string) $data['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('email is not a valid address');
        }
        if (
            array_key_exists('status', $data)
            && !in_array((string) $data['status'], Subcontractor::STATUSES, true)
        ) {
            throw new InvalidArgumentException(
                'status must be one of: ' . implode(', ', Subcontractor::STATUSES)
            );
        }
        if (
            array_key_exists('insurance_expires_at', $data)
            && $data['insurance_expires_at'] !== null
            && $data['insurance_expires_at'] !== ''
            && !preg_match('/^\d{4}-\d{2}-\d{2}/', (string) $data['insurance_expires_at'])
        ) {
            throw new InvalidArgumentException('insurance_expires_at must be ISO date (YYYY-MM-DD)');
        }
        if (
            array_key_exists('hourly_rate_cents', $data)
            && $data['hourly_rate_cents'] !== null
            && $data['hourly_rate_cents'] !== ''
            && (int) $data['hourly_rate_cents'] < 0
        ) {
            throw new InvalidArgumentException('hourly_rate_cents must be non-negative');
        }

        $portalLoginEnabled = array_key_exists('portal_login_enabled', $data)
            ? (bool) $data['portal_login_enabled']
            : (bool) ($existing?->portal_login_enabled ?? false);
        if (!$portalLoginEnabled) {
            return;
        }

        $email = array_key_exists('email', $data)
            ? trim((string) ($data['email'] ?? ''))
            : trim((string) ($existing?->email ?? ''));
        if ($email === '') {
            throw new InvalidArgumentException('email is required to enable portal login');
        }

        $passwordIsSet = $portalPasswordWasProvided || (bool) ($existing?->portal_password_set ?? false);
        if (!$passwordIsSet) {
            throw new InvalidArgumentException('portal_password is required to enable portal login');
        }
    }
}
