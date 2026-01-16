<?php

namespace App\Services\Leave;

use App\Database\Connection;
use DateTimeImmutable;
use PDO;

class LeaveRequestService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function isUserOnLeave(int $userId, DateTimeImmutable $start, DateTimeImmutable $end): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM leave_requests lr '
            . 'INNER JOIN employees e ON e.id = lr.employee_id '
            . 'WHERE e.user_id = :user_id '
            . 'AND lr.status = :status '
            . 'AND lr.start_at <= :end_at '
            . 'AND lr.end_at >= :start_at'
        );
        $stmt->execute([
            'user_id' => $userId,
            'status' => 'approved',
            'start_at' => $start->format('Y-m-d H:i:s'),
            'end_at' => $end->format('Y-m-d H:i:s'),
        ]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /**
     * @param int[] $userIds
     * @return array<int, array<string, mixed>>
     */
    public function fetchApprovedLeaveByUserIds(array $userIds, DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        if ($userIds === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $sql = 'SELECT lr.id, lr.leave_type, lr.start_at, lr.end_at, lr.paid_hours, lr.approved_hours, '
            . 'e.user_id, u.name '
            . 'FROM leave_requests lr '
            . 'INNER JOIN employees e ON e.id = lr.employee_id '
            . 'INNER JOIN users u ON u.id = e.user_id '
            . 'WHERE lr.status = ? '
            . 'AND lr.start_at <= ? '
            . 'AND lr.end_at >= ? '
            . 'AND e.user_id IN (' . $placeholders . ') '
            . 'ORDER BY lr.start_at ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $params = array_merge(
            ['approved', $end->format('Y-m-d H:i:s'), $start->format('Y-m-d H:i:s')],
            $userIds
        );

        foreach ($params as $index => $value) {
            $stmt->bindValue($index + 1, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }

        $stmt->execute();

        $leaves = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $userId = (int) $row['user_id'];
            $leaves[$userId] = $row;
        }

        return $leaves;
    }
}
