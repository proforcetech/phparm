<?php

namespace App\Services\Reporting;

use InvalidArgumentException;

class ReportExportService
{
    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $columns
     */
    public function export(array $rows, array $columns, string $format): string
    {
        return match ($format) {
            'csv' => $this->csv($rows, $columns),
            'json' => $this->json($rows, $columns),
            default => throw new InvalidArgumentException('Unknown export format: ' . $format),
        };
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $columns
     */
    private function csv(array $rows, array $columns): string
    {
        $fp = fopen('php://temp', 'r+');
        if ($fp === false) {
            throw new \RuntimeException('Failed to open temp stream for CSV export.');
        }

        $headers = array_map(static fn ($c) => $c['label'] ?? $c['key'], $columns);
        fputcsv($fp, $headers);

        $keys = array_map(static fn ($c) => $c['key'], $columns);
        foreach ($rows as $row) {
            $line = [];
            foreach ($keys as $k) {
                $v = $row[$k] ?? '';
                if (is_array($v)) {
                    $v = json_encode($v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                }
                $line[] = $v;
            }
            fputcsv($fp, $line);
        }

        rewind($fp);
        $out = stream_get_contents($fp);
        fclose($fp);
        return $out === false ? '' : $out;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<int, array<string, mixed>> $columns
     */
    private function json(array $rows, array $columns): string
    {
        $payload = [
            'columns' => $columns,
            'rows' => $rows,
            'count' => count($rows),
        ];
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        return $encoded === false ? '{}' : $encoded;
    }

    public function contentTypeFor(string $format): string
    {
        return match ($format) {
            'csv' => 'text/csv',
            'json' => 'application/json',
            default => 'application/octet-stream',
        };
    }

    public function filenameFor(string $reportKey, string $format): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9._-]+/', '_', $reportKey) ?: 'report';
        $ts = date('Ymd_His');
        return "{$safe}_{$ts}.{$format}";
    }
}
