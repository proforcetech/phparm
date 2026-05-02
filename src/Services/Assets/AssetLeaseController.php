<?php

namespace App\Services\Assets;

use App\Models\AssetLease;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/asset-leases — Phase 13 (M3) of
 * docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   asset_leases.view   — read
 *   asset_leases.manage — write (create/update/decision/terminate)
 *
 * The customer-portal slice (see Phase 13 task #123) will reuse this
 * controller behind a separate gate that scopes results by customer_id.
 */
class AssetLeaseController
{
    public function __construct(
        private readonly AssetLeaseRepository $repository,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{leases: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'asset_leases.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->repository->list($filters, $perPage, $offset);
        $total = $this->repository->count($filters);

        return [
            'leases' => array_map([self::class, 'toArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->gate->assert($user, 'asset_leases.view');

        $lease = $this->repository->findById($id);
        if ($lease === null) {
            throw new InvalidArgumentException("Asset lease {$id} not found");
        }

        return self::toArray($lease);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body): array
    {
        $this->gate->assert($user, 'asset_leases.manage');

        $assetId = (int) ($body['site_asset_id'] ?? 0);
        $lessor = trim((string) ($body['lessor_name'] ?? ''));
        $startDate = trim((string) ($body['start_date'] ?? ''));
        $endDate = trim((string) ($body['end_date'] ?? ''));
        if ($assetId <= 0 || $lessor === '' || $startDate === '' || $endDate === '') {
            throw new InvalidArgumentException(
                'site_asset_id, lessor_name, start_date, and end_date are required'
            );
        }
        if ($endDate < $startDate) {
            throw new InvalidArgumentException('end_date must be on or after start_date');
        }

        $lease = $this->repository->create([
            'site_asset_id' => $assetId,
            'customer_id' => $body['customer_id'] ?? null,
            'lessor_name' => $lessor,
            'lessor_contact' => $body['lessor_contact'] ?? null,
            'lease_number' => $body['lease_number'] ?? null,
            'start_date' => $startDate,
            'end_date' => $endDate,
            'monthly_payment_cents' => $body['monthly_payment_cents'] ?? null,
            'payment_schedule' => $body['payment_schedule'] ?? AssetLease::SCHEDULE_MONTHLY,
            'mileage_cap' => $body['mileage_cap'] ?? null,
            'current_mileage' => $body['current_mileage'] ?? null,
            'residual_value_cents' => $body['residual_value_cents'] ?? null,
            'buyout_price_cents' => $body['buyout_price_cents'] ?? null,
            'status' => $body['status'] ?? AssetLease::STATUS_ACTIVE,
            'terms' => $body['terms'] ?? null,
            'notes' => $body['notes'] ?? null,
            'attachments' => $body['attachments'] ?? null,
        ]);

        return self::toArray($lease);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'asset_leases.manage');

        if ($this->repository->findById($id) === null) {
            throw new InvalidArgumentException("Asset lease {$id} not found");
        }

        $allowed = [
            'customer_id', 'lessor_name', 'lessor_contact', 'lease_number',
            'start_date', 'end_date', 'monthly_payment_cents', 'payment_schedule',
            'mileage_cap', 'current_mileage', 'residual_value_cents',
            'buyout_price_cents', 'status', 'end_of_lease_decision',
            'terms', 'notes', 'attachments',
        ];

        $updates = [];
        foreach ($allowed as $key) {
            if (array_key_exists($key, $body)) {
                $updates[$key] = $body[$key];
            }
        }

        if (isset($updates['start_date'], $updates['end_date'])
            && $updates['end_date'] < $updates['start_date']
        ) {
            throw new InvalidArgumentException('end_date must be on or after start_date');
        }

        $updated = $this->repository->update($id, $updates);
        return self::toArray($updated);
    }

    /**
     * Capture an end-of-lease decision (renew / buyout / return / replace).
     * Sets status to its pending companion. Downstream workflow dispatch
     * (handing off to acquisition or decommission) is intentionally NOT done
     * here — it lives in the acquisition/decommission controllers per Phase 13
     * task split.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function decide(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'asset_leases.manage');

        $decision = trim((string) ($body['decision'] ?? ''));
        if ($decision === '') {
            throw new InvalidArgumentException('decision is required');
        }

        $lease = $this->repository->findById($id);
        if ($lease === null) {
            throw new InvalidArgumentException("Asset lease {$id} not found");
        }

        $updated = $this->repository->recordDecision($id, $decision, (int) $user->id);
        return self::toArray($updated);
    }

    /**
     * Hard-terminate a lease before its end_date. Use sparingly — the normal
     * exit path is record-decision + downstream workflow.
     *
     * @return array<string, mixed>
     */
    public function terminate(User $user, int $id): array
    {
        $this->gate->assert($user, 'asset_leases.manage');

        if ($this->repository->findById($id) === null) {
            throw new InvalidArgumentException("Asset lease {$id} not found");
        }

        $updated = $this->repository->terminate($id);
        return self::toArray($updated);
    }

    /**
     * @return array<string, mixed>
     */
    public static function toArray(AssetLease $lease): array
    {
        return [
            'id' => $lease->id,
            'site_asset_id' => $lease->site_asset_id,
            'customer_id' => $lease->customer_id,
            'lessor_name' => $lease->lessor_name,
            'lessor_contact' => $lease->lessor_contact,
            'lease_number' => $lease->lease_number,
            'start_date' => $lease->start_date,
            'end_date' => $lease->end_date,
            'monthly_payment_cents' => $lease->monthly_payment_cents,
            'payment_schedule' => $lease->payment_schedule,
            'mileage_cap' => $lease->mileage_cap,
            'current_mileage' => $lease->current_mileage,
            'residual_value_cents' => $lease->residual_value_cents,
            'buyout_price_cents' => $lease->buyout_price_cents,
            'status' => $lease->status,
            'end_of_lease_decision' => $lease->end_of_lease_decision,
            'decision_made_at' => $lease->decision_made_at,
            'decision_made_by' => $lease->decision_made_by,
            'alerts_sent' => [
                '90d' => $lease->alert_90d_sent_at,
                '60d' => $lease->alert_60d_sent_at,
                '30d' => $lease->alert_30d_sent_at,
                '0d' => $lease->alert_0d_sent_at,
            ],
            'terms' => $lease->terms,
            'notes' => $lease->notes,
            'attachments' => $lease->attachments,
            'created_at' => $lease->created_at,
            'updated_at' => $lease->updated_at,
        ];
    }
}
