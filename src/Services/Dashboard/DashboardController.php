<?php

namespace App\Services\Dashboard;

use App\DTO\Dashboard\ChartSeries;
use App\DTO\Dashboard\KpiResponse;
use App\Services\Dashboard\ChartImageRenderer;
use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;

class DashboardController
{
    private DashboardService $service;
    private ChartImageRenderer $renderer;

    public function __construct(DashboardService $service, ?ChartImageRenderer $renderer = null)
    {
        $this->service = $service;
        $this->renderer = $renderer ?? new ChartImageRenderer();
    }

    /**
     * @param array<string, mixed> $params
     */
    public function handleKpis(array $params): array
    {
        [$start, $end, $timezone] = $this->resolveRange($params);
        $options = $this->extractOptions($params);

        $kpis = $this->service->kpis($start, $end, $options);

        return $kpis->toArray();
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function handleMonthlyTrends(array $params): array
    {
        [$start, $end, $timezone] = $this->resolveRange($params);
        $options = $this->extractOptions($params);

        $series = $this->service->monthlyTrends($start, $end, $options);

        return array_map(static fn (ChartSeries $item) => $item->toArray(), $series);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function handleServiceTypeBreakdown(array $params): array
    {
        [$start, $end, $timezone] = $this->resolveRange($params);
        $options = $this->extractOptions($params);
        $options['timezone'] = $timezone;
        if (isset($params['limit'])) {
            $options['limit'] = (int) $params['limit'];
        }

        $series = $this->service->serviceTypeBreakdown($start, $end, $options);

        return $series->toArray();
    }

    /**
     * @param array<string, mixed> $params
     * @return array{filename: string, content_type: string, body: string}
     */
    public function exportDashboardData(array $params): array
    {
        $format = strtolower((string) ($params['format'] ?? 'csv'));
        $type = strtolower((string) ($params['type'] ?? 'kpis'));

        [$start, $end, $timezone] = $this->resolveRange($params);
        $options = $this->extractOptions($params);

        if ($type === 'kpis') {
            $payload = $this->service->kpis($start, $end, $options);
            return $this->exportKpis($payload, $format, $timezone);
        }

        if ($type === 'monthly_trends') {
            $payload = $this->service->monthlyTrends($start, $end, $options);
            return $this->exportMonthlyTrends($payload, $format, $timezone, $start, $end);
        }

        throw new InvalidArgumentException('Unsupported export type: ' . $type);
    }

    /**
     * @param array<string, mixed> $params
     * @return array{filename: string, content_type: string, body: string}
     */
    public function exportChartImage(array $params): array
    {
        $chart = strtolower((string) ($params['chart'] ?? 'monthly_trends'));
        [$start, $end, $timezone] = $this->resolveRange($params);
        $options = $this->extractOptions($params);
        $options['timezone'] = $timezone;

        if ($chart === 'service_types') {
            $series = [$this->service->serviceTypeBreakdown($start, $end, $options)];
            $title = 'Service Type Breakdown';
            $filename = sprintf('service_type_breakdown_%s_to_%s.png', $start->format('Ymd'), $end->format('Ymd'));
        } else {
            $series = $this->service->monthlyTrends($start, $end, $options);
            $title = 'Monthly Trends';
            $filename = sprintf('monthly_trends_%s_to_%s.png', $start->format('Ymd'), $end->format('Ymd'));
        }

        $body = $this->renderer->renderBarChart($series, $title);

        return [
            'filename' => $filename,
            'content_type' => 'image/png',
            'body' => $body,
        ];
    }

    /**
     * @return array{0: DateTimeInterface, 1: DateTimeInterface, 2: string}
     */
    private function resolveRange(array $params): array
    {
        $timezone = $params['timezone'] ?? 'UTC';

        if (isset($params['preset'])) {
            [$start, $end] = $this->service->resolvePreset((string) $params['preset'], (string) $timezone);
        } elseif (isset($params['start'], $params['end'])) {
            $start = new DateTimeImmutable((string) $params['start']);
            $end = new DateTimeImmutable((string) $params['end']);
        } else {
            throw new InvalidArgumentException('Provide either preset or start/end range.');
        }

        if ($start > $end) {
            throw new InvalidArgumentException('Start date must be before end date.');
        }

        return [$start, $end, (string) $timezone];
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractOptions(array $params): array
    {
        $options = [];
        foreach (['timezone', 'cache_ttl', 'customer_id', 'role'] as $key) {
            if (isset($params[$key])) {
                $options[$key] = $params[$key];
            }
        }

        return $options;
    }

    private function exportKpis(KpiResponse $response, string $format, string $timezone): array
    {
        $filename = sprintf('kpis_%s.%s', strtolower($timezone), $format === 'json' ? 'json' : 'csv');

        if ($format === 'json') {
            return [
                'filename' => $filename,
                'content_type' => 'application/json',
                'body' => json_encode($response->toArray(), JSON_PRETTY_PRINT),
            ];
        }

        $rows = [
            ['Metric', 'Value'],
        ];

        foreach ($response->estimateStatusCounts as $status => $count) {
            $rows[] = ['Estimate: ' . $status, (string) $count];
        }

        foreach ($response->invoiceTotals as $field => $value) {
            $rows[] = ['Invoice: ' . $field, (string) $value];
        }

        foreach ($response->taxTotals as $field => $value) {
            $rows[] = ['Tax: ' . $field, (string) $value];
        }

        foreach ($response->warrantyCounts as $status => $count) {
            $rows[] = ['Warranty: ' . $status, (string) $count];
        }

        foreach ($response->appointmentCounts as $status => $count) {
            $rows[] = ['Appointment: ' . $status, (string) $count];
        }

        foreach ($response->inventoryAlerts as $type => $count) {
            $rows[] = ['Inventory: ' . $type, (string) $count];
        }

        return [
            'filename' => $filename,
            'content_type' => 'text/csv',
            'body' => $this->toCsv($rows),
        ];
    }

    /**
     * @param array<int, ChartSeries> $series
     */
    private function exportMonthlyTrends(array $series, string $format, string $timezone, DateTimeInterface $start, DateTimeInterface $end): array
    {
        $filename = sprintf(
            'monthly_trends_%s_%s_to_%s.%s',
            strtolower($timezone),
            $start->format('Ymd'),
            $end->format('Ymd'),
            $format === 'json' ? 'json' : 'csv'
        );

        if ($format === 'json') {
            return [
                'filename' => $filename,
                'content_type' => 'application/json',
                'body' => json_encode(array_map(static fn (ChartSeries $item) => $item->toArray(), $series), JSON_PRETTY_PRINT),
            ];
        }

        $categories = $series[0]->categories ?? [];
        $header = array_merge(['Month'], array_map(static fn (ChartSeries $item) => $item->label, $series));
        $rows = [$header];

        foreach ($categories as $index => $month) {
            $row = [$month];
            foreach ($series as $item) {
                $row[] = (string) ($item->data[$index] ?? 0);
            }
            $rows[] = $row;
        }

        return [
            'filename' => $filename,
            'content_type' => 'text/csv',
            'body' => $this->toCsv($rows),
        ];
    }

    /**
     * @param array<int, array<int, string>> $rows
     */
    private function toCsv(array $rows): string
    {
        $buffer = fopen('php://temp', 'rb+');
        foreach ($rows as $row) {
            fputcsv($buffer, $row);
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer) ?: '';
        fclose($buffer);

        return $csv;
    }
}
