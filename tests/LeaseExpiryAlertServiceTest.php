<?php

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Models\AssetLease;
use App\Services\Assets\AssetLeaseRepository;
use App\Services\Assets\LeaseExpiryAlertService;
use App\Support\Notifications\NotificationDispatcher;

/**
 * Phase 13 (M3) of docs/woms-expansion-plan.md — task #120.
 *
 * Covers:
 *   * milestone resolution (90/60/30/0) for assorted days_left values
 *   * idempotency: a stamped alert column suppresses re-sends
 *   * single milestone per lease per run (no blast of all four when a
 *     newly-created lease is already inside the 30-day window)
 *   * SMTP failure leaves the alert column unstamped so the next run retries
 *   * empty recipient list returns a no-op summary instead of bombing
 */

class LeaseAlertFakeNotifier extends NotificationDispatcher
{
    /** @var array<int, array{templateKey:string, to:string, data:array<string,mixed>, subject:?string}> */
    public array $sent = [];
    public bool $throw = false;

    public function __construct()
    {
    }

    public function sendMail(string $templateKey, string $to, array $data, ?string $subject = null): void
    {
        if ($this->throw) {
            throw new RuntimeException('simulated SMTP failure');
        }
        $this->sent[] = compact('templateKey', 'to', 'data', 'subject');
    }
}

class LeaseAlertFakeRepo extends AssetLeaseRepository
{
    /** @var array<int, AssetLease> */
    public array $store = [];
    /** @var array<int, array<int, string>> */
    public array $marks = [];

    public function __construct()
    {
    }

    public function expiringWithin(int $days, ?string $today = null): array
    {
        $today = $today ?? date('Y-m-d');
        $cutoff = date('Y-m-d', strtotime($today . ' +' . max(0, $days) . ' days'));

        return array_values(array_filter(
            $this->store,
            static function (AssetLease $l) use ($today, $cutoff): bool {
                return $l->status === AssetLease::STATUS_ACTIVE
                    && $l->end_date >= $today
                    && $l->end_date <= $cutoff;
            }
        ));
    }

    public function markAlertSent(int $id, int $milestoneDays, ?string $when = null): void
    {
        $when = $when ?? date('Y-m-d H:i:s');
        $this->marks[$id][$milestoneDays] = $when;
        if (!isset($this->store[$id])) {
            return;
        }
        $col = match ($milestoneDays) {
            90 => 'alert_90d_sent_at',
            60 => 'alert_60d_sent_at',
            30 => 'alert_30d_sent_at',
            0 => 'alert_0d_sent_at',
            default => null,
        };
        if ($col !== null) {
            $this->store[$id]->{$col} = $when;
        }
    }
}

function leaseAlertMakeLease(int $id, string $endDate, array $alertsSent = []): AssetLease
{
    $lease = new AssetLease();
    $lease->id = $id;
    $lease->site_asset_id = 100 + $id;
    $lease->lessor_name = 'Acme Leasing #' . $id;
    $lease->lease_number = 'L-' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
    $lease->start_date = date('Y-m-d', strtotime($endDate . ' -1 year'));
    $lease->end_date = $endDate;
    $lease->status = AssetLease::STATUS_ACTIVE;
    foreach ($alertsSent as $milestone) {
        $col = match ($milestone) {
            90 => 'alert_90d_sent_at',
            60 => 'alert_60d_sent_at',
            30 => 'alert_30d_sent_at',
            0 => 'alert_0d_sent_at',
        };
        $lease->{$col} = '2025-01-01 08:00:00';
    }
    return $lease;
}

// -------- milestone resolution -------------------------------------------------

$cases = [
    [120, null], // outside 90-day window
    [91, null],
    [90, 90],
    [85, 90],
    [61, 90],
    [60, 60],
    [55, 60],
    [31, 60],
    [30, 30],
    [15, 30],
    [1, 30],
    [0, 0],
    [-1, null], // already past — repository window keeps these out anyway
];
foreach ($cases as [$daysLeft, $expected]) {
    $got = LeaseExpiryAlertService::applicableMilestone($daysLeft);
    if ($got !== $expected) {
        fwrite(STDERR, "FAIL: applicableMilestone({$daysLeft}) expected " . var_export($expected, true)
            . " got " . var_export($got, true) . "\n");
        exit(1);
    }
}

// -------- end-to-end runDaily --------------------------------------------------

$today = '2026-05-02';
$repo = new LeaseAlertFakeRepo();

// Lease A: 88 days out, no alerts sent → fires 90.
$repo->store[1] = leaseAlertMakeLease(1, date('Y-m-d', strtotime($today . ' +88 days')));
// Lease B: 45 days out, 90 already sent → fires 60.
$repo->store[2] = leaseAlertMakeLease(2, date('Y-m-d', strtotime($today . ' +45 days')), [90]);
// Lease C: 25 days out, 90 + 60 already sent → fires 30.
$repo->store[3] = leaseAlertMakeLease(3, date('Y-m-d', strtotime($today . ' +25 days')), [90, 60]);
// Lease D: expires today, all earlier sent → fires 0.
$repo->store[4] = leaseAlertMakeLease(4, $today, [90, 60, 30]);
// Lease E: 88 days out, 90 already sent → SKIP.
$repo->store[5] = leaseAlertMakeLease(5, date('Y-m-d', strtotime($today . ' +88 days')), [90]);
// Lease F: just created at 25 days, NO alerts sent → fires only 30 (no blast of 60+90).
$repo->store[6] = leaseAlertMakeLease(6, date('Y-m-d', strtotime($today . ' +25 days')));
// Lease G: 200 days out → not in window, repo filters it.
$repo->store[7] = leaseAlertMakeLease(7, date('Y-m-d', strtotime($today . ' +200 days')));
// Lease H: terminated → not active, repo filters it.
$repo->store[8] = leaseAlertMakeLease(8, date('Y-m-d', strtotime($today . ' +10 days')));
$repo->store[8]->status = AssetLease::STATUS_TERMINATED;

$notifier = new LeaseAlertFakeNotifier();
$service = new LeaseExpiryAlertService($repo, $notifier);

$summary = $service->runDaily(['ops@example.com', 'manager@example.com'], $today);

$expectedFirings = [
    [1, 90],
    [2, 60],
    [3, 30],
    [4, 0],
    [6, 30],
];

if (count($summary['sent']) !== count($expectedFirings)) {
    fwrite(STDERR, "FAIL: expected " . count($expectedFirings) . " sends, got " . count($summary['sent'])
        . "\n  sent: " . json_encode($summary['sent']) . "\n");
    exit(1);
}
foreach ($expectedFirings as $i => [$leaseId, $milestone]) {
    if (
        $summary['sent'][$i]['lease_id'] !== $leaseId
        || $summary['sent'][$i]['milestone'] !== $milestone
    ) {
        fwrite(STDERR, "FAIL: send #{$i} expected lease={$leaseId} milestone={$milestone}, got "
            . json_encode($summary['sent'][$i]) . "\n");
        exit(1);
    }
    if (!isset($repo->marks[$leaseId][$milestone])) {
        fwrite(STDERR, "FAIL: alert column not stamped for lease={$leaseId} milestone={$milestone}\n");
        exit(1);
    }
}

if ($summary['skipped'] !== 1) {
    fwrite(STDERR, "FAIL: expected 1 skipped (lease 5 already-sent), got {$summary['skipped']}\n");
    exit(1);
}
if (count($summary['failed']) !== 0) {
    fwrite(STDERR, "FAIL: expected 0 failed, got " . count($summary['failed']) . "\n");
    exit(1);
}

// 5 leases × 2 recipients each = 10 mails.
if (count($notifier->sent) !== 10) {
    fwrite(STDERR, "FAIL: expected 10 mails (5 sends × 2 recipients), got " . count($notifier->sent) . "\n");
    exit(1);
}

// Lease F (id=6) was newly created at 25 days — verify ONLY 30 fired (no
// retroactive 60 or 90).
if (
    isset($repo->marks[6][60])
    || isset($repo->marks[6][90])
    || !isset($repo->marks[6][30])
) {
    fwrite(STDERR, "FAIL: lease 6 should only have 30-day stamped, got " . json_encode($repo->marks[6]) . "\n");
    exit(1);
}

// Re-running the SAME day must be a complete no-op (idempotency).
$summary2 = $service->runDaily(['ops@example.com', 'manager@example.com'], $today);
if (count($summary2['sent']) !== 0) {
    fwrite(STDERR, "FAIL: 2nd same-day run should send nothing, sent " . count($summary2['sent']) . "\n");
    exit(1);
}
if ($summary2['skipped'] !== count($expectedFirings) + 1) {
    fwrite(STDERR, "FAIL: 2nd run skipped count expected " . (count($expectedFirings) + 1)
        . ", got {$summary2['skipped']}\n");
    exit(1);
}

// -------- SMTP failure leaves stamp untouched --------------------------------

$repo2 = new LeaseAlertFakeRepo();
$repo2->store[10] = leaseAlertMakeLease(10, date('Y-m-d', strtotime($today . ' +20 days')));
$failingNotifier = new LeaseAlertFakeNotifier();
$failingNotifier->throw = true;
$failingService = new LeaseExpiryAlertService($repo2, $failingNotifier);
$summary3 = $failingService->runDaily(['ops@example.com'], $today);
if (count($summary3['failed']) !== 1) {
    fwrite(STDERR, "FAIL: SMTP failure should yield 1 failure, got " . count($summary3['failed']) . "\n");
    exit(1);
}
if (isset($repo2->marks[10])) {
    fwrite(STDERR, "FAIL: failed send must NOT stamp alert column (would suppress retries)\n");
    exit(1);
}

// -------- empty recipients = no-op --------------------------------------------

$repo3 = new LeaseAlertFakeRepo();
$repo3->store[20] = leaseAlertMakeLease(20, date('Y-m-d', strtotime($today . ' +5 days')));
$noopService = new LeaseExpiryAlertService($repo3, new LeaseAlertFakeNotifier());
$summary4 = $noopService->runDaily([], $today);
if ($summary4['sent'] !== [] || $summary4['failed'] !== [] || $summary4['skipped'] !== 0) {
    fwrite(STDERR, "FAIL: empty recipients must produce zero-everything summary, got "
        . json_encode($summary4) . "\n");
    exit(1);
}
if (isset($repo3->marks[20])) {
    fwrite(STDERR, "FAIL: empty recipients must NOT stamp alert columns\n");
    exit(1);
}

echo "LeaseExpiryAlertServiceTest: OK\n";
