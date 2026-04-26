<?php

namespace App\Services\Reporting;

use App\Models\ReportExecution;
use App\Models\SavedReport;
use App\Models\ScheduledReport;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP edge for the unified reporting layer.
 *
 * Three audiences:
 *   1. Authenticated users with reporting.view  — browse the catalog,
 *      run reports ad-hoc or saved, page through their own/shared
 *      saved reports, view drill-down rows.
 *   2. Owners of a saved report                 — update/delete their own.
 *   3. Admins / managers (reporting.manage)     — share reports, manage
 *      schedules, view system-wide execution audit.
 */
class ReportingController
{
    public function __construct(
        private ReportCatalogService $catalog,
        private SavedReportService $reportService,
        private ScheduledReportService $scheduleService,
        private SavedReportRepository $savedRepo,
        private ReportExecutionRepository $executionRepo,
        private ReportExportService $exporter,
        private AccessGate $gate
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listCatalog(User $actor): array
    {
        $this->gate->assert($actor, 'reporting.view');
        return ['data' => $this->catalog->listReports()];
    }

    /**
     * @return array<string, mixed>
     */
    public function describe(User $actor, string $key): array
    {
        $this->gate->assert($actor, 'reporting.view');
        $entry = $this->catalog->describeReport($key);
        if ($entry === null) {
            throw new InvalidArgumentException('Unknown report.');
        }
        return ['data' => $entry];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function runAdhoc(User $actor, array $payload): array
    {
        $key = (string) ($payload['report_key'] ?? '');
        if ($key === '') {
            throw new InvalidArgumentException('report_key is required.');
        }
        $params = is_array($payload['parameters'] ?? null) ? $payload['parameters'] : [];

        $result = $this->reportService->runAdhoc($actor, $key, $params);
        return ['data' => $result];
    }

    /**
     * @return array<string, mixed>
     */
    public function listSaved(User $actor): array
    {
        $items = array_map(
            static fn (SavedReport $r): array => $r->toArray(),
            $this->reportService->listForUser($actor)
        );
        return ['data' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSaved(User $actor, int $id): array
    {
        $this->gate->assert($actor, 'reporting.view');
        $saved = $this->savedRepo->find($id);
        if ($saved === null) {
            throw new InvalidArgumentException('Saved report not found.');
        }
        if ($saved->owner_user_id !== (int) $actor->id && !$saved->is_shared) {
            $this->gate->assert($actor, 'reporting.manage');
        }
        return ['data' => $saved->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSaved(User $actor, array $payload): array
    {
        $saved = $this->reportService->create($actor, $payload);
        return ['data' => $saved->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateSaved(User $actor, int $id, array $payload): array
    {
        $saved = $this->reportService->update($actor, $id, $payload);
        if ($saved === null) {
            throw new InvalidArgumentException('Saved report not found.');
        }
        return ['data' => $saved->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteSaved(User $actor, int $id): array
    {
        $deleted = $this->reportService->delete($actor, $id);
        return ['data' => ['deleted' => $deleted]];
    }

    /**
     * @return array<string, mixed>
     */
    public function runSaved(User $actor, int $id): array
    {
        $result = $this->reportService->runSaved($actor, $id);
        return ['data' => $result];
    }

    /**
     * Run + export a saved report in one call. Returns the exported payload
     * with the appropriate content type so the caller can stream a download.
     *
     * @return array{body: string, content_type: string, filename: string}
     */
    public function exportSaved(User $actor, int $id, string $format): array
    {
        if (!in_array($format, ['csv', 'json'], true)) {
            throw new InvalidArgumentException('Unknown export format.');
        }
        $result = $this->reportService->runSaved($actor, $id);
        $saved = $this->savedRepo->find($id);
        $key = $saved?->report_key ?? 'report';
        return [
            'body' => $this->exporter->export($result['rows'], $result['columns'], $format),
            'content_type' => $this->exporter->contentTypeFor($format),
            'filename' => $this->exporter->filenameFor($key, $format),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{body: string, content_type: string, filename: string}
     */
    public function exportAdhoc(User $actor, array $payload): array
    {
        $key = (string) ($payload['report_key'] ?? '');
        $format = (string) ($payload['format'] ?? 'csv');
        if (!in_array($format, ['csv', 'json'], true)) {
            throw new InvalidArgumentException('Unknown export format.');
        }
        $params = is_array($payload['parameters'] ?? null) ? $payload['parameters'] : [];
        $result = $this->reportService->runAdhoc($actor, $key, $params);
        return [
            'body' => $this->exporter->export($result['rows'], $result['columns'], $format),
            'content_type' => $this->exporter->contentTypeFor($format),
            'filename' => $this->exporter->filenameFor($key, $format),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listExecutions(User $actor): array
    {
        $this->gate->assert($actor, 'reporting.view');
        $items = array_map(
            static fn (ReportExecution $e): array => $e->toArray(),
            $this->executionRepo->listRecent(100)
        );
        return ['data' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function listExecutionsForSaved(User $actor, int $savedReportId): array
    {
        $this->gate->assert($actor, 'reporting.view');
        $saved = $this->savedRepo->find($savedReportId);
        if ($saved === null) {
            throw new InvalidArgumentException('Saved report not found.');
        }
        if ($saved->owner_user_id !== (int) $actor->id && !$saved->is_shared) {
            $this->gate->assert($actor, 'reporting.manage');
        }
        $items = array_map(
            static fn (ReportExecution $e): array => $e->toArray(),
            $this->executionRepo->listForSavedReport($savedReportId, 50)
        );
        return ['data' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function listSchedules(User $actor): array
    {
        $items = array_map(
            static fn (ScheduledReport $s): array => $s->toArray(),
            $this->scheduleService->listAll($actor)
        );
        return ['data' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function getSchedule(User $actor, int $id): array
    {
        $schedule = $this->scheduleService->find($actor, $id);
        if ($schedule === null) {
            throw new InvalidArgumentException('Schedule not found.');
        }
        return ['data' => $schedule->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createSchedule(User $actor, array $payload): array
    {
        $schedule = $this->scheduleService->create($actor, $payload);
        return ['data' => $schedule->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateSchedule(User $actor, int $id, array $payload): array
    {
        $schedule = $this->scheduleService->update($actor, $id, $payload);
        if ($schedule === null) {
            throw new InvalidArgumentException('Schedule not found.');
        }
        return ['data' => $schedule->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function deleteSchedule(User $actor, int $id): array
    {
        $deleted = $this->scheduleService->delete($actor, $id);
        return ['data' => ['deleted' => $deleted]];
    }
}
