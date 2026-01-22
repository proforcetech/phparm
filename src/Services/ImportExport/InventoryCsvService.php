<?php

namespace App\Services\ImportExport;

use App\Database\Connection;
use App\Services\Inventory\InventoryItemRepository;
use App\Services\Inventory\InventoryItemValidator;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;

class InventoryCsvService extends CsvImportService
{
    private InventoryItemRepository $repository;
    private InventoryItemValidator $validator;

    public function __construct(Connection $connection, ?AuditLogger $audit = null, ?InventoryItemRepository $repository = null, ?InventoryItemValidator $validator = null)
    {
        parent::__construct($connection, $audit);
        $this->validator = $validator ?? new InventoryItemValidator();
        $this->repository = $repository ?? new InventoryItemRepository($connection, $this->validator);
    }

    /**
     * @return array{created:int,updated:int,failed:int,errors:array<int,string>,dry_run:bool}
     */
    public function import(string $dataset, string $csv, int $actorId, bool $dryRun = false): array
    {
        $rows = $this->parseCsv($csv);
        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [], 'dry_run' => $dryRun];
        $seenSkus = [];

        foreach ($rows as $index => $row) {
            try {
                $sku = trim((string) ($row['sku'] ?? ''));
                if ($sku === '') {
                    throw new InvalidArgumentException('SKU is required.');
                }

                $branchKey = isset($row['branch_id']) ? (string) $row['branch_id'] : '';
                $skuKey = strtolower($sku) . '|' . $branchKey;
                if (isset($seenSkus[$skuKey])) {
                    throw new InvalidArgumentException('Duplicate SKU in CSV file.');
                }
                $seenSkus[$skuKey] = true;

                $payload = $this->validator->validate($row);
                $existing = $this->repository->findBySku($payload['sku'], $payload['branch_id'] ?? null);

                if ($existing) {
                    if (!$dryRun) {
                        $this->repository->update($existing->id, $payload);
                    }
                    $stats['updated']++;
                    $this->log('import.inventory', $existing->id, $actorId, ['row' => $row, 'result' => 'updated', 'dry_run' => $dryRun]);
                    continue;
                }

                if (!$dryRun) {
                    $created = $this->repository->create($payload);
                    $this->log('import.inventory', $created->id, $actorId, ['row' => $row, 'result' => 'created', 'dry_run' => $dryRun]);
                }

                $stats['created']++;
            } catch (\Throwable $e) {
                $stats['failed']++;
                $rowNumber = $index + 2;
                $stats['errors'][] = "Row {$rowNumber}: {$e->getMessage()}";
            }
        }

        return $stats;
    }
}
