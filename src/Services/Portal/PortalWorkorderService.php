<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;
use App\Models\Workorder;
use App\Models\WorkorderJob;
use App\Models\WorkorderItem;
use App\Models\WorkorderStatusHistory;
use App\Services\Customer\CustomerRepository;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Phase 2c — read-only workorder surface for the customer portal.
 *
 * Visibility model: portal_account.company_id → list customer_ids in that
 * company → query workorders.customer_id IN (...). The legacy workorders
 * schema doesn't carry company_id directly so we resolve via customers,
 * matching how PortalBillingService scopes invoices. We deliberately omit
 * `internal_notes` from the serialized payload — that field is for staff
 * eyes only.
 *
 * Status filter mirrors the existing WorkorderRepository::list filter; we
 * default to all visible statuses but allow `?status_bucket=open|closed`
 * shortcuts so the UI doesn't have to enumerate them.
 */
class PortalWorkorderService
{
    public const OPEN_STATUSES = [
        Workorder::STATUS_PENDING,
        Workorder::STATUS_IN_PROGRESS,
        Workorder::STATUS_ON_HOLD,
        Workorder::STATUS_PARTS_PENDING,
        Workorder::STATUS_AWAITING_AUTHORIZATION,
        Workorder::STATUS_QC_REQUIRED,
        Workorder::STATUS_READY_FOR_PICKUP,
    ];

    public const CLOSED_STATUSES = [
        Workorder::STATUS_COMPLETED,
        Workorder::STATUS_CANCELLED,
        Workorder::STATUS_GOA,
    ];

    public function __construct(
        private readonly WorkorderRepository $workorders,
        private readonly CustomerRepository $customers,
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function listForPortal(User $user, PortalAccount $account, array $query = []): array
    {
        $this->assertUsable($account);
        $customerIds = $this->customers->listIdsForCompany($account->company_id);
        if ($customerIds === []) {
            return ['data' => [], 'total' => 0];
        }

        $statuses = $this->resolveStatusFilter($query);
        $limit = $this->intOr($query['limit'] ?? null, 50, 1, 200);
        $offset = $this->intOr($query['offset'] ?? null, 0, 0, PHP_INT_MAX);

        // Repository::list takes a single customer_id. Iterate per-customer
        // (portal_account companies are usually a small set of customers)
        // and aggregate, then sort + page in PHP. For accounts with many
        // customers this becomes O(C*S) queries — acceptable for portal
        // usage where the user is reading one screen at a time. If we
        // ever want true server-side paging we'd push customer_id IN(...)
        // into the repo.
        $rows = [];
        foreach ($customerIds as $customerId) {
            foreach ($this->workorders->list(
                ['customer_id' => $customerId, 'status' => $statuses],
                500,
                0,
            ) as $wo) {
                $rows[] = $wo;
            }
        }
        usort(
            $rows,
            static fn(Workorder $a, Workorder $b) => strcmp((string) $b->created_at, (string) $a->created_at),
        );
        $total = count($rows);
        $page = array_slice($rows, $offset, $limit);

        return [
            'data' => array_map(
                fn(Workorder $wo) => $this->serializeSummary($wo),
                $page,
            ),
            'total' => $total,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getForPortal(User $user, PortalAccount $account, int $workorderId): array
    {
        $wo = $this->loadScoped($account, $workorderId);
        $jobs = $this->workorders->getJobsWithItems($wo->id);
        $history = $this->workorders->getStatusHistory($wo->id);

        return array_merge(
            $this->serializeSummary($wo),
            [
                'customer_notes' => $wo->customer_notes,
                'started_at' => $wo->started_at,
                'completed_at' => $wo->completed_at,
                'estimated_completion' => $wo->estimated_completion,
                'mileage_in' => $wo->mileage_in,
                'mileage_out' => $wo->mileage_out,
                'subtotal' => $wo->subtotal,
                'tax' => $wo->tax,
                'discounts' => $wo->discounts,
                'shop_fee' => $wo->shop_fee,
                'call_out_fee' => $wo->call_out_fee,
                'jobs' => array_map(
                    fn(array $j) => [
                        'job' => $this->serializeJob($j['job']),
                        'items' => array_map(
                            fn(WorkorderItem $i) => $this->serializeItem($i),
                            $j['items'],
                        ),
                    ],
                    $jobs,
                ),
                'status_history' => array_map(
                    fn(WorkorderStatusHistory $h) => [
                        'from_status' => $h->from_status,
                        'to_status' => $h->to_status,
                        'notes' => $h->notes,
                        'created_at' => $h->created_at,
                    ],
                    $history,
                ),
            ],
        );
    }

    private function loadScoped(PortalAccount $account, int $workorderId): Workorder
    {
        $this->assertUsable($account);
        if ($workorderId <= 0) {
            throw new InvalidArgumentException('workorder id is required');
        }
        $wo = $this->workorders->find($workorderId);
        if ($wo === null) {
            throw new InvalidArgumentException("workorder {$workorderId} not found");
        }
        $customer = $this->customers->find($wo->customer_id);
        if ($customer === null
            || (int) ($customer->company_id ?? 0) !== $account->company_id
        ) {
            throw new UnauthorizedException('workorder belongs to a different company');
        }
        return $wo;
    }

    /**
     * @param array<string, mixed> $query
     * @return array<int, string>
     */
    private function resolveStatusFilter(array $query): array
    {
        if (isset($query['status']) && is_string($query['status']) && $query['status'] !== '') {
            // Caller passed a single status — pass through if known.
            $status = $query['status'];
            if (in_array($status, Workorder::ALLOWED_STATUSES, true)) {
                return [$status];
            }
            throw new InvalidArgumentException("unknown workorder status: {$status}");
        }
        $bucket = isset($query['status_bucket']) && is_string($query['status_bucket'])
            ? strtolower($query['status_bucket'])
            : '';
        if ($bucket === 'open') return self::OPEN_STATUSES;
        if ($bucket === 'closed') return self::CLOSED_STATUSES;
        return Workorder::ALLOWED_STATUSES;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    private function intOr(mixed $value, int $default, int $min, int $max): int
    {
        if ($value === null || $value === '') {
            return $default;
        }
        $n = (int) $value;
        if ($n < $min) return $min;
        if ($n > $max) return $max;
        return $n;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSummary(Workorder $wo): array
    {
        return [
            'id' => $wo->id,
            'number' => $wo->number,
            'status' => $wo->status,
            'priority' => $wo->priority,
            'type' => $wo->type,
            'customer_id' => $wo->customer_id,
            'vehicle_id' => $wo->vehicle_id,
            'site_asset_id' => $wo->site_asset_id,
            'service_line_id' => $wo->service_line_id,
            'estimated_completion' => $wo->estimated_completion,
            'grand_total' => $wo->grand_total,
            'created_at' => $wo->created_at,
            'updated_at' => $wo->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeJob(WorkorderJob $job): array
    {
        return [
            'id' => $job->id,
            'title' => $job->title,
            'status' => $job->status,
            'reference' => $job->reference,
            'subtotal' => $job->subtotal,
            'tax' => $job->tax,
            'total' => $job->total,
            'started_at' => $job->started_at,
            'completed_at' => $job->completed_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeItem(WorkorderItem $item): array
    {
        return [
            'id' => $item->id,
            'description' => $item->description,
            'quantity' => $item->quantity,
            'unit_price' => $item->unit_price,
            'line_total' => $item->line_total,
            'type' => $item->type,
            'sku' => $item->sku,
            'taxable' => $item->taxable,
        ];
    }
}
