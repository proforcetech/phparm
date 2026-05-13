<?php

namespace App\Support\Http;

use App\Database\Connection;
use App\Services\Notification\PushNotificationService;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\PolicyRegistry;
use App\Support\Auth\RolePermissions;
use App\Support\Security\PublicLinkRateLimiter;
use App\Support\SettingsRepository;

/**
 * Shared bootstrap dependencies passed to per-module route files in
 * routes/modules/. Establishes the pattern for splitting the monolithic
 * routes/api.php as described in docs/expansion-plan.md Phase 0.1.
 *
 * $policies is the PolicyRegistry attached to $gate (Phase 0.3). Modules
 * that need contextual permission checks register policies on it during
 * their setup phase.
 */
final class RouteContext
{
    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly Connection $connection,
        public readonly array $config,
        public readonly AccessGate $gate,
        public readonly RolePermissions $rolePermissions,
        public readonly AuditLogger $auditLogger,
        public readonly PushNotificationService $pushNotifications,
        public readonly SettingsRepository $settingsRepository,
        public readonly PolicyRegistry $policies,
        public readonly ?PublicLinkRateLimiter $publicLinkLimiter = null,
    ) {
    }
}
