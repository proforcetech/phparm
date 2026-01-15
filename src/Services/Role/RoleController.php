<?php

namespace App\Services\Role;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\RolePermissions;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class RoleController
{
    private RoleRepository $repository;
    private AccessGate $gate;
    private RolePermissions $permissions;

    public function __construct(RoleRepository $repository, AccessGate $gate, RolePermissions $permissions)
    {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->permissions = $permissions;
    }

    public function listRoles(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'users.view')) {
            throw new UnauthorizedException('Cannot view roles');
        }

        $roles = $this->repository->list($filters);
        $rolesByName = [];

        foreach ($roles as $role) {
            $rolesByName[$role->name] = [
                'id' => $role->id,
                'name' => $role->name,
                'label' => $role->label,
                'description' => $role->description,
                'permissions' => $role->permissions,
                'is_system' => $role->is_system,
                'created_at' => $role->created_at,
                'updated_at' => $role->updated_at,
            ];
        }

        $includeSystem = $filters['include_system'] ?? true;
        if ($includeSystem) {
            $query = strtolower((string) ($filters['query'] ?? ''));
            foreach ($this->permissions->roleDefinitions() as $name => $definition) {
                if (isset($rolesByName[$name])) {
                    continue;
                }

                $label = $definition['label'] ?? $name;
                $description = $definition['description'] ?? '';
                if ($query !== '') {
                    $haystack = strtolower($name . ' ' . $label . ' ' . $description);
                    if (!str_contains($haystack, $query)) {
                        continue;
                    }
                }

                $rolesByName[$name] = [
                    'id' => null,
                    'name' => $name,
                    'label' => $label,
                    'description' => $description,
                    'permissions' => $definition['permissions'] ?? [],
                    'is_system' => true,
                    'created_at' => null,
                    'updated_at' => null,
                ];
            }
        }

        $rolesList = array_values($rolesByName);
        usort($rolesList, static function ($a, $b) {
            if ($a['is_system'] !== $b['is_system']) {
                return $a['is_system'] ? -1 : 1;
            }

            return strcmp($a['name'], $b['name']);
        });

        return $rolesList;
    }

    public function getRole(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'users.view')) {
            throw new UnauthorizedException('Cannot view roles');
        }

        $role = $this->repository->find($id);
        if (!$role) {
            throw new InvalidArgumentException('Role not found');
        }

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'permissions' => $role->permissions,
            'is_system' => $role->is_system,
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];
    }

    public function createRole(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'users.create')) {
            throw new UnauthorizedException('Cannot create roles');
        }

        if (empty($data['name'])) {
            throw new InvalidArgumentException('Role name is required');
        }

        if (empty($data['label'])) {
            throw new InvalidArgumentException('Role label is required');
        }

        if ($this->repository->findByName($data['name'])) {
            throw new InvalidArgumentException('Role name already exists');
        }

        $data['is_system'] = false;

        $role = $this->repository->create($data);

        return [
            'id' => $role->id,
            'name' => $role->name,
            'label' => $role->label,
            'description' => $role->description,
            'permissions' => $role->permissions,
            'is_system' => $role->is_system,
            'created_at' => $role->created_at,
            'updated_at' => $role->updated_at,
        ];
    }

    public function updateRole(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'users.update')) {
            throw new UnauthorizedException('Cannot update roles');
        }

        $role = $this->repository->find($id);
        if (!$role) {
            throw new InvalidArgumentException('Role not found');
        }

        if ($role->is_system) {
            throw new InvalidArgumentException('Cannot modify system roles');
        }

        if (isset($data['name']) && $data['name'] !== $role->name) {
            if ($this->repository->findByName($data['name'])) {
                throw new InvalidArgumentException('Role name already exists');
            }
        }

        $updatedRole = $this->repository->update($id, $data);

        return [
            'id' => $updatedRole->id,
            'name' => $updatedRole->name,
            'label' => $updatedRole->label,
            'description' => $updatedRole->description,
            'permissions' => $updatedRole->permissions,
            'is_system' => $updatedRole->is_system,
            'created_at' => $updatedRole->created_at,
            'updated_at' => $updatedRole->updated_at,
        ];
    }

    public function deleteRole(User $user, int $id): bool
    {
        if (!$this->gate->can($user, 'users.delete')) {
            throw new UnauthorizedException('Cannot delete roles');
        }

        $role = $this->repository->find($id);
        if (!$role) {
            throw new InvalidArgumentException('Role not found');
        }

        if ($role->is_system) {
            throw new InvalidArgumentException('Cannot delete system roles');
        }

        return $this->repository->delete($id);
    }

    public function getAvailablePermissions(User $user): array
    {
        if (!$this->gate->can($user, 'users.view')) {
            throw new UnauthorizedException('Cannot view permissions');
        }

        return $this->permissions->availablePermissions();
    }
}
