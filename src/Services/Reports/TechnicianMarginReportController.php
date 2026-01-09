<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class TechnicianMarginReportController
{
    private TechnicianMarginReportService $reports;
    private AccessGate $gate;

    public function __construct(TechnicianMarginReportService $reports, AccessGate $gate)
    {
        $this->reports = $reports;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function report(User $user, array $params): array
    {
        if (!$this->gate->can($user, 'reports.view')) {
            throw new UnauthorizedException('Cannot view technician margin reports');
        }

        $startDate = $params['start_date'] ?? null;
        $endDate = $params['end_date'] ?? null;
        $branchId = isset($params['branch_id']) && $params['branch_id'] !== ''
            ? (int) $params['branch_id']
            : null;

        if (!$startDate || !$endDate) {
            throw new InvalidArgumentException('start_date and end_date are required');
        }

        return $this->reports->report($startDate, $endDate, $branchId);
    }
}
