<?php

namespace App\Services\Assets;

use App\Models\AssetLease;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use Throwable;

/**
 * Lease expiry alert worker — Phase 13 (M3) of docs/woms-expansion-plan.md
 * (task #120).
 *
 * Runs once per day. For every active lease whose end_date is within 90 days,
 * resolves the *single applicable milestone* — the closest of {90, 60, 30, 0}
 * the lease has crossed below — and fires the corresponding notification iff
 * the per-lease alert column for that milestone is still NULL.
 *
 * Stamping the column makes the worker idempotent: a same-day re-run is a
 * no-op, and a backfill (e.g. a lease created at 25 days remaining) only
 * fires the milestone that's actually applicable now (30-day) rather than
 * blasting the historical 60/90 notices.
 */
class LeaseExpiryAlertService
{
    private const MILESTONE_TEMPLATE = 'lease.expiring';

    public function __construct(
        private readonly AssetLeaseRepository $leases,
        private readonly NotificationDispatcher $dispatcher,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * @param array<int, string> $recipients
     * @return array{sent: array<int, array{lease_id:int, milestone:int}>,
     *               failed: array<int, array{lease_id:int, milestone:int, error:string}>,
     *               skipped: int}
     */
    public function runDaily(array $recipients, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $summary = ['sent' => [], 'failed' => [], 'skipped' => 0];

        if ($recipients === []) {
            return $summary;
        }

        foreach ($this->leases->expiringWithin(90, $today) as $lease) {
            $daysLeft = self::daysBetween($today, $lease->end_date);
            $milestone = self::applicableMilestone($daysLeft);
            if ($milestone === null) {
                continue;
            }
            if (self::alreadySent($lease, $milestone)) {
                $summary['skipped']++;
                continue;
            }

            try {
                $this->dispatch($lease, $milestone, $daysLeft, $recipients, $today);
                $this->leases->markAlertSent($lease->id, $milestone);
                $this->logAudit($lease, $milestone, $daysLeft, $recipients);
                $summary['sent'][] = ['lease_id' => $lease->id, 'milestone' => $milestone];
            } catch (Throwable $e) {
                $summary['failed'][] = [
                    'lease_id' => $lease->id,
                    'milestone' => $milestone,
                    'error' => $e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * @param array<int, string> $recipients
     */
    private function dispatch(
        AssetLease $lease,
        int $milestone,
        int $daysLeft,
        array $recipients,
        string $today,
    ): void {
        $label = self::milestoneLabel($milestone);
        $subject = sprintf(
            '[Lease %s] %s expires %s (%d day%s left)',
            $label,
            $lease->lessor_name !== '' ? $lease->lessor_name : ('lease #' . $lease->id),
            $lease->end_date,
            $daysLeft,
            $daysLeft === 1 ? '' : 's',
        );

        $data = [
            'lessor_name' => $lease->lessor_name,
            'lease_number' => $lease->lease_number ?? ('#' . $lease->id),
            'lease_id' => (string) $lease->id,
            'site_asset_id' => (string) $lease->site_asset_id,
            'customer_id' => $lease->customer_id !== null ? (string) $lease->customer_id : '',
            'start_date' => $lease->start_date,
            'end_date' => $lease->end_date,
            'days_left' => (string) $daysLeft,
            'milestone_label' => $label,
            'today' => $today,
        ];

        foreach ($recipients as $to) {
            $this->dispatcher->sendMail(self::MILESTONE_TEMPLATE, $to, $data, $subject);
        }
    }

    /**
     * @param array<int, string> $recipients
     */
    private function logAudit(AssetLease $lease, int $milestone, int $daysLeft, array $recipients): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry(
            'asset_lease.alert_sent',
            'asset_lease',
            (string) $lease->id,
            null,
            [
                'milestone_days' => $milestone,
                'days_left' => $daysLeft,
                'end_date' => $lease->end_date,
                'recipients' => $recipients,
            ]
        ));
    }

    public static function applicableMilestone(int $daysLeft): ?int
    {
        if ($daysLeft < 0) {
            return null;
        }
        if ($daysLeft <= 0) {
            return 0;
        }
        if ($daysLeft <= 30) {
            return 30;
        }
        if ($daysLeft <= 60) {
            return 60;
        }
        if ($daysLeft <= 90) {
            return 90;
        }
        return null;
    }

    public static function alreadySent(AssetLease $lease, int $milestone): bool
    {
        return match ($milestone) {
            90 => $lease->alert_90d_sent_at !== null,
            60 => $lease->alert_60d_sent_at !== null,
            30 => $lease->alert_30d_sent_at !== null,
            0 => $lease->alert_0d_sent_at !== null,
            default => true,
        };
    }

    public static function milestoneLabel(int $milestone): string
    {
        return match ($milestone) {
            90 => '90-day notice',
            60 => '60-day notice',
            30 => '30-day notice',
            0 => 'expiry',
            default => $milestone . '-day notice',
        };
    }

    private static function daysBetween(string $today, string $endDate): int
    {
        $diff = strtotime($endDate) - strtotime($today);
        return (int) floor($diff / 86400);
    }
}
