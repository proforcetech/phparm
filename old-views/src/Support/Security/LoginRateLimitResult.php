<?php

namespace App\Support\Security;

class LoginRateLimitResult
{
    public bool $allowed;
    public bool $locked;
    public bool $cooldown;
    public bool $captchaRequired;
    public int $retryAfter;
    public int $lockoutSeconds;
    public int $ipAttempts;
    public int $identifierAttempts;

    public function __construct(
        bool $allowed,
        bool $locked,
        bool $cooldown,
        bool $captchaRequired,
        int $retryAfter,
        int $lockoutSeconds,
        int $ipAttempts,
        int $identifierAttempts
    ) {
        $this->allowed = $allowed;
        $this->locked = $locked;
        $this->cooldown = $cooldown;
        $this->captchaRequired = $captchaRequired;
        $this->retryAfter = $retryAfter;
        $this->lockoutSeconds = $lockoutSeconds;
        $this->ipAttempts = $ipAttempts;
        $this->identifierAttempts = $identifierAttempts;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(string $message, string $error = 'rate_limited'): array
    {
        return [
            'error' => $error,
            'message' => $message,
            'retry_after' => $this->retryAfter,
            'lockout_seconds' => $this->lockoutSeconds,
            'captcha_required' => $this->captchaRequired,
            'ip_attempts' => $this->ipAttempts,
            'identifier_attempts' => $this->identifierAttempts,
        ];
    }
}
