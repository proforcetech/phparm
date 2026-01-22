<?php

namespace App\Services\ImportExport;

use App\Database\Connection;
use App\Services\Inventory\InventoryLookupService;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;

class LocationCsvService extends CsvImportService
{
    private InventoryLookupService $service;

    public function __construct(Connection $connection, ?AuditLogger $audit = null, ?InventoryLookupService $service = null)
    {
        parent::__construct($connection, $audit);
        $this->service = $service ?? new InventoryLookupService($connection);
    }

    /**
     * @return array{created:int,updated:int,failed:int,errors:array<int,string>,dry_run:bool}
     */
    public function import(string $dataset, string $csv, int $actorId, bool $dryRun = false): array
    {
        $rows = $this->parseCsv($csv);
        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => [], 'dry_run' => $dryRun];
        $seenNames = [];

        foreach ($rows as $index => $row) {
            try {
                $name = trim((string) ($row['name'] ?? ''));
                if ($name === '') {
                    throw new InvalidArgumentException('Name is required.');
                }

                $nameKey = strtolower($name);
                if (isset($seenNames[$nameKey])) {
                    throw new InvalidArgumentException('Duplicate location name in CSV file.');
                }
                $seenNames[$nameKey] = true;

                $payload = [
                    'name' => $name,
                    'description' => isset($row['description']) && $row['description'] !== '' ? (string) $row['description'] : null,
                ];

                $existingId = $this->findExistingLookupId('locations', $nameKey);

                if ($existingId !== null) {
                    if (!$dryRun) {
                        $this->service->update($existingId, 'locations', $payload);
                    }
                    $stats['updated']++;
                    $this->log('import.locations', $existingId, $actorId, ['row' => $row, 'result' => 'updated', 'dry_run' => $dryRun]);
                    continue;
                }

                if (!$dryRun) {
                    $created = $this->service->create('locations', $payload);
                    $this->log('import.locations', $created->id, $actorId, ['row' => $row, 'result' => 'created', 'dry_run' => $dryRun]);
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

    private function findExistingLookupId(string $type, string $nameKey): ?int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id FROM inventory_lookups WHERE type = :type AND LOWER(name) = :name LIMIT 1');
        $stmt->execute(['type' => $type, 'name' => $nameKey]);
        $id = $stmt->fetchColumn();

        return $id ? (int) $id : null;
    }
}
