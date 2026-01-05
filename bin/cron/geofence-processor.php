<?php

/**
 * Geofence Processor
 *
 * This cron job monitors driver locations against geofences and triggers
 * automatic state transitions and idle alerts.
 *
 * Recommended: Run every 30-60 seconds via cron or supervisor
 *
 * Example cron entry:
 * * * * * * php /path/to/bin/cron/geofence-processor.php >> /var/log/geofence-processor.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Dispatch\GeofencingService;

$config = require __DIR__ . '/../../config/app.php';
$connection = new Connection($config['database']);

$geofencingService = new GeofencingService($connection);

echo "[" . date('Y-m-d H:i:s') . "] Geofence Processor started\n";

try {
    // Process geofence checks
    $result = $geofencingService->processGeofenceChecks();

    if (!empty($result['events'])) {
        echo "[" . date('Y-m-d H:i:s') . "] Detected " . count($result['events']) . " geofence events\n";

        foreach ($result['events'] as $event) {
            $eventType = $event['event_type'] ?? 'unknown';
            $geofenceId = $event['geofence_id'] ?? 'N/A';
            $driverId = $event['driver_profile_id'] ?? 'N/A';

            echo "  - Event: $eventType at geofence #$geofenceId for driver #$driverId\n";
        }
    }

    if (!empty($result['actions'])) {
        echo "[" . date('Y-m-d H:i:s') . "] Triggered " . count($result['actions']) . " automatic actions\n";

        foreach ($result['actions'] as $action) {
            $actionResult = $geofencingService->processAutomaticTransition($action);

            if ($actionResult !== null) {
                echo "  - Action: {$action['type']} → " . json_encode($actionResult) . "\n";
            }
        }
    }

    // Detect idle drivers
    $idleAlerts = $geofencingService->detectIdleDrivers();

    if (!empty($idleAlerts)) {
        echo "[" . date('Y-m-d H:i:s') . "] Created " . count($idleAlerts) . " idle driver alerts\n";

        foreach ($idleAlerts as $alert) {
            $driverId = $alert['driver_profile_id'];
            $alertId = $alert['alert']['id'] ?? 'N/A';
            $jobRef = $alert['alert']['job_reference'] ?? 'N/A';

            echo "  - Alert #$alertId: Driver #$driverId stationary on job $jobRef\n";
        }
    }

    // Summary
    $totalEvents = count($result['events'] ?? []);
    $totalActions = count($result['actions'] ?? []);
    $totalAlerts = count($idleAlerts);

    if ($totalEvents === 0 && $totalActions === 0 && $totalAlerts === 0) {
        echo "[" . date('Y-m-d H:i:s') . "] No geofence events or alerts detected\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Geofence Processor completed successfully\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);
