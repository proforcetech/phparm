<?php

namespace App\Services\Assets;

use App\Models\AssetDecommission;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * State machine + permission gate for `asset_decommissions` — Phase 13 (M5)
 * of docs/woms-expansion-plan.md.
 *
 * Each public method enforces a single allowed transition and writes a
 * matching `audit_logs` row keyed by entity_type='asset_decommission' so the
 * full lifecycle history can be rendered from there.
 *
 * Permissions:
 *   asset_decommissions.view    — read
 *   asset_decommissions.manage  — create/edit + most transitions
 *   asset_decommissions.retire  — terminal retire step (admin-only),
 *                                 separated because it ALSO flips the
 *                                 underlying site_assets row to retired.
 *
 * Side-effect columns (timestamps, actor stamps, references) are populated
 * here rather than in the repo so the column patches stay co-located with
 * the rule that triggers them.
 */
class AssetDecommissionService
{
    public function __construct(
        private readonly AssetDecommissionRepository $repository,
        private readonly SiteAssetRepository $siteAssets,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $body
     */
    public function create(User $actor, array $body): AssetDecommission
    {
        $this->gate->assert($actor, 'asset_decommissions.manage');

        $body['requested_by_user_id'] = $body['requested_by_user_id'] ?? $actor->id;
        $row = $this->repository->create($body);

        $this->logTransition($row, null, AssetDecommission::STATUS_INITIATED, (int) $actor->id, [
            'reason' => 'created',
            'requires_wipe' => $row->requiresWipe(),
            'recovery_method' => $row->recovery_method,
        ]);

        return $row;
    }

    /**
     * @param array<string, mixed> $body
     */
    public function update(User $actor, int $id, array $body): AssetDecommission
    {
        $this->gate->assert($actor, 'asset_decommissions.manage');
        return $this->repository->update($id, $body);
    }

    public function find(User $actor, int $id): AssetDecommission
    {
        $this->gate->assert($actor, 'asset_decommissions.view');
        $row = $this->repository->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("Decommission {$id} not found");
        }
        return $row;
    }

    /**
     * Begin the data-wipe step. Only legal when requires_wipe=1; rows with
     * requires_wipe=0 must skip straight to recovery via {@see startRecovery()}.
     *
     * @param array<string, mixed> $body { note?: string }
     */
    public function startWipe(User $actor, int $id, array $body = []): AssetDecommission
    {
        $current = $this->mustFind($id);
        if (!$current->requiresWipe()) {
            throw new InvalidArgumentException(
                'startWipe is not allowed: this decommission has requires_wipe=0. Call startRecovery instead.'
            );
        }
        return $this->advance(
            $actor,
            $current,
            AssetDecommission::STATUS_WIPE_IN_PROGRESS,
            'asset_decommissions.manage',
            ['wipe_started_at' => $this->now()],
            $body['note'] ?? null,
        );
    }

    /**
     * Mark the wipe complete and stamp the certificate URL.
     *
     * @param array<string, mixed> $body { wipe_certificate_url: string,
     *                                     wipe_completed_by?: int, note?: string }
     */
    public function completeWipe(User $actor, int $id, array $body): AssetDecommission
    {
        $cert = trim((string) ($body['wipe_certificate_url'] ?? ''));
        if ($cert === '') {
            throw new InvalidArgumentException('wipe_certificate_url is required');
        }
        return $this->advance(
            $actor,
            $this->mustFind($id),
            AssetDecommission::STATUS_WIPE_COMPLETE,
            'asset_decommissions.manage',
            [
                'wipe_completed_at' => $this->now(),
                'wipe_completed_by' => $this->intOrNull($body['wipe_completed_by'] ?? $actor->id),
                'wipe_certificate_url' => $cert,
            ],
            $body['note'] ?? null,
        );
    }

    /**
     * Begin the recovery step. Reachable from `wipe_complete` (normal path)
     * or `initiated` directly (when requires_wipe=0).
     *
     * @param array<string, mixed> $body { note?: string }
     */
    public function startRecovery(User $actor, int $id, array $body = []): AssetDecommission
    {
        $current = $this->mustFind($id);
        if (
            $current->status === AssetDecommission::STATUS_INITIATED
            && $current->requiresWipe()
        ) {
            throw new InvalidArgumentException(
                'startRecovery from initiated is not allowed: requires_wipe=1, call startWipe first.'
            );
        }
        return $this->advance(
            $actor,
            $current,
            AssetDecommission::STATUS_RECOVERY_IN_PROGRESS,
            'asset_decommissions.manage',
            ['recovery_started_at' => $this->now()],
            $body['note'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $body { recovery_reference?: string,
     *                                     recovery_value_cents?: int,
     *                                     recovery_completed_by?: int, note?: string }
     */
    public function completeRecovery(User $actor, int $id, array $body = []): AssetDecommission
    {
        return $this->advance(
            $actor,
            $this->mustFind($id),
            AssetDecommission::STATUS_RECOVERY_COMPLETE,
            'asset_decommissions.manage',
            [
                'recovery_completed_at' => $this->now(),
                'recovery_completed_by' => $this->intOrNull($body['recovery_completed_by'] ?? $actor->id),
                'recovery_reference' => $this->stringOrNull($body['recovery_reference'] ?? null),
                'recovery_value_cents' => $this->intOrNull($body['recovery_value_cents'] ?? null),
            ],
            $body['note'] ?? null,
        );
    }

    /**
     * @param array<string, mixed> $body { entitlement_updated_by?: int, note?: string }
     */
    public function updateEntitlements(User $actor, int $id, array $body = []): AssetDecommission
    {
        return $this->advance(
            $actor,
            $this->mustFind($id),
            AssetDecommission::STATUS_ENTITLEMENT_UPDATED,
            'asset_decommissions.manage',
            [
                'entitlement_updated_at' => $this->now(),
                'entitlement_updated_by' => $this->intOrNull($body['entitlement_updated_by'] ?? $actor->id),
            ],
            $body['note'] ?? null,
        );
    }

    /**
     * Capture the formal audit. The transition's own audit_logs row id is
     * stamped back onto asset_decommissions.audit_log_id so reports can pull
     * the signed entry directly without a join through context JSON.
     *
     * @param array<string, mixed> $body { audited_by?: int, note?: string }
     */
    public function markAudited(User $actor, int $id, array $body = []): AssetDecommission
    {
        $current = $this->mustFind($id);
        $row = $this->advance(
            $actor,
            $current,
            AssetDecommission::STATUS_AUDITED,
            'asset_decommissions.manage',
            [
                'audited_at' => $this->now(),
                'audited_by' => $this->intOrNull($body['audited_by'] ?? $actor->id),
            ],
            $body['note'] ?? null,
        );

        $auditLogId = $this->lastAuditLogId;
        if ($auditLogId !== null) {
            $this->repository->setAuditLogId($id, $auditLogId);
            $row->audit_log_id = $auditLogId;
        }

        return $row;
    }

    /**
     * Terminal retire step. Side effects:
     *   1. asset_decommissions.status = 'retired' + retired_at/by stamps
     *   2. site_assets.status = 'retired' + decommissioned_at = NOW()
     * The two writes are NOT in a single transaction (no DDL support in PDO
     * scope here), but the audit row pinpoints the moment so the pair can be
     * reconciled if step 2 ever fails.
     *
     * @param array<string, mixed> $body { note?: string }
     */
    public function retire(User $actor, int $id, array $body = []): AssetDecommission
    {
        $current = $this->mustFind($id);
        $now = $this->now();

        $row = $this->advance(
            $actor,
            $current,
            AssetDecommission::STATUS_RETIRED,
            'asset_decommissions.retire',
            [
                'retired_at' => $now,
                'retired_by' => (int) $actor->id,
            ],
            $body['note'] ?? null,
        );

        // Flip the underlying site_asset. Done AFTER the transition so a
        // failed audit-log write doesn't leave an orphaned retired asset.
        $this->siteAssets->update($current->site_asset_id, [
            'status' => 'retired',
            'decommissioned_at' => $now,
        ]);

        return $row;
    }

    /**
     * @param array<string, mixed> $body { reason: string }
     */
    public function cancel(User $actor, int $id, array $body): AssetDecommission
    {
        $reason = trim((string) ($body['reason'] ?? ''));
        if ($reason === '') {
            throw new InvalidArgumentException('reason is required');
        }
        return $this->advance(
            $actor,
            $this->mustFind($id),
            AssetDecommission::STATUS_CANCELLED,
            'asset_decommissions.manage',
            [
                'cancelled_at' => $this->now(),
                'cancelled_by' => (int) $actor->id,
                'cancelled_reason' => $reason,
            ],
            $reason,
        );
    }

    private ?int $lastAuditLogId = null;

    /**
     * Shared transition helper: permission → matrix check → repo write →
     * audit log. The caller has already loaded the current row (so it can
     * apply row-aware rules first, e.g. requires_wipe checks).
     *
     * @param array<string, mixed> $sideEffects
     */
    private function advance(
        User $actor,
        AssetDecommission $current,
        string $toStatus,
        string $permission,
        array $sideEffects,
        ?string $note,
    ): AssetDecommission {
        $this->gate->assert($actor, $permission);

        if (!AssetDecommission::canTransition($current->status, $toStatus)) {
            $allowed = AssetDecommission::allowedTransitionsFrom($current->status);
            throw new InvalidArgumentException(sprintf(
                'Illegal decommission transition: %s → %s (allowed from %s: %s)',
                $current->status,
                $toStatus,
                $current->status,
                $allowed === [] ? '<none — terminal>' : implode(', ', $allowed),
            ));
        }

        $updated = $this->repository->applyTransition(
            $current->id,
            $toStatus,
            (int) $actor->id,
            $sideEffects,
        );

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
        AssetDecommission $row,
        ?string $from,
        string $to,
        int $actorId,
        array $context = [],
    ): void {
        $this->lastAuditLogId = null;
        if ($this->audit === null) {
            return;
        }

        $context['from'] = $from;
        $context['to'] = $to;

        $this->lastAuditLogId = $this->audit->logAndGetId(new AuditEntry(
            'decommission.transitioned',
            'asset_decommission',
            (string) $row->id,
            $actorId > 0 ? $actorId : null,
            $context,
        ));
    }

    private function mustFind(int $id): AssetDecommission
    {
        $row = $this->repository->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("Decommission {$id} not found");
        }
        return $row;
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
