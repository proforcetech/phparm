<?php

namespace App\Services\ServiceType;

use App\Database\Connection;
use App\Models\ServiceType;
use InvalidArgumentException;
use PDO;

class ServiceTypeSeeder
{
    private Connection $connection;
    private ServiceTypeRepository $repository;

    public function __construct(Connection $connection, ?ServiceTypeRepository $repository = null)
    {
        $this->connection = $connection;
        $this->repository = $repository ?? new ServiceTypeRepository($connection);
    }

    /**
     * Seed a default catalog of service types and normalize display order/activation.
     *
     * @return array<int, array<string, mixed>>
     */
    public function seedDefaults(): array
    {
        $defaults = [
            ['name' => 'General Service', 'alias' => 'general', 'color' => '#2563eb', 'icon' => 'wrench', 'description' => 'General maintenance, diagnostics, or uncategorized work.'],
            ['name' => 'Oil Change', 'alias' => 'oil-change', 'color' => '#f59e0b', 'icon' => 'oil-can', 'description' => 'Engine oil and filter replacement.'],
            ['name' => 'Brake Service', 'alias' => 'brake-service', 'color' => '#ef4444', 'icon' => 'brake-warning', 'description' => 'Pads, rotors, calipers, and brake fluid service.'],
            ['name' => 'Tires & Alignment', 'alias' => 'tires-alignment', 'color' => '#10b981', 'icon' => 'car', 'description' => 'Tire replacement/rotation and alignment checks.'],
            ['name' => 'Battery & Electrical', 'alias' => 'battery-electrical', 'color' => '#0ea5e9', 'icon' => 'bolt', 'description' => 'Batteries, starters, alternators, and electrical diagnostics.'],
            ['name' => 'Heating & Cooling', 'alias' => 'hvac', 'color' => '#8b5cf6', 'icon' => 'snowflake', 'description' => 'HVAC repairs including AC recharge and heater core work.'],
            ['name' => 'Engine Repair', 'alias' => 'engine', 'color' => '#fb7185', 'icon' => 'engine', 'description' => 'Engine repairs, timing components, gaskets, and internals.'],
            ['name' => 'Transmission & Drivetrain', 'alias' => 'transmission', 'color' => '#06b6d4', 'icon' => 'transmission', 'description' => 'Transmission, transfer case, and drivetrain servicing.'],
            ['name' => 'State Inspection', 'alias' => 'state-inspection', 'color' => '#22c55e', 'icon' => 'clipboard-check', 'description' => 'Regulatory or safety inspections.'],
        ];

        $seeded = [];
        foreach ($defaults as $index => $definition) {
            $existing = $this->findByAliasOrName($definition['alias'], $definition['name']);
            $payload = array_merge($definition, [
                'active' => true,
                'display_order' => $index + 1,
            ]);

            $seeded[] = $existing
                ? $this->repository->update($existing->id, $payload)
                : $this->repository->create($payload);
        }

        $this->normalizeDisplayOrder($seeded);

        return array_map(static fn (ServiceType $serviceType) => $serviceType->toArray(), $seeded);
    }

    public function backfillReferences(?int $defaultServiceTypeId = null): void
    {
        $defaultId = $defaultServiceTypeId ?? $this->resolveDefaultServiceTypeId();
        if ($defaultId === null) {
            throw new InvalidArgumentException('Cannot backfill service type references without a default service type.');
        }

        foreach (['estimate_jobs', 'invoices'] as $table) {
            $stmt = $this->connection->pdo()->prepare(
                sprintf('UPDATE %s SET service_type_id = :service_type WHERE service_type_id IS NULL', $table)
            );
            $stmt->execute(['service_type' => $defaultId]);
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function seedAndBackfill(): array
    {
        $seeded = $this->seedDefaults();
        $default = $this->findByAliasOrName('general', 'General Service');
        $this->backfillReferences($default?->id);

        return $seeded;
    }

    private function resolveDefaultServiceTypeId(): ?int
    {
        $serviceType = $this->findByAliasOrName('general', 'General Service');

        return $serviceType?->id;
    }

    private function normalizeDisplayOrder(array $serviceTypes): void
    {
        $ids = array_map(static fn (ServiceType $serviceType) => $serviceType->id, $serviceTypes);
        $this->repository->updateDisplayOrder($ids);
    }

    private function findByAliasOrName(?string $alias, string $name): ?ServiceType
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM service_types WHERE name = :name OR alias <=> :alias LIMIT 1'
        );
        $stmt->execute(['name' => $name, 'alias' => $alias]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['active'] = (bool) $row['active'];
        $row['display_order'] = (int) $row['display_order'];

        return new ServiceType($row);
    }
}
