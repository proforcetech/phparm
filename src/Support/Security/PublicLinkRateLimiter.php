<?php

namespace App\Support\Security;

use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Http\IpAddressResolver;
use App\Support\Http\RateLimiter;
use App\Support\Http\Request;

/**
 * Rate limiter for public contract / estimate signing links (R-02a, AUD-064).
 *
 * Why: the public e-sign surface (token + short-code links) was previously
 * relying on the generic IP throttle, which lets a single token be hammered
 * from many rotating IPs without anyone noticing. This limiter also tracks
 * per-link traffic so a specific short-code or token can be cooled-down
 * regardless of source IP — the relevant defense for short-code enumeration.
 *
 * Stripped-down compared to LoginRateLimiter:
 *   - no captcha gating (public read paths shouldn't require interactive
 *     proofs; if a link is being hammered, throttling is the answer)
 *   - no persistent lockouts (a public link is either valid or revoked —
 *     it's not the right place to deny *future* legitimate traffic).
 */
class PublicLinkRateLimiter
{
    private RateLimiter $ipLimiter;
    private RateLimiter $linkLimiter;
    private array $config;
    private ?AuditLogger $audit;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(RateLimiter $baseLimiter, array $config = [], ?AuditLogger $audit = null)
    {
        $defaults = [
            'decay_seconds' => 60,
            'max_attempts_per_ip' => 30,
            'max_attempts_per_link' => 10,
            'log_incidents' => true,
        ];

        $this->config = array_merge($defaults, $config);

        $this->ipLimiter = $baseLimiter->withLimits(
            (int) $this->config['max_attempts_per_ip'],
            (int) $this->config['decay_seconds']
        );
        $this->linkLimiter = $baseLimiter->withLimits(
            (int) $this->config['max_attempts_per_link'],
            (int) $this->config['decay_seconds']
        );

        $this->audit = $audit;
    }

    /**
     * Read-only check: does NOT increment any counters. Use this to decide
     * whether to reject a request before doing work.
     */
    public function check(string $ip, ?string $linkIdentifier): PublicLinkRateLimitResult
    {
        $ipKey = $this->ipKey($ip);
        $linkKey = $this->linkKey($linkIdentifier);

        $ipAttempts = $this->ipLimiter->attempts($ipKey);
        $linkAttempts = $linkKey !== null ? $this->linkLimiter->attempts($linkKey) : 0;

        $ipCooldown = $this->ipLimiter->tooManyAttempts($ipKey)
            ? $this->ipLimiter->availableIn($ipKey)
            : 0;
        $linkCooldown = $linkKey !== null && $this->linkLimiter->tooManyAttempts($linkKey)
            ? $this->linkLimiter->availableIn($linkKey)
            : 0;

        $retryAfter = max($ipCooldown, $linkCooldown);
        $reason = '';
        if ($ipCooldown > 0 && $linkCooldown > 0) {
            $reason = 'ip_and_link';
        } elseif ($ipCooldown > 0) {
            $reason = 'ip';
        } elseif ($linkCooldown > 0) {
            $reason = 'link';
        }

        return new PublicLinkRateLimitResult(
            $retryAfter === 0,
            $retryAfter > 0,
            $retryAfter,
            $ipAttempts,
            $linkAttempts,
            $reason
        );
    }

    /**
     * Pre-flight + record. If either bucket is already at the cap, deny
     * the request *without* incrementing — being over the cap is its own
     * signal and we shouldn't extend the cooldown window every time someone
     * retries. If neither bucket is full, increment both and return an
     * allowed result.
     */
    public function hit(string $ip, ?string $linkIdentifier): PublicLinkRateLimitResult
    {
        $ipKey = $this->ipKey($ip);
        $linkKey = $this->linkKey($linkIdentifier);

        $ipCooldown = $this->ipLimiter->tooManyAttempts($ipKey)
            ? $this->ipLimiter->availableIn($ipKey)
            : 0;
        $linkCooldown = $linkKey !== null && $this->linkLimiter->tooManyAttempts($linkKey)
            ? $this->linkLimiter->availableIn($linkKey)
            : 0;

        if ($ipCooldown > 0 || $linkCooldown > 0) {
            $retryAfter = max($ipCooldown, $linkCooldown);
            $reason = '';
            if ($ipCooldown > 0 && $linkCooldown > 0) {
                $reason = 'ip_and_link';
            } elseif ($ipCooldown > 0) {
                $reason = 'ip';
            } else {
                $reason = 'link';
            }

            $ipAttempts = $this->ipLimiter->attempts($ipKey);
            $linkAttempts = $linkKey !== null ? $this->linkLimiter->attempts($linkKey) : 0;

            $this->log('public_link.rate_limited', [
                'ip' => $ip,
                'link' => $linkIdentifier,
                'retry_after' => $retryAfter,
                'ip_attempts' => $ipAttempts,
                'link_attempts' => $linkAttempts,
                'reason' => $reason,
            ]);

            return new PublicLinkRateLimitResult(
                false,
                true,
                $retryAfter,
                $ipAttempts,
                $linkAttempts,
                $reason
            );
        }

        $ipAttempts = $this->ipLimiter->hit($ipKey);
        $linkAttempts = $linkKey !== null ? $this->linkLimiter->hit($linkKey) : 0;

        return new PublicLinkRateLimitResult(
            true,
            false,
            0,
            $ipAttempts,
            $linkAttempts,
            ''
        );
    }

    public static function clientIp(Request $request): string
    {
        return $request->getClientIp()
            ?? IpAddressResolver::resolve($_SERVER, [])
            ?? '127.0.0.1';
    }

    public function getMaxAttemptsPerIp(): int
    {
        return (int) $this->config['max_attempts_per_ip'];
    }

    public function getMaxAttemptsPerLink(): int
    {
        return (int) $this->config['max_attempts_per_link'];
    }

    public function getDecaySeconds(): int
    {
        return (int) $this->config['decay_seconds'];
    }

    private function ipKey(string $ip): string
    {
        return 'publink:ip:' . $ip;
    }

    private function linkKey(?string $linkIdentifier): ?string
    {
        if ($linkIdentifier === null || $linkIdentifier === '') {
            return null;
        }
        // Hash so we never store raw tokens on disk in the rate-limit files.
        return 'publink:link:' . hash('sha256', $linkIdentifier);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $event, array $context): void
    {
        if ($this->audit === null || !($this->config['log_incidents'] ?? false)) {
            return;
        }

        $entry = new AuditEntry($event, 'public_link', null, null, $context);
        $this->audit->log($entry);
    }
}
