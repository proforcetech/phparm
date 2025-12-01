<?php

namespace App\Database\Seeders;

use App\Database\Connection;
use DateTimeImmutable;
use PDO;

class DatabaseSeeder
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    public function seed(): void
    {
        $this->seedServiceTypes();
        $this->seedSettings();
        $this->seedDemoCustomers();
    }

    private function seedServiceTypes(): void
    {
        $pdo = $this->connection->pdo();
        $types = [
            ['name' => 'Oil Change', 'alias' => 'oil_change', 'display_order' => 1],
            ['name' => 'Brake Service', 'alias' => 'brake_service', 'display_order' => 2],
            ['name' => 'Tire Rotation', 'alias' => 'tire_rotation', 'display_order' => 3],
            ['name' => 'Diagnostics', 'alias' => 'diagnostics', 'display_order' => 4],
        ];

        $stmt = $pdo->prepare('INSERT INTO service_types (name, alias, active, display_order, created_at, updated_at) '
            . 'VALUES (:name, :alias, 1, :display_order, :now, :now) '
            . 'ON DUPLICATE KEY UPDATE name = VALUES(name), active = 1, display_order = VALUES(display_order)');

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($types as $type) {
            $stmt->execute([
                'name' => $type['name'],
                'alias' => $type['alias'],
                'display_order' => $type['display_order'],
                'now' => $now,
            ]);
        }
    }

    private function seedSettings(): void
    {
        $pdo = $this->connection->pdo();
        $settings = [
            'shop.name' => 'Demo Auto Shop',
            'shop.currency' => 'USD',
            'pricing.tax_rate' => '0.07',
            'notifications.from_email' => 'noreply@example.com',
        ];

        $stmt = $pdo->prepare('INSERT INTO settings (`key`, `value`, created_at, updated_at) '
            . 'VALUES (:key, :value, :now, :now) '
            . 'ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)');

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($settings as $key => $value) {
            $stmt->execute([
                'key' => $key,
                'value' => $value,
                'now' => $now,
            ]);
        }
    }

    private function seedDemoCustomers(): void
    {
        $pdo = $this->connection->pdo();
        $customers = [
            ['name' => 'Jane Driver', 'email' => 'jane.driver@example.com', 'phone' => '+155555501'],
            ['name' => 'Contoso Logistics', 'email' => 'fleet@contoso.test', 'phone' => '+155555502', 'commercial' => 1],
            ['name' => 'Northwind Farms', 'email' => 'service@northwind.test', 'phone' => '+155555503', 'tax_exempt' => 1],
        ];

        $stmt = $pdo->prepare('INSERT INTO customers (name, email, phone, commercial, tax_exempt, created_at, updated_at) '
            . 'VALUES (:name, :email, :phone, :commercial, :tax_exempt, :now, :now) '
            . 'ON DUPLICATE KEY UPDATE phone = VALUES(phone), commercial = VALUES(commercial), tax_exempt = VALUES(tax_exempt)');

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($customers as $customer) {
            $stmt->execute([
                'name' => $customer['name'],
                'email' => $customer['email'],
                'phone' => $customer['phone'],
                'commercial' => (int) ($customer['commercial'] ?? 0),
                'tax_exempt' => (int) ($customer['tax_exempt'] ?? 0),
                'now' => $now,
            ]);
        }
    }
}
