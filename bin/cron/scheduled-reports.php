<?php

/**
 * Scheduled Reports Runner
 *
 * Walks every active row in `scheduled_reports` whose next_run_at has
 * passed, executes the underlying saved report, exports the result in
 * the configured format (csv|json), and emails it to the configured
 * recipient list. Each run also stamps last_run_at, recomputes
 * next_run_at, and records the status (succeeded/failed) so ops can
 * spot a stuck schedule from the catalog UI.
 *
 * The cron entry calls this every 15 minutes. Schedules with a sub-
 * 15-minute cadence still resolve correctly — the runner walks all due
 * rows in a single tick.
 *
 * Recommended schedule: every 15 minutes. Schedules finer than that
 * (e.g. * / 5 * * * *) drift toward the 15-minute granularity, which
 * is acceptable for report-style notifications.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Models\ScheduledReport;
use App\Services\Reporting\ReportCatalogService;
use App\Services\Reporting\ReportExecutionRepository;
use App\Services\Reporting\ReportExportService;
use App\Services\Reporting\SavedReportRepository;
use App\Services\Reporting\SavedReportService;
use App\Services\Reporting\ScheduledReportRepository;
use App\Services\Reporting\ScheduledReportService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\PolicyRegistry;
use App\Support\Auth\RolePermissions;
use App\Support\Env;
use App\Support\Notifications\LogMailDriver;
use App\Support\Notifications\NotificationLogRepository;
use App\Support\Notifications\SmtpMailDriver;

$env = new Env(__DIR__ . '/../../.env');

$dbConfig = [
    'driver' => $env->get('DB_DRIVER', 'mysql'),
    'host' => $env->get('DB_HOST', '127.0.0.1'),
    'port' => (int) $env->get('DB_PORT', 3306),
    'database' => $env->get('DB_DATABASE', 'phparm'),
    'username' => $env->get('DB_USERNAME', 'root'),
    'password' => $env->get('DB_PASSWORD', ''),
    'charset' => $env->get('DB_CHARSET', 'utf8mb4'),
];

$connection = new Connection($dbConfig);

$rolePermissions = new RolePermissions(require __DIR__ . '/../../config/auth.php');
$gate = new AccessGate($rolePermissions, new PolicyRegistry());

$catalog = new ReportCatalogService($connection);
$savedRepo = new SavedReportRepository($connection);
$scheduleRepo = new ScheduledReportRepository($connection);
$executionRepo = new ReportExecutionRepository($connection);
$exporter = new ReportExportService();
$reportService = new SavedReportService($savedRepo, $catalog, $executionRepo, $gate);
$scheduleService = new ScheduledReportService(
    $scheduleRepo,
    $savedRepo,
    $reportService,
    $exporter,
    $gate
);

// Resolve a mail driver. SMTP if env-configured, else log driver
// so dev environments still get an audit trail without a real
// outbound channel.
$mailDriver = null;
$smtpHost = $env->get('SMTP_HOST');
if (!empty($smtpHost)) {
    $mailDriver = new SmtpMailDriver(
        $smtpHost,
        (int) $env->get('SMTP_PORT', 587),
        $env->get('SMTP_USERNAME', ''),
        $env->get('SMTP_PASSWORD', ''),
        $env->get('SMTP_ENCRYPTION', 'tls')
    );
} else {
    $mailDriver = new LogMailDriver(new NotificationLogRepository($connection));
}

$fromName = $env->get('MAIL_FROM_NAME', 'PHPArm Reports');
$fromAddress = $env->get('MAIL_FROM_ADDRESS', 'reports@example.com');

echo sprintf("[%s] Scheduled reports runner started\n", date('Y-m-d H:i:s'));

$emailDispatcher = static function (
    ScheduledReport $schedule,
    string $body,
    string $attachmentBytes,
    array $recipients
) use ($mailDriver, $fromName, $fromAddress): void {
    $subject = '[Report] ' . $schedule->name;
    // The MailDriverInterface is text-only. We inline the export bytes
    // directly into the body when small (< 64KB) and otherwise summarize
    // — a follow-up can add real attachment support to the driver.
    $size = strlen($attachmentBytes);
    $rendered = $body . "\n\n--- " . strtoupper($schedule->output_format) . " (" . $size . " bytes) ---\n";
    if ($size < 65536) {
        $rendered .= $attachmentBytes;
    } else {
        $rendered .= "[truncated — output exceeds 64KB inline limit]";
    }
    foreach ($recipients as $to) {
        $mailDriver->send($to, $subject, $rendered, $fromName, $fromAddress);
    }
};

$results = $scheduleService->processDue($emailDispatcher);

$succeeded = 0;
$failed = 0;
foreach ($results as $r) {
    if (($r['status'] ?? '') === 'succeeded') {
        $succeeded++;
    } else {
        $failed++;
    }
    echo sprintf(
        "  schedule=%d status=%s%s%s\n",
        $r['schedule_id'] ?? 0,
        $r['status'] ?? '?',
        isset($r['rows']) ? ' rows=' . $r['rows'] : '',
        isset($r['error']) ? ' error=' . $r['error'] : ''
    );
}

echo sprintf(
    "[%s] Scheduled reports runner finished: %d ok, %d failed (%d total)\n",
    date('Y-m-d H:i:s'),
    $succeeded,
    $failed,
    count($results)
);

exit($failed > 0 ? 1 : 0);
