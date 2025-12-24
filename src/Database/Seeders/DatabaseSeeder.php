<?php

namespace App\Database\Seeders;

use App\Database\Connection;
use App\Support\SettingsRepository;
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
        $this->seedVehicleMaster();
        $this->seedServiceTypes();
        $this->seedSettings();
        $this->seedDemoCustomers();
        $this->seedCMS();
    }

    private function seedCMS(): void
    {
        $cmsSeeder = new CMSSeeder($this->connection);
        $cmsSeeder->seed();
    }

    private function seedVehicleMaster(): void
    {
        $pdo = $this->connection->pdo();
        $vehicles = [
            ['year' => 2020, 'make' => 'Toyota', 'model' => 'Camry', 'engine' => '2.5L I4', 'transmission' => 'Automatic', 'drive' => 'FWD', 'trim' => 'SE'],
            ['year' => 2020, 'make' => 'Toyota', 'model' => 'Camry', 'engine' => '3.5L V6', 'transmission' => 'Automatic', 'drive' => 'FWD', 'trim' => 'XSE'],
            ['year' => 2021, 'make' => 'Ford', 'model' => 'F-150', 'engine' => '3.5L EcoBoost V6', 'transmission' => 'Automatic', 'drive' => '4WD', 'trim' => 'Lariat'],
            ['year' => 2021, 'make' => 'Ford', 'model' => 'F-150', 'engine' => '5.0L V8', 'transmission' => 'Automatic', 'drive' => '4WD', 'trim' => 'Platinum'],
            ['year' => 2019, 'make' => 'Honda', 'model' => 'Civic', 'engine' => '2.0L I4', 'transmission' => 'CVT', 'drive' => 'FWD', 'trim' => 'EX'],
            ['year' => 2019, 'make' => 'Honda', 'model' => 'Civic', 'engine' => '1.5L Turbo I4', 'transmission' => 'CVT', 'drive' => 'FWD', 'trim' => 'Touring'],
        ];

        $stmt = $pdo->prepare('INSERT INTO vehicle_master (year, make, model, engine, transmission, drive, trim, created_at, updated_at) '
            . 'VALUES (:year, :make, :model, :engine, :transmission, :drive, :trim, :created_at, :updated_at) '
            . 'ON DUPLICATE KEY UPDATE updated_at = VALUES(updated_at)');

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        foreach ($vehicles as $vehicle) {
            $stmt->execute(array_merge($vehicle, ['created_at' => $now, 'updated_at' => $now]));
        }
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
        $config = require __DIR__ . '/../../../config/settings.php';
        $defaults = $config['defaults'] ?? [];
        $repository = new SettingsRepository($this->connection);
        $repository->seedDefaults($defaults);
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
