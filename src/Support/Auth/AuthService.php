<?php

namespace App\Support\Auth;

use App\Database\Connection;
use App\Models\EmailVerificationToken;
use App\Models\PasswordResetToken;
use App\Models\User;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class AuthService
{
    private Connection $connection;
    private RolePermissions $roles;
    private PasswordResetRepository $passwordResets;
    private EmailVerificationRepository $verifications;
    private PasswordHistoryRepository $passwordHistory;
    private PasswordPolicy $passwordPolicy;
    private int $passwordHistoryLimit;

    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        Connection $connection,
        RolePermissions $roles,
        PasswordResetRepository $passwordResets,
        EmailVerificationRepository $verifications,
        PasswordHistoryRepository $passwordHistory,
        PasswordPolicy $passwordPolicy,
        array $config
    ) {
        $this->connection = $connection;
        $this->roles = $roles;
        $this->passwordResets = $passwordResets;
        $this->verifications = $verifications;
        $this->passwordHistory = $passwordHistory;
        $this->passwordPolicy = $passwordPolicy;
        $this->config = $config;
        $this->passwordHistoryLimit = (int) ($config['passwords']['history_limit'] ?? 5);
    }

    public function registerStaff(string $name, string $email, string $password, string $role): User
    {
        $role = strtolower($role);
        $this->roles->validateRole($role);
        if ($role === 'customer') {
            throw new InvalidArgumentException('Staff role cannot be customer.');
        }
        $this->passwordPolicy->assertPasswordStrength($password);

        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES (:name, :email, :password, :role, NOW(), NOW())'
        );
        $stmt->execute([
            'name' => $name,
            'email' => $email,
            'password' => $passwordHash,
            'role' => $role,
        ]);

        $userId = (int) $this->connection->pdo()->lastInsertId();
        $this->recordPasswordHistory($userId, $passwordHash);

        if (($this->config['verification']['require_staff_verification'] ?? false) === true) {
            $this->verifications->createToken($userId);
        }

        return $this->findUserById($userId);
    }

    public function customerPortalLogin(string $email, string $password): ?User
    {
        if (($this->config['customer_portal']['login_enabled'] ?? true) !== true) {
            throw new RuntimeException('Customer portal login is disabled.');
        }

        $user = $this->findByEmail($email);

        if (!$user || $user->role !== 'customer') {
            return null;
        }

        if (!password_verify($password, $user->password)) {
            return null;
        }

        if (($this->config['verification']['require_customer_verification'] ?? false) && !$user->email_verified) {
            return null;
        }

        return $user;
    }

    public function staffLogin(string $email, string $password): ?User
    {
        $user = $this->findByEmail($email);
        if (!$user || $user->role === 'customer' || !$this->roles->hasRole($user->role)) {
            return null;
        }

        if (!password_verify($password, $user->password)) {
            return null;
        }

        if (($this->config['verification']['require_staff_verification'] ?? false) && !$user->email_verified) {
            return null;
        }

        return $user;
    }

    public function requestPasswordReset(string $email): ?PasswordResetToken
    {
        $user = $this->findByEmail($email);
        if (!$user) {
            return null;
        }

        return $this->passwordResets->createToken($email);
    }

    public function resetPassword(string $token, string $newPassword): bool
    {
        $this->passwordPolicy->assertPasswordStrength($newPassword);

        $reset = $this->passwordResets->findValidToken($token);
        if ($reset === null) {
            return false;
        }

        $user = $this->findByEmail($reset->email);
        if (!$user) {
            return false;
        }

        $this->assertPasswordNotReused($user, $newPassword);
        $passwordHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET password = :password, updated_at = NOW() WHERE email = :email'
        );
        $stmt->execute([
            'password' => $passwordHash,
            'email' => $reset->email,
        ]);

        $this->passwordResets->markUsed($token);
        $this->recordPasswordHistory($user->id, $passwordHash);

        return true;
    }

    public function issueVerificationToken(int $userId): EmailVerificationToken
    {
        return $this->verifications->createToken($userId);
    }

    public function verifyEmail(string $token): bool
    {
        $verification = $this->verifications->findValidToken($token);
        if ($verification === null) {
            return false;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET email_verified = 1, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute(['id' => $verification->user_id]);

        $this->verifications->markUsed($token);

        return true;
    }

    public function acceptInvitation(string $token, string $password): ?User
    {
        $this->passwordPolicy->assertPasswordStrength($password);

        $verification = $this->verifications->findValidToken($token);
        if ($verification === null) {
            return null;
        }

        $user = $this->findUserById($verification->user_id);
        $this->assertPasswordNotReused($user, $password);
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET password = :password, email_verified = 1, updated_at = NOW() WHERE id = :id'
        );
        $stmt->execute([
            'password' => $passwordHash,
            'id' => $verification->user_id,
        ]);

        $this->verifications->markUsed($token);
        $this->recordPasswordHistory($verification->user_id, $passwordHash);

        return $this->findUserById($verification->user_id);
    }

    public function linkCustomerUserByEmail(int $customerId, string $email, ?string $name = null): User
    {
        $user = $this->findByEmail($email);
        if ($user instanceof User) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE users SET customer_id = :customer_id WHERE id = :id'
            );
            $stmt->execute(['customer_id' => $customerId, 'id' => $user->id]);

            return $this->findUserById($user->id);
        }

        $password = bin2hex(random_bytes(10));
        $passwordHash = password_hash($password, PASSWORD_BCRYPT);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO users (name, email, password, role, customer_id, created_at, updated_at) VALUES (:name, :email, :password, :role, :customer_id, NOW(), NOW())'
        );
        $stmt->execute([
            'name' => $name ?? $email,
            'email' => $email,
            'password' => $passwordHash,
            'role' => 'customer',
            'customer_id' => $customerId,
        ]);

        $userId = (int) $this->connection->pdo()->lastInsertId();
        $this->recordPasswordHistory($userId, $passwordHash);

        if (($this->config['verification']['require_customer_verification'] ?? false) === true) {
            $this->verifications->createToken($userId);
        }

        return $this->findUserById($userId);
    }

    public function updateProfile(User $user, array $attributes): User
    {
        $fields = ['name' => $attributes['name'] ?? $user->name];
        $sql = 'UPDATE users SET name = :name, updated_at = NOW() WHERE id = :id';
        $params = ['name' => $fields['name'], 'id' => $user->id];

        if (isset($attributes['password'])) {
            $this->passwordPolicy->assertPasswordStrength((string) $attributes['password']);
            $this->assertPasswordNotReused($user, (string) $attributes['password']);
            $sql = 'UPDATE users SET name = :name, password = :password, updated_at = NOW() WHERE id = :id';
            $passwordHash = password_hash((string) $attributes['password'], PASSWORD_BCRYPT);
            $params['password'] = $passwordHash;
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        $updated = $this->findUserById($user->id);

        if (isset($passwordHash)) {
            $this->recordPasswordHistory($user->id, $passwordHash);
        }

        return $updated;
    }

    public function recordLastActivity(int $userId): void
    {
        $stmt = $this->connection->pdo()->prepare('UPDATE users SET last_activity_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $userId]);
    }

    private function assertPasswordNotReused(User $user, string $password): void
    {
        if (password_verify($password, $user->password)) {
            throw new InvalidArgumentException('New password must be different from your current password.');
        }

        if ($this->passwordHistory->wasPasswordUsed($user->id, $password, $this->passwordHistoryLimit)) {
            throw new InvalidArgumentException('You cannot reuse a recent password.');
        }
    }

    private function recordPasswordHistory(int $userId, string $passwordHash): void
    {
        $this->passwordHistory->record($userId, $passwordHash);
        $this->passwordHistory->prune($userId, $this->passwordHistoryLimit);
    }

    private function findByEmail(string $email): ?User
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM users WHERE email = :email AND active = 1 LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }

    public function findUserById(int $id): User
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM users WHERE id = :id AND active = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('User not found for id ' . $id);
        }

        return new User($row);
    }
}
