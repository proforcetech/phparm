<?php

namespace App\Services\Financial;

use App\Database\Connection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class FinancialReportService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(string $startDate, string $endDate, ?string $category = null): array
    {
        $start = DateTimeImmutable::createFromFormat('Y-m-d', $startDate) ?: null;
        $end = DateTimeImmutable::createFromFormat('Y-m-d', $endDate) ?: null;

        if ($start === null || $end === null) {
            throw new InvalidArgumentException('Invalid date range');
        }

        $summary = $this->summary($start, $end, $category);

        return [
            'range' => [$start->format('Y-m-d'), $end->format('Y-m-d')],
            'summary' => $summary,
            'net' => $summary['income'] - $summary['expense'] - $summary['purchase'],
            'monthly' => $this->monthlyBreakdown($start, $end, $category),
        ];
    }

    /**
     * @return array<string, float>
     */
    public function summary(DateTimeImmutable $start, DateTimeImmutable $end, ?string $category = null): array
    {
        $sql = 'SELECT type, SUM(amount) as total FROM financial_entries WHERE entry_date BETWEEN :start AND :end';
        $params = [
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ];

        if ($category) {
            $sql .= ' AND category = :category';
            $params['category'] = $category;
        }

        $sql .= ' GROUP BY type';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        $summary = ['income' => 0.0, 'expense' => 0.0, 'purchase' => 0.0];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $summary[$row['type']] = (float) $row['total'];
        }

        return $summary;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthlyBreakdown(DateTimeImmutable $start, DateTimeImmutable $end, ?string $category = null): array
    {
        $period = new DatePeriod($start->modify('first day of this month'), new DateInterval('P1M'), $end->modify('first day of next month'));
        $results = [];

        foreach ($period as $month) {
            $monthStart = $month->modify('first day of this month');
            $monthEnd = $month->modify('last day of this month');
            $summary = $this->summary($monthStart, $monthEnd, $category);
            $results[] = [
                'month' => $monthStart->format('Y-m'),
                'summary' => $summary,
                'net' => $summary['income'] - $summary['expense'] - $summary['purchase'],
            ];
        }

        return $results;
    }

    public function export(string $startDate, string $endDate, string $format = 'csv', ?string $category = null): string
    {
        $report = $this->generate($startDate, $endDate, $category);

        if ($format !== 'csv') {
            throw new InvalidArgumentException('Unsupported export format');
        }

        $rows = [];
        $rows[] = ['Month', 'Income', 'Expenses', 'Purchases', 'Net'];
        foreach ($report['monthly'] as $row) {
            $rows[] = [
                $row['month'],
                number_format($row['summary']['income'], 2, '.', ''),
                number_format($row['summary']['expense'], 2, '.', ''),
                number_format($row['summary']['purchase'], 2, '.', ''),
                number_format($row['net'], 2, '.', ''),
            ];
        }

        return $this->toCsv($rows);
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function toCsv(array $rows): string
    {
        $fh = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($fh, $row);
        }
        rewind($fh);
        $csv = stream_get_contents($fh) ?: '';
        fclose($fh);

        return $csv;
    }
}
