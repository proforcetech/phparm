<?php

namespace App\Services\CMS;

use App\CMS\Models\Component;
use App\Database\Connection;
use App\Services\Dispatch\TrafficAwareEtaService;
use DateTimeImmutable;
use PDO;

class CMSDynamicComponentService
{
    private Connection $connection;
    private TrafficAwareEtaService $etaService;

    public function __construct(Connection $connection, ?TrafficAwareEtaService $etaService = null)
    {
        $this->connection = $connection;
        $this->etaService = $etaService ?? new TrafficAwareEtaService($connection);
    }

    public function render(Component $component): ?string
    {
        return match ($component->type) {
            'live_coverage_map' => $this->renderLiveCoverageMap($component),
            'eta', 'estimated_wait_time' => $this->renderEtaBlock($component),
            default => null,
        };
    }

    private function renderLiveCoverageMap(Component $component): string
    {
        $config = $this->parseConfig($component->content);
        $title = $config['title'] ?? $component->name ?? 'Live Coverage Map';
        $date = $config['date'] ?? date('Y-m-d');
        $hour = $config['hour'] ?? null;
        $limit = $this->clampInt($config['limit'] ?? 12, 1, 50);
        $includeDrivers = $config['include_drivers'] ?? true;
        $driversWindow = $this->clampInt($config['drivers_window_minutes'] ?? 10, 1, 120);
        $driversLimit = $this->clampInt($config['drivers_limit'] ?? 8, 1, 50);

        $heatmap = $this->fetchHeatmapData($date, $hour, $limit);
        $drivers = $includeDrivers ? $this->fetchRecentDrivers($driversWindow, $driversLimit) : [];

        $payload = [
            'date' => $date,
            'hour' => $hour,
            'summary' => [
                'points' => count($heatmap),
                'total_jobs' => array_sum(array_column($heatmap, 'job_count')),
                'drivers' => count($drivers),
            ],
            'heatmap' => $heatmap,
            'drivers' => $drivers,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT);
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i');

        $rows = '';
        foreach ($heatmap as $point) {
            $rows .= sprintf(
                '<li><strong>%s</strong> jobs near %s, %s</li>',
                htmlspecialchars((string) $point['job_count']),
                htmlspecialchars((string) $point['grid_lat']),
                htmlspecialchars((string) $point['grid_lng'])
            );
        }

        $driverRows = '';
        foreach ($drivers as $driver) {
            $driverRows .= sprintf(
                '<li>Driver %s at %s, %s (as of %s)</li>',
                htmlspecialchars((string) $driver['driver_profile_id']),
                htmlspecialchars((string) $driver['latitude']),
                htmlspecialchars((string) $driver['longitude']),
                htmlspecialchars((string) $driver['recorded_at'])
            );
        }

        return sprintf(
            '<section class="cms-coverage-map" data-updated="%s">
                <header>
                    <h2>%s</h2>
                    <p>Snapshot %s%s</p>
                </header>
                <div class="cms-coverage-map__summary">
                    <strong>%d</strong> hotspots • <strong>%d</strong> active drivers
                </div>
                <ul class="cms-coverage-map__hotspots">%s</ul>
                %s
                <script type="application/json" class="cms-coverage-map__data">%s</script>
            </section>',
            htmlspecialchars($timestamp),
            htmlspecialchars((string) $title),
            htmlspecialchars($date),
            $hour !== null ? ' at ' . htmlspecialchars((string) $hour) . ':00' : '',
            count($heatmap),
            count($drivers),
            $rows !== '' ? $rows : '<li>No hotspots available.</li>',
            $includeDrivers
                ? sprintf(
                    '<div class="cms-coverage-map__drivers"><h3>Active Drivers</h3><ul>%s</ul></div>',
                    $driverRows !== '' ? $driverRows : '<li>No active driver pings.</li>'
                )
                : '',
            $encoded !== false ? htmlspecialchars($encoded) : ''
        );
    }

    private function renderEtaBlock(Component $component): string
    {
        $config = $this->parseConfig($component->content);
        $title = $config['title'] ?? $component->name ?? 'Estimated Arrival';
        $driverProfileId = isset($config['driver_profile_id']) ? (int) $config['driver_profile_id'] : null;

        $origin = $this->resolveOrigin($config, $driverProfileId);
        $destination = $this->resolveDestination($config);

        if ($origin === null || $destination === null) {
            return sprintf(
                '<section class="cms-eta-block"><h2>%s</h2><p>ETA unavailable. Configure origin and destination.</p></section>',
                htmlspecialchars((string) $title)
            );
        }

        $eta = $this->etaService->calculateEta(
            $origin['latitude'],
            $origin['longitude'],
            $destination['latitude'],
            $destination['longitude'],
            $driverProfileId
        );

        $payload = [
            'origin' => $origin,
            'destination' => $destination,
            'eta' => $eta,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT);
        $timestamp = (new DateTimeImmutable())->format('Y-m-d H:i');

        return sprintf(
            '<section class="cms-eta-block" data-updated="%s">
                <header>
                    <h2>%s</h2>
                    <p>Updated %s</p>
                </header>
                <div class="cms-eta-block__value">
                    <strong>%d</strong> minutes
                </div>
                <dl class="cms-eta-block__meta">
                    <div><dt>Distance</dt><dd>%s km</dd></div>
                    <div><dt>Traffic</dt><dd>%s×</dd></div>
                    <div><dt>Source</dt><dd>%s</dd></div>
                </dl>
                <script type="application/json" class="cms-eta-block__data">%s</script>
            </section>',
            htmlspecialchars($timestamp),
            htmlspecialchars((string) $title),
            htmlspecialchars($timestamp),
            (int) $eta['eta_minutes'],
            htmlspecialchars((string) $eta['distance_km']),
            htmlspecialchars((string) $eta['traffic_factor']),
            htmlspecialchars((string) $eta['source']),
            $encoded !== false ? htmlspecialchars($encoded) : ''
        );
    }

    private function parseConfig(?string $content): array
    {
        if ($content === null || trim($content) === '') {
            return [];
        }

        $decoded = json_decode($content, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function resolveOrigin(array $config, ?int $driverProfileId): ?array
    {
        if (isset($config['from_latitude'], $config['from_longitude'])) {
            return [
                'latitude' => (float) $config['from_latitude'],
                'longitude' => (float) $config['from_longitude'],
            ];
        }

        if ($driverProfileId !== null) {
            $location = $this->etaService->getDriverCurrentLocation($driverProfileId);
            if ($location !== null) {
                return [
                    'latitude' => $location['latitude'],
                    'longitude' => $location['longitude'],
                ];
            }
        }

        return null;
    }

    private function resolveDestination(array $config): ?array
    {
        if (isset($config['to_latitude'], $config['to_longitude'])) {
            return [
                'latitude' => (float) $config['to_latitude'],
                'longitude' => (float) $config['to_longitude'],
            ];
        }

        if (isset($config['dispatch_requirement_id'])) {
            $requirement = $this->fetchDispatchRequirement((int) $config['dispatch_requirement_id']);
            if ($requirement !== null) {
                return [
                    'latitude' => (float) $requirement['pickup_latitude'],
                    'longitude' => (float) $requirement['pickup_longitude'],
                ];
            }
        }

        return null;
    }

    private function fetchDispatchRequirement(int $dispatchRequirementId): ?array
    {
        if ($dispatchRequirementId <= 0) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT pickup_latitude, pickup_longitude
             FROM dispatch_requirements
             WHERE id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $dispatchRequirementId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row || $row['pickup_latitude'] === null || $row['pickup_longitude'] === null) {
            return null;
        }

        return $row;
    }

    private function fetchHeatmapData(string $date, ?int $hour, int $limit): array
    {
        $sql = 'SELECT grid_lat, grid_lng, job_count, snapshot_hour
                FROM job_density_snapshots
                WHERE snapshot_date = :date';
        $params = ['date' => $date];

        if ($hour !== null) {
            $sql .= ' AND snapshot_hour = :hour';
            $params['hour'] = $hour;
        }

        $sql .= ' ORDER BY job_count DESC LIMIT :limit';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function fetchRecentDrivers(int $windowMinutes, int $limit): array
    {
        $minutes = max(1, $windowMinutes);
        $sql = '
            SELECT dl.driver_profile_id, dl.latitude, dl.longitude, dl.recorded_at
            FROM driver_locations dl
            INNER JOIN (
                SELECT driver_profile_id, MAX(recorded_at) AS latest_at
                FROM driver_locations
                WHERE recorded_at >= (NOW() - INTERVAL ' . $minutes . ' MINUTE)
                GROUP BY driver_profile_id
            ) latest
                ON latest.driver_profile_id = dl.driver_profile_id
                AND latest.latest_at = dl.recorded_at
            ORDER BY dl.recorded_at DESC
            LIMIT :limit';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function clampInt(mixed $value, int $min, int $max): int
    {
        $int = (int) $value;
        if ($int < $min) {
            return $min;
        }
        if ($int > $max) {
            return $max;
        }
        return $int;
    }
}
