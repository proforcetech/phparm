<?php

namespace App\Services\Dashboard;

use DateInterval;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;

class DateRangePresetResolver
{
    /**
     * Resolve a named preset into a date range aligned to the provided timezone.
     *
     * @return array{start: DateTimeInterface, end: DateTimeInterface}
     */
    public function resolve(string $preset, string $timezone = 'UTC', ?DateTimeInterface $now = null): array
    {
        $now = $now ? DateTimeImmutable::createFromInterface($now) : new DateTimeImmutable('now', new DateTimeZone($timezone));
        $tzNow = $now->setTimezone(new DateTimeZone($timezone));

        return match ($preset) {
            'today' => [
                'start' => $this->startOfDay($tzNow),
                'end' => $this->endOfDay($tzNow),
            ],
            'last_7_days' => [
                'start' => $this->startOfDay($tzNow->sub(new DateInterval('P6D'))),
                'end' => $this->endOfDay($tzNow),
            ],
            'last_30_days' => [
                'start' => $this->startOfDay($tzNow->sub(new DateInterval('P29D'))),
                'end' => $this->endOfDay($tzNow),
            ],
            'this_month' => [
                'start' => $this->startOfMonth($tzNow),
                'end' => $this->endOfMonth($tzNow),
            ],
            'last_month' => $this->lastMonthRange($tzNow),
            'year_to_date' => [
                'start' => $this->startOfDay($tzNow->setDate((int) $tzNow->format('Y'), 1, 1)),
                'end' => $this->endOfDay($tzNow),
            ],
            default => [
                'start' => $this->startOfDay($tzNow),
                'end' => $this->endOfDay($tzNow),
            ],
        };
    }

    private function startOfDay(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)->setTime(0, 0, 0);
    }

    private function endOfDay(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)->setTime(23, 59, 59);
    }

    private function startOfMonth(DateTimeInterface $date): DateTimeImmutable
    {
        return DateTimeImmutable::createFromInterface($date)->setDate((int) $date->format('Y'), (int) $date->format('m'), 1)->setTime(0, 0, 0);
    }

    private function endOfMonth(DateTimeInterface $date): DateTimeImmutable
    {
        return $this->startOfMonth($date)->modify('last day of this month')->setTime(23, 59, 59);
    }

    /**
     * @return array{start: DateTimeInterface, end: DateTimeInterface}
     */
    private function lastMonthRange(DateTimeInterface $date): array
    {
        $startOfLastMonth = $this->startOfMonth($date)->sub(new DateInterval('P1M'));
        $endOfLastMonth = $this->endOfMonth($startOfLastMonth);

        return [
            'start' => $startOfLastMonth,
            'end' => $endOfLastMonth,
        ];
    }
}
