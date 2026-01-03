<?php

namespace App\Services\Role;

use App\Database\Connection;
use App\Models\Role;
use PDO;

class RoleRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * List all roles
     *
     * @param array<string, mixed> $filters
     * @return array<int, Role>
     */
    public function list(array $filters = []): array
    {
        $query = 'SELECT id, name, label, description, permissions, is_system, created_at, updated_at FROM custom_roles WHERE 1=1';
        $bindings = [];

        if (isset($filters['include_system']) && !$filters['include_system']) {
            $query .= ' AND is_system = 0';
        }

        if (!empty($filters['query'])) {
            $query .= ' AND (name LIKE :query OR label LIKE :query OR description LIKE :query)';
            $bindings['query'] = '%' . $filters['query'] . '%';
        }

        $query .= ' ORDER BY is_system DESC, name ASC';

        $stmt = $this->connection->pdo()->prepare($query);
        $stmt->execute($bindings);

        $results = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            // Decode JSON permissions
            if (isset($row['permissions']) && is_string($row['permissions'])) {
                $row['permissions'] = json_decode($row['permissions'], true) ?? [];
            }
            $row['is_system'] = (bool) $row['is_system'];
            $results[] = new Role($row);
        }

        return $results;
    }

    /**
     * Find a role by ID
     */
    public function find(int $id): ?Role
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, label, description, permissions, is_system, created_at, updated_at
             FROM custom_roles
             WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        // Decode JSON permissions
        if (isset($row['permissions']) && is_string($row['permissions'])) {
            $row['permissions'] = json_decode($row['permissions'], true) ?? [];
        }
        $row['is_system'] = (bool) $row['is_system'];

        return new Role($row);
    }

    /**
     * Find a role by name
     */
    public function findByName(string $name): ?Role
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, name, label, description, permissions, is_system, created_at, updated_at
             FROM custom_roles
             WHERE name = :name'
        );
        $stmt->execute(['name' => $name]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }

        // Decode JSON permissions
        if (isset($row['permissions']) && is_string($row['permissions'])) {
            $row['permissions'] = json_decode($row['permissions'], true) ?? [];
        }
        $row['is_system'] = (bool) $row['is_system'];

        return new Role($row);
    }

    /**
     * Create a new role
     *
     * @param array<string, mixed> $data
     */
    public function create(array $data): Role
    {
        $permissions = json_encode($data['permissions'] ?? []);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO custom_roles (name, label, description, permissions, is_system, created_at, updated_at)
             VALUES (:name, :label, :description, :permissions, :is_system, NOW(), NOW())'
        );

        $stmt->execute([
            'name' => $data['name'],
            'label' => $data['label'],
            'description' => $data['description'] ?? null,
            'permissions' => $permissions,
            'is_system' => $data['is_system'] ?? false,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id);
    }

    /**
     * Update a role
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Role
    {
        $fields = [];
        $bindings = ['id' => $id];

        if (isset($data['name'])) {
            $fields[] = 'name = :name';
            $bindings['name'] = $data['name'];
        }

        if (isset($data['label'])) {
            $fields[] = 'label = :label';
            $bindings['label'] = $data['label'];
        }

        if (isset($data['description'])) {
            $fields[] = 'description = :description';
            $bindings['description'] = $data['description'];
        }

        if (isset($data['permissions'])) {
            $fields[] = 'permissions = :permissions';
            $bindings['permissions'] = json_encode($data['permissions']);
        }

        if (empty($fields)) {
            return $this->find($id);
        }

        $fields[] = 'updated_at = NOW()';

        $query = 'UPDATE custom_roles SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($query);
        $stmt->execute($bindings);

        return $this->find($id);
    }

    /**
     * Delete a role
     */
    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM custom_roles WHERE id = :id AND is_system = 0');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get all available permissions
     *
     * @return array<string, array<string, mixed>>
     */
    public function getAvailablePermissions(): array
    {
        return [
            'users' => [
                'label' => 'User Management',
                'permissions' => [
                    'users.view' => 'View users',
                    'users.create' => 'Create users',
                    'users.update' => 'Update users',
                    'users.delete' => 'Delete users',
                    'users.invite' => 'Invite users',
                    'users.*' => 'All user permissions',
                ]
            ],
            'customers' => [
                'label' => 'Customer Management',
                'permissions' => [
                    'customers.view' => 'View customers',
                    'customers.create' => 'Create customers',
                    'customers.update' => 'Update customers',
                    'customers.delete' => 'Delete customers',
                    'customers.*' => 'All customer permissions',
                ]
            ],
            'vehicles' => [
                'label' => 'Vehicle Management',
                'permissions' => [
                    'vehicles.view' => 'View vehicles',
                    'vehicles.create' => 'Create vehicles',
                    'vehicles.update' => 'Update vehicles',
                    'vehicles.delete' => 'Delete vehicles',
                    'vehicles.*' => 'All vehicle permissions',
                ]
            ],
            'estimates' => [
                'label' => 'Estimate Management',
                'permissions' => [
                    'estimates.view' => 'View estimates',
                    'estimates.create' => 'Create estimates',
                    'estimates.update' => 'Update estimates',
                    'estimates.delete' => 'Delete estimates',
                    'estimates.*' => 'All estimate permissions',
                ]
            ],
            'invoices' => [
                'label' => 'Invoice Management',
                'permissions' => [
                    'invoices.view' => 'View invoices',
                    'invoices.create' => 'Create invoices',
                    'invoices.update' => 'Update invoices',
                    'invoices.delete' => 'Delete invoices',
                    'invoices.*' => 'All invoice permissions',
                ]
            ],
            'payments' => [
                'label' => 'Payment Management',
                'permissions' => [
                    'payments.view' => 'View payments',
                    'payments.create' => 'Process payments',
                    'payments.update' => 'Update payments',
                    'payments.delete' => 'Delete payments',
                    'payments.*' => 'All payment permissions',
                ]
            ],
            'appointments' => [
                'label' => 'Appointment Management',
                'permissions' => [
                    'appointments.view' => 'View appointments',
                    'appointments.create' => 'Create appointments',
                    'appointments.update' => 'Update appointments',
                    'appointments.delete' => 'Delete appointments',
                    'appointments.*' => 'All appointment permissions',
                ]
            ],
            'inventory' => [
                'label' => 'Inventory Management',
                'permissions' => [
                    'inventory.view' => 'View inventory',
                    'inventory.create' => 'Create inventory items',
                    'inventory.update' => 'Update inventory',
                    'inventory.delete' => 'Delete inventory items',
                    'inventory.*' => 'All inventory permissions',
                ]
            ],
            'inspections' => [
                'label' => 'Inspection Management',
                'permissions' => [
                    'inspections.view' => 'View inspections',
                    'inspections.create' => 'Create inspections',
                    'inspections.update' => 'Update inspections',
                    'inspections.delete' => 'Delete inspections',
                    'inspections.*' => 'All inspection permissions',
                ]
            ],
            'time' => [
                'label' => 'Time Tracking',
                'permissions' => [
                    'time.view' => 'View time entries',
                    'time.create' => 'Create time entries',
                    'time.update' => 'Update time entries',
                    'time.delete' => 'Delete time entries',
                    'time.*' => 'All time tracking permissions',
                ]
            ],
            'reports' => [
                'label' => 'Reporting',
                'permissions' => [
                    'reports.view' => 'View reports',
                    'reports.*' => 'All reporting permissions',
                ]
            ],
            'settings' => [
                'label' => 'Settings',
                'permissions' => [
                    'settings.view' => 'View settings',
                    'settings.update' => 'Update settings',
                    'settings.*' => 'All settings permissions',
                ]
            ],
            'cms' => [
                'label' => 'Content Management System',
                'permissions' => [
                    'cms.dashboard.view' => 'View CMS dashboard',
                    'cms.pages.view' => 'View CMS pages',
                    'cms.pages.create' => 'Create CMS pages',
                    'cms.pages.update' => 'Update CMS pages',
                    'cms.pages.delete' => 'Delete CMS pages',
                    'cms.components.view' => 'View CMS components',
                    'cms.components.create' => 'Create CMS components',
                    'cms.components.update' => 'Update CMS components',
                    'cms.components.delete' => 'Delete CMS components',
                    'cms.templates.view' => 'View CMS templates',
                    'cms.templates.create' => 'Create CMS templates',
                    'cms.templates.update' => 'Update CMS templates',
                    'cms.templates.delete' => 'Delete CMS templates',
                    'cms.menus.view' => 'View CMS menus',
                    'cms.menus.create' => 'Create CMS menus',
                    'cms.menus.update' => 'Update CMS menus',
                    'cms.menus.delete' => 'Delete CMS menus',
                    'cms.media.view' => 'View CMS media',
                    'cms.media.create' => 'Upload CMS media',
                    'cms.media.update' => 'Update CMS media',
                    'cms.media.delete' => 'Delete CMS media',
                    'cms.*' => 'All CMS permissions',
                ]
            ],
            'portal' => [
                'label' => 'Customer Portal',
                'permissions' => [
                    'portal.profile' => 'Access profile',
                    'portal.vehicles' => 'Access vehicles',
                    'portal.estimates' => 'Access estimates',
                    'portal.invoices' => 'Access invoices',
                    'portal.warranty' => 'Access warranty claims',
                    'portal.reminders' => 'Access reminders',
                ]
            ],
            'system' => [
                'label' => 'System',
                'permissions' => [
                    '*' => 'All permissions (Super Admin)',
                ]
            ],
        ];
    }
}
