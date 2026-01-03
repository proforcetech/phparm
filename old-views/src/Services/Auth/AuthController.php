<?php

namespace App\Services\Auth;

use App\Models\User;
use App\Support\Auth\AuthService;
use App\Support\Auth\UnauthorizedException;
use App\Support\Notifications\NotificationDispatcher;
use InvalidArgumentException;

class AuthController
{
    private AuthService $auth;
    private ?NotificationDispatcher $notifications;
    private string $appUrl;
    private int $passwordResetExpiryMinutes;
    private int $verificationExpiryHours;

    public function __construct(
        AuthService $auth,
        ?NotificationDispatcher $notifications = null,
        string $appUrl = '',
        int $passwordResetExpiryMinutes = 60,
        int $verificationExpiryHours = 48
    ) {
        $this->auth = $auth;
        $this->notifications = $notifications;
        $this->appUrl = rtrim($appUrl, '/');
        $this->passwordResetExpiryMinutes = $passwordResetExpiryMinutes;
        $this->verificationExpiryHours = $verificationExpiryHours;
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

        // Send welcome email
        $this->sendWelcomeEmail($user);

        return [
            'user' => $user->toArray(),
            'message' => 'Staff registered successfully',
        ];
    }

    /**
     * Request email verification resend
     *
     * @param User $user
     * @return array<string, mixed>
     */
    public function resendVerification(User $user): array
    {
        if ($user->email_verified) {
            return ['message' => 'Email is already verified'];
        }

        $token = $this->auth->issueVerificationToken($user->id);
        $this->sendVerificationEmail($user, $token->token);

        return ['message' => 'Verification email has been sent'];
    }

    /**
     * Send welcome email to new user
     */
    private function sendWelcomeEmail(User $user): void
    {
        if ($this->notifications === null) {
            return;
        }

        $loginUrl = $this->appUrl . '/login';

        try {
            $this->notifications->sendMail(
                'auth.welcome',
                $user->email,
                [
                    'name' => $user->name,
                    'login_url' => $loginUrl,
                ],
                'Welcome to Auto Repair Shop Management'
            );
        } catch (\Throwable $e) {
            error_log('Failed to send welcome email: ' . $e->getMessage());
        }
    }

    /**
     * Send verification email
     */
    private function sendVerificationEmail(User $user, string $token): void
    {
        if ($this->notifications === null) {
            return;
        }

        $verificationUrl = $this->appUrl . '/verify-email?token=' . urlencode($token);

        try {
            $this->notifications->sendMail(
                'auth.email_verification',
                $user->email,
                [
                    'name' => $user->name,
                    'verification_url' => $verificationUrl,
                    'expiry_hours' => $this->verificationExpiryHours,
                ],
                'Verify Your Email Address'
            );
        } catch (\Throwable $e) {
            error_log('Failed to send verification email: ' . $e->getMessage());
        }
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

        $email = (string) $data['email'];
        $token = $this->auth->requestPasswordReset($email);

        if ($token === null) {
            // Don't reveal if email exists
            return ['message' => 'If an account exists, a password reset link has been sent'];
        }

        // Send password reset email
        if ($this->notifications !== null) {
            $resetUrl = $this->appUrl . '/reset-password?token=' . urlencode($token->token);
            $expiryHours = round($this->passwordResetExpiryMinutes / 60, 1);

            try {
                $this->notifications->sendMail(
                    'auth.password_reset',
                    $email,
                    [
                        'reset_url' => $resetUrl,
                        'expiry_hours' => $expiryHours,
                    ],
                    'Reset Your Password'
                );
            } catch (\Throwable $e) {
                // Log but don't fail the request
                error_log('Failed to send password reset email: ' . $e->getMessage());
            }
        }

        return [
            'message' => 'If an account exists, a password reset link has been sent',
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
