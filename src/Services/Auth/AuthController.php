<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Auth\AuthService;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class AuthController
{
    private AuthService $auth;

    public function __construct(AuthService $auth)
    {
        $this->auth = $auth;
    }

    /**
     * Staff login
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function login(array $data): array
    {
        if (!isset($data['email'], $data['password'])) {
            throw new InvalidArgumentException('Email and password are required');
        }

        $user = $this->auth->staffLogin((string) $data['email'], (string) $data['password']);

        if ($user === null) {
            throw new UnauthorizedException('Invalid credentials');
        }

        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $user->id;
        $_SESSION['user'] = $user->toArray();

        return [
            'user' => $user->toArray(),
            'message' => 'Login successful',
        ];
    }

    /**
     * Customer portal login
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function customerLogin(array $data): array
    {
        if (!isset($data['email'], $data['password'])) {
            throw new InvalidArgumentException('Email and password are required');
        }

        $user = $this->auth->customerPortalLogin((string) $data['email'], (string) $data['password']);

        if ($user === null) {
            throw new UnauthorizedException('Invalid credentials');
        }

        // Start session
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $_SESSION['user_id'] = $user->id;
        $_SESSION['user'] = $user->toArray();

        return [
            'user' => $user->toArray(),
            'message' => 'Login successful',
        ];
    }

    /**
     * Logout
     *
     * @return array<string, mixed>
     */
    public function logout(): array
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        session_destroy();

        return ['message' => 'Logged out successfully'];
    }

    /**
     * Register staff member
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function registerStaff(array $data): array
    {
        if (!isset($data['name'], $data['email'], $data['password'], $data['role'])) {
            throw new InvalidArgumentException('Name, email, password, and role are required');
        }

        $user = $this->auth->registerStaff(
            (string) $data['name'],
            (string) $data['email'],
            (string) $data['password'],
            (string) $data['role']
        );

        return [
            'user' => $user->toArray(),
            'message' => 'Staff registered successfully',
        ];
    }

    /**
     * Request password reset
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function requestPasswordReset(array $data): array
    {
        if (!isset($data['email'])) {
            throw new InvalidArgumentException('Email is required');
        }

        $token = $this->auth->requestPasswordReset((string) $data['email']);

        if ($token === null) {
            // Don't reveal if email exists
            return ['message' => 'If an account exists, a password reset link has been sent'];
        }

        return [
            'message' => 'Password reset link has been sent',
            'token' => $token->token, // For testing/dev only, remove in production
        ];
    }

    /**
     * Reset password with token
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function resetPassword(array $data): array
    {
        if (!isset($data['token'], $data['password'])) {
            throw new InvalidArgumentException('Token and new password are required');
        }

        $success = $this->auth->resetPassword((string) $data['token'], (string) $data['password']);

        if (!$success) {
            throw new InvalidArgumentException('Invalid or expired token');
        }

        return ['message' => 'Password reset successfully'];
    }

    /**
     * Verify email
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function verifyEmail(array $data): array
    {
        if (!isset($data['token'])) {
            throw new InvalidArgumentException('Token is required');
        }

        $success = $this->auth->verifyEmail((string) $data['token']);

        if (!$success) {
            throw new InvalidArgumentException('Invalid or expired verification token');
        }

        return ['message' => 'Email verified successfully'];
    }

    /**
     * Update user profile
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateProfile(User $user, array $data): array
    {
        $updated = $this->auth->updateProfile($user, $data);

        // Update session
        if (session_status() !== PHP_SESSION_NONE && isset($_SESSION['user'])) {
            $_SESSION['user'] = $updated->toArray();
        }

        return [
            'user' => $updated->toArray(),
            'message' => 'Profile updated successfully',
        ];
    }

    /**
     * Get current user
     *
     * @return array<string, mixed>
     */
    public function me(User $user): array
    {
        return ['user' => $user->toArray()];
    }
}
