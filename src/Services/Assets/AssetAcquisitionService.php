<?php

namespace App\Services\Assets;

use App\Models\AssetAcquisition;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * State machine + permission gate for `asset_acquisitions` — Phase 13 (M4)
 * of docs/woms-expansion-plan.md.
 *
 * Each public method enforces a single allowed transition and writes a
 * matching `audit_logs` row keyed by entity_type='asset_acquisition' so the
 * full lifecycle history can be rendered from there.
 *
 * Permissions:
 *   asset_acquisitions.view     — read
 *   asset_acquisitions.manage   — create/edit + most transitions
 *   asset_acquisitions.activate — final activation (terminal happy path),
 *                                 separated so site managers can advance
 *                                 quote/PO/install steps but only an admin
 *                                 marks the asset as live in the CMDB.
 *
 * Side-effect columns (timestamps, actor stamps, references) are populated
 * here rather than in the repo so the column patches stay co-located with
 * the rule that triggers them.
 */
class AssetAcquisitionService
{
    public function __construct(
        private readonly AssetAcquisitionRepository $repository,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function create(User $actor, array $body): AssetAcquisition
    {
        $this->gate->assert($actor, 'asset_acquisitions.manage');

        $body['requested_by_user_id'] = $body['requested_by_user_id'] ?? $actor->id;
        $acq = $this->repository->create($body);

        $this->logTransition($acq, null, AssetAcquisition::STATUS_DRAFT, $actor->id, [
            'reason' => 'created',
        ]);

        return $acq;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function update(User $actor, int $id, array $body): AssetAcquisition
    {
        $this->gate->assert($actor, 'asset_acquisitions.manage');
        return $this->repository->update($id, $body);
    }

    public function find(User $actor, int $id): AssetAcquisition
    {
        $this->gate->assert($actor, 'asset_acquisitions.view');
        $acq = $this->repository->findById($id);
        if ($acq === null) {
            throw new InvalidArgumentException("Acquisition {$id} not found");
        }
        return $acq;
    }

    /**
     * @param array<string, mixed> $body { estimate_id: int, note?: string }
     */
    public function attachQuote(User $actor, int $id, array $body): AssetAcquisition
    {
        $estimateId = (int) ($body['estimate_id'] ?? 0);
        if ($estimateId <= 0) {
            throw new InvalidArgumentException('estimate_id is required');
        }
        return $this->advance(
            $actor,
            $id,
            AssetAcquisition::STATUS_QUOTED,
            'asset_acquisitions.manage',
            ['estimate_id' => $estimateId],
            $body['note'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $body { customer_approved_by?: int, note?: string }
     */
    public function customerApprove(User $actor, int $id, array $body = []): AssetAcquisition
    {
        $now = $this->now();
        return $this->advance(
            $actor,
            $id,
            AssetAcquisition::STATUS_APPROVED,
            'asset_acquisitions.manage',
            [
                'customer_approved_at' => $now,
                'customer_approved_by' => $this->intOrNull($body['customer_approved_by'] ?? null),
            ],
            $body['note'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $body { reason: string }
     */
    public function customerReject(User $actor, int $id, array $body): AssetAcquisition
    {
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('reason is required');
        }
        return $this->advance(
            $actor,
            $id,
            AssetAcquisition::STATUS_REJECTED,
            'asset_acquisitions.manage',
            [
                'customer_rejected_at' => $this->now(),
                'customer_rejection_reason' => $reason,
            ],
            $reason,
        );
    }

    /**
     * @param array<string, mixed> $body { vendor_name: string, vendor_po_number: string,
     *                                     vendor_po_total_cents?: int, note?: string }
     */
    public function issuePo(User $actor, int $id, array $body): AssetAcquisition
    {
        $vendorName = trim((string) ($body['vendor_name'] ?? ''));
        $poNumber = trim((string) ($body['vendor_po_number'] ?? ''));
        if ($vendorName === '' || $poNumber === '') {
            throw new InvalidArgumentException('vendor_name and vendor_po_number are required');
        }
        return $this->advance(
            $actor,
            $id,
            AssetAcquisition::STATUS_PO_ISSUED,
            'asset_acquisitions.manage',
            [
                'vendor_name' => $vendorName,
                'vendor_po_number' => $poNumber,
                'vendor_po_total_cents' => $this->intOrNull($body['vendor_po_total_cents'] ?? null),
                'vendor_po_issued_at' => $this->now(),
            ],
            $body['note'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $body { received_by?: int, note?: string }
     */
    public function markReceived(User $actor, int $id, array $body = []): AssetAcquisition
    {
        return $this->advance(
            $actor,
            $id,
            AssetAcquisition::STATUS_RECEIVED,
            'asset_acquisitions.manage',
            [
                'received_at' => $this->now(),
                'received_by' => $this->intOrNull($body['received_by'] ?? $actor->id),
            ],
            $body['note'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $body { install_workorder_id: int,
     *                                     install_scheduled_at?: string, note?: string }
     */
    public function scheduleInstall(User $actor, int $id, array $body): AssetAcquisition
    {
        $woId = (int) ($body['install_workorder_id'] ?? 0);
        if ($woId <= 0) {
            throw new InvalidArgumentException('install_workorder_id is required');
        }
        return $this->advance(
            $actor,
            $id,
            AssetAcquisition::STATUS_INSTALL_SCHEDULED,
            'asset_acquisitions.manage',
            [
                'install_workorder_id' => $woId,
                'install_scheduled_at' => $this->stringOrNull($body['install_scheduled_at'] ?? null) ?? $this->now(),
            ],
            $body['note'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $body { note?: string }
     */
    public function markInstalled(User $actor, int $id, array $body = []): AssetAcquisition
    {
        return $this->advance(
            $actor,
            $id,
            AssetAcquisition::STATUS_INSTALLED,
            'asset_acquisitions.manage',
            ['installed_at' => $this->now()],
            $body['note'] ?? null,
        );
    }

    /**
     * Final happy-path step: the goods are installed and the corresponding
     * `site_assets` row exists. We don't create the site_asset here — that's
     * the responsibility of the install workorder workflow — we only link
     * to it and stamp activation. Gated on the dedicated `.activate`
     * permission so admins control go-live.
     *
     * @param array<string, mixed> $body { target_site_asset_id: int, note?: string }
     */
    public function activate(User $actor, int $id, array $body): AssetAcquisition
    {
        $assetId = (int) ($body['target_site_asset_id'] ?? 0);
        if ($assetId <= 0) {
            throw new InvalidArgumentException('target_site_asset_id is required');
        }
        return $this->advance(
            $actor,
            $id,
            AssetAcquisition::STATUS_ACTIVATED,
            'asset_acquisitions.activate',
            [
                'target_site_asset_id' => $assetId,
                'activated_at' => $this->now(),
                'activated_by' => $actor->id,
            ],
            $body['note'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $body { reason: string }
     */
    public function cancel(User $actor, int $id, array $body): AssetAcquisition
    {
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('reason is required');
        }
        return $this->advance(
            $actor,
            $id,
            AssetAcquisition::STATUS_CANCELLED,
            'asset_acquisitions.manage',
            [
                'cancelled_at' => $this->now(),
                'cancelled_by' => $actor->id,
                'cancelled_reason' => $reason,
            ],
            $reason,
        );
    }

    /**
     * Shared transition helper: load → permission → matrix check → repo
     * write → audit log. Methods above are thin wrappers that supply the
     * target state, side-effect patch, and an optional human note.
     *
     * @param array<string, mixed> $sideEffects
     */
    private function advance(
        User $actor,
        int $id,
        string $toStatus,
        string $permission,
        array $sideEffects,
        ?string $note,
    ): AssetAcquisition {
        $this->gate->assert($actor, $permission);

        $current = $this->repository->findById($id);
        if ($current === null) {
            throw new InvalidArgumentException("Acquisition {$id} not found");
        }
        if (!AssetAcquisition::canTransition($current->status, $toStatus)) {
            $allowed = AssetAcquisition::allowedTransitionsFrom($current->status);
            throw new InvalidArgumentException(sprintf(
                'Illegal acquisition transition: %s → %s (allowed from %s: %s)',
                $current->status,
                $toStatus,
                $current->status,
                $allowed === [] ? '<none — terminal>' : implode(', ', $allowed),
            ));
        }

        $updated = $this->repository->applyTransition($id, $toStatus, (int) $actor->id, $sideEffects);

        $context = ['from' => $current->status, 'to' => $toStatus, 'side_effects' => $sideEffects];
        if ($note !== null && $note !== '') {
            $context['note'] = $note;
        }
        $this->logTransition($updated, $current->status, $toStatus, (int) $actor->id, $context);

        return $updated;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logTransition(
        AssetAcquisition $acq,
        ?string $from,
        string $to,
        int $actorId,
        array $context = [],
    ): void {
        if ($this->audit === null) {
            return;
        }

        $context['from'] = $from;
        $context['to'] = $to;

        $this->audit->log(new AuditEntry(
            'acquisition.transitioned',
            'asset_acquisition',
            (string) $acq->id,
            $actorId > 0 ? $actorId : null,
            $context,
        ));
    }

    private function now(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    private function intOrNull(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }
}
