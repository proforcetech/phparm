<?php

namespace App\Services\User;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class UserController
{
    private UserRepository $repository;
    private AccessGate $gate;

    public function __construct(UserRepository $repository, AccessGate $gate)
    {
        $this->repository = $repository;
        $this->gate = $gate;
    }

    /**
     * List technicians
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function listTechnicians(User $user, array $params = []): array
    {
        if (!$this->gate->can($user, 'users.view') && !$this->gate->can($user, 'appointments.*')) {
            throw new UnauthorizedException('Cannot view technicians');
        }

        $query = $params['query'] ?? '';

        if ($query !== '') {
            $technicians = $this->repository->searchByRole('technician', $query, 20);
        } else {
            $technicians = $this->repository->listByRole('technician');
        }

        return array_map(static fn ($tech) => [
            'id' => $tech->id,
            'name' => $tech->name,
            'email' => $tech->email,
            'role' => $tech->role
        ], $technicians);
    }

    /**
     * List all users
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listUsers(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'users.view')) {
            throw new UnauthorizedException('Cannot view users');
        }

        $users = $this->repository->list($filters);

        return array_map(static fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'email_verified' => $u->email_verified,
            'two_factor_enabled' => $u->two_factor_enabled,
            'two_factor_type' => $u->two_factor_type ?? 'none',
            'created_at' => $u->created_at,
            'updated_at' => $u->updated_at,
        ], $users);
    }

    /**
     * Get a single user
     *
     * @return array<string, mixed>
     */
    public function getUser(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'users.view')) {
            throw new UnauthorizedException('Cannot view users');
        }

        $targetUser = $this->repository->find($id);
        if (!$targetUser) {
            throw new InvalidArgumentException('User not found');
        }

        return [
            'id' => $targetUser->id,
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'role' => $targetUser->role,
            'email_verified' => $targetUser->email_verified,
            'two_factor_enabled' => $targetUser->two_factor_enabled,
            'two_factor_type' => $targetUser->two_factor_type ?? 'none',
            'created_at' => $targetUser->created_at,
            'updated_at' => $targetUser->updated_at,
        ];
    }

    /**
     * Create a new user
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function createUser(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'users.create')) {
            throw new UnauthorizedException('Cannot create users');
        }

        // Validate required fields
        if (empty($data['name'])) {
            throw new InvalidArgumentException('Name is required');
        }

        if (empty($data['email'])) {
            throw new InvalidArgumentException('Email is required');
        }

        if (empty($data['password'])) {
            throw new InvalidArgumentException('Password is required');
        }

        if (empty($data['role'])) {
            throw new InvalidArgumentException('Role is required');
        }

        // Validate role
        $validRoles = ['admin', 'manager', 'technician', 'customer'];
        if (!in_array($data['role'], $validRoles, true)) {
            throw new InvalidArgumentException('Invalid role');
        }

        // Check if email already exists
        if ($this->repository->findByEmail($data['email'])) {
            throw new InvalidArgumentException('Email already exists');
        }

        // Hash password
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        $newUser = $this->repository->create($data);

        return [
            'id' => $newUser->id,
            'name' => $newUser->name,
            'email' => $newUser->email,
            'role' => $newUser->role,
            'email_verified' => $newUser->email_verified,
            'two_factor_enabled' => $newUser->two_factor_enabled,
            'two_factor_type' => $newUser->two_factor_type ?? 'none',
            'created_at' => $newUser->created_at,
            'updated_at' => $newUser->updated_at,
        ];
    }

    /**
     * Update a user
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateUser(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'users.update')) {
            throw new UnauthorizedException('Cannot update users');
        }

        $targetUser = $this->repository->find($id);
        if (!$targetUser) {
            throw new InvalidArgumentException('User not found');
        }

        // Validate role if provided
        if (isset($data['role'])) {
            $validRoles = ['admin', 'manager', 'technician', 'customer'];
            if (!in_array($data['role'], $validRoles, true)) {
                throw new InvalidArgumentException('Invalid role');
            }
        }

        // Check if email already exists (for different user)
        if (isset($data['email']) && $data['email'] !== $targetUser->email) {
            $existingUser = $this->repository->findByEmail($data['email']);
            if ($existingUser && $existingUser->id !== $id) {
                throw new InvalidArgumentException('Email already exists');
            }
        }

        // Hash password if provided
        if (isset($data['password']) && !empty($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);
        } else {
            unset($data['password']);
        }

        $updatedUser = $this->repository->update($id, $data);

        return [
            'id' => $updatedUser->id,
            'name' => $updatedUser->name,
            'email' => $updatedUser->email,
            'role' => $updatedUser->role,
            'email_verified' => $updatedUser->email_verified,
            'two_factor_enabled' => $updatedUser->two_factor_enabled,
            'two_factor_type' => $updatedUser->two_factor_type ?? 'none',
            'created_at' => $updatedUser->created_at,
            'updated_at' => $updatedUser->updated_at,
        ];
    }

    /**
     * Delete a user
     */
    public function deleteUser(User $user, int $id): bool
    {
        if (!$this->gate->can($user, 'users.delete')) {
            throw new UnauthorizedException('Cannot delete users');
        }

        // Prevent users from deleting themselves
        if ($user->id === $id) {
            throw new InvalidArgumentException('Cannot delete your own account');
        }

        $targetUser = $this->repository->find($id);
        if (!$targetUser) {
            throw new InvalidArgumentException('User not found');
        }

        return $this->repository->delete($id);
    }

    /**
     * Reset 2FA for a user
     *
     * @return array<string, mixed>
     */
    public function reset2FA(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'users.update')) {
            throw new UnauthorizedException('Cannot reset 2FA');
        }

        $targetUser = $this->repository->find($id);
        if (!$targetUser) {
            throw new InvalidArgumentException('User not found');
        }

        $updatedUser = $this->repository->reset2FA($id);

        return [
            'id' => $updatedUser->id,
            'name' => $updatedUser->name,
            'email' => $updatedUser->email,
            'role' => $updatedUser->role,
            'email_verified' => $updatedUser->email_verified,
            'two_factor_enabled' => $updatedUser->two_factor_enabled,
            'two_factor_type' => $updatedUser->two_factor_type ?? 'none',
            'created_at' => $updatedUser->created_at,
            'updated_at' => $updatedUser->updated_at,
        ];
    }

    /**
     * Require 2FA for a user
     *
     * @return array<string, mixed>
     */
    public function require2FA(User $user, int $id, bool $required): array
    {
        if (!$this->gate->can($user, 'users.update')) {
            throw new UnauthorizedException('Cannot manage 2FA requirements');
        }

        $targetUser = $this->repository->find($id);
        if (!$targetUser) {
            throw new InvalidArgumentException('User not found');
        }

        // Note: This sets two_factor_enabled which indicates the user has set it up
        // You may want to add a separate field for "2FA required" vs "2FA enabled"
        $updatedUser = $this->repository->update($id, [
            'two_factor_enabled' => $required
        ]);

        return [
            'id' => $updatedUser->id,
            'name' => $updatedUser->name,
            'email' => $updatedUser->email,
            'role' => $updatedUser->role,
            'email_verified' => $updatedUser->email_verified,
            'two_factor_enabled' => $updatedUser->two_factor_enabled,
            'two_factor_type' => $updatedUser->two_factor_type ?? 'none',
            'created_at' => $updatedUser->created_at,
            'updated_at' => $updatedUser->updated_at,
        ];
    }
}
