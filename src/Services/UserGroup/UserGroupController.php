<?php

namespace App\Services\UserGroup;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Controller for user group management
 */
class UserGroupController
{
    private UserGroupService $service;
    private AccessGate $gate;

    public function __construct(UserGroupService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * List all user groups
     *
     * @return array<int, array>
     */
    public function index(User $user): array
    {
        $this->assertPermission($user, 'users.view');

        return $this->service->list();
    }

    /**
     * Get a single user group
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->assertPermission($user, 'users.view');

        $group = $this->service->find($id);
        if ($group === null) {
            throw new InvalidArgumentException('User group not found');
        }

        // Include members in detail view
        $group['members'] = $this->service->getMembers($id);

        return $group;
    }

    /**
     * Create a new user group
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->assertPermission($user, 'users.create');

        $this->validateGroupData($data);

        return $this->service->create($data, $user->id);
    }

    /**
     * Update a user group
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $data): array
    {
        $this->assertPermission($user, 'users.update');

        $group = $this->service->find($id);
        if ($group === null) {
            throw new InvalidArgumentException('User group not found');
        }

        if (isset($data['name'])) {
            $this->validateGroupName($data['name'], $id);
        }

        $updated = $this->service->update($id, $data, $user->id);
        if ($updated === null) {
            throw new InvalidArgumentException('Failed to update user group');
        }

        return $updated;
    }

    /**
     * Delete a user group
     */
    public function destroy(User $user, int $id): bool
    {
        $this->assertPermission($user, 'users.delete');

        $group = $this->service->find($id);
        if ($group === null) {
            throw new InvalidArgumentException('User group not found');
        }

        return $this->service->delete($id, $user->id);
    }

    /**
     * Get members of a group
     *
     * @return array<int, array>
     */
    public function members(User $user, int $id): array
    {
        $this->assertPermission($user, 'users.view');

        $group = $this->service->find($id);
        if ($group === null) {
            throw new InvalidArgumentException('User group not found');
        }

        return $this->service->getMembers($id);
    }

    /**
     * Add a member to a group
     */
    public function addMember(User $user, int $groupId, int $userId): bool
    {
        $this->assertPermission($user, 'users.update');

        $group = $this->service->find($groupId);
        if ($group === null) {
            throw new InvalidArgumentException('User group not found');
        }

        return $this->service->addMember($groupId, $userId, $user->id);
    }

    /**
     * Remove a member from a group
     */
    public function removeMember(User $user, int $groupId, int $userId): bool
    {
        $this->assertPermission($user, 'users.update');

        $group = $this->service->find($groupId);
        if ($group === null) {
            throw new InvalidArgumentException('User group not found');
        }

        return $this->service->removeMember($groupId, $userId, $user->id);
    }

    /**
     * Set all members of a group
     *
     * @param int[] $userIds
     */
    public function setMembers(User $user, int $groupId, array $userIds): bool
    {
        $this->assertPermission($user, 'users.update');

        $group = $this->service->find($groupId);
        if ($group === null) {
            throw new InvalidArgumentException('User group not found');
        }

        return $this->service->setMembers($groupId, $userIds, $user->id);
    }

    /**
     * Get users not in a group (for adding)
     *
     * @return array<int, array>
     */
    public function nonMembers(User $user, int $groupId, ?string $search = null): array
    {
        $this->assertPermission($user, 'users.view');

        $group = $this->service->find($groupId);
        if ($group === null) {
            throw new InvalidArgumentException('User group not found');
        }

        return $this->service->getNonMembers($groupId, $search);
    }

    /**
     * Get groups for a specific user
     *
     * @return array<int, array>
     */
    public function userGroups(User $user, int $userId): array
    {
        $this->assertPermission($user, 'users.view');

        return $this->service->getGroupsForUser($userId);
    }

    /**
     * Set groups for a specific user
     *
     * @param int[] $groupIds
     */
    public function setUserGroups(User $user, int $userId, array $groupIds): bool
    {
        $this->assertPermission($user, 'users.update');

        return $this->service->setGroupsForUser($userId, $groupIds, $user->id);
    }

    /**
     * Assert that the user has a specific permission
     */
    private function assertPermission(User $user, string $permission): void
    {
        if (!$this->gate->can($user, $permission)) {
            throw new UnauthorizedException("Permission denied: {$permission}");
        }
    }

    /**
     * Validate group data for creation
     *
     * @param array<string, mixed> $data
     */
    private function validateGroupData(array $data): void
    {
        if (!isset($data['name']) || trim($data['name']) === '') {
            throw new InvalidArgumentException('Group name is required');
        }

        $this->validateGroupName($data['name']);

        if (isset($data['permissions']) && !is_array($data['permissions'])) {
            throw new InvalidArgumentException('Permissions must be an array');
        }

        if (isset($data['disabled_modules']) && !is_array($data['disabled_modules'])) {
            throw new InvalidArgumentException('Disabled modules must be an array');
        }
    }

    /**
     * Validate group name is unique
     */
    private function validateGroupName(string $name, ?int $excludeId = null): void
    {
        $groups = $this->service->list();
        foreach ($groups as $group) {
            if (strtolower($group['name']) === strtolower($name)) {
                if ($excludeId === null || $group['id'] !== $excludeId) {
                    throw new InvalidArgumentException('A group with this name already exists');
                }
            }
        }
    }
}
