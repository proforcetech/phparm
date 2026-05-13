<?php

namespace App\Support\Security;

class PublicLinkRateLimitResult
{
    public bool $allowed;
    public bool $cooldown;
    public int $retryAfter;
    public int $ipAttempts;
    public int $linkAttempts;
    public string $reason;

    public function __construct(
        bool $allowed,
        bool $cooldown,
        int $retryAfter,
        int $ipAttempts,
        int $linkAttempts,
        string $reason = ''
    ) {
        $this->allowed = $allowed;
        $this->cooldown = $cooldown;
        $this->retryAfter = $retryAfter;
        $this->ipAttempts = $ipAttempts;
        $this->linkAttempts = $linkAttempts;
        $this->reason = $reason;
    }

    /**
     * @return array<string, mixed>
     */
    public function toPayload(string $message): array
    {
        return [
            'error' => 'rate_limited',
            'message' => $message,
            'retry_after' => $this->retryAfter,
            'reason' => $this->reason,
        ];
    }
}
