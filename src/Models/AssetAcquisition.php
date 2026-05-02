<?php

namespace App\Models;

/**
 * Acquisition workflow row — Phase 13 (M4) of docs/woms-expansion-plan.md.
 *
 * Orchestrates the front half of the asset lifecycle:
 *   draft → quoted → approved → po_issued → received
 *         → install_scheduled → installed → activated
 * with rejected / cancelled as terminal off-ramps.
 *
 * The transition matrix lives in {@see AssetAcquisition::TRANSITIONS} so the
 * service and the UI can both consume the same source of truth.
 *
 * Per-transition history is captured in `audit_logs` keyed by
 *   entity_type='asset_acquisition', event='acquisition.transitioned'.
 *
 * See migration 160_asset_lease_lifecycle.sql for the schema.
 */
class AssetAcquisition extends BaseModel
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_QUOTED = 'quoted';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_PO_ISSUED = 'po_issued';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_INSTALL_SCHEDULED = 'install_scheduled';
    public const STATUS_INSTALLED = 'installed';
    public const STATUS_ACTIVATED = 'activated';
    public const STATUS_CANCELLED = 'cancelled';

    public const ALLOWED_STATUSES = [
        self::STATUS_DRAFT,
        self::STATUS_QUOTED,
        self::STATUS_APPROVED,
        self::STATUS_REJECTED,
        self::STATUS_PO_ISSUED,
        self::STATUS_RECEIVED,
        self::STATUS_INSTALL_SCHEDULED,
        self::STATUS_INSTALLED,
        self::STATUS_ACTIVATED,
        self::STATUS_CANCELLED,
    ];

    public const TERMINAL_STATUSES = [
        self::STATUS_REJECTED,
        self::STATUS_ACTIVATED,
        self::STATUS_CANCELLED,
    ];

    /**
     * Allowed forward transitions. `cancelled` is reachable from every
     * non-terminal state and is added at lookup time, so we don't repeat it
     * in every row here.
     *
     * @var array<string, array<int, string>>
     */
    public const TRANSITIONS = [
        self::STATUS_DRAFT => [self::STATUS_QUOTED],
        self::STATUS_QUOTED => [self::STATUS_APPROVED, self::STATUS_REJECTED],
        self::STATUS_APPROVED => [self::STATUS_PO_ISSUED],
        self::STATUS_PO_ISSUED => [self::STATUS_RECEIVED],
        self::STATUS_RECEIVED => [self::STATUS_INSTALL_SCHEDULED],
        self::STATUS_INSTALL_SCHEDULED => [self::STATUS_INSTALLED],
        self::STATUS_INSTALLED => [self::STATUS_ACTIVATED],
        // Terminal states have no forward transitions.
        self::STATUS_REJECTED => [],
        self::STATUS_ACTIVATED => [],
        self::STATUS_CANCELLED => [],
    ];

    public int $id = 0;
    public int $customer_id = 0;
    public ?int $site_id = null;
    public ?int $service_line_id = null;
    public ?int $asset_type_id = null;
    public ?int $requested_by_user_id = null;
    public ?int $requested_by_portal_user_id = null;
    public ?string $requested_at = null;
    public string $title = '';
    public ?string $description = null;
    public int $quantity = 1;
    public ?string $target_install_date = null;
    public string $status = self::STATUS_DRAFT;
    public ?int $estimate_id = null;
    public ?string $customer_approved_at = null;
    public ?int $customer_approved_by = null;
    public ?string $customer_rejected_at = null;
    public ?string $customer_rejection_reason = null;
    public ?string $vendor_name = null;
    public ?string $vendor_po_number = null;
    public ?int $vendor_po_total_cents = null;
    public ?string $vendor_po_issued_at = null;
    public ?string $received_at = null;
    public ?int $received_by = null;
    public ?int $install_workorder_id = null;
    public ?string $install_scheduled_at = null;
    public ?string $installed_at = null;
    public ?int $target_site_asset_id = null;
    public ?string $activated_at = null;
    public ?int $activated_by = null;
    public ?string $cancelled_at = null;
    public ?int $cancelled_by = null;
    public ?string $cancelled_reason = null;
    public ?string $last_state_changed_at = null;
    public ?int $last_state_changed_by = null;
    public ?string $created_at = null;
    public ?string $updated_at = null;

    /**
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row): self
    {
        return new self($row);
    }

    public function isTerminal(): bool
    {
        return in_array($this->status, self::TERMINAL_STATUSES, true);
    }

    /**
     * @return array<int, string>
     */
    public static function allowedTransitionsFrom(string $status): array
    {
        $forward = self::TRANSITIONS[$status] ?? [];
        if (in_array($status, self::TERMINAL_STATUSES, true)) {
            return [];
        }
        // cancel is universally available from every non-terminal state.
        return array_values(array_unique(array_merge($forward, [self::STATUS_CANCELLED])));
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::allowedTransitionsFrom($from), true);
    }
}
