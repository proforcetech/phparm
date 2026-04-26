<?php

namespace App\Services\Reporting;

use App\Models\ReportExecution;
use App\Models\SavedReport;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Orchestrates create/update/run of saved reports and writes execution
 * audit rows. The actual SQL of each report lives in ReportCatalogService;
 * this service is the policy + bookkeeping layer in front of it.
 *
 * - Permission model:
 *   reporting.view  — required to read or run any report. Owner of a
 *                     saved report can always read+run their own;
 *                     shared reports require reporting.view.
 *   reporting.manage — required to update/delete a saved report you
 *                     don't own, or to share/unshare any saved report.
 */
class SavedReportService
{
    public function __construct(
        private SavedReportRepository $savedRepo,
        private ReportCatalogService $catalog,
        private ReportExecutionRepository $executions,
        private AccessGate $gate
    ) {
    }

    public function find(int $id): ?SavedReport
    {
        return $this->savedRepo->find($id);
    }

    /**
     * @return array<int, SavedReport>
     */
    public function listForUser(User $user): array
    {
        $this->gate->assert($user, 'reporting.view');
        return $this->savedRepo->listForOwner((int) $user->id);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(User $user, array $payload): SavedReport
    {
        $this->gate->assert($user, 'reporting.view');

        $reportKey = (string) ($payload['report_key'] ?? '');
        if ($reportKey === '' || !$this->catalog->hasReport($reportKey)) {
            throw new InvalidArgumentException('Unknown report_key.');
        }
        $name = trim((string) ($payload['name'] ?? ''));
        if ($name === '') {
            throw new InvalidArgumentException('Saved report name is required.');
        }

        $isShared = !empty($payload['is_shared']);
        if ($isShared) {
            $this->gate->assert($user, 'reporting.manage');
        }

        return $this->savedRepo->create([
            'report_key' => $reportKey,
            'name' => $name,
            'description' => $payload['description'] ?? null,
            'parameters' => $payload['parameters'] ?? null,
            'columns_visible' => $payload['columns_visible'] ?? null,
            'drill_down' => $payload['drill_down'] ?? null,
            'owner_user_id' => (int) $user->id,
            'is_shared' => $isShared,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(User $user, int $id, array $payload): ?SavedReport
    {
        $existing = $this->savedRepo->find($id);
        if ($existing === null) {
            return null;
        }

        $isOwner = $existing->owner_user_id === (int) $user->id;
        if (!$isOwner) {
            $this->gate->assert($user, 'reporting.manage');
        } else {
            $this->gate->assert($user, 'reporting.view');
        }

        $update = [];
        foreach (['name', 'description', 'parameters', 'columns_visible', 'drill_down'] as $field) {
            if (array_key_exists($field, $payload)) {
                $update[$field] = $payload[$field];
            }
        }
        if (array_key_exists('is_shared', $payload)) {
            $this->gate->assert($user, 'reporting.manage');
            $update['is_shared'] = (bool) $payload['is_shared'];
        }

        return $this->savedRepo->update($id, $update);
    }

    public function delete(User $user, int $id): bool
    {
        $existing = $this->savedRepo->find($id);
        if ($existing === null) {
            return false;
        }
        if ($existing->owner_user_id !== (int) $user->id) {
            $this->gate->assert($user, 'reporting.manage');
        } else {
            $this->gate->assert($user, 'reporting.view');
        }
        return $this->savedRepo->delete($id);
    }

    /**
     * Run a saved report. Records execution audit row.
     *
     * @return array{rows: array<int, array<string, mixed>>, columns: array<int, array<string, mixed>>, total: int, drill_down: ?array<string, mixed>, execution: array<string, mixed>}
     */
    public function runSaved(User $user, int $id, ?DateTimeImmutable $now = null): array
    {
        $saved = $this->savedRepo->find($id);
        if ($saved === null) {
            throw new InvalidArgumentException('Saved report not found.');
        }
        $isOwner = $saved->owner_user_id === (int) $user->id;
        if (!$isOwner && !$saved->is_shared) {
            $this->gate->assert($user, 'reporting.manage');
        } else {
            $this->gate->assert($user, 'reporting.view');
        }

        return $this->execute(
            $saved->report_key,
            $saved->parameters ?? [],
            ReportExecution::TRIGGER_MANUAL,
            (int) $user->id,
            $saved->id,
            null,
            $now
        );
    }

    /**
     * Run an ad-hoc report (no saved record).
     *
     * @param array<string, mixed> $parameters
     * @return array{rows: array<int, array<string, mixed>>, columns: array<int, array<string, mixed>>, total: int, drill_down: ?array<string, mixed>, execution: array<string, mixed>}
     */
    public function runAdhoc(
        User $user,
        string $reportKey,
        array $parameters,
        ?DateTimeImmutable $now = null
    ): array {
        $this->gate->assert($user, 'reporting.view');
        if (!$this->catalog->hasReport($reportKey)) {
            throw new InvalidArgumentException('Unknown report_key.');
        }
        return $this->execute(
            $reportKey,
            $parameters,
            ReportExecution::TRIGGER_MANUAL,
            (int) $user->id,
            null,
            null,
            $now
        );
    }

    /**
     * Run a scheduled report (cron-driven, no user actor).
     *
     * @param array<string, mixed> $parameters
     * @return array{rows: array<int, array<string, mixed>>, columns: array<int, array<string, mixed>>, total: int, drill_down: ?array<string, mixed>, execution: array<string, mixed>}
     */
    public function runForSchedule(
        string $reportKey,
        array $parameters,
        int $savedReportId,
        int $scheduledReportId,
        ?DateTimeImmutable $now = null
    ): array {
        if (!$this->catalog->hasReport($reportKey)) {
            throw new RuntimeException('Unknown report_key in schedule: ' . $reportKey);
        }
        return $this->execute(
            $reportKey,
            $parameters,
            ReportExecution::TRIGGER_SCHEDULED,
            null,
            $savedReportId,
            $scheduledReportId,
            $now
        );
    }

    /**
     * @param array<string, mixed> $parameters
     * @return array{rows: array<int, array<string, mixed>>, columns: array<int, array<string, mixed>>, total: int, drill_down: ?array<string, mixed>, execution: array<string, mixed>}
     */
    private function execute(
        string $reportKey,
        array $parameters,
        string $trigger,
        ?int $userId,
        ?int $savedReportId,
        ?int $scheduledReportId,
        ?DateTimeImmutable $now
    ): array {
        $startNow = $now ?? new DateTimeImmutable();
        $execution = $this->executions->start([
            'report_key' => $reportKey,
            'saved_report_id' => $savedReportId,
            'scheduled_report_id' => $scheduledReportId,
            'triggered_by' => $trigger,
            'user_id' => $userId,
            'parameters' => $parameters,
            'status' => ReportExecution::STATUS_RUNNING,
            'started_at' => $startNow->format('Y-m-d H:i:s'),
        ]);
        $startMs = (int) (microtime(true) * 1000);

        try {
            $result = $this->catalog->run($reportKey, $parameters);
        } catch (Throwable $e) {
            $finishMs = (int) (microtime(true) * 1000);
            $this->executions->finish(
                (int) $execution->id,
                ReportExecution::STATUS_FAILED,
                null,
                $finishMs - $startMs,
                substr($e->getMessage(), 0, 1000),
                (new DateTimeImmutable())->format('Y-m-d H:i:s')
            );
            throw $e;
        }

        $finishMs = (int) (microtime(true) * 1000);
        $this->executions->finish(
            (int) $execution->id,
            ReportExecution::STATUS_SUCCEEDED,
            (int) $result['total'],
            $finishMs - $startMs,
            null,
            (new DateTimeImmutable())->format('Y-m-d H:i:s')
        );

        return [
            'rows' => $result['rows'],
            'columns' => $result['columns'],
            'total' => $result['total'],
            'drill_down' => $result['drill_down'] ?? null,
            'execution' => [
                'id' => (int) $execution->id,
                'duration_ms' => $finishMs - $startMs,
                'started_at' => $startNow->format('Y-m-d H:i:s'),
            ],
        ];
    }
}
