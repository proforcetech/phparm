<?php

namespace App\Support\Security;

use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Http\RateLimiter;
use App\Support\Http\Request;

class LoginRateLimiter
{
    private RateLimiter $ipLimiter;
    private RateLimiter $identifierLimiter;
    private string $lockoutPath;
    private array $config;
    private ?AuditLogger $audit;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(RateLimiter $baseLimiter, array $config = [], ?AuditLogger $audit = null)
    {
        $defaults = [
            'decay_seconds' => 60,
            'max_attempts_per_ip' => 25,
            'max_attempts_per_identifier' => 10,
            'lockout_threshold' => 8,
            'lockout_minutes' => 15,
            'captcha_after_attempts' => 4,
            'captcha_cooldown_minutes' => 10,
            'log_incidents' => true,
        ];

        $this->config = array_merge($defaults, $config);

        $this->ipLimiter = $baseLimiter->withLimits(
            (int) $this->config['max_attempts_per_ip'],
            (int) $this->config['decay_seconds']
        );
        $this->identifierLimiter = $baseLimiter->withLimits(
            (int) $this->config['max_attempts_per_identifier'],
            (int) $this->config['decay_seconds']
        );

        $this->lockoutPath = dirname(__DIR__, 3) . '/storage/temp/ratelimits/lockouts';
        if (!is_dir($this->lockoutPath)) {
            mkdir($this->lockoutPath, 0755, true);
        }

        $this->audit = $audit;
    }

    public function check(string $identifier, string $ip): LoginRateLimitResult
    {
        $normalized = $this->normalize($identifier);
        $lockoutSeconds = $this->lockoutRemaining($normalized);

        if ($lockoutSeconds > 0) {
            return new LoginRateLimitResult(false, true, true, true, $lockoutSeconds, $lockoutSeconds, 0, 0);
        }

        $ipKey = $this->ipKey($ip);
        $identifierKey = $this->identifierKey($normalized);

        $ipAttempts = $this->ipLimiter->attempts($ipKey);
        $identifierAttempts = $this->identifierLimiter->attempts($identifierKey);
        $retryAfter = $this->computeRetryAfter($ipKey, $identifierKey);

        $captchaRequired = $this->needsCaptcha($ipAttempts, $identifierAttempts);

        return new LoginRateLimitResult(
            $retryAfter === 0,
            false,
            $retryAfter > 0,
            $captchaRequired,
            $retryAfter,
            0,
            $ipAttempts,
            $identifierAttempts
        );
    }

    public function recordFailure(string $identifier, string $ip): LoginRateLimitResult
    {
        $normalized = $this->normalize($identifier);

        $lockoutSeconds = $this->lockoutRemaining($normalized);
        if ($lockoutSeconds > 0) {
            return new LoginRateLimitResult(false, true, true, true, $lockoutSeconds, $lockoutSeconds, 0, 0);
        }

        $ipKey = $this->ipKey($ip);
        $identifierKey = $this->identifierKey($normalized);

        $ipAttempts = $this->ipLimiter->hit($ipKey);
        $identifierAttempts = $this->identifierLimiter->hit($identifierKey);

        $captchaRequired = $this->needsCaptcha($ipAttempts, $identifierAttempts);
        $retryAfter = $this->computeRetryAfter($ipKey, $identifierKey);

        $locked = false;
        $lockoutDuration = 0;

        if (
            $this->config['lockout_threshold'] > 0
            && $identifierAttempts >= (int) $this->config['lockout_threshold']
        ) {
            $lockoutDuration = (int) $this->config['lockout_minutes'] * 60;
            $this->setLockout($normalized, $lockoutDuration);
            $locked = true;
            $retryAfter = max($retryAfter, $lockoutDuration);
            $this->log('auth.lockout', [
                'identifier' => $normalized,
                'ip' => $ip,
                'lockout_seconds' => $lockoutDuration,
                'attempts' => $identifierAttempts,
            ]);
        }

        if (!$locked && $retryAfter > 0) {
            $this->log('auth.rate_limit', [
                'identifier' => $normalized,
                'ip' => $ip,
                'retry_after' => $retryAfter,
                'ip_attempts' => $ipAttempts,
                'identifier_attempts' => $identifierAttempts,
            ]);
        }

        if ($captchaRequired) {
            $this->log('auth.captcha_challenge', [
                'identifier' => $normalized,
                'ip' => $ip,
                'attempts' => $identifierAttempts,
            ]);
        }

        return new LoginRateLimitResult(
            !$locked && $retryAfter === 0,
            $locked,
            $retryAfter > 0,
            $captchaRequired,
            $retryAfter,
            $lockoutDuration,
            $ipAttempts,
            $identifierAttempts
        );
    }

    public function recordSuccess(string $identifier, string $ip): void
    {
        $normalized = $this->normalize($identifier);
        $this->ipLimiter->clear($this->ipKey($ip));
        $this->identifierLimiter->clear($this->identifierKey($normalized));
        $this->clearLockout($normalized);
    }

    public static function clientIp(Request $request): string
    {
        $forwardedFor = $request->header('X-Forwarded-For');
        if ($forwardedFor !== null) {
            $ips = array_map('trim', explode(',', $forwardedFor));
            if (!empty($ips)) {
                return $ips[0];
            }
        }

        $realIp = $request->header('X-Real-IP');
        if ($realIp !== null) {
            return $realIp;
        }

        return $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    }

    private function computeRetryAfter(string $ipKey, string $identifierKey): int
    {
        $ipCooldown = $this->ipLimiter->tooManyAttempts($ipKey)
            ? $this->ipLimiter->availableIn($ipKey)
            : 0;
        $identifierCooldown = $this->identifierLimiter->tooManyAttempts($identifierKey)
            ? $this->identifierLimiter->availableIn($identifierKey)
            : 0;

        return max($ipCooldown, $identifierCooldown);
    }

    private function needsCaptcha(int $ipAttempts, int $identifierAttempts): bool
    {
        $threshold = (int) $this->config['captcha_after_attempts'];
        if ($threshold <= 0) {
            return false;
        }

        $cooldownSeconds = (int) $this->config['captcha_cooldown_minutes'] * 60;

        return $identifierAttempts >= $threshold || $ipAttempts >= $threshold || $this->recentLockout($cooldownSeconds);
    }

    private function lockoutRemaining(string $identifier): int
    {
        $file = $this->lockoutPath . '/' . md5($identifier) . '.lock';
        if (!file_exists($file)) {
            return 0;
        }

        $expiresAt = (int) file_get_contents($file);
        $remaining = $expiresAt - time();

        if ($remaining <= 0) {
            unlink($file);
            return 0;
        }

        return $remaining;
    }

    private function setLockout(string $identifier, int $durationSeconds): void
    {
        $file = $this->lockoutPath . '/' . md5($identifier) . '.lock';
        file_put_contents($file, (string) (time() + $durationSeconds), LOCK_EX);
        $this->identifierLimiter->clear($this->identifierKey($identifier));
    }

    private function clearLockout(string $identifier): void
    {
        $file = $this->lockoutPath . '/' . md5($identifier) . '.lock';
        if (file_exists($file)) {
            unlink($file);
        }
    }

    private function recentLockout(int $cooldownSeconds): bool
    {
        $files = glob($this->lockoutPath . '/*.lock');
        if ($files === false) {
            return false;
        }

        $now = time();
        foreach ($files as $file) {
            $expiresAt = (int) file_get_contents($file);
            if ($expiresAt > ($now - $cooldownSeconds)) {
                return true;
            }
        }

        return false;
    }

    private function ipKey(string $ip): string
    {
        return 'ip:' . $ip;
    }

    private function identifierKey(string $identifier): string
    {
        return 'identifier:' . $identifier;
    }

    private function normalize(string $identifier): string
    {
        $trimmed = trim($identifier);
        return $trimmed === '' ? 'unknown' : strtolower($trimmed);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $event, array $context): void
    {
        if ($this->audit === null || !($this->config['log_incidents'] ?? false)) {
            return;
        }

        $entry = new AuditEntry($event, 'authentication', null, null, $context);
        $this->audit->log($entry);
    }
}
