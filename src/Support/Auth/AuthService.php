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
        array $config
    ) {
        $this->connection = $connection;
        $this->roles = $roles;
        $this->passwordResets = $passwordResets;
        $this->verifications = $verifications;
        $this->config = $config;
    }

    public function registerStaff(string $name, string $email, string $password, string $role): User
    {
        $role = strtolower($role);
        if (!in_array($role, ['admin', 'manager', 'technician'], true)) {
            throw new InvalidArgumentException('Staff role must be admin, manager, or technician.');
        }

        $this->roles->validateRole($role);
        $this->assertPasswordStrength($password);

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
        if (!$user || !in_array($user->role, ['admin', 'manager', 'technician'], true)) {
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
        $this->assertPasswordStrength($newPassword);

        $reset = $this->passwordResets->findValidToken($token);
        if ($reset === null) {
            return false;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET password = :password, updated_at = NOW() WHERE email = :email'
        );
        $stmt->execute([
            'password' => password_hash($newPassword, PASSWORD_BCRYPT),
            'email' => $reset->email,
        ]);

        $this->passwordResets->markUsed($token);

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
            $this->assertPasswordStrength((string) $attributes['password']);
            $sql = 'UPDATE users SET name = :name, password = :password, updated_at = NOW() WHERE id = :id';
            $params['password'] = password_hash((string) $attributes['password'], PASSWORD_BCRYPT);
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return $this->findUserById($user->id);
    }

    private function assertPasswordStrength(string $password): void
    {
        $minLength = (int) ($this->config['passwords']['min_length'] ?? 12);
        if (strlen($password) < $minLength) {
            throw new InvalidArgumentException('Password must be at least ' . $minLength . ' characters long.');
        }
    }

    private function findByEmail(string $email): ?User
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM users WHERE email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new User($row) : null;
    }

    private function findUserById(int $id): User
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('User not found for id ' . $id);
        }

        return new User($row);
    }
}
