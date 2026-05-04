<?php

/**
 * POS terminal stale-heartbeat sweeper.
 *
 * Phase 16 / S2 of docs/woms-expansion-plan.md.
 *
 * Walks every active POS terminal whose `last_seen_at` is older than its
 * configured `stale_after_seconds` (or has never sent a heartbeat). For
 * each:
 *   1. If `last_alert_ticket_id` is already set on the terminal, skip — the
 *      previous tick already opened a ticket and ingestion will clear the
 *      pointer when a fresh heartbeat lands.
 *   2. Otherwise open a ticket via TicketRepository (source='pos-sweeper'),
 *      stash the ticket id back on the terminal so we don't re-fire, and
 *      record a programming_log entry with action='sweep_alert' so the
 *      audit trail captures the page.
 *
 * Recommended schedule: every minute. Cheap — one indexed query, then a
 * tight loop over only the stale rows.
 */

require __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Models\PosTerminal;
use App\Models\ProgrammingLog;
use App\Services\POS\PosTerminalRepository;
use App\Services\Security\ProgrammingLogRepository;
use App\Services\Tickets\TicketRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Env;

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
$auditConfig = require __DIR__ . '/../../config/audit.php';
$audit = new AuditLogger($connection, $auditConfig);

$terminals = new PosTerminalRepository($connection);
$tickets = new TicketRepository($connection);
$logs = new ProgrammingLogRepository($connection);

$now = date('Y-m-d H:i:s');
$alerted = 0;
$skipped = 0;
$failed = 0;

try {
    $stale = $terminals->listStale($now);

    foreach ($stale as $terminal) {
        if ($terminal->last_alert_ticket_id !== null) {
            // Already paged on this terminal — wait for the next heartbeat
            // (which clears the pointer) or for ops to close the ticket.
            $skipped++;
            continue;
        }

        try {
            $title = sprintf(
                'POS terminal "%s" stopped reporting',
                $terminal->terminal_code
            );
            $description = buildAlertDescription($terminal, $now);

            $ticket = $tickets->create([
                'company_id' => $terminal->customer_id,
                'site_id' => $terminal->site_id,
                'asset_id' => $terminal->site_asset_id,
                'priority' => 'p2_high',
                'severity' => 'sev2',
                'status' => 'new',
                'title' => $title,
                'description' => $description,
                'source' => 'pos-sweeper',
                'source_ref' => 'pos_terminal:' . $terminal->id,
            ]);

            $terminals->setAlertTicket($terminal->id, $ticket->id);

            try {
                $logs->record([
                    'customer_id' => $terminal->customer_id,
                    'site_id' => $terminal->site_id,
                    'target_type' => ProgrammingLog::TARGET_POS_TERMINAL,
                    'target_id' => $terminal->id,
                    'action' => ProgrammingLog::ACTION_SWEEP_ALERT,
                    'summary' => sprintf(
                        'Stale-heartbeat sweep opened ticket %s for terminal "%s"',
                        $ticket->ticket_number,
                        $terminal->terminal_code
                    ),
                    'after_snapshot' => [
                        'alert_ticket_id' => $ticket->id,
                        'alert_ticket_number' => $ticket->ticket_number,
                        'last_seen_at' => $terminal->last_seen_at,
                        'stale_after_seconds' => $terminal->stale_after_seconds,
                        'detected_at' => $now,
                    ],
                    'programmed_by_external' => 'cron:pos-sweeper',
                ]);
            } catch (Throwable $logError) {
                // Programming-log write failure is advisory; the ticket is
                // already filed. Surface via audit logger.
                $audit->log(new AuditEntry(
                    'programming_log.write_failed',
                    'pos_terminal',
                    (string) $terminal->id,
                    null,
                    ['error' => $logError->getMessage()]
                ));
            }

            $audit->log(new AuditEntry(
                'pos_sweeper.alert_opened',
                'pos_terminal',
                (string) $terminal->id,
                null,
                [
                    'terminal_code' => $terminal->terminal_code,
                    'ticket_id' => $ticket->id,
                    'ticket_number' => $ticket->ticket_number,
                ]
            ));

            $alerted++;
        } catch (Throwable $perTerminalError) {
            // Don't let one failed terminal kill the whole sweep — log it
            // and continue so the rest of the fleet still gets paged.
            $failed++;
            fwrite(STDERR, sprintf(
                "[%s] pos-stale-sweeper: terminal_id=%d FAILED: %s\n",
                date('Y-m-d H:i:s'),
                $terminal->id,
                $perTerminalError->getMessage()
            ));
            $audit->log(new AuditEntry(
                'pos_sweeper.alert_failed',
                'pos_terminal',
                (string) $terminal->id,
                null,
                ['error' => $perTerminalError->getMessage()]
            ));
        }
    }

    echo sprintf(
        "[%s] pos-stale-sweeper: stale=%d alerted=%d skipped=%d failed=%d\n",
        date('Y-m-d H:i:s'),
        count($stale),
        $alerted,
        $skipped,
        $failed
    );
    exit($failed === 0 ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, sprintf(
        "[%s] pos-stale-sweeper FAILED: %s\n",
        date('Y-m-d H:i:s'),
        $e->getMessage()
    ));
    exit(1);
}

function buildAlertDescription(PosTerminal $terminal, string $now): string
{
    $lastSeen = $terminal->last_seen_at ?? '(never received)';
    $secondsSilent = $terminal->last_seen_at !== null
        ? max(0, strtotime($now) - strtotime($terminal->last_seen_at))
        : null;

    $lines = [
        "POS terminal `{$terminal->terminal_code}` has not sent a heartbeat in the configured stale window.",
        '',
        "- Site ID: {$terminal->site_id}",
        '- Vendor: ' . ($terminal->vendor ?? 'n/a'),
        '- Model: ' . ($terminal->model ?? 'n/a'),
        '- Serial: ' . ($terminal->serial_number ?? 'n/a'),
        "- Last seen at: {$lastSeen}",
        '- Stale threshold: ' . $terminal->stale_after_seconds . ' seconds',
    ];
    if ($secondsSilent !== null) {
        $lines[] = "- Silent for: {$secondsSilent} seconds";
    }
    $lines[] = '';
    $lines[] = 'This ticket was opened automatically by `bin/cron/pos-stale-sweeper.php` and will be auto-resolved when the next valid heartbeat arrives.';
    return implode("\n", $lines);
}
