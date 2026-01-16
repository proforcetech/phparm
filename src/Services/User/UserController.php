<?php

namespace App\Services\User;

use App\Services\ImportExport\CsvExportService;
use App\Models\Employee;
use App\Models\User;
use App\Services\Employee\EmployeeRepository;
use App\Support\Auth\AccessGate;
use App\Support\Auth\RolePermissions;
use App\Support\Auth\TotpService;
use App\Support\Auth\UnauthorizedException;
use DateTime;
use InvalidArgumentException;

class UserController
{
    private const EMPLOYEE_PAY_STRUCTURES = ['Hourly', 'Flat Rate', 'Commission', 'Salary'];

    private UserRepository $repository;
    private AccessGate $gate;
    private TotpService $totpService;
    private RolePermissions $roles;
    private CsvExportService $csvExportService;
    private EmployeeRepository $employeeRepository;

    public function __construct(
        UserRepository $repository,
        AccessGate $gate,
        TotpService $totpService,
        RolePermissions $roles,
        CsvExportService $csvExportService,
        EmployeeRepository $employeeRepository
    ) {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->totpService = $totpService;
        $this->roles = $roles;
        $this->csvExportService = $csvExportService;
        $this->employeeRepository = $employeeRepository;
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
            $technicians = $this->repository->searchByRole('technician', $query, 20, $this->resolveBranchFilter($user, $params));
        } else {
            $technicians = $this->repository->listByRole('technician', $this->resolveBranchFilter($user, $params));
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

        $filters = $this->applyBranchFilter($user, $filters);
        $users = $this->repository->list($filters);

        return array_map(static fn ($u) => [
            'id' => $u->id,
            'name' => $u->name,
            'email' => $u->email,
            'role' => $u->role,
            'email_verified' => $u->email_verified,
            'branch_id' => $u->branch_id,
            'two_factor_enabled' => $u->two_factor_enabled,
            'two_factor_type' => $u->two_factor_type ?? 'none',
            'created_at' => $u->created_at,
            'updated_at' => $u->updated_at,
            'last_activity_at' => $u->last_activity_at,
        ], $users);
    }

    /**
     * Export users
     *
     * @param array<string, mixed> $filters
     */
    public function exportUsers(User $user, array $filters = []): string
    {
        if (!$this->gate->can($user, 'users.view')) {
            throw new UnauthorizedException('Cannot export users');
        }

        return $this->csvExportService->export('users', $this->applyBranchFilter($user, $filters));
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

        $employee = $this->employeeRepository->findByUserId($id);

        return [
            'id' => $targetUser->id,
            'name' => $targetUser->name,
            'email' => $targetUser->email,
            'role' => $targetUser->role,
            'email_verified' => $targetUser->email_verified,
            'branch_id' => $targetUser->branch_id,
            'two_factor_enabled' => $targetUser->two_factor_enabled,
            'two_factor_type' => $targetUser->two_factor_type ?? 'none',
            'created_at' => $targetUser->created_at,
            'updated_at' => $targetUser->updated_at,
            'last_activity_at' => $targetUser->last_activity_at,
            'employee' => $this->serializeEmployee($employee),
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
        $this->roles->validateRole($data['role']);

        // Check if email already exists
        if ($this->repository->findByEmail($data['email'])) {
            throw new InvalidArgumentException('Email already exists');
        }

        // Hash password
        $data['password'] = password_hash($data['password'], PASSWORD_DEFAULT);

        $employeePayload = $this->normalizeEmployeePayload($data);
        if ($employeePayload !== null) {
            $this->validateEmployeePayload($employeePayload);
        }
        unset($data['employee']);
        $data['branch_id'] = $this->resolveBranchAssignment($user, $data);

        $newUser = $this->repository->create($data);
        $employee = $employeePayload !== null
            ? $this->employeeRepository->upsertByUserId($newUser->id, $employeePayload)
            : null;

        return [
            'id' => $newUser->id,
            'name' => $newUser->name,
            'email' => $newUser->email,
            'role' => $newUser->role,
            'email_verified' => $newUser->email_verified,
            'branch_id' => $newUser->branch_id,
            'two_factor_enabled' => $newUser->two_factor_enabled,
            'two_factor_type' => $newUser->two_factor_type ?? 'none',
            'created_at' => $newUser->created_at,
            'updated_at' => $newUser->updated_at,
            'last_activity_at' => $newUser->last_activity_at,
            'employee' => $this->serializeEmployee($employee),
        ];
    }

    /**
     * Invite a new user
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function inviteUser(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'users.invite')) {
            throw new UnauthorizedException('Cannot invite users');
        }

        if (empty($data['name'])) {
            throw new InvalidArgumentException('Name is required');
        }

        if (empty($data['email'])) {
            throw new InvalidArgumentException('Email is required');
        }

        if (empty($data['role'])) {
            throw new InvalidArgumentException('Role is required');
        }

        $this->roles->validateRole($data['role']);

        if ($this->repository->findByEmail($data['email'])) {
            throw new InvalidArgumentException('Email already exists');
        }

        $temporaryPassword = bin2hex(random_bytes(24));
        $employeePayload = $this->normalizeEmployeePayload($data);
        if ($employeePayload !== null) {
            $this->validateEmployeePayload($employeePayload);
        }
        $data['branch_id'] = $this->resolveBranchAssignment($user, $data);

        $newUser = $this->repository->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'role' => $data['role'],
            'password' => password_hash($temporaryPassword, PASSWORD_DEFAULT),
            'email_verified' => false,
            'two_factor_enabled' => false,
            'two_factor_type' => 'none',
            'branch_id' => $data['branch_id'],
        ]);
        $employee = $employeePayload !== null
            ? $this->employeeRepository->upsertByUserId($newUser->id, $employeePayload)
            : null;

        return [
            'id' => $newUser->id,
            'name' => $newUser->name,
            'email' => $newUser->email,
            'role' => $newUser->role,
            'email_verified' => $newUser->email_verified,
            'branch_id' => $newUser->branch_id,
            'two_factor_enabled' => $newUser->two_factor_enabled,
            'two_factor_type' => $newUser->two_factor_type ?? 'none',
            'created_at' => $newUser->created_at,
            'updated_at' => $newUser->updated_at,
            'last_activity_at' => $newUser->last_activity_at,
            'employee' => $this->serializeEmployee($employee),
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
            $this->roles->validateRole($data['role']);
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

        $employeePayload = $this->normalizeEmployeePayload($data);
        if ($employeePayload !== null) {
            $this->validateEmployeePayload($employeePayload);
        }
        unset($data['employee']);
        $data['branch_id'] = $this->resolveBranchAssignment($user, $data);

        $updatedUser = $this->repository->update($id, $data);
        $employee = $employeePayload !== null
            ? $this->employeeRepository->upsertByUserId($id, $employeePayload)
            : $this->employeeRepository->findByUserId($id);

        return [
            'id' => $updatedUser->id,
            'name' => $updatedUser->name,
            'email' => $updatedUser->email,
            'role' => $updatedUser->role,
            'email_verified' => $updatedUser->email_verified,
            'branch_id' => $updatedUser->branch_id,
            'two_factor_enabled' => $updatedUser->two_factor_enabled,
            'two_factor_type' => $updatedUser->two_factor_type ?? 'none',
            'created_at' => $updatedUser->created_at,
            'updated_at' => $updatedUser->updated_at,
            'employee' => $this->serializeEmployee($employee),
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
                'branch_id' => $updatedUser->branch_id,
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
            'branch_id' => $updatedUser->branch_id,
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
            'branch_id' => $updatedUser->branch_id,
            'two_factor_enabled' => $updatedUser->two_factor_enabled,
            'two_factor_type' => $updatedUser->two_factor_type ?? 'none',
            'two_factor_setup_pending' => $updatedUser->two_factor_setup_pending,
            'created_at' => $updatedUser->created_at,
            'updated_at' => $updatedUser->updated_at,
        ];
    }

    /**
     * Bulk deactivate users.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function bulkDeactivate(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'users.delete')) {
            throw new UnauthorizedException('Cannot deactivate users');
        }

        $ids = $data['user_ids'] ?? [];
        if (!is_array($ids) || empty($ids)) {
            throw new InvalidArgumentException('User IDs are required');
        }

        $count = $this->repository->bulkDeactivate($ids, $user->id);
        if ($count === 0) {
            throw new InvalidArgumentException('No valid users selected');
        }

        return [
            'message' => 'Users deactivated successfully',
            'deactivated' => $count,
        ];
    }

    /**
     * Bulk update user roles.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function bulkUpdateRole(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'users.update')) {
            throw new UnauthorizedException('Cannot update user roles');
        }

        $ids = $data['user_ids'] ?? [];
        $role = $data['role'] ?? '';

        if (!is_array($ids) || empty($ids)) {
            throw new InvalidArgumentException('User IDs are required');
        }

        if ($role === '') {
            throw new InvalidArgumentException('Role is required');
        }

        $validRoles = ['admin', 'dispatcher', 'manager', 'technician', 'parts', 'roadside', 'cms', 'customer'];
        if (!in_array($role, $validRoles, true)) {
            throw new InvalidArgumentException('Invalid role');
        }

        $count = $this->repository->bulkUpdateRole($ids, $role);
        if ($count === 0) {
            throw new InvalidArgumentException('No valid users selected');
        }

        return [
            'message' => 'User roles updated successfully',
            'updated' => $count,
            'role' => $role,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    private function normalizeEmployeePayload(array $data): ?array
    {
        if (!array_key_exists('employee', $data) || !is_array($data['employee'])) {
            return null;
        }

        $employee = $data['employee'];

        return [
            'hire_date' => $this->normalizeNullableString($employee['hire_date'] ?? null),
            'emergency_contact' => $this->normalizeNullableString($employee['emergency_contact'] ?? null),
            'pay_structure' => $this->normalizeNullableString($employee['pay_structure'] ?? null),
            'skills' => $this->normalizeEmployeeSkills($employee['skills'] ?? null),
        ];
    }

    /**
     * @param mixed $value
     */
    private function normalizeNullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param mixed $value
     * @return array<int, string>|null
     */
    private function normalizeEmployeeSkills($value): ?array
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $parts = preg_split('/[,\n]/', $value) ?: [];
        } elseif (is_array($value)) {
            $parts = $value;
        } else {
            return null;
        }

        $skills = array_values(array_filter(array_map(
            static fn ($skill) => trim((string) $skill),
            $parts
        ), static fn ($skill) => $skill !== ''));

        return $skills;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validateEmployeePayload(array $payload): void
    {
        if ($payload['hire_date'] !== null) {
            $date = DateTime::createFromFormat('Y-m-d', $payload['hire_date']);
            $errors = DateTime::getLastErrors();

            if ($date === false || $errors === false || $errors['warning_count'] > 0 || $errors['error_count'] > 0) {
                throw new InvalidArgumentException('Hire date must be in YYYY-MM-DD format');
            }
        }

        if ($payload['pay_structure'] !== null
            && !in_array($payload['pay_structure'], self::EMPLOYEE_PAY_STRUCTURES, true)
        ) {
            throw new InvalidArgumentException('Invalid pay structure');
        }

        if ($payload['skills'] !== null) {
            foreach ($payload['skills'] as $skill) {
                if (!is_string($skill) || trim($skill) === '') {
                    throw new InvalidArgumentException('Skills must be a list of non-empty strings');
                }
            }
        }
    }

    private function serializeEmployee(?Employee $employee): ?array
    {
        if (!$employee) {
            return null;
        }

        return [
            'id' => $employee->id,
            'user_id' => $employee->user_id,
            'hire_date' => $employee->hire_date,
            'emergency_contact' => $employee->emergency_contact,
            'pay_structure' => $employee->pay_structure,
            'skills' => $employee->skills ?? [],
            'created_at' => $employee->created_at,
            'updated_at' => $employee->updated_at,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function applyBranchFilter(User $user, array $filters): array
    {
        if ($user->role !== 'admin' && $user->branch_id !== null) {
            $filters['branch_id'] = $user->branch_id;
        }

        return $filters;
    }

    /**
     * @param array<string, mixed> $params
     */
    private function resolveBranchFilter(User $user, array $params): ?int
    {
        if ($user->role !== 'admin') {
            return $user->branch_id;
        }

        if (!array_key_exists('branch_id', $params)) {
            return null;
        }

        return $this->normalizeBranchId($params['branch_id']);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function resolveBranchAssignment(User $user, array $data): ?int
    {
        if ($user->role !== 'admin') {
            return $user->branch_id;
        }

        return $this->normalizeBranchId($data['branch_id'] ?? null);
    }

    /**
     * @param mixed $value
     */
    private function normalizeBranchId($value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (int) $value;
    }
}
