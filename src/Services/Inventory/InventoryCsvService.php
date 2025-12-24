<?php

namespace App\Services\Inventory;

use InvalidArgumentException;

class InventoryCsvService
{
    private InventoryItemRepository $repository;
    private InventoryItemValidator $validator;

    public function __construct(InventoryItemRepository $repository, ?InventoryItemValidator $validator = null)
    {
        $this->repository = $repository;
        $this->validator = $validator ?? new InventoryItemValidator();
    }

    /**
     * Export inventory items matching optional filters to CSV.
     *
     * @param array<string, mixed> $filters
     */
    public function export(array $filters = []): string
    {
        $headers = $this->headers();
        $stream = fopen('php://temp', 'r+');
        if ($stream === false) {
            throw new InvalidArgumentException('Unable to create CSV stream.');
        }

        fputcsv($stream, $headers);

        $limit = 250;
        $offset = 0;
        do {
            $batch = $this->repository->list($filters, $limit, $offset);
            foreach ($batch as $item) {
                fputcsv($stream, [
                    $item->name,
                    $item->sku,
                    $item->category,
                    $item->stock_quantity,
                    $item->low_stock_threshold,
                    $item->reorder_quantity,
                    $item->cost,
                    $item->sale_price,
                    $item->markup,
                    $item->location,
                    $item->vendor,
                    $item->notes,
                ]);
            }

            $offset += $limit;
        } while (count($batch) === $limit);

        rewind($stream);
        $csv = stream_get_contents($stream) ?: '';
        fclose($stream);

        return $csv;
    }

    /**
     * Import CSV content and return a summary of operations.
     *
     * @return array<string, mixed>
     */
    public function import(string $csv, bool $updateExisting = false): array
    {
        [$headers, $rows] = $this->parseCsv($csv);
        $summary = [
            'created' => 0,
            'updated' => 0,
            'duplicates' => 0,
            'failed' => 0,
            'errors' => [],
        ];

        foreach ($rows as $index => $row) {
            try {
                $payload = $this->validator->validate($this->mapRow($row, $headers));
                $existing = $this->repository->findDuplicate($payload);

                if ($existing !== null) {
                    $summary['duplicates']++;
                    if ($updateExisting) {
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
                    'row' => $index + 2, // header row offset
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
     * @param array<int, string> $row
     * @param array<int, string> $headers
     * @return array<string, mixed>
     */
    private function mapRow(array $row, array $headers): array
    {
        $allowed = $this->headers();
        $mapped = [];
        foreach ($headers as $index => $header) {
            $field = strtolower(trim($header));
            if (!in_array($field, $allowed, true)) {
                continue;
            }

            $mapped[$field] = $row[$index] ?? null;
        }

        return $mapped;
    }

    /**
     * @return array<int, string>
     */
    private function headers(): array
    {
        return [
            'name',
            'sku',
            'category',
            'stock_quantity',
            'low_stock_threshold',
            'reorder_quantity',
            'cost',
            'sale_price',
            'markup',
            'location',
            'vendor',
            'notes',
        ];
    }
}
