<?php

/**
 * Job Density Snapshot Generator
 *
 * This cron job generates hourly snapshots of job density for heatmap
 * visualization and demand prediction.
 *
 * Recommended: Run every hour
 *
 * Example cron entry:
 * 0 * * * * php /path/to/bin/cron/job-density-snapshot.php >> /var/log/job-density.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;

$config = require __DIR__ . '/../../config/app.php';
$connection = new Connection($config['database']);

echo "[" . date('Y-m-d H:i:s') . "] Job Density Snapshot Generator started\n";

try {
    $pdo = $connection->pdo();
    $today = date('Y-m-d');
    $currentHour = (int) date('G');

    // Grid resolution: approximately 1km squares (0.01 degrees ≈ 1km)
    $gridSize = 0.01;

    // Get job counts grouped by grid location from dispatch requirements
    $stmt = $pdo->prepare(
        'SELECT
            ROUND(pickup_latitude / :grid_size) * :grid_size2 as grid_lat,
            ROUND(pickup_longitude / :grid_size3) * :grid_size4 as grid_lng,
            COUNT(*) as job_count
         FROM dispatch_requirements
         WHERE scheduled_start >= :start_time
         AND scheduled_start < :end_time
         AND pickup_latitude IS NOT NULL
         AND pickup_longitude IS NOT NULL
         GROUP BY grid_lat, grid_lng'
    );

    $startTime = $today . ' ' . sprintf('%02d', $currentHour) . ':00:00';
    $endTime = $today . ' ' . sprintf('%02d', $currentHour + 1) . ':00:00';

    $stmt->execute([
        'grid_size' => $gridSize,
        'grid_size2' => $gridSize,
        'grid_size3' => $gridSize,
        'grid_size4' => $gridSize,
        'start_time' => $startTime,
        'end_time' => $endTime,
    ]);

    $gridData = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Also get data from workorders for broader coverage
    $workorderStmt = $pdo->prepare(
        'SELECT
            ROUND(dr.pickup_latitude / :grid_size) * :grid_size2 as grid_lat,
            ROUND(dr.pickup_longitude / :grid_size3) * :grid_size4 as grid_lng,
            COUNT(*) as job_count
         FROM workorders w
         INNER JOIN dispatch_requirements dr ON dr.dispatch_reference = w.number
         WHERE w.created_at >= :start_time
         AND w.created_at < :end_time
         AND dr.pickup_latitude IS NOT NULL
         AND dr.pickup_longitude IS NOT NULL
         GROUP BY grid_lat, grid_lng'
    );

    $workorderStmt->execute([
        'grid_size' => $gridSize,
        'grid_size2' => $gridSize,
        'grid_size3' => $gridSize,
        'grid_size4' => $gridSize,
        'start_time' => $startTime,
        'end_time' => $endTime,
    ]);

    $workorderData = $workorderStmt->fetchAll(PDO::FETCH_ASSOC);

    // Merge data from both sources
    $mergedData = [];
    foreach (array_merge($gridData, $workorderData) as $row) {
        $key = $row['grid_lat'] . ',' . $row['grid_lng'];
        if (!isset($mergedData[$key])) {
            $mergedData[$key] = [
                'grid_lat' => $row['grid_lat'],
                'grid_lng' => $row['grid_lng'],
                'job_count' => 0,
            ];
        }
        $mergedData[$key]['job_count'] += (int) $row['job_count'];
    }

    // Insert snapshots
    $insertStmt = $pdo->prepare(
        'INSERT INTO job_density_snapshots
            (snapshot_date, snapshot_hour, grid_lat, grid_lng, job_count, created_at)
         VALUES
            (:date, :hour, :lat, :lng, :count, NOW())
         ON DUPLICATE KEY UPDATE job_count = VALUES(job_count)'
    );

    $insertedCount = 0;
    foreach ($mergedData as $data) {
        if ($data['grid_lat'] === null || $data['grid_lng'] === null) {
            continue;
        }

        $insertStmt->execute([
            'date' => $today,
            'hour' => $currentHour,
            'lat' => $data['grid_lat'],
            'lng' => $data['grid_lng'],
            'count' => $data['job_count'],
        ]);
        $insertedCount++;
    }

    echo "[" . date('Y-m-d H:i:s') . "] Inserted/updated $insertedCount density snapshots for hour $currentHour\n";

    // Clean up old data (keep last 90 days)
    $cleanupStmt = $pdo->prepare(
        'DELETE FROM job_density_snapshots WHERE snapshot_date < DATE_SUB(CURDATE(), INTERVAL 90 DAY)'
    );
    $cleanupStmt->execute();
    $deletedCount = $cleanupStmt->rowCount();

    if ($deletedCount > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Cleaned up $deletedCount old snapshot records\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Job Density Snapshot Generator completed successfully\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);
