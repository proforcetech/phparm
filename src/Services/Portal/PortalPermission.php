<?php

namespace App\Services\Portal;

/**
 * Phase 2d (Decision C in memory) — portal permission catalog + tier baselines.
 *
 * Why a catalog of slug constants instead of strings spread across services:
 *   * one place to grep when adding a new gated portal action;
 *   * the slugs land in audit logs and JSON scope payloads, so they're
 *     stable contract-wide — typos at a call site become PHPStan errors
 *     instead of silently bypassing a gate.
 *
 * Tier baselines are STRICT subsets — viewer ⊂ requester ⊂ approver ⊂ admin.
 * The PortalPermissionService uses these as the starting set, then applies
 * the per-account scope.permissions.{grant,deny} overlay on top. Keep the
 * tiers monotonic so an admin always covers approver, etc., and a downgrade
 * always strips capability rather than swapping it sideways.
 */
final class PortalPermission
{
    // Read surfaces — every authenticated portal user gets these regardless
    // of tier, but they're listed explicitly so an account can be denied
    // (via scope.permissions.deny) e.g. to hide invoices from a viewer who
    // only handles operational work.
    public const VIEW_DASHBOARD = 'view.dashboard';
    public const VIEW_SITES = 'view.sites';
    public const VIEW_ASSETS = 'view.assets';
    public const VIEW_WORKORDERS = 'view.workorders';
    public const VIEW_INVOICES = 'view.invoices';
    public const VIEW_CONTRACTS = 'view.contracts';
    public const VIEW_ESTIMATES = 'view.estimates';
    public const VIEW_LIFECYCLE = 'view.lifecycle';
    public const VIEW_MESSAGES = 'view.messages';

    // Write surfaces — the gates that distinguish requester from viewer.
    public const CREATE_TICKETS = 'create.tickets';
    public const CREATE_MESSAGES = 'create.messages';
    public const UPLOAD_FILES = 'upload.files';

    // Approval surfaces — the gates that distinguish approver from requester.
    // Splitting pay vs sign vs approve so an account can hold one without
    // the others (e.g. a tech lead who can sign change orders but does not
    // have payment authority).
    public const APPROVE_ESTIMATES = 'approve.estimates';
    public const SIGN_CONTRACTS = 'sign.contracts';
    public const PAY_INVOICES = 'pay.invoices';
    public const MANAGE_PAYMENT_METHODS = 'manage.payment_methods';
    public const DECIDE_LIFECYCLE = 'decide.lifecycle';

    // Admin-only — the gates reserved for the account's owner-level user.
    // Reserved for future Phase 2d.b (sub-user provisioning UI). Keeping
    // the constant defined now so audit slugs don't move once the UI ships.
    public const MANAGE_TEAM = 'manage.team';

    /** @var list<string> */
    public const ALL = [
        self::VIEW_DASHBOARD,
        self::VIEW_SITES,
        self::VIEW_ASSETS,
        self::VIEW_WORKORDERS,
        self::VIEW_INVOICES,
        self::VIEW_CONTRACTS,
        self::VIEW_ESTIMATES,
        self::VIEW_LIFECYCLE,
        self::VIEW_MESSAGES,
        self::CREATE_TICKETS,
        self::CREATE_MESSAGES,
        self::UPLOAD_FILES,
        self::APPROVE_ESTIMATES,
        self::SIGN_CONTRACTS,
        self::PAY_INVOICES,
        self::MANAGE_PAYMENT_METHODS,
        self::DECIDE_LIFECYCLE,
        self::MANAGE_TEAM,
    ];

    public const TIER_VIEWER = 'viewer';
    public const TIER_REQUESTER = 'requester';
    public const TIER_APPROVER = 'approver';
    public const TIER_ADMIN = 'admin';

    /** @var list<string> */
    public const TIERS = [
        self::TIER_VIEWER,
        self::TIER_REQUESTER,
        self::TIER_APPROVER,
        self::TIER_ADMIN,
    ];

    /**
     * Strict-subset tier baselines. Each tier returns the FULL set of
     * permissions it grants (not just the additions over the prior tier),
     * so callers don't have to walk the inheritance chain themselves.
     *
     * @return list<string>
     */
    public static function baselineFor(string $tier): array
    {
        $viewer = [
            self::VIEW_DASHBOARD,
            self::VIEW_SITES,
            self::VIEW_ASSETS,
            self::VIEW_WORKORDERS,
            self::VIEW_INVOICES,
            self::VIEW_CONTRACTS,
            self::VIEW_ESTIMATES,
            self::VIEW_LIFECYCLE,
            self::VIEW_MESSAGES,
        ];
        $requester = array_merge($viewer, [
            self::CREATE_TICKETS,
            self::CREATE_MESSAGES,
            self::UPLOAD_FILES,
        ]);
        $approver = array_merge($requester, [
            self::APPROVE_ESTIMATES,
            self::SIGN_CONTRACTS,
            self::PAY_INVOICES,
            self::MANAGE_PAYMENT_METHODS,
            self::DECIDE_LIFECYCLE,
        ]);
        $admin = array_merge($approver, [
            self::MANAGE_TEAM,
        ]);

        return match ($tier) {
            self::TIER_VIEWER => $viewer,
            self::TIER_REQUESTER => $requester,
            self::TIER_APPROVER => $approver,
            self::TIER_ADMIN => $admin,
            // Unknown tiers fall back to viewer-equivalent so a corrupted
            // row never silently grants more than read-only.
            default => $viewer,
        };
    }

    public static function isValidTier(string $tier): bool
    {
        return in_array($tier, self::TIERS, true);
    }

    public static function isValidPermission(string $permission): bool
    {
        return in_array($permission, self::ALL, true);
    }
}
