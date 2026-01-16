<?php

namespace App\Services\Reports;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class LeaveReportController
{
    private LeaveReportService $reports;
    private AccessGate $gate;

    public function __construct(LeaveReportService $reports, AccessGate $gate)
    {
        $this->reports = $reports;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function summary(User $user, array $params): array
    {
        if (!$this->gate->can($user, 'reports.view')) {
            throw new UnauthorizedException('Cannot view leave reports');
        }

        $startDate = $params['start_date'] ?? null;
        $endDate = $params['end_date'] ?? null;
        $employeeId = isset($params['employee_id']) && $params['employee_id'] !== ''
            ? (int) $params['employee_id']
            : null;

        if (!$startDate || !$endDate) {
            throw new InvalidArgumentException('start_date and end_date are required');
        }

        return $this->reports->summary($startDate, $endDate, $employeeId);
    }
}
