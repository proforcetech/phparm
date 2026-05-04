<?php

/**
 * Unified Cron Runner
 *
 * This script provides a single entry point for running all scheduled jobs.
 * It can be configured to run specific jobs or all due jobs based on schedule.
 *
 * Usage:
 *   php bin/cron/run.php                    # Run all due jobs
 *   php bin/cron/run.php --job=reminders    # Run specific job
 *   php bin/cron/run.php --list             # List available jobs
 *   php bin/cron/run.php --help             # Show help
 *
 * Recommended crontab entry (run every minute):
 *   * * * * * php /path/to/bin/cron/run.php >> /var/log/phparm-cron.log 2>&1
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Support\Env;

// Parse command line arguments
$options = getopt('', ['job:', 'list', 'help', 'force', 'quiet']);

if (isset($options['help'])) {
    showHelp();
    exit(0);
}

// Available jobs with their schedules
$jobs = [
    'reminders' => [
        'name' => 'Reminder Campaigns',
        'script' => __DIR__ . '/reminder-campaigns.php',
        'schedule' => '*/15 * * * *', // Every 15 minutes
        'description' => 'Sends due reminder campaigns to customers',
    ],
    'appointments' => [
        'name' => 'Appointment Reminders',
        'script' => __DIR__ . '/appointment-reminders.php',
        'schedule' => '0 * * * *', // Every hour
        'description' => 'Sends reminders for upcoming appointments',
    ],
    'inventory' => [
        'name' => 'Low Stock Alerts',
        'script' => __DIR__ . '/inventory-low-stock.php',
        'schedule' => '0 8 * * *', // Daily at 8 AM
        'description' => 'Sends low stock inventory alerts',
    ],
    'lien-notices' => [
        'name' => 'Lien Notice Automation',
        'script' => __DIR__ . '/storage-lien-notices.php',
        'schedule' => '0 7 * * *', // Daily at 7 AM
        'description' => 'Flags impound cases that have reached the lien notice threshold',
    ],
    'cleanup' => [
        'name' => 'Data Cleanup',
        'script' => __DIR__ . '/data-cleanup.php',
        'schedule' => '0 2 * * *', // Daily at 2 AM
        'description' => 'Cleans up expired and temporary data',
    ],
    'retention-runner' => [
        'name' => 'Data Retention Runner',
        'script' => __DIR__ . '/retention-runner.php',
        'schedule' => '0 3 * * *', // Daily at 3 AM (after cleanup)
        'description' => 'Applies configured retention policies (delete/archive)',
    ],
    'waterfall-dispatch' => [
        'name' => 'Waterfall Dispatch Processor',
        'script' => __DIR__ . '/waterfall-dispatch.php',
        'schedule' => '* * * * *', // Every minute
        'description' => 'Processes expired job offers and advances waterfall sequences',
    ],
    'geofence-processor' => [
        'name' => 'Geofence Processor',
        'script' => __DIR__ . '/geofence-processor.php',
        'schedule' => '* * * * *', // Every minute
        'description' => 'Monitors driver locations for geofence events and idle detection',
    ],
    'job-density' => [
        'name' => 'Job Density Snapshot',
        'script' => __DIR__ . '/job-density-snapshot.php',
        'schedule' => '0 * * * *', // Every hour
        'description' => 'Generates job density data for dispatcher heatmaps',
    ],
    'cms-search-reindex' => [
        'name' => 'CMS Search Reindex',
        'script' => __DIR__ . '/cms-search-reindex.php',
        'schedule' => '0 3 * * 0', // Weekly on Sunday at 3 AM
        'description' => 'Rebuilds the CMS search index for pages and components',
    ],
    'ticket-sla-breach' => [
        'name' => 'Ticket SLA Breach Detector',
        'script' => __DIR__ . '/ticket-sla-breach.php',
        'schedule' => '* * * * *', // Every minute
        'description' => 'Stamps breached_at on running ticket SLA clocks past target',
    ],
    'ticket-escalation' => [
        'name' => 'Ticket Escalation',
        'script' => __DIR__ . '/ticket-escalation.php',
        'schedule' => '*/5 * * * *', // Every 5 minutes
        'description' => 'Applies escalation rules (stale/SLA breach) against open tickets',
    ],
    'contracts-renewal' => [
        'name' => 'Contract Auto-Renewal + Expiry',
        'script' => __DIR__ . '/contracts-renewal.php',
        'schedule' => '0 1 * * *', // Daily at 1 AM
        'description' => 'Auto-renews contracts within notice window and expires non-renewing ones',
    ],
    'pm-generator' => [
        'name' => 'PM Schedule → Ticket Generator',
        'script' => __DIR__ . '/pm-generator.php',
        'schedule' => '0 2 * * *', // Daily at 2 AM
        'description' => 'Generates tickets from due PM schedules and advances cadence',
    ],
    'auth-sweep' => [
        'name' => 'SSO + Trusted-Device Sweep',
        'script' => __DIR__ . '/auth-sweep.php',
        'schedule' => '30 3 * * *', // Daily at 3:30 AM (after retention)
        'description' => 'Expires stale SSO login attempts and purges expired trust tokens',
    ],
    'scheduled-reports' => [
        'name' => 'Scheduled Reports Runner',
        'script' => __DIR__ . '/scheduled-reports.php',
        'schedule' => '*/15 * * * *', // Every 15 minutes
        'description' => 'Runs due saved-report schedules and emails CSV/JSON output to recipients',
    ],
    'integration-sync' => [
        'name' => 'Third-Party Integration Sync',
        'script' => __DIR__ . '/integration-sync.php',
        'schedule' => '*/5 * * * *', // Every 5 minutes
        'description' => 'Pulls data from connected third-party integrations on their cadence',
    ],
    'lease-expiry-alerts' => [
        'name' => 'Asset Lease Expiry Alerts',
        'script' => __DIR__ . '/lease-expiry-alerts.php',
        'schedule' => '0 8 * * *', // Daily at 8 AM
        'description' => 'Sends 90/60/30/0-day notices for active asset leases approaching end_date',
    ],
    'route-visit-generator' => [
        'name' => 'Recurring Route Visit Generator + Overdue Sweep',
        'script' => __DIR__ . '/route-visit-generator.php',
        'schedule' => '*/5 * * * *', // Every 5 minutes
        'description' => 'Materializes route_visits forward through generation_horizon_days and marks overdue planned visits as missed',
    ],
    'pos-stale-sweeper' => [
        'name' => 'POS Terminal Stale-Heartbeat Sweeper',
        'script' => __DIR__ . '/pos-stale-sweeper.php',
        'schedule' => '* * * * *', // Every minute
        'description' => 'Opens alert tickets for POS terminals whose last_seen_at exceeded their stale_after_seconds threshold',
    ],
    'consolidated-monthly-billing' => [
        'name' => 'Consolidated Monthly Billing',
        'script' => __DIR__ . '/consolidated-monthly-billing.php',
        'schedule' => '0 2 1 * *', // 1st of every month at 2 AM
        'description' => 'Generates one bundled monthly statement per opted-in chain customer for the prior calendar month',
    ],
];

if (isset($options['list'])) {
    listJobs($jobs);
    exit(0);
}

$quiet = isset($options['quiet']);
$force = isset($options['force']);

// Run specific job or all due jobs
if (isset($options['job'])) {
    $jobKey = $options['job'];
    if (!isset($jobs[$jobKey])) {
        fwrite(STDERR, "Unknown job: {$jobKey}\n");
        fwrite(STDERR, "Use --list to see available jobs\n");
        exit(1);
    }

    runJob($jobs[$jobKey], $quiet);
} else {
    // Run all due jobs
    $env = new Env(__DIR__ . '/../../.env');
    $lockFile = __DIR__ . '/../../storage/temp/cron.lock';

    // Prevent concurrent runs (unless forced)
    if (!$force && file_exists($lockFile)) {
        $lockTime = (int) file_get_contents($lockFile);
        if (time() - $lockTime < 300) { // 5 minute lock timeout
            if (!$quiet) {
                echo "Another cron process is running. Use --force to override.\n";
            }
            exit(0);
        }
    }

    // Create lock
    file_put_contents($lockFile, time());

    try {
        foreach ($jobs as $key => $job) {
            if (isDue($job['schedule'])) {
                runJob($job, $quiet);
            }
        }
    } finally {
        // Remove lock
        if (file_exists($lockFile)) {
            unlink($lockFile);
        }
    }
}

/**
 * Run a specific job
 */
function runJob(array $job, bool $quiet): void
{
    $script = $job['script'];

    if (!file_exists($script)) {
        fwrite(STDERR, "Job script not found: {$script}\n");
        return;
    }

    if (!$quiet) {
        echo sprintf("[%s] Running: %s\n", date('Y-m-d H:i:s'), $job['name']);
    }

    $startTime = microtime(true);

    // Execute the job script
    $output = [];
    $returnCode = 0;
    exec("php {$script} 2>&1", $output, $returnCode);

    $duration = round(microtime(true) - $startTime, 2);

    if (!$quiet) {
        foreach ($output as $line) {
            echo "  {$line}\n";
        }

        if ($returnCode !== 0) {
            echo sprintf("  [ERROR] Job failed with code %d (%.2fs)\n", $returnCode, $duration);
        } else {
            echo sprintf("  [OK] Completed in %.2fs\n", $duration);
        }
    }
}

/**
 * Check if a cron schedule is due
 */
function isDue(string $schedule): bool
{
    $parts = explode(' ', $schedule);
    if (count($parts) !== 5) {
        return false;
    }

    [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

    $now = [
        (int) date('i'), // minute (0-59)
        (int) date('G'), // hour (0-23)
        (int) date('j'), // day of month (1-31)
        (int) date('n'), // month (1-12)
        (int) date('w'), // day of week (0-6, Sunday=0)
    ];

    $fields = [$minute, $hour, $dayOfMonth, $month, $dayOfWeek];

    foreach ($fields as $index => $field) {
        if (!matchesField($field, $now[$index])) {
            return false;
        }
    }

    return true;
}

/**
 * Check if a cron field matches a value
 */
function matchesField(string $field, int $value): bool
{
    // Wildcard
    if ($field === '*') {
        return true;
    }

    // Simple value
    if (is_numeric($field)) {
        return (int) $field === $value;
    }

    // Step values (*/n)
    if (str_starts_with($field, '*/')) {
        $step = (int) substr($field, 2);
        return $step > 0 && $value % $step === 0;
    }

    // Range (n-m)
    if (str_contains($field, '-')) {
        [$start, $end] = explode('-', $field);
        return $value >= (int) $start && $value <= (int) $end;
    }

    // List (n,m,o)
    if (str_contains($field, ',')) {
        $values = array_map('intval', explode(',', $field));
        return in_array($value, $values, true);
    }

    return false;
}

/**
 * List available jobs
 */
function listJobs(array $jobs): void
{
    echo "Available Cron Jobs:\n";
    echo str_repeat('-', 70) . "\n";

    foreach ($jobs as $key => $job) {
        echo sprintf("  %-15s %s\n", $key, $job['name']);
        echo sprintf("  %-15s Schedule: %s\n", '', $job['schedule']);
        echo sprintf("  %-15s %s\n\n", '', $job['description']);
    }
}

/**
 * Show help
 */
function showHelp(): void
{
    echo <<<'HELP'
Unified Cron Runner

Usage:
  php bin/cron/run.php [options]

Options:
  --job=NAME    Run a specific job by name
  --list        List all available jobs
  --force       Override lock and force run
  --quiet       Suppress output
  --help        Show this help message

Examples:
  php bin/cron/run.php                    Run all due jobs
  php bin/cron/run.php --job=reminders    Run reminder campaigns
  php bin/cron/run.php --job=cleanup      Run data cleanup
  php bin/cron/run.php --list             List all jobs

Crontab Setup:
  To run all jobs on their schedules, add this to your crontab:
  * * * * * php /path/to/bin/cron/run.php --quiet >> /var/log/phparm-cron.log 2>&1

  Or run specific jobs:
  */15 * * * * php /path/to/bin/cron/run.php --job=reminders --quiet
  0 * * * *    php /path/to/bin/cron/run.php --job=appointments --quiet
  0 8 * * *    php /path/to/bin/cron/run.php --job=inventory --quiet
  0 2 * * *    php /path/to/bin/cron/run.php --job=cleanup --quiet

HELP;
}
