<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Http\Request;

/**
 * Thin SOC-style logger. Distinct from AuditLogger which records every
 * domain mutation — this one is reserved for events a security analyst
 * should be able to triage. Auto-pulls actor/IP/UA/path from the
 * request when one is supplied; explicit values always win.
 */
class SecurityEventLogger
{
    /**
     * @var array<int, string> Keys that get scrubbed from context before
     * persistence — same intent as AuditLogger::redact_keys but with a
     * smaller default set since security events shouldn't be storing
     * arbitrary user payloads in the first place.
     */
    private const REDACT_KEYS = ['password', 'password_confirmation', 'token', 'totp_code', 'secret'];

    public function __construct(private SecurityEventRepository $repo)
    {
    }

    /**
     * @param array<string, mixed> $options Optional keys: actor (User),
     *   target_user_id (int), ip_address (string), user_agent (string),
     *   request_path (string), context (array), request (Request — for
     *   auto-fill of ip/ua/path).
     */
    public function log(string $eventType, string $severity = SecurityEvent::SEVERITY_INFO, array $options = []): SecurityEvent
    {
        if (!in_array($severity, SecurityEvent::SEVERITIES, true)) {
            $severity = SecurityEvent::SEVERITY_INFO;
        }

        $payload = [
            'event_type' => $eventType,
            'severity' => $severity,
        ];

        $actor = $options['actor'] ?? null;
        if ($actor instanceof User) {
            $payload['actor_user_id'] = $actor->id;
        } elseif (isset($options['actor_user_id'])) {
            $payload['actor_user_id'] = (int) $options['actor_user_id'];
        }

        if (isset($options['target_user_id'])) {
            $payload['target_user_id'] = (int) $options['target_user_id'];
        }

        $request = $options['request'] ?? null;
        if ($request instanceof Request) {
            $payload['ip_address'] = $options['ip_address'] ?? $this->safeIp($request);
            $payload['user_agent'] = $options['user_agent'] ?? $this->safeHeader($request, 'User-Agent');
            $payload['request_path'] = $options['request_path'] ?? $request->path();
        } else {
            if (isset($options['ip_address'])) {
                $payload['ip_address'] = (string) $options['ip_address'];
            }
            if (isset($options['user_agent'])) {
                $payload['user_agent'] = (string) $options['user_agent'];
            }
            if (isset($options['request_path'])) {
                $payload['request_path'] = (string) $options['request_path'];
            }
        }

        if (isset($options['context']) && is_array($options['context'])) {
            $payload['context'] = $this->scrub($options['context']);
        }

        return $this->repo->create($payload);
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function scrub(array $context): array
    {
        $out = [];
        foreach ($context as $k => $v) {
            $key = (string) $k;
            if (in_array(strtolower($key), self::REDACT_KEYS, true)) {
                $out[$key] = '[REDACTED]';
                continue;
            }
            $out[$key] = is_array($v) ? $this->scrub($v) : $v;
        }
        return $out;
    }

    private function safeIp(Request $request): ?string
    {
        try {
            return $request->getClientIp();
        } catch (\Throwable) {
            return null;
        }
    }

    private function safeHeader(Request $request, string $name): ?string
    {
        try {
            return $request->header($name);
        } catch (\Throwable) {
            return null;
        }
    }
}
