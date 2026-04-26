<?php

namespace App\Services\Crm;

use InvalidArgumentException;

/**
 * Value object wrapping the JSON `permission_scope` stored on site_contacts
 * and billing_contacts. Codifies the shape so callers can't sprinkle loose
 * `$scope['whatever'] ?? null` across the codebase.
 *
 * Shape:
 * {
 *   "entitlements": ["estimates.view", "estimates.approve", ...],
 *   "site_ids":     [int, ...],       // optional, scopes the contact to N sites
 *   "max_amount":   float,             // optional cap for approval actions
 *   "allow_portal_login": true,        // optional, gates portal access
 * }
 *
 * A site_contact inherits a single-site scope (their own `site_id`) unless an
 * explicit `site_ids` list is present. A billing_contact defaults to all
 * company sites unless `site_ids` narrows it.
 */
final class ContactPermissionScope
{
    /**
     * Entitlements the scope may grant. Kept closed so typos get caught at
     * write time. Expand as new portal capabilities come online.
     *
     * @var array<int, string>
     */
    public const KNOWN_ENTITLEMENTS = [
        'portal.profile',
        'portal.sites.view',
        'portal.sites.assets.view',
        'portal.tickets.view',
        'portal.tickets.create',
        'portal.workorders.view',
        'portal.estimates.view',
        'portal.estimates.approve',
        'portal.invoices.view',
        'portal.invoices.pay',
        'portal.contracts.view',
        'portal.contracts.sign',
        'portal.documents.view',
        'portal.documents.upload',
        'portal.messages.view',
        'portal.messages.send',
    ];

    /**
     * @param array<int, string> $entitlements
     * @param array<int, int>|null $siteIds
     */
    public function __construct(
        public readonly array $entitlements,
        public readonly ?array $siteIds,
        public readonly ?float $maxAmount,
        public readonly bool $allowPortalLogin,
    ) {
    }

    /**
     * @param array<string, mixed>|null $raw
     */
    public static function fromArray(?array $raw): self
    {
        if ($raw === null) {
            return new self([], null, null, false);
        }

        $entitlements = [];
        if (isset($raw['entitlements'])) {
            if (!is_array($raw['entitlements'])) {
                throw new InvalidArgumentException('entitlements must be an array');
            }
            foreach ($raw['entitlements'] as $e) {
                $e = (string) $e;
                if (!in_array($e, self::KNOWN_ENTITLEMENTS, true)) {
                    throw new InvalidArgumentException("Unknown entitlement '{$e}'");
                }
                $entitlements[] = $e;
            }
        }

        $siteIds = null;
        if (isset($raw['site_ids'])) {
            if (!is_array($raw['site_ids'])) {
                throw new InvalidArgumentException('site_ids must be an array of integers');
            }
            $siteIds = [];
            foreach ($raw['site_ids'] as $id) {
                if (!is_numeric($id) || (int) $id <= 0) {
                    throw new InvalidArgumentException('site_ids must contain positive integers');
                }
                $siteIds[] = (int) $id;
            }
        }

        $maxAmount = null;
        if (isset($raw['max_amount'])) {
            if (!is_numeric($raw['max_amount'])) {
                throw new InvalidArgumentException('max_amount must be numeric');
            }
            $maxAmount = (float) $raw['max_amount'];
        }

        $allowPortalLogin = !empty($raw['allow_portal_login']);

        return new self($entitlements, $siteIds, $maxAmount, $allowPortalLogin);
    }

    public function grants(string $entitlement): bool
    {
        return in_array($entitlement, $this->entitlements, true);
    }

    public function coversSite(int $siteId): bool
    {
        if ($this->siteIds === null) {
            return true; // null = no explicit narrowing
        }
        return in_array($siteId, $this->siteIds, true);
    }

    public function withinAmount(float $amount): bool
    {
        return $this->maxAmount === null || $amount <= $this->maxAmount;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'entitlements' => $this->entitlements,
            'site_ids' => $this->siteIds,
            'max_amount' => $this->maxAmount,
            'allow_portal_login' => $this->allowPortalLogin,
        ];
    }
}
