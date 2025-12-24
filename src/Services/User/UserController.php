<?php

namespace App\Services\User;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\TotpService;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class UserController
{
    private UserRepository $repository;
    private AccessGate $gate;
    private TotpService $totpService;

    public function __construct(UserRepository $repository, AccessGate $gate, TotpService $totpService)
    {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->totpService = $totpService;
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
     * Update the authenticated user's profile
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateProfile(User $user, array $data): array
    {
        $updateData = [];
        $sensitiveChange = false;
        $twoFactorChange = false;

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('Name is required');
            }
            $updateData['name'] = $name;
        }

        if (array_key_exists('email', $data)) {
            $email = trim((string) $data['email']);
            if ($email === '') {
                throw new InvalidArgumentException('Email is required');
            }

            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new InvalidArgumentException('Invalid email address');
            }

            if ($email !== $user->email) {
                if ($this->repository->findByEmail($email)) {
                    throw new InvalidArgumentException('Email already exists');
                }

                $updateData['email'] = $email;
                $sensitiveChange = true;
            }
        }

        $passwordChange = array_key_exists('password', $data) && $data['password'] !== '';
        if ($passwordChange) {
            if (empty($data['password_confirmation'])) {
                throw new InvalidArgumentException('Password confirmation is required');
            }

            if ($data['password'] !== $data['password_confirmation']) {
                throw new InvalidArgumentException('Password confirmation does not match');
            }

            $updateData['password'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
            $sensitiveChange = true;
        }

        if (array_key_exists('two_factor_enabled', $data)) {
            $requestedTwoFactor = (bool) $data['two_factor_enabled'];
            $twoFactorChange = $requestedTwoFactor !== $user->two_factor_enabled;
            if ($twoFactorChange) {
                $sensitiveChange = true;
            }
        }

        if ($sensitiveChange) {
            $currentPassword = (string) ($data['current_password'] ?? '');
            if ($currentPassword === '' || !password_verify($currentPassword, $user->password)) {
                throw new InvalidArgumentException('Current password is incorrect');
            }
        }

        if ($user->two_factor_enabled || $twoFactorChange) {
            $twoFactorCode = trim((string) ($data['two_factor_code'] ?? ''));
            if ($twoFactorCode === '') {
                throw new InvalidArgumentException('Two-factor code is required');
            }

            if (!$user->two_factor_secret) {
                throw new InvalidArgumentException('Two-factor authentication is not configured');
            }

            $twoFactorType = $data['two_factor_type'] ?? $user->two_factor_type;
            if (!in_array($twoFactorType, ['totp', 'sms'], true)) {
                throw new InvalidArgumentException('Unsupported two-factor authentication type');
            }

            if (!$this->totpService->verifyCode($user->two_factor_secret, $twoFactorCode)) {
                throw new InvalidArgumentException('Invalid two-factor code');
            }
        }

        $updatedUser = $user;
        if (!empty($updateData)) {
            $updatedUser = $this->repository->update($user->id, $updateData);
        }

        if ($twoFactorChange && isset($data['two_factor_enabled']) && !$data['two_factor_enabled']) {
            $updatedUser = $this->repository->reset2FA($user->id);
        }

        return [
            'message' => 'Profile updated successfully',
            'user' => [
                'id' => $updatedUser->id,
                'name' => $updatedUser->name,
                'email' => $updatedUser->email,
                'role' => $updatedUser->role,
                'email_verified' => $updatedUser->email_verified,
                'two_factor_enabled' => $updatedUser->two_factor_enabled,
                'two_factor_type' => $updatedUser->two_factor_type ?? 'none',
                'two_factor_setup_pending' => $updatedUser->two_factor_setup_pending,
                'created_at' => $updatedUser->created_at,
                'updated_at' => $updatedUser->updated_at,
            ],
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

        // If requiring 2FA and user hasn't set it up yet, mark as pending setup
        // If disabling 2FA requirement, reset their 2FA completely
        if ($required) {
            // Only mark as pending if they don't already have 2FA enabled
            if (!$targetUser->two_factor_enabled) {
                $updatedUser = $this->repository->requireTwoFactorSetup($id);
            } else {
                $updatedUser = $targetUser; // Already set up, no changes needed
            }
        } else {
            // Disabling 2FA requirement - reset everything
            $updatedUser = $this->repository->reset2FA($id);
        }

        return [
            'id' => $updatedUser->id,
            'name' => $updatedUser->name,
            'email' => $updatedUser->email,
            'role' => $updatedUser->role,
            'email_verified' => $updatedUser->email_verified,
            'two_factor_enabled' => $updatedUser->two_factor_enabled,
            'two_factor_type' => $updatedUser->two_factor_type ?? 'none',
            'two_factor_setup_pending' => $updatedUser->two_factor_setup_pending,
            'created_at' => $updatedUser->created_at,
            'updated_at' => $updatedUser->updated_at,
        ];
    }
}
