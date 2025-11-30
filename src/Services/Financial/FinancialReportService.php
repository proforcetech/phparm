<?php

namespace App\Services\Financial;

use App\Database\Connection;
use DateInterval;
use DatePeriod;
use DateTimeImmutable;
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
    public function summary(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT type, SUM(amount) as total FROM financial_entries WHERE occurred_on BETWEEN :start AND :end GROUP BY type'
        );
        $stmt->execute([
            'start' => $start->format('Y-m-d'),
            'end' => $end->format('Y-m-d'),
        ]);

        $summary = ['income' => 0.0, 'expense' => 0.0, 'purchase' => 0.0];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $summary[$row['type']] = (float) $row['total'];
        }

        return [
            'range' => [$start->format('Y-m-d'), $end->format('Y-m-d')],
            'summary' => $summary,
            'net' => $summary['income'] - $summary['expense'] - $summary['purchase'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function monthlyBreakdown(DateTimeImmutable $start, DateTimeImmutable $end): array
    {
        $period = new DatePeriod($start->modify('first day of this month'), new DateInterval('P1M'), $end->modify('first day of next month'));
        $results = [];

        foreach ($period as $month) {
            $monthStart = $month->modify('first day of this month');
            $monthEnd = $month->modify('last day of this month');
            $results[] = array_merge([
                'month' => $monthStart->format('Y-m'),
            ], $this->summary($monthStart, $monthEnd));
        }

        return $results;
    }
}
