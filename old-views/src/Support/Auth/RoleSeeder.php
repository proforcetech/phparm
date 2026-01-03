<?php

namespace App\Support\Auth;

use App\Database\Connection;

class RoleSeeder
{
    private Connection $connection;
    private RolePermissions $roles;

    public function __construct(Connection $connection, RolePermissions $roles)
    {
        $this->connection = $connection;
        $this->roles = $roles;
    }

    public function seed(): void
    {
        foreach ($this->rolesConfig() as $role => $definition) {
            $this->upsertRole($role, $definition['description']);
            $this->syncPermissions($role, $definition['permissions']);
        }
    }

    /**
     * @return array<string, array{label: string, description: string, permissions: string[]}>
     */
    private function rolesConfig(): array
    {
        $reflection = new \ReflectionClass($this->roles);
        $property = $reflection->getProperty('roles');
        $property->setAccessible(true);

        /** @var array<string, array{label: string, description: string, permissions: string[]}> $roles */
        $roles = $property->getValue($this->roles);

        return $roles;
    }

    private function upsertRole(string $name, string $description): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO roles (name, description) VALUES (:name, :description) ON DUPLICATE KEY UPDATE description = VALUES(description)'
        );
        $stmt->execute(['name' => $name, 'description' => $description]);
    }

    private function syncPermissions(string $role, array $permissions): void
    {
        $deleteStmt = $this->connection->pdo()->prepare('DELETE FROM role_permissions WHERE role = :role');
        $deleteStmt->execute(['role' => $role]);

        $insertStmt = $this->connection->pdo()->prepare(
            'INSERT INTO role_permissions (role, permission, created_at) VALUES (:role, :permission, NOW())'
        );

        foreach ($permissions as $permission) {
            $insertStmt->execute(['role' => $role, 'permission' => $permission]);
        }
    }
}
