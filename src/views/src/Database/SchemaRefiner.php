<?php

namespace App\Database;

use PDO;

class SchemaRefiner
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function ensureIndexes(): void
    {
        $pdo = $this->connection->pdo();
        $indexes = [
            'customers' => [
                ['name' => 'idx_customers_email', 'sql' => 'ALTER TABLE customers ADD INDEX idx_customers_email (email)'],
                ['name' => 'idx_customers_phone', 'sql' => 'ALTER TABLE customers ADD INDEX idx_customers_phone (phone)'],
            ],
            'estimates' => [
                ['name' => 'idx_estimates_status', 'sql' => 'ALTER TABLE estimates ADD INDEX idx_estimates_status (status)'],
                ['name' => 'idx_estimates_service_type', 'sql' => 'ALTER TABLE estimates ADD INDEX idx_estimates_service_type (service_type_id)'],
            ],
            'appointments' => [
                ['name' => 'idx_appointments_status', 'sql' => 'ALTER TABLE appointments ADD INDEX idx_appointments_status (status)'],
            ],
        ];

        foreach ($indexes as $table => $tableIndexes) {
            foreach ($tableIndexes as $index) {
                if (!$this->indexExists($pdo, $table, $index['name'])) {
                    $pdo->exec($index['sql']);
                }
            }
        }
    }

    public function backfillDefaults(): void
    {
        $pdo = $this->connection->pdo();
        $pdo->exec('UPDATE customers SET commercial = 0 WHERE commercial IS NULL');
        $pdo->exec('UPDATE customers SET tax_exempt = 0 WHERE tax_exempt IS NULL');
        $pdo->exec('UPDATE estimates SET status = "draft" WHERE status IS NULL');
        $pdo->exec('UPDATE service_types SET active = 1 WHERE active IS NULL');
    }

    private function indexExists(PDO $pdo, string $table, string $indexName): bool
    {
        $stmt = $pdo->prepare('SHOW INDEX FROM ' . $table . ' WHERE Key_name = :name');
        $stmt->execute(['name' => $indexName]);

        return (bool) $stmt->fetchColumn();
    }
}
