<?php

namespace App\Services\Reports;

use App\Database\Connection;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class LeaveReportService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(string $startDate, string $endDate, ?int $employeeId = null): array
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate) ?: null;
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate) ?: null;

        if ($start === null || $end === null) {
            throw new InvalidArgumentException('Invalid date range');
        }

        $balances = $this->fetchBalances($employeeId);
        $usage = $this->fetchUsage($start, $end, $employeeId);

        $summary = [];
        foreach ($balances as $row) {
            $key = $row['employee_id'] . '|' . $row['leave_type'];
            $summary[$key] = [
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'employee_email' => $row['employee_email'],
                'leave_type' => $row['leave_type'],
                'balance_hours' => (float) $row['balance_hours'],
                'used_hours' => 0.0,
                'paid_hours' => 0.0,
            ];
        }

        foreach ($usage as $row) {
            $key = $row['employee_id'] . '|' . $row['leave_type'];
            $summary[$key] ??= [
                'employee_id' => (int) $row['employee_id'],
                'employee_name' => $row['employee_name'],
                'employee_email' => $row['employee_email'],
                'leave_type' => $row['leave_type'],
                'balance_hours' => 0.0,
                'used_hours' => 0.0,
                'paid_hours' => 0.0,
            ];

            $summary[$key]['used_hours'] = (float) $row['used_hours'];
            $summary[$key]['paid_hours'] = (float) $row['paid_hours'];
        }

        return [
            'range' => [$start->format('Y-m-d'), $end->format('Y-m-d')],
            'summary' => array_values($summary),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchBalances(?int $employeeId): array
    {
        $sql = 'SELECT lb.employee_id, lb.leave_type, lb.balance_hours, u.name AS employee_name, u.email AS employee_email '
            . 'FROM leave_balances lb '
            . 'INNER JOIN employees e ON e.id = lb.employee_id '
            . 'INNER JOIN users u ON u.id = e.user_id';

        $params = [];
        if ($employeeId !== null) {
            $sql .= ' WHERE lb.employee_id = :employee_id';
            $params['employee_id'] = $employeeId;
        }

        $sql .= ' ORDER BY u.name ASC, lb.leave_type ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchUsage(DateTimeImmutable $start, DateTimeImmutable $end, ?int $employeeId): array
    {
        $sql = 'SELECT lr.employee_id, lr.leave_type, '
            . 'SUM(COALESCE(lr.approved_hours, lr.requested_hours, 0)) AS used_hours, '
            . 'SUM(COALESCE(lr.paid_hours, 0)) AS paid_hours, '
            . 'u.name AS employee_name, u.email AS employee_email '
            . 'FROM leave_requests lr '
            . 'INNER JOIN employees e ON e.id = lr.employee_id '
            . 'INNER JOIN users u ON u.id = e.user_id '
            . 'WHERE lr.status = :status '
            . 'AND lr.start_at <= :end_at '
            . 'AND lr.end_at >= :start_at';

        $params = [
            'status' => 'approved',
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ];

        if ($employeeId !== null) {
            $sql .= ' AND lr.employee_id = :employee_id';
            $params['employee_id'] = $employeeId;
        }

        $sql .= ' GROUP BY lr.employee_id, lr.leave_type, u.name, u.email '
            . 'ORDER BY u.name ASC, lr.leave_type ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
