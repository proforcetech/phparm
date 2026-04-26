<?php

namespace App\Services\Payroll;

use App\Database\Connection;
use App\Support\SettingsRepository;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class PayrollExportService
{
    private Connection $connection;
    private SettingsRepository $settings;

    public function __construct(Connection $connection, SettingsRepository $settings)
    {
        $this->connection = $connection;
        $this->settings = $settings;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function list(int $limit = 25, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);

        $stmt = $this->connection->pdo()->prepare(
            'SELECT pe.*, u.name AS created_by_name '
            . 'FROM payroll_exports pe '
            . 'LEFT JOIN users u ON u.id = pe.created_by '
            . 'ORDER BY pe.created_at DESC '
            . 'LIMIT :limit OFFSET :offset'
        );
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $rowCount = count($rows);
        $total = $rowCount < $limit
            ? $offset + $rowCount
            : (int) $this->connection->pdo()->query('SELECT COUNT(*) FROM payroll_exports')->fetchColumn();

        return [
            'data' => $rows,
            'pagination' => [
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * @return array{format: string, filename: string, data: string, export: array<string, mixed>}
     */
    public function export(string $provider, string $startDate, string $endDate, int $actorId): array
    {
        $providerKey = $this->normalizeProvider($provider);
        [$start, $end] = $this->normalizeDateRange($startDate, $endDate);

        $rows = $this->fetchPayrollRows($start, $end);
        $totals = $this->summarizeRows($rows);
        $csv = $this->buildCsv($providerKey, $rows);
        $status = $totals['row_count'] > 0 ? 'completed' : 'empty';

        $exportId = $this->recordExport([
            'provider' => $providerKey,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'status' => $status,
            'total_hours' => $totals['total_hours'],
            'total_gross_pay' => $totals['total_gross_pay'],
            'row_count' => $totals['row_count'],
            'created_by' => $actorId,
            'error_message' => null,
        ]);

        return [
            'format' => 'csv',
            'filename' => sprintf(
                'payroll-%s-%s-%s.csv',
                $providerKey,
                $start->format('Ymd'),
                $end->format('Ymd')
            ),
            'data' => $csv,
            'export' => [
                'id' => $exportId,
                'provider' => $providerKey,
                'status' => $status,
                'total_hours' => $totals['total_hours'],
                'total_gross_pay' => $totals['total_gross_pay'],
                'row_count' => $totals['row_count'],
            ],
        ];
    }

    public function recordFailure(string $provider, string $startDate, string $endDate, int $actorId, string $message): void
    {
        $providerKey = $this->normalizeProvider($provider);
        [$start, $end] = $this->normalizeDateRange($startDate, $endDate);

        $this->recordExport([
            'provider' => $providerKey,
            'start_date' => $start->format('Y-m-d'),
            'end_date' => $end->format('Y-m-d'),
            'status' => 'failed',
            'total_hours' => 0,
            'total_gross_pay' => 0,
            'row_count' => 0,
            'created_by' => $actorId,
            'error_message' => $message,
        ]);
    }

    private function normalizeProvider(string $provider): string
    {
        $normalized = strtolower(trim($provider));
        $allowed = ['gusto', 'adp', 'quickbooks'];

        if (!in_array($normalized, $allowed, true)) {
            throw new InvalidArgumentException('Unsupported payroll export provider.');
        }

        return $normalized;
    }

    /**
     * @return array{0: DateTimeImmutable, 1: DateTimeImmutable}
     */
    private function normalizeDateRange(string $startDate, string $endDate): array
    {
        if ($startDate === '' || $endDate === '') {
            throw new InvalidArgumentException('Start date and end date are required.');
        }

        $start = new DateTimeImmutable($startDate);
        $end = new DateTimeImmutable($endDate);

        if ($end < $start) {
            throw new InvalidArgumentException('End date must be on or after the start date.');
        }

        return [$start, $end];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchPayrollRows(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $defaultLaborRate = (float) ($this->settings->get('pricing.labor_rate', 0) ?? 0.0);

        $stmt = $this->connection->pdo()->prepare(
            'SELECT
                u.id AS employee_id,
                u.name AS employee_name,
                u.email AS employee_email,
                SUM(te.duration_minutes) AS total_minutes,
                SUM((te.duration_minutes / 60) * COALESCE(lt.labor_rate, :default_rate)) AS gross_pay
            FROM time_entries te
            JOIN users u ON u.id = te.technician_id
            LEFT JOIN labor_tasks lt ON lt.id = te.task_id
            WHERE te.ended_at IS NOT NULL
                AND te.status = \'approved\'
                AND te.started_at BETWEEN :start AND :end
            GROUP BY u.id, u.name, u.email
            ORDER BY u.name'
        );

        $stmt->execute([
            'default_rate' => $defaultLaborRate,
            'start' => $start->format('Y-m-d 00:00:00'),
            'end' => $end->format('Y-m-d 23:59:59'),
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array{total_hours: float, total_gross_pay: float, row_count: int}
     */
    private function summarizeRows(array $rows): array
    {
        $totalHours = 0.0;
        $totalGrossPay = 0.0;
        foreach ($rows as $row) {
            $minutes = (float) ($row['total_minutes'] ?? 0);
            $hours = $minutes / 60;
            $totalHours += $hours;
            $totalGrossPay += (float) ($row['gross_pay'] ?? 0);
        }

        return [
            'total_hours' => round($totalHours, 2),
            'total_gross_pay' => round($totalGrossPay, 2),
            'row_count' => count($rows),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildCsv(string $provider, array $rows): string
    {
        $headers = $this->headersForProvider($provider);
        $buffer = fopen('php://temp', 'r+');
        fputcsv($buffer, $headers);

        foreach ($rows as $row) {
            $minutes = (float) ($row['total_minutes'] ?? 0);
            $hours = round($minutes / 60, 2);
            $grossPay = round((float) ($row['gross_pay'] ?? 0), 2);
            $line = $this->rowForProvider($provider, [
                'employee_id' => $row['employee_id'] ?? null,
                'employee_name' => $row['employee_name'] ?? null,
                'employee_email' => $row['employee_email'] ?? null,
                'hours' => $hours,
                'gross_pay' => $grossPay,
            ]);
            fputcsv($buffer, $line);
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer) ?: '';
        fclose($buffer);

        return $csv;
    }

    /**
     * @return array<int, string>
     */
    private function headersForProvider(string $provider): array
    {
        return match ($provider) {
            'gusto' => ['Employee ID', 'Employee Name', 'Employee Email', 'Hours', 'Gross Pay'],
            'adp' => ['Associate ID', 'Associate Name', 'Associate Email', 'Hours', 'Gross Pay'],
            'quickbooks' => ['Employee ID', 'Employee Name', 'Employee Email', 'Hours', 'Gross Pay'],
            default => throw new InvalidArgumentException('Unsupported payroll export provider.'),
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<int, string|float|int|null>
     */
    private function rowForProvider(string $provider, array $payload): array
    {
        $row = [
            $payload['employee_id'] ?? null,
            $payload['employee_name'] ?? null,
            $payload['employee_email'] ?? null,
            $payload['hours'] ?? 0,
            $payload['gross_pay'] ?? 0,
        ];

        if ($provider === 'adp') {
            return $row;
        }

        return $row;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function recordExport(array $payload): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payroll_exports (provider, start_date, end_date, status, total_hours, total_gross_pay, row_count, created_by, error_message, created_at, updated_at) '
            . 'VALUES (:provider, :start_date, :end_date, :status, :total_hours, :total_gross_pay, :row_count, :created_by, :error_message, NOW(), NOW())'
        );
        $stmt->execute([
            'provider' => $payload['provider'],
            'start_date' => $payload['start_date'],
            'end_date' => $payload['end_date'],
            'status' => $payload['status'],
            'total_hours' => $payload['total_hours'],
            'total_gross_pay' => $payload['total_gross_pay'],
            'row_count' => $payload['row_count'],
            'created_by' => $payload['created_by'],
            'error_message' => $payload['error_message'],
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }
}
