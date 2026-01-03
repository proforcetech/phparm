<?php

namespace App\Services\ImportExport;

use App\Database\Connection;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class CsvImportService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param string $dataset customers|vehicle_master|inventory
     * @param string $csv
     * @return array{created:int,updated:int,failed:int,errors:array<int,string>}
     */
    public function import(string $dataset, string $csv, int $actorId): array
    {
        $rows = $this->parseCsv($csv);
        $stats = ['created' => 0, 'updated' => 0, 'failed' => 0, 'errors' => []];
        foreach ($rows as $index => $row) {
            try {
                switch ($dataset) {
                    case 'customers':
                        $result = $this->upsertCustomer($row);
                        break;
                    case 'vehicle_master':
                        $result = $this->upsertVehicleMaster($row);
                        break;
                    case 'inventory':
                        $result = $this->upsertInventory($row);
                        break;
                    default:
                        throw new InvalidArgumentException('Unsupported dataset');
                }

                $stats[$result ? 'updated' : 'created']++;
                $this->log("import.{$dataset}", $row['id'] ?? null, $actorId, ['row' => $row, 'result' => $result ? 'updated' : 'created']);
            } catch (\Throwable $e) {
                $stats['failed']++;
                $stats['errors'][] = "Row {$index}: {$e->getMessage()}";
            }
        }

        return $stats;
    }

    /**
     * @return array<int,array<string,string>>
     */
    private function parseCsv(string $csv): array
    {
        $handle = fopen('php://temp', 'r+');
        fwrite($handle, $csv);
        rewind($handle);
        $headers = null;
        $rows = [];
        while (($row = fgetcsv($handle)) !== false) {
            if ($headers === null) {
                $headers = $row;
                continue;
            }

            $rows[] = array_combine($headers, $row);
        }

        fclose($handle);

        return $rows;
    }

    /**
     * @param array<string,string> $row
     */
    private function upsertCustomer(array $row): bool
    {
        $required = ['name', 'email'];
        foreach ($required as $column) {
            if (!isset($row[$column]) || $row[$column] === '') {
                throw new InvalidArgumentException("Missing {$column}");
            }
        }

        $existing = $this->connection->pdo()->prepare('SELECT id FROM customers WHERE email = :email');
        $existing->execute(['email' => $row['email']]);
        $customerId = $existing->fetchColumn();
        if ($customerId) {
            $stmt = $this->connection->pdo()->prepare('UPDATE customers SET name = :name, phone = :phone, is_commercial = :is_commercial, tax_exempt = :tax_exempt WHERE id = :id');
            $stmt->execute([
                'name' => $row['name'],
                'phone' => $row['phone'] ?? null,
                'is_commercial' => isset($row['is_commercial']) ? (int) $row['is_commercial'] : 0,
                'tax_exempt' => isset($row['tax_exempt']) ? (int) $row['tax_exempt'] : 0,
                'id' => $customerId,
            ]);

            return true;
        }

        $stmt = $this->connection->pdo()->prepare('INSERT INTO customers (name, email, phone, is_commercial, tax_exempt) VALUES (:name, :email, :phone, :is_commercial, :tax_exempt)');
        $stmt->execute([
            'name' => $row['name'],
            'email' => $row['email'],
            'phone' => $row['phone'] ?? null,
            'is_commercial' => isset($row['is_commercial']) ? (int) $row['is_commercial'] : 0,
            'tax_exempt' => isset($row['tax_exempt']) ? (int) $row['tax_exempt'] : 0,
        ]);

        return false;
    }

    /**
     * @param array<string,string> $row
     */
    private function upsertVehicleMaster(array $row): bool
    {
        $required = ['year', 'make', 'model'];
        foreach ($required as $column) {
            if (!isset($row[$column]) || $row[$column] === '') {
                throw new InvalidArgumentException("Missing {$column}");
            }
        }

        $stmt = $this->connection->pdo()->prepare('SELECT id FROM vehicle_master WHERE year = :year AND make = :make AND model = :model AND COALESCE(trim, "") = COALESCE(:trim, "") AND COALESCE(engine, "") = COALESCE(:engine, "")');
        $stmt->execute([
            'year' => (int) $row['year'],
            'make' => $row['make'],
            'model' => $row['model'],
            'trim' => $row['trim'] ?? null,
            'engine' => $row['engine'] ?? null,
        ]);
        $vehicleId = $stmt->fetchColumn();
        if ($vehicleId) {
            $update = $this->connection->pdo()->prepare('UPDATE vehicle_master SET transmission = :transmission, drive = :drive WHERE id = :id');
            $update->execute([
                'transmission' => $row['transmission'] ?? null,
                'drive' => $row['drive'] ?? null,
                'id' => $vehicleId,
            ]);

            return true;
        }

        $insert = $this->connection->pdo()->prepare('INSERT INTO vehicle_master (year, make, model, trim, engine, transmission, drive) VALUES (:year, :make, :model, :trim, :engine, :transmission, :drive)');
        $insert->execute([
            'year' => (int) $row['year'],
            'make' => $row['make'],
            'model' => $row['model'],
            'trim' => $row['trim'] ?? null,
            'engine' => $row['engine'] ?? null,
            'transmission' => $row['transmission'] ?? null,
            'drive' => $row['drive'] ?? null,
        ]);

        return false;
    }

    /**
     * @param array<string,string> $row
     */
    private function upsertInventory(array $row): bool
    {
        $required = ['sku', 'name', 'quantity'];
        foreach ($required as $column) {
            if (!isset($row[$column]) || $row[$column] === '') {
                throw new InvalidArgumentException("Missing {$column}");
            }
        }

        $stmt = $this->connection->pdo()->prepare('SELECT id FROM inventory_items WHERE sku = :sku');
        $stmt->execute(['sku' => $row['sku']]);
        $inventoryId = $stmt->fetchColumn();
        if ($inventoryId) {
            $update = $this->connection->pdo()->prepare('UPDATE inventory_items SET name = :name, quantity = :quantity, reorder_threshold = :reorder_threshold, price = :price, location = :location WHERE id = :id');
            $update->execute([
                'name' => $row['name'],
                'quantity' => (int) $row['quantity'],
                'reorder_threshold' => isset($row['reorder_threshold']) ? (int) $row['reorder_threshold'] : null,
                'price' => isset($row['price']) ? (float) $row['price'] : null,
                'location' => $row['location'] ?? null,
                'id' => $inventoryId,
            ]);

            return true;
        }

        $insert = $this->connection->pdo()->prepare('INSERT INTO inventory_items (sku, name, quantity, reorder_threshold, price, location) VALUES (:sku, :name, :quantity, :reorder_threshold, :price, :location)');
        $insert->execute([
            'sku' => $row['sku'],
            'name' => $row['name'],
            'quantity' => (int) $row['quantity'],
            'reorder_threshold' => isset($row['reorder_threshold']) ? (int) $row['reorder_threshold'] : null,
            'price' => isset($row['price']) ? (float) $row['price'] : null,
            'location' => $row['location'] ?? null,
        ]);

        return false;
    }

    private function log(string $event, $entityId, int $actorId, array $context): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'import', $entityId, $actorId, $context));
    }
}
