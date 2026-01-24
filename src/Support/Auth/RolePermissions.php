<?php

namespace App\Support\Auth;

use App\Database\Connection;
use InvalidArgumentException;

class RolePermissions
{
    public const ROLE_ADMIN = 'admin';
    public const ROLE_MANAGER = 'manager';
    public const ROLE_ADVISOR = 'advisor';
    public const ROLE_TECHNICIAN = 'technician';
    public const ROLE_STAFF = 'staff';
    public const ROLE_DRIVER = 'driver';

    /**
     * Map of roles to their default permissions.
     */
    protected static array $rolePermissions = [
        self::ROLE_ADMIN => [
            // Dashboard & Core
            'view_dashboard',
            'view_calendar',
            
            // CRM
            'view_customers',
            'manage_customers',
            'view_vehicles',
            'manage_vehicles',
            
            // Sales & Service
            'view_estimates',
            'manage_estimates',
            'view_workorders',
            'manage_workorders',
            'view_invoices',
            'manage_invoices',
            
            // Inventory
            'view_inventory',
            'manage_inventory',
            'view_vendors',
            'manage_vendors',
            'view_purchase_orders',
            
            // Operations
            'view_dispatch',
            'manage_dispatch',
            'view_time_tracking',
            'manage_time_tracking',
            'view_documents',
            'manage_documents',
            
            // Admin
            'view_reports',
            'view_users',
            'manage_users',
            'view_roles',
            'manage_roles',
            'view_settings',
            'manage_settings',
            'view_audit_logs',
        ],
        
        self::ROLE_MANAGER => [
            'view_dashboard',
            'view_calendar',
            'view_customers', 'manage_customers',
            'view_vehicles', 'manage_vehicles',
            'view_estimates', 'manage_estimates',
            'view_workorders', 'manage_workorders',
            'view_invoices', 'manage_invoices',
            'view_inventory', 'manage_inventory',
            'view_vendors',
            'view_dispatch', 'manage_dispatch',
            'view_time_tracking', 'manage_time_tracking',
            'view_reports',
            'view_users',
            'view_documents', 'manage_documents',
        ],

        self::ROLE_ADVISOR => [
            'view_dashboard',
            'view_calendar',
            'view_customers', 'manage_customers',
            'view_vehicles', 'manage_vehicles',
            'view_estimates', 'manage_estimates',
            'view_workorders', 'manage_workorders',
            'view_invoices', 'create_invoices',
            'view_inventory',
            'view_dispatch',
        ],

        self::ROLE_TECHNICIAN => [
            'view_calendar',
            'view_workorders',
            'update_workorder_status',
            'view_inventory',
            'request_parts',
            'view_time_tracking',
            'clock_in_out',
        ],
        
        self::ROLE_DRIVER => [
            'view_dispatch',
            'update_dispatch_status',
            'view_time_tracking',
            'clock_in_out',
        ],
    ];
    /**
     * @var array<string, array{label: string, description: string, permissions: string[], requires_2fa?: bool}>
     */
    private array $roles;

    /**
     * @param array<string, array{label: string, description: string, permissions: string[], requires_2fa?: bool}> $roles
     */
    public function __construct(array $roles)
    {
        $this->roles = $roles;
    }

    /**
     * @param array<string, array{label: string, description: string, permissions: string[], requires_2fa?: bool}> $defaultRoles
     */
    public static function fromDatabase(Connection $connection, array $defaultRoles): self
    {
        $roles = $defaultRoles;

        if (!self::hasCustomRolesTable($connection)) {
            return new self($roles);
        }

        $stmt = $connection->pdo()->prepare(
            'SELECT name, label, description, permissions FROM custom_roles'
        );
        $stmt->execute();

        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            $permissions = [];
            if (isset($row['permissions']) && is_string($row['permissions'])) {
                $permissions = json_decode($row['permissions'], true) ?? [];
            }

            $roleName = (string) ($row['name'] ?? '');
            if ($roleName === '') {
                continue;
            }

            $roles[$roleName] = [
                'label' => (string) ($row['label'] ?? ($roles[$roleName]['label'] ?? $roleName)),
                'description' => (string) ($row['description'] ?? ($roles[$roleName]['description'] ?? '')),
                'permissions' => is_array($permissions) ? $permissions : [],
                'requires_2fa' => $roles[$roleName]['requires_2fa'] ?? false,
            ];
        }

        return new self($roles);
    }

    public function hasRole(string $role): bool
    {
        return isset($this->roles[$role]);
    }

    /**
     * @return array<string, array{label: string, description: string, permissions: string[], requires_2fa?: bool}>
     */
    public function roleDefinitions(): array
    {
        return $this->roles;
    }

    public function validateRole(string $role): void
    {
        if (!isset($this->roles[$role])) {
            throw new InvalidArgumentException('Unknown role: ' . $role);
        }
    }

    /**
     * @return string[]
     */
    public function permissionsFor(string $role): array
    {
        $this->validateRole($role);

        return $this->roles[$role]['permissions'];
    }

    public function hasPermission(string $role, string $permission): bool
    {
        $granted = $this->permissionsFor($role);
        error_log("RolePermissions::hasPermission - Role: {$role}, Permission: {$permission}, Granted: " . json_encode($granted));

        foreach ($granted as $grantedPermission) {
            if ($grantedPermission === '*') {
                error_log("RolePermissions::hasPermission - Matched wildcard *");
                return true;
            }

            if ($this->permissionMatches($grantedPermission, $permission)) {
                error_log("RolePermissions::hasPermission - Matched: {$grantedPermission}");
                return true;
            }
        }

        error_log("RolePermissions::hasPermission - No match found, DENIED");
        return false;
    }

    /**
     * @return string[]
     */
    public function availablePermissions(): array
    {
        $permissions = [];

        foreach ($this->roles as $role) {
            foreach ($role['permissions'] as $permission) {
                $permissions[$permission] = true;
            }
        }

        $list = array_keys($permissions);
        sort($list);

        return $list;
    }

    private function permissionMatches(string $grantedPermission, string $permission): bool
    {
        if ($grantedPermission === $permission) {
            return true;
        }

        if (str_ends_with($grantedPermission, '.*')) {
            $prefix = substr($grantedPermission, 0, -2);

            return str_starts_with($permission, $prefix . '.');
        }

        return false;
    }

    private static function hasCustomRolesTable(Connection $connection): bool
    {
        $stmt = $connection->pdo()->prepare("SHOW TABLES LIKE 'custom_roles'");
        $stmt->execute();

        return (bool) $stmt->fetchColumn();
    }
}
