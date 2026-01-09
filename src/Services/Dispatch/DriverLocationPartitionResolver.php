<?php

namespace App\Services\Dispatch;

use DateTimeImmutable;

final class DriverLocationPartitionResolver
{
    /**
     * Build a PARTITION clause for recent driver location queries.
     */
    public static function recentPartitions(
        ?DateTimeImmutable $reference = null,
        int $monthsBack = 1,
        int $monthsForward = 0
    ): string {
        $referenceDate = $reference ?? new DateTimeImmutable('now');
        $startDate = $referenceDate->modify(sprintf('first day of -%d month', $monthsBack));
        $endDate = $referenceDate->modify(sprintf('first day of +%d month', $monthsForward + 1));

        $partitions = [];
        $cursor = $startDate;

        while ($cursor < $endDate) {
            $partitions[] = sprintf('p%s', $cursor->format('Y_m'));
            $cursor = $cursor->modify('first day of next month');
        }

        if ($partitions === []) {
            return '';
        }

        return sprintf('PARTITION (%s)', implode(', ', $partitions));
    }
}
