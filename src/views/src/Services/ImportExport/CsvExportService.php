<?php

namespace App\Services\ImportExport;

use App\Database\Connection;
use PDO;

class CsvExportService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Export customers, vehicles, or inventory rows to CSV.
     *
     * @param string $dataset customers|vehicle_master|inventory
     */
    public function export(string $dataset): string
    {
        $dataset = strtolower($dataset);
        $queries = [
            'customers' => 'SELECT id, name, email, phone, is_commercial, tax_exempt, created_at FROM customers ORDER BY id',
            'vehicle_master' => 'SELECT id, year, make, model, trim, engine, transmission, drive, created_at FROM vehicle_master ORDER BY id',
            'inventory' => 'SELECT id, sku, name, quantity, reorder_threshold, price, location, updated_at FROM inventory_items ORDER BY id',
        ];

        if (!isset($queries[$dataset])) {
            throw new \InvalidArgumentException('Unsupported dataset for export');
        }

        $stmt = $this->connection->pdo()->query($queries[$dataset]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) === 0) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, array_map(static function ($value) {
                if ($value instanceof \DateTimeInterface) {
                    return $value->format(DATE_ATOM);
                }

                return $value;
            }, $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }
}
