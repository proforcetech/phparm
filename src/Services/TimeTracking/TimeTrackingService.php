<?php

namespace App\Services\TimeTracking;

use App\Database\Connection;
use App\Models\TimeEntry;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class TimeTrackingService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed>|null $location
     */
    public function start(int $technicianId, ?int $estimateJobId = null, ?array $location = null, ?int $taskId = null): TimeEntry
    {
        $open = $this->fetchOpenEntry($technicianId);
        if ($open !== null) {
            throw new InvalidArgumentException('Technician already has an active timer.');
        }

        $isMobile = $this->isMobileJob($estimateJobId);
        $normalizedLocation = $this->normalizeLocation($location);
        $this->assertLocationIfMobile($isMobile, $normalizedLocation, 'start');

        // Fetch task details if task_id is provided (snapshot for efficiency tracking)
        $taskName = null;
        $flatRateMinutes = null;
        if ($taskId !== null) {
            $taskData = $this->fetchTaskDetails($taskId);
            if ($taskData !== null) {
                $taskName = $taskData['name'];
                $flatRateMinutes = $taskData['flat_rate_minutes'];
            }
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO time_entries (technician_id, estimate_job_id, task_id, task_name, flat_rate_minutes, started_at, start_latitude, start_longitude, start_accuracy, start_altitude, start_speed, start_heading, start_recorded_at, start_source, start_error, manual_override, created_at, updated_at) '
            . 'VALUES (:technician_id, :estimate_job_id, :task_id, :task_name, :flat_rate_minutes, NOW(), :lat, :lng, :accuracy, :altitude, :speed, :heading, :recorded_at, :source, :error, 0, NOW(), NOW())'
        );
        $stmt->execute([
            'technician_id' => $technicianId,
            'estimate_job_id' => $estimateJobId,
            'task_id' => $taskId,
            'task_name' => $taskName,
            'flat_rate_minutes' => $flatRateMinutes,
            'lat' => $normalizedLocation['lat'],
            'lng' => $normalizedLocation['lng'],
            'accuracy' => $normalizedLocation['accuracy'],
            'altitude' => $normalizedLocation['altitude'],
            'speed' => $normalizedLocation['speed'],
            'heading' => $normalizedLocation['heading'],
            'recorded_at' => $normalizedLocation['recorded_at'],
            'source' => $normalizedLocation['source'],
            'error' => $normalizedLocation['error'],
        ]);

        $entryId = (int) $this->connection->pdo()->lastInsertId();
        $entry = $this->find($entryId);
        if ($entry !== null) {
            $entry->is_mobile = $isMobile;
        }
        $this->log($technicianId, 'time.start', $entryId, $entry?->toArray() ?? []);

        return $entry ?? new TimeEntry(['id' => $entryId]);
    }

    /**
     * Fetch labor task details for snapshotting.
     *
     * @return array<string, mixed>|null
     */
    private function fetchTaskDetails(?int $taskId): ?array
    {
        if ($taskId === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, flat_rate_minutes FROM labor_tasks WHERE id = :id AND is_active = 1'
        );
        $stmt->execute(['id' => $taskId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row !== false ? $row : null;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $baseSql = 'FROM time_entries te '
            . 'LEFT JOIN users u ON u.id = te.technician_id '
            . 'LEFT JOIN users ru ON ru.id = te.reviewed_by '
            . 'LEFT JOIN estimate_jobs ej ON ej.id = te.estimate_job_id '
            . 'LEFT JOIN estimates e ON e.id = ej.estimate_id '
            . 'LEFT JOIN customers c ON c.id = e.customer_id '
            . 'LEFT JOIN customer_vehicles cv ON cv.id = e.vehicle_id '
            . 'WHERE 1=1';
        $params = [];

        if (isset($filters['technician_id'])) {
            $baseSql .= ' AND te.technician_id = :technician_id';
            $params['technician_id'] = (int) $filters['technician_id'];
        }

        if (!empty($filters['start_date'])) {
            $baseSql .= ' AND te.started_at >= :start_date';
            $params['start_date'] = $filters['start_date'] . ' 00:00:00';
        }

        if (!empty($filters['end_date'])) {
            $baseSql .= ' AND te.started_at <= :end_date';
            $params['end_date'] = $filters['end_date'] . ' 23:59:59';
        }

        if (!empty($filters['search'])) {
            $baseSql .= ' AND (u.name LIKE :search OR ej.title LIKE :search OR CONCAT(c.first_name, " ", c.last_name) LIKE :search OR e.number LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (!empty($filters['status'])) {
            $baseSql .= ' AND te.status = :status';
            $params['status'] = $filters['status'];
        }

        $countStmt = $this->connection->pdo()->prepare('SELECT COUNT(*) ' . $baseSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT te.*, e.is_mobile, u.name AS technician_name, ej.title AS job_title, e.number AS estimate_number, '
            . 'CONCAT(c.first_name, " ", c.last_name) AS customer_name, cv.vin AS vehicle_vin, ru.name AS reviewer_name, '
            . 'CASE WHEN te.duration_minutes > 0 AND te.flat_rate_minutes > 0 THEN ROUND((te.flat_rate_minutes / te.duration_minutes) * 100, 2) ELSE NULL END AS efficiency_percentage ' . $baseSql . ' ORDER BY te.started_at DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $type);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = array_map(static function (array $row) {
            $row['is_mobile'] = isset($row['is_mobile']) ? (bool) $row['is_mobile'] : false;
            $row['payroll_included'] = isset($row['payroll_included']) ? (bool) $row['payroll_included'] : false;

            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
        $entries = array_map(static fn (array $row) => new TimeEntry($row), $rows);
        $adjustments = $this->fetchAdjustments(array_map(static fn (TimeEntry $entry) => $entry->id, $entries));

        $metaById = [];
        foreach ($rows as $row) {
            $metaById[(int) $row['id']] = $row;
        }

        $data = [];
        foreach ($entries as $entry) {
            $row = $entry->toArray();
            $meta = $metaById[$entry->id] ?? [];
            $row['technician_name'] = $meta['technician_name'] ?? null;
            $row['job_title'] = $meta['job_title'] ?? null;
            $row['estimate_number'] = $meta['estimate_number'] ?? null;
            $row['customer_name'] = $meta['customer_name'] ?? null;
            $row['vehicle_vin'] = $meta['vehicle_vin'] ?? null;
            $row['reviewer_name'] = $meta['reviewer_name'] ?? null;
            $row['efficiency_percentage'] = $meta['efficiency_percentage'] ?? null;
            $row['adjustments'] = $adjustments[$entry->id] ?? [];
            $data[] = $row;
        }

        return [
            'data' => $data,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * Export time entries with context and adjustment reasons.
     *
     * @param array<string, mixed> $filters
     */
    public function exportCsv(array $filters = []): string
    {
        $limit = isset($filters['limit']) ? (int) $filters['limit'] : 1000;
        $result = $this->list($filters, $limit, 0);
        $rows = $result['data'];

        if (count($rows) === 0) {
            return '';
        }

        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, [
            'ID',
            'Technician',
            'Task',
            'Job Title',
            'Estimate #',
            'Customer',
            'Vehicle VIN',
            'Mobile Repair',
            'Start Location',
            'End Location',
            'En Route At',
            'On Site At',
            'Wrap Up At',
            'Started At',
            'Ended At',
            'Actual (minutes)',
            'Flat Rate (minutes)',
            'Efficiency %',
            'Status',
            'Reviewed By',
            'Reviewed At',
            'Review Notes',
            'Payroll Included',
            'Payroll Included At',
            'Manual Override',
            'Notes',
            'Adjustments',
        ]);

        foreach ($rows as $row) {
            $adjustments = $row['adjustments'] ?? [];
            $adjustmentNotes = array_map(static function (array $adj) {
                return sprintf(
                    '%s by %s at %s',
                    $adj['reason'],
                    $adj['actor_name'] ?? ('User #' . $adj['actor_id']),
                    $adj['created_at'] ?? ''
                );
            }, $adjustments);

            fputcsv($buffer, [
                $row['id'] ?? null,
                $row['technician_name'] ?? ('Tech #' . ($row['technician_id'] ?? '')),
                $row['task_name'] ?? null,
                $row['job_title'] ?? null,
                $row['estimate_number'] ?? null,
                $row['customer_name'] ?? null,
                $row['vehicle_vin'] ?? null,
                !empty($row['is_mobile']) ? 'Yes' : 'No',
                ($row['start_latitude'] ?? null) && ($row['start_longitude'] ?? null)
                    ? ($row['start_latitude'] . ', ' . $row['start_longitude'])
                    : null,
                ($row['end_latitude'] ?? null) && ($row['end_longitude'] ?? null)
                    ? ($row['end_latitude'] . ', ' . $row['end_longitude'])
                    : null,
                $row['en_route_at'] ?? null,
                $row['on_site_at'] ?? null,
                $row['wrap_up_at'] ?? null,
                $row['started_at'] ?? null,
                $row['ended_at'] ?? null,
                $row['duration_minutes'] ?? null,
                $row['flat_rate_minutes'] ?? null,
                $row['efficiency_percentage'] ?? null,
                $row['status'] ?? null,
                $row['reviewer_name'] ?? null,
                $row['reviewed_at'] ?? null,
                $row['review_notes'] ?? null,
                !empty($row['payroll_included']) ? 'Yes' : 'No',
                $row['payroll_included_at'] ?? null,
                ($row['manual_override'] ?? false) ? 'Yes' : 'No',
                $row['notes'] ?? null,
                implode(' | ', $adjustmentNotes),
            ]);
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer) ?: '';
        fclose($buffer);

        return $csv;
    }

    /**
     * @param array<string, mixed>|null $location
     */
    public function stop(int $entryId, int $actorId, ?array $location = null): ?TimeEntry
    {
        $entry = $this->find($entryId);
        if ($entry === null || $entry->ended_at !== null) {
            return null;
        }

        $endedAt = new DateTimeImmutable();
        $startedAt = new DateTimeImmutable($entry->started_at);
        $minutes = ($endedAt->getTimestamp() - $startedAt->getTimestamp()) / 60;

        $isMobile = $this->isMobileJob($entry->estimate_job_id);
        $endLocation = $this->normalizeLocation($location);
        $this->assertLocationIfMobile($isMobile, $endLocation, 'stop');

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE time_entries SET ended_at = :ended_at, end_latitude = :lat, end_longitude = :lng, end_accuracy = :accuracy, '
            . 'end_altitude = :altitude, end_speed = :speed, end_heading = :heading, end_recorded_at = :recorded_at, '
            . 'end_source = :source, end_error = :error, duration_minutes = :minutes, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'id' => $entryId,
            'ended_at' => $endedAt->format('Y-m-d H:i:s'),
            'lat' => $endLocation['lat'],
            'lng' => $endLocation['lng'],
            'accuracy' => $endLocation['accuracy'],
            'altitude' => $endLocation['altitude'],
            'speed' => $endLocation['speed'],
            'heading' => $endLocation['heading'],
            'recorded_at' => $endLocation['recorded_at'],
            'source' => $endLocation['source'],
            'error' => $endLocation['error'],
            'minutes' => $minutes,
        ]);

        $updated = $this->find($entryId);
        if ($updated !== null) {
            $updated->is_mobile = $isMobile;
        }
        $this->log($actorId, 'time.stop', $entryId, [
            'duration_minutes' => $minutes,
        ]);

        return $updated;
    }

    public function manualEntry(
        int $technicianId,
        string $startedAt,
        string $endedAt,
        ?int $estimateJobId = null,
        ?string $enRouteAt = null,
        ?string $onSiteAt = null,
        ?string $wrapUpAt = null,
        ?string $notes = null,
        ?string $reason = null,
        bool $override = true,
        ?int $actorId = null,
        ?int $taskId = null
    ): TimeEntry {
        $start = new DateTimeImmutable($startedAt);
        $end = new DateTimeImmutable($endedAt);
        $minutes = max(0, ($end->getTimestamp() - $start->getTimestamp()) / 60);
        $normalizedEnRoute = $this->normalizeTimestamp($enRouteAt);
        $normalizedOnSite = $this->normalizeTimestamp($onSiteAt);
        $normalizedWrapUp = $this->normalizeTimestamp($wrapUpAt);

        if ($reason === null || trim($reason) === '') {
            throw new InvalidArgumentException('Adjustment reason is required for manual entry');
        }

        // Fetch task details if task_id is provided
        $taskName = null;
        $flatRateMinutes = null;
        if ($taskId !== null) {
            $taskData = $this->fetchTaskDetails($taskId);
            if ($taskData !== null) {
                $taskName = $taskData['name'];
                $flatRateMinutes = $taskData['flat_rate_minutes'];
            }
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO time_entries (technician_id, estimate_job_id, task_id, task_name, flat_rate_minutes, started_at, ended_at, en_route_at, on_site_at, wrap_up_at, duration_minutes, status, reviewed_by, reviewed_at, review_notes, manual_override, notes, created_at, updated_at) VALUES '
            . '(:technician_id, :estimate_job_id, :task_id, :task_name, :flat_rate_minutes, :started_at, :ended_at, :en_route_at, :on_site_at, :wrap_up_at, :minutes, :status, :reviewed_by, :reviewed_at, :review_notes, :override, :notes, NOW(), NOW())'
        );
        $stmt->execute([
            'technician_id' => $technicianId,
            'estimate_job_id' => $estimateJobId,
            'task_id' => $taskId,
            'task_name' => $taskName,
            'flat_rate_minutes' => $flatRateMinutes,
            'started_at' => $start->format('Y-m-d H:i:s'),
            'ended_at' => $end->format('Y-m-d H:i:s'),
            'en_route_at' => $normalizedEnRoute,
            'on_site_at' => $normalizedOnSite,
            'wrap_up_at' => $normalizedWrapUp,
            'minutes' => $minutes,
            'status' => 'pending',
            'reviewed_by' => null,
            'reviewed_at' => null,
            'review_notes' => null,
            'override' => $override ? 1 : 0,
            'notes' => $notes,
        ]);

        $entryId = (int) $this->connection->pdo()->lastInsertId();
        $this->log($actorId ?? $technicianId, 'time.manual', $entryId, ['minutes' => $minutes]);
        $this->recordAdjustment(
            $entryId,
            $actorId ?? $technicianId,
            $reason,
            [
                'status' => null,
                'started_at' => null,
                'ended_at' => null,
                'en_route_at' => null,
                'on_site_at' => null,
                'wrap_up_at' => null,
                'duration_minutes' => null,
                'estimate_job_id' => null,
                'task_id' => null,
                'task_name' => null,
                'flat_rate_minutes' => null,
                'notes' => null,
                'manual_override' => null,
            ],
            [
                'status' => 'pending',
                'started_at' => $start->format('Y-m-d H:i:s'),
                'ended_at' => $end->format('Y-m-d H:i:s'),
                'en_route_at' => $normalizedEnRoute,
                'on_site_at' => $normalizedOnSite,
                'wrap_up_at' => $normalizedWrapUp,
                'duration_minutes' => $minutes,
                'estimate_job_id' => $estimateJobId,
                'task_id' => $taskId,
                'task_name' => $taskName,
                'flat_rate_minutes' => $flatRateMinutes,
                'notes' => $notes,
                'manual_override' => $override,
            ]
        );

        return $this->find($entryId) ?? new TimeEntry(['id' => $entryId]);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createManual(array $data, int $actorId): TimeEntry
    {
        if (!isset($data['reason']) || trim((string) $data['reason']) === '') {
            throw new InvalidArgumentException('Adjustment reason is required');
        }

        return $this->manualEntry(
            (int) $data['technician_id'],
            (string) $data['started_at'],
            (string) $data['ended_at'],
            isset($data['estimate_job_id']) ? (int) $data['estimate_job_id'] : null,
            $data['en_route_at'] ?? null,
            $data['on_site_at'] ?? null,
            $data['wrap_up_at'] ?? null,
            $data['notes'] ?? null,
            (string) $data['reason'],
            $data['manual_override'] ?? true,
            $actorId,
            isset($data['task_id']) ? (int) $data['task_id'] : null
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $entryId, array $data, int $actorId): ?TimeEntry
    {
        $entry = $this->find($entryId);
        if ($entry === null) {
            return null;
        }

        if (!isset($data['reason']) || trim((string) $data['reason']) === '') {
            throw new InvalidArgumentException('Adjustment reason is required');
        }

        $startedAt = $data['started_at'] ?? $entry->started_at;
        $endedAt = $data['ended_at'] ?? $entry->ended_at;
        $enRouteAt = $data['en_route_at'] ?? $entry->en_route_at;
        $onSiteAt = $data['on_site_at'] ?? $entry->on_site_at;
        $wrapUpAt = $data['wrap_up_at'] ?? $entry->wrap_up_at;
        $estimateJobId = $data['estimate_job_id'] ?? $entry->estimate_job_id;
        $notes = $data['notes'] ?? $entry->notes;
        $override = $data['manual_override'] ?? $entry->manual_override;

        // Handle task changes
        $taskId = array_key_exists('task_id', $data) ? $data['task_id'] : $entry->task_id;
        $taskName = $entry->task_name;
        $flatRateMinutes = $entry->flat_rate_minutes;
        if (array_key_exists('task_id', $data) && $data['task_id'] !== $entry->task_id) {
            if ($taskId !== null) {
                $taskData = $this->fetchTaskDetails((int) $taskId);
                if ($taskData !== null) {
                    $taskName = $taskData['name'];
                    $flatRateMinutes = $taskData['flat_rate_minutes'];
                }
            } else {
                $taskName = null;
                $flatRateMinutes = null;
            }
        }

        $status = $entry->status ?? 'approved';
        $reviewedBy = $entry->reviewed_by;
        $reviewedAt = $entry->reviewed_at;
        $reviewNotes = $entry->review_notes;
        $payrollIncluded = $entry->payroll_included ?? false;
        $payrollIncludedAt = $entry->payroll_included_at;

        if ($override) {
            $status = 'pending';
            $reviewedBy = null;
            $reviewedAt = null;
            $reviewNotes = null;
            $payrollIncluded = false;
            $payrollIncludedAt = null;
        }

        $start = new DateTimeImmutable($startedAt);
        $end = $endedAt !== null ? new DateTimeImmutable($endedAt) : null;
        $normalizedEnRoute = $this->normalizeTimestamp($enRouteAt);
        $normalizedOnSite = $this->normalizeTimestamp($onSiteAt);
        $normalizedWrapUp = $this->normalizeTimestamp($wrapUpAt);
        $minutes = $end !== null ? max(0, ($end->getTimestamp() - $start->getTimestamp()) / 60) : null;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE time_entries SET started_at = :started_at, ended_at = :ended_at, en_route_at = :en_route_at, on_site_at = :on_site_at, wrap_up_at = :wrap_up_at, duration_minutes = :minutes, estimate_job_id = :estimate_job_id, task_id = :task_id, task_name = :task_name, flat_rate_minutes = :flat_rate_minutes, notes = :notes, manual_override = :override, status = :status, reviewed_by = :reviewed_by, reviewed_at = :reviewed_at, review_notes = :review_notes, payroll_included = :payroll_included, payroll_included_at = :payroll_included_at, updated_at = NOW() WHERE id = :id'
        );

        $stmt->execute([
            'id' => $entryId,
            'started_at' => $start->format('Y-m-d H:i:s'),
            'ended_at' => $end?->format('Y-m-d H:i:s'),
            'en_route_at' => $normalizedEnRoute,
            'on_site_at' => $normalizedOnSite,
            'wrap_up_at' => $normalizedWrapUp,
            'minutes' => $minutes,
            'estimate_job_id' => $estimateJobId,
            'task_id' => $taskId,
            'task_name' => $taskName,
            'flat_rate_minutes' => $flatRateMinutes,
            'notes' => $notes,
            'override' => $override ? 1 : 0,
            'status' => $status,
            'reviewed_by' => $reviewedBy,
            'reviewed_at' => $reviewedAt,
            'review_notes' => $reviewNotes,
            'payroll_included' => $payrollIncluded ? 1 : 0,
            'payroll_included_at' => $payrollIncludedAt,
        ]);

        $updated = $this->find($entryId);
        $this->log($actorId, 'time.update', $entryId, ['duration_minutes' => $minutes, 'status' => $status]);
        $this->recordAdjustment(
            $entryId,
            $actorId,
            (string) $data['reason'],
            [
                'started_at' => $entry->started_at,
                'ended_at' => $entry->ended_at,
                'en_route_at' => $entry->en_route_at,
                'on_site_at' => $entry->on_site_at,
                'wrap_up_at' => $entry->wrap_up_at,
                'duration_minutes' => $entry->duration_minutes,
                'estimate_job_id' => $entry->estimate_job_id,
                'task_id' => $entry->task_id,
                'task_name' => $entry->task_name,
                'flat_rate_minutes' => $entry->flat_rate_minutes,
                'notes' => $entry->notes,
                'manual_override' => $entry->manual_override,
                'status' => $entry->status,
            ],
            [
                'started_at' => $start->format('Y-m-d H:i:s'),
                'ended_at' => $end?->format('Y-m-d H:i:s'),
                'en_route_at' => $normalizedEnRoute,
                'on_site_at' => $normalizedOnSite,
                'wrap_up_at' => $normalizedWrapUp,
                'duration_minutes' => $minutes,
                'estimate_job_id' => $estimateJobId,
                'task_id' => $taskId,
                'task_name' => $taskName,
                'flat_rate_minutes' => $flatRateMinutes,
                'notes' => $notes,
                'manual_override' => (bool) $override,
                'status' => $status,
            ]
        );

        return $updated;
    }

    public function review(
        int $entryId,
        int $actorId,
        string $decision,
        ?string $notes = null,
        ?bool $includeInPayroll = null
    ): ?TimeEntry
    {
        $entry = $this->find($entryId);
        if ($entry === null) {
            return null;
        }

        if (!in_array($decision, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid review status');
        }

        $shouldIncludeInPayroll = $decision === 'approved' ? ($includeInPayroll ?? true) : false;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE time_entries SET status = :status, reviewed_by = :reviewed_by, reviewed_at = NOW(), review_notes = :review_notes, payroll_included = :payroll_included, payroll_included_at = :payroll_included_at, updated_at = NOW() WHERE id = :id'
        );

        $stmt->execute([
            'id' => $entryId,
            'status' => $decision,
            'reviewed_by' => $actorId,
            'review_notes' => $notes,
            'payroll_included' => $shouldIncludeInPayroll ? 1 : 0,
            'payroll_included_at' => $shouldIncludeInPayroll ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null,
        ]);

        $this->recordAdjustment(
            $entryId,
            $actorId,
            $notes ?? ucfirst($decision) . ' manual entry',
            [
                'started_at' => $entry->started_at,
                'ended_at' => $entry->ended_at,
                'en_route_at' => $entry->en_route_at,
                'on_site_at' => $entry->on_site_at,
                'wrap_up_at' => $entry->wrap_up_at,
                'duration_minutes' => $entry->duration_minutes,
                'estimate_job_id' => $entry->estimate_job_id,
                'task_id' => $entry->task_id,
                'task_name' => $entry->task_name,
                'flat_rate_minutes' => $entry->flat_rate_minutes,
                'notes' => $entry->notes,
                'manual_override' => $entry->manual_override,
                'status' => $entry->status,
            ],
            [
                'started_at' => $entry->started_at,
                'ended_at' => $entry->ended_at,
                'en_route_at' => $entry->en_route_at,
                'on_site_at' => $entry->on_site_at,
                'wrap_up_at' => $entry->wrap_up_at,
                'duration_minutes' => $entry->duration_minutes,
                'estimate_job_id' => $entry->estimate_job_id,
                'task_id' => $entry->task_id,
                'task_name' => $entry->task_name,
                'flat_rate_minutes' => $entry->flat_rate_minutes,
                'notes' => $entry->notes,
                'manual_override' => $entry->manual_override,
                'status' => $decision,
            ]
        );

        $updated = $this->find($entryId);
        $this->log($actorId, 'time.review', $entryId, [
            'status' => $decision,
            'notes' => $notes,
            'payroll_included' => $shouldIncludeInPayroll,
        ]);
        $this->log($actorId, 'time.' . $decision, $entryId, [
            'notes' => $notes,
            'payroll_included' => $shouldIncludeInPayroll,
        ]);

        return $updated;
    }

    public function find(int $entryId): ?TimeEntry
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT te.*, e.is_mobile FROM time_entries te '
            . 'LEFT JOIN estimate_jobs ej ON ej.id = te.estimate_job_id '
            . 'LEFT JOIN estimates e ON e.id = ej.estimate_id '
            . 'WHERE te.id = :id'
        );
        $stmt->execute(['id' => $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['is_mobile'] = isset($row['is_mobile']) ? (bool) $row['is_mobile'] : false;
        $row['payroll_included'] = isset($row['payroll_included']) ? (bool) $row['payroll_included'] : false;

        return new TimeEntry($row);
    }

    public function fetchOpenEntry(int $technicianId): ?TimeEntry
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT te.*, e.is_mobile FROM time_entries te '
            . 'LEFT JOIN estimate_jobs ej ON ej.id = te.estimate_job_id '
            . 'LEFT JOIN estimates e ON e.id = ej.estimate_id '
            . 'WHERE te.technician_id = :tech AND te.ended_at IS NULL ORDER BY te.started_at DESC LIMIT 1'
        );
        $stmt->execute(['tech' => $technicianId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $row['is_mobile'] = isset($row['is_mobile']) ? (bool) $row['is_mobile'] : false;
        $row['payroll_included'] = isset($row['payroll_included']) ? (bool) $row['payroll_included'] : false;

        return new TimeEntry($row);
    }

    public function entriesForTechnician(int $technicianId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT te.*, e.is_mobile FROM time_entries te '
            . 'LEFT JOIN estimate_jobs ej ON ej.id = te.estimate_job_id '
            . 'LEFT JOIN estimates e ON e.id = ej.estimate_id '
            . 'WHERE te.technician_id = :tech ORDER BY te.started_at DESC'
        );
        $stmt->execute(['tech' => $technicianId]);

        $rows = array_map(static function (array $row) {
            $row['is_mobile'] = isset($row['is_mobile']) ? (bool) $row['is_mobile'] : false;
            $row['payroll_included'] = isset($row['payroll_included']) ? (bool) $row['payroll_included'] : false;

            return $row;
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));

        return array_map(static fn (array $row) => new TimeEntry($row), $rows);
    }

    /**
     * @param array<int, int> $entryIds
     * @return array<int, array<int, array<string, mixed>>>
     */
    private function fetchAdjustments(array $entryIds): array
    {
        if (count($entryIds) === 0) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($entryIds), '?'));
        $sql = 'SELECT ta.*, u.name AS actor_name FROM time_adjustments ta '
            . 'LEFT JOIN users u ON u.id = ta.actor_id WHERE ta.time_entry_id IN (' . $placeholders . ') ORDER BY ta.created_at DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($entryIds as $index => $id) {
            $stmt->bindValue($index + 1, $id, PDO::PARAM_INT);
        }

        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $grouped = [];

        foreach ($rows as $row) {
            $entryId = (int) $row['time_entry_id'];
            $grouped[$entryId] ??= [];
            $grouped[$entryId][] = $row;
        }

        return $grouped;
    }

    /**
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     */
    private function recordAdjustment(int $entryId, int $actorId, string $reason, array $before, array $after): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO time_adjustments (time_entry_id, actor_id, reason, previous_status, previous_started_at, previous_ended_at, previous_en_route_at, previous_on_site_at, previous_wrap_up_at, previous_duration_minutes, previous_estimate_job_id, previous_task_id, previous_task_name, previous_flat_rate_minutes, previous_notes, previous_manual_override, new_status, new_started_at, new_ended_at, new_en_route_at, new_on_site_at, new_wrap_up_at, new_duration_minutes, new_estimate_job_id, new_task_id, new_task_name, new_flat_rate_minutes, new_notes, new_manual_override) '
            . 'VALUES (:entry_id, :actor_id, :reason, :prev_status, :prev_start, :prev_end, :prev_en_route, :prev_on_site, :prev_wrap_up, :prev_minutes, :prev_job, :prev_task_id, :prev_task_name, :prev_flat_rate, :prev_notes, :prev_override, :new_status, :new_start, :new_end, :new_en_route, :new_on_site, :new_wrap_up, :new_minutes, :new_job, :new_task_id, :new_task_name, :new_flat_rate, :new_notes, :new_override)'
        );

        $stmt->execute([
            'entry_id' => $entryId,
            'actor_id' => $actorId,
            'reason' => $reason,
            'prev_status' => $before['status'] ?? null,
            'prev_start' => $before['started_at'],
            'prev_end' => $before['ended_at'],
            'prev_en_route' => $before['en_route_at'] ?? null,
            'prev_on_site' => $before['on_site_at'] ?? null,
            'prev_wrap_up' => $before['wrap_up_at'] ?? null,
            'prev_minutes' => $before['duration_minutes'],
            'prev_job' => $before['estimate_job_id'],
            'prev_task_id' => $before['task_id'] ?? null,
            'prev_task_name' => $before['task_name'] ?? null,
            'prev_flat_rate' => $before['flat_rate_minutes'] ?? null,
            'prev_notes' => $before['notes'],
            'prev_override' => $before['manual_override'],
            'new_status' => $after['status'] ?? null,
            'new_start' => $after['started_at'],
            'new_end' => $after['ended_at'],
            'new_en_route' => $after['en_route_at'] ?? null,
            'new_on_site' => $after['on_site_at'] ?? null,
            'new_wrap_up' => $after['wrap_up_at'] ?? null,
            'new_minutes' => $after['duration_minutes'],
            'new_job' => $after['estimate_job_id'],
            'new_task_id' => $after['task_id'] ?? null,
            'new_task_name' => $after['task_name'] ?? null,
            'new_flat_rate' => $after['flat_rate_minutes'] ?? null,
            'new_notes' => $after['notes'],
            'new_override' => $after['manual_override'],
        ]);
    }

    private function normalizeTimestamp(?string $value): ?string
    {
        if ($value === null || trim($value) === '') {
            return null;
        }

        $date = new DateTimeImmutable($value);
        return $date->format('Y-m-d H:i:s');
    }

    private function isMobileJob(?int $estimateJobId): bool
    {
        if ($estimateJobId === null) {
            return false;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT e.is_mobile FROM estimate_jobs ej JOIN estimates e ON e.id = ej.estimate_id WHERE ej.id = :job_id LIMIT 1'
        );
        $stmt->execute(['job_id' => $estimateJobId]);

        $result = $stmt->fetchColumn();

        return $result !== false ? (bool) $result : false;
    }

    private function assertLocationIfMobile(bool $isMobile, array $location, string $stage): void
    {
        if (!$isMobile) {
            return;
        }

        if ($location['lat'] === null || $location['lng'] === null) {
            throw new InvalidArgumentException('Location is required to ' . $stage . ' mobile repairs.');
        }
    }

    /**
     * @param array<string, mixed>|null $location
     * @return array<string, mixed>
     */
    private function normalizeLocation(?array $location): array
    {
        if ($location === null) {
            return [
                'lat' => null,
                'lng' => null,
                'accuracy' => null,
                'altitude' => null,
                'speed' => null,
                'heading' => null,
                'recorded_at' => null,
                'source' => null,
                'error' => null,
            ];
        }

        return [
            'lat' => $location['lat'] ?? $location['latitude'] ?? null,
            'lng' => $location['lng'] ?? $location['longitude'] ?? null,
            'accuracy' => $location['accuracy'] ?? null,
            'altitude' => $location['altitude'] ?? null,
            'speed' => $location['speed'] ?? null,
            'heading' => $location['heading'] ?? null,
            'recorded_at' => $location['recorded_at'] ?? null,
            'source' => $location['source'] ?? null,
            'error' => $location['error'] ?? null,
        ];
    }

    private function log(int $actorId, string $event, int $entryId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'time_entry', (string) $entryId, $actorId, $context));
    }
}
