<?php

namespace App\Services\Audit;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use App\Services\ImportExport\AuditExportService;

class AuditController
{
    private AuditLogViewerService $viewer;
    private AuditExportService $export;
    private AccessGate $gate;

    public function __construct(
        AuditLogViewerService $viewer,
        AuditExportService $export,
        AccessGate $gate
    ) {
        $this->viewer = $viewer;
        $this->export = $export;
        $this->gate = $gate;
    }

    /**
     * List audit logs
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'audit.view')) {
            throw new UnauthorizedException('Cannot view audit logs');
        }

        $logs = $this->viewer->list($filters);
        return array_map(static fn ($log) => $log->toArray(), $logs);
    }

    /**
     * Get single audit log entry
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'audit.view')) {
            throw new UnauthorizedException('Cannot view audit logs');
        }

        $log = $this->viewer->findById($id);

        if ($log === null) {
            throw new \InvalidArgumentException('Audit log entry not found');
        }

        return $log->toArray();
    }

    /**
     * Export audit logs
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function export(User $user, array $params): array
    {
        if (!$this->gate->can($user, 'audit.view')) {
            throw new UnauthorizedException('Cannot export audit logs');
        }

        $filters = [
            'entity_type' => $params['entity_type'] ?? null,
            'actor_id' => $params['actor_id'] ?? null,
            'start_date' => $params['start_date'] ?? null,
            'end_date' => $params['end_date'] ?? null,
        ];

        $format = $params['format'] ?? 'csv';
        $data = $this->export->export($filters, $format);

        return [
            'format' => $format,
            'data' => $data,
            'filename' => 'audit-logs-' . date('Y-m-d') . '.' . $format,
        ];
    }
}
