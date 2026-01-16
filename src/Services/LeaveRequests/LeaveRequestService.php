<?php

namespace App\Services\LeaveRequests;

use App\Database\Connection;
use App\Models\LeaveRequest;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class LeaveRequestService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function list(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $baseSql = 'FROM leave_requests lr '
            . 'LEFT JOIN users u ON u.id = lr.user_id '
            . 'LEFT JOIN users ru ON ru.id = lr.reviewer_id '
            . 'WHERE 1=1';
        $params = [];

        if (!empty($filters['user_id'])) {
            $baseSql .= ' AND lr.user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }

        if (!empty($filters['status'])) {
            $baseSql .= ' AND lr.status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['start_date'])) {
            $baseSql .= ' AND lr.start_date >= :start_date';
            $params['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $baseSql .= ' AND lr.end_date <= :end_date';
            $params['end_date'] = $filters['end_date'];
        }

        if (!empty($filters['search'])) {
            $baseSql .= ' AND (u.name LIKE :search OR lr.type LIKE :search OR lr.reason LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $countStmt = $this->connection->pdo()->prepare('SELECT COUNT(*) ' . $baseSql);
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $sql = 'SELECT lr.*, u.name AS user_name, ru.name AS reviewer_name ' . $baseSql
            . ' ORDER BY lr.created_at DESC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $type = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $type);
        }

        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $requests = array_map(static fn (array $row) => new LeaveRequest($row), $rows);
        $metaById = [];
        foreach ($rows as $row) {
            $metaById[(int) $row['id']] = $row;
        }

        $data = [];
        foreach ($requests as $request) {
            $row = $request->toArray();
            $meta = $metaById[$request->id] ?? [];
            $row['user_name'] = $meta['user_name'] ?? null;
            $row['reviewer_name'] = $meta['reviewer_name'] ?? null;
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
     * @param array<string, mixed> $data
     */
    public function create(int $userId, array $data): LeaveRequest
    {
        $startDate = $this->normalizeDate($data['start_date'] ?? null, 'Start date');
        $endDate = $this->normalizeDate($data['end_date'] ?? null, 'End date');

        if ($endDate < $startDate) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }

        $type = trim((string) ($data['type'] ?? ''));
        if ($type === '') {
            throw new InvalidArgumentException('Leave type is required.');
        }

        $reason = isset($data['reason']) ? trim((string) $data['reason']) : null;
        if ($reason === '') {
            $reason = null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO leave_requests (user_id, start_date, end_date, type, status, reason, created_at, updated_at) '
            . 'VALUES (:user_id, :start_date, :end_date, :type, :status, :reason, NOW(), NOW())'
        );
        $stmt->execute([
            'user_id' => $userId,
            'start_date' => $startDate->format('Y-m-d'),
            'end_date' => $endDate->format('Y-m-d'),
            'type' => $type,
            'status' => 'pending',
            'reason' => $reason,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new LeaveRequest(['id' => $id]);
    }

    public function find(int $id): ?LeaveRequest
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM leave_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return new LeaveRequest($row);
    }

    public function review(int $id, int $reviewerId, string $status, ?string $notes = null): ?LeaveRequest
    {
        if (!in_array($status, ['approved', 'rejected'], true)) {
            throw new InvalidArgumentException('Invalid review status.');
        }

        $request = $this->find($id);
        if ($request === null) {
            return null;
        }

        if ($request->status !== 'pending') {
            throw new InvalidArgumentException('Leave request has already been reviewed.');
        }

        $normalizedNotes = $notes !== null ? trim($notes) : null;
        if ($normalizedNotes === '') {
            $normalizedNotes = null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE leave_requests SET status = :status, reviewer_id = :reviewer_id, reviewer_notes = :notes, reviewed_at = NOW(), updated_at = NOW() '
            . 'WHERE id = :id'
        );
        $stmt->execute([
            'status' => $status,
            'reviewer_id' => $reviewerId,
            'notes' => $normalizedNotes,
            'id' => $id,
        ]);

        return $this->find($id);
    }

    private function normalizeDate(?string $value, string $label): DateTimeImmutable
    {
        if ($value === null || trim($value) === '') {
            throw new InvalidArgumentException(sprintf('%s is required.', $label));
        }

        $date = DateTimeImmutable::createFromFormat('Y-m-d', $value);
        if ($date === false) {
            throw new InvalidArgumentException(sprintf('%s must be in YYYY-MM-DD format.', $label));
        }

        return $date;
    }
}
