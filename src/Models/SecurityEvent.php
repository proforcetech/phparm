<?php

namespace App\Models;

class SecurityEvent extends BaseModel
{
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_CRITICAL = 'critical';

    public const SEVERITIES = [
        self::SEVERITY_INFO,
        self::SEVERITY_WARNING,
        self::SEVERITY_CRITICAL,
    ];

    // Standard SOC event type constants. Free-form strings are still
    // accepted at the logger so callers can record domain-specific events
    // without first registering them here, but using these constants
    // keeps the common ones consistent across writers and dashboards.
    public const EVENT_LOGIN_SUCCESS = 'login.success';
    public const EVENT_LOGIN_FAILURE = 'login.failure';
    public const EVENT_LOGOUT = 'logout';
    public const EVENT_PASSWORD_RESET_REQUESTED = 'password_reset.requested';
    public const EVENT_PASSWORD_RESET_COMPLETED = 'password_reset.completed';
    public const EVENT_PASSWORD_CHANGED = 'password.changed';
    public const EVENT_TWO_FACTOR_ENABLED = 'two_factor.enabled';
    public const EVENT_TWO_FACTOR_DISABLED = 'two_factor.disabled';
    public const EVENT_TWO_FACTOR_FAILED = 'two_factor.failed';
    public const EVENT_ACCOUNT_LOCKED = 'account.locked';
    public const EVENT_ACCOUNT_UNLOCKED = 'account.unlocked';
    public const EVENT_PRIVILEGE_GRANTED = 'privilege.granted';
    public const EVENT_PRIVILEGE_REVOKED = 'privilege.revoked';
    public const EVENT_ROLE_CHANGED = 'role.changed';
    public const EVENT_ADMIN_ACTION = 'admin.action';
    public const EVENT_RATE_LIMIT_EXCEEDED = 'rate_limit.exceeded';
    public const EVENT_SUSPICIOUS_REQUEST = 'suspicious.request';
    public const EVENT_API_KEY_CREATED = 'api_key.created';
    public const EVENT_API_KEY_REVOKED = 'api_key.revoked';
    public const EVENT_DATA_EXPORT = 'data.export';
    public const EVENT_DATA_RETENTION_RUN = 'data_retention.run';

    public ?int $id = null;
    public string $event_type = '';
    public string $severity = self::SEVERITY_INFO;
    public ?int $actor_user_id = null;
    public ?int $target_user_id = null;
    public ?string $ip_address = null;
    public ?string $user_agent = null;
    public ?string $request_path = null;
    public ?array $context = null;
    public ?string $created_at = null;
}
