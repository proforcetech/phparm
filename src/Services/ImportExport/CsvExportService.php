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
     * Export customers, vehicles, inventory, or users rows to CSV.
     *
     * @param string $dataset customers|vehicle_master|inventory|users
     * @param array<string, mixed> $filters
     */
    public function export(string $dataset, array $filters = []): string
    {
        $dataset = strtolower($dataset);
        if ($dataset === 'users') {
            return $this->exportUsers($filters);
        }

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

        return $this->buildCsv($rows);
    }

    /**
     * @param array<string, mixed> $filters
     */
    private function exportUsers(array $filters): string
    {
        $query = 'SELECT id, name, email, role,'
            . " CASE WHEN email_verified = 1 THEN 'Email Verified' ELSE 'Email Not Verified' END AS status,"
            . " CASE WHEN two_factor_enabled = 1 THEN 'Enabled' ELSE 'Disabled' END AS two_factor_status,"
            . ' created_at'
            . ' FROM users'
            . ' WHERE active = 1';
        $bindings = [];

        if (!empty($filters['role'])) {
            $query .= ' AND role = :role';
            $bindings['role'] = $filters['role'];
        }

        if (!empty($filters['query'])) {
            $query .= ' AND (id = :exact_id OR name LIKE :query OR email LIKE :query)';
            $bindings['exact_id'] = is_numeric($filters['query']) ? (int) $filters['query'] : 0;
            $bindings['query'] = '%' . $filters['query'] . '%';
        }

        $query .= ' ORDER BY created_at DESC';

        $stmt = $this->connection->pdo()->prepare($query);
        $stmt->execute($bindings);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return $this->buildCsv($rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function buildCsv(array $rows): string
    {
        if (count($rows) === 0) {
            return '';
        }

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, array_keys($rows[0]));
        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn ($value) => $this->escapeCsvValue($value), $row));
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }

    private function escapeCsvValue($value): string
    {
        if ($value === null) {
            return '';
        }

        if ($value instanceof \DateTimeInterface) {
            $value = $value->format(DATE_ATOM);
        }

        $stringValue = (string) $value;
        if (preg_match('/^[=+\-@\t]/', $stringValue)) {
            return "'" . $stringValue;
        }

        return $stringValue;
    }
}
