<?php

namespace App\Services\Vehicle;

use InvalidArgumentException;

class VehicleMasterImporter
{
    private VehicleMasterRepository $repository;
    private VehicleMasterValidator $validator;

    public function __construct(VehicleMasterRepository $repository, ?VehicleMasterValidator $validator = null)
    {
        $this->repository = $repository;
        $this->validator = $validator ?? new VehicleMasterValidator();
    }

    /**
     * Generate a preview with validation and duplicate flags.
     *
     * @param array<string, int|string> $mapping
     * @return array<string, mixed>
     */
    public function preview(string $csv, array $mapping, int $limit = 20): array
    {
        [$headers, $rows] = $this->parseCsv($csv);
        $resolvedMapping = $this->normalizeMapping($mapping, $headers);

        $preview = [];
        $errors = [];
        $seen = [];
        foreach ($rows as $index => $row) {
            if (count($preview) >= $limit) {
                break;
            }

            try {
                $mapped = $this->mapRow($row, $resolvedMapping);
                $payload = $this->validator->validate($mapped);
                $fingerprint = $this->fingerprint($payload);
                $duplicate = isset($seen[$fingerprint]) || $this->repository->findByAttributes($payload) !== null;
                $seen[$fingerprint] = true;
                $preview[] = [
                    'row' => $index + 2, // account for header
                    'data' => $payload,
                    'duplicate' => $duplicate,
                ];
            } catch (InvalidArgumentException $e) {
                $errors[] = [
                    'row' => $index + 2,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return [
            'preview' => $preview,
            'errors' => $errors,
        ];
    }

    /**
     * Import CSV content and return a summary of outcomes.
     *
     * @param array<string, int|string> $mapping
     * @return array<string, mixed>
     */
    public function import(string $csv, array $mapping, bool $updateDuplicates = false): array
    {
        [$headers, $rows] = $this->parseCsv($csv);
        $resolvedMapping = $this->normalizeMapping($mapping, $headers);

        $summary = [
            'created' => 0,
            'updated' => 0,
            'duplicates' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($rows as $index => $row) {
            try {
                $mapped = $this->mapRow($row, $resolvedMapping);
                $payload = $this->validator->validate($mapped);

                $existing = $this->repository->findByAttributes($payload);
                if ($existing !== null) {
                    $summary['duplicates']++;
                    if ($updateDuplicates) {
                        $this->repository->update($existing->id, $payload);
                        $summary['updated']++;
                    }
                    continue;
                }

                $this->repository->create($payload);
                $summary['created']++;
            } catch (InvalidArgumentException $e) {
                $summary['failed']++;
                $summary['errors'][] = [
                    'row' => $index + 2,
                    'message' => $e->getMessage(),
                ];
            }
        }

        return $summary;
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, array<int, string>>}
     */
    private function parseCsv(string $csv): array
    {
        $lines = preg_split("/(\r\n|\n|\r)/", trim($csv));
        if (!$lines || count($lines) < 2) {
            throw new InvalidArgumentException('CSV content must include a header row and at least one data row.');
        }

        $headers = str_getcsv((string) array_shift($lines), ',', '"', '\\');
        $rows = array_map(static fn ($line) => str_getcsv($line, ',', '"', '\\'), $lines);

        return [$headers, $rows];
    }

    /**
     * @param array<string, int|string> $mapping
     * @param array<int, string> $headers
     * @return array<string, int>
     */
    private function normalizeMapping(array $mapping, array $headers): array
    {
        $required = ['year', 'make', 'model', 'engine', 'transmission', 'drive'];
        foreach ($required as $field) {
            if (!isset($mapping[$field])) {
                throw new InvalidArgumentException("Mapping missing required field: {$field}.");
            }
        }

        $resolved = [];
        foreach ($mapping as $field => $column) {
            if (is_int($column)) {
                $resolved[$field] = $column;
                continue;
            }

            $index = array_search($column, $headers, true);
            if ($index === false) {
                throw new InvalidArgumentException("Column '{$column}' not found in CSV headers.");
            }
            $resolved[$field] = $index;
        }

        return $resolved;
    }

    /**
     * @param array<int, string> $row
     * @param array<string, int> $mapping
     * @return array<string, string|null>
     */
    private function mapRow(array $row, array $mapping): array
    {
        $mapped = [];
        foreach ($mapping as $field => $index) {
            $mapped[$field] = $row[$index] ?? '';
        }

        return $mapped;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function fingerprint(array $payload): string
    {
        return md5(json_encode([
            $payload['year'],
            $payload['make'],
            $payload['model'],
            $payload['engine'],
            $payload['transmission'],
            $payload['drive'],
            $payload['trim'] ?? null,
        ]));
    }
}
