<?php

namespace App\Services\Crm;

use App\Database\Connection;
use App\Models\User;
use PDO;

/**
 * Resolves the permission scopes granted to a portal user through their
 * linked site_contacts / billing_contacts rows.
 *
 * A portal user is *any* authenticated User whose row is referenced by a
 * contact's `user_id`. A single person may be linked to multiple contacts
 * (across sites or as both a site + billing contact); this resolver
 * aggregates every active scope they have and exposes convenience queries.
 *
 * The resolver is read-only and caches per-user results in-memory for the
 * lifetime of the request.
 */
class ContactScopeResolver
{
    /**
     * @var array<int, array<int, ContactPermissionScope>>  Keyed by user_id.
     */
    private array $cache = [];

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, ContactPermissionScope>
     */
    public function scopesFor(User $user): array
    {
        $uid = $user->id ?? 0;
        if ($uid <= 0) {
            return [];
        }
        if (array_key_exists($uid, $this->cache)) {
            return $this->cache[$uid];
        }

        $scopes = [];
        $pdo = $this->connection->pdo();

        $siteStmt = $pdo->prepare(
            'SELECT permission_scope, site_id FROM site_contacts
             WHERE user_id = :uid AND is_active = 1'
        );
        $siteStmt->execute(['uid' => $uid]);
        foreach ($siteStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $raw = $row['permission_scope'] !== null
                ? (json_decode((string) $row['permission_scope'], true) ?: [])
                : [];
            // Narrow the scope to this site unless an explicit site_ids override exists
            if (!isset($raw['site_ids'])) {
                $raw['site_ids'] = [(int) $row['site_id']];
            }
            $scopes[] = ContactPermissionScope::fromArray($raw);
        }

        $billingStmt = $pdo->prepare(
            'SELECT bc.permission_scope, bc.company_id
             FROM billing_contacts bc
             WHERE bc.user_id = :uid AND bc.is_active = 1'
        );
        $billingStmt->execute(['uid' => $uid]);
        foreach ($billingStmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $raw = $row['permission_scope'] !== null
                ? (json_decode((string) $row['permission_scope'], true) ?: [])
                : [];
            // Billing contacts default to all company sites unless narrowed.
            if (!isset($raw['site_ids'])) {
                $raw['site_ids'] = $this->siteIdsForCompany((int) $row['company_id']);
            }
            $scopes[] = ContactPermissionScope::fromArray($raw);
        }

        $this->cache[$uid] = $scopes;
        return $scopes;
    }

    /**
     * True if ANY scope attached to this user grants the entitlement and
     * covers the (optional) site in question.
     */
    public function can(User $user, string $entitlement, ?int $siteId = null, ?float $amount = null): bool
    {
        foreach ($this->scopesFor($user) as $scope) {
            if (!$scope->grants($entitlement)) {
                continue;
            }
            if ($siteId !== null && !$scope->coversSite($siteId)) {
                continue;
            }
            if ($amount !== null && !$scope->withinAmount($amount)) {
                continue;
            }
            return true;
        }
        return false;
    }

    /**
     * @return array<int, int>
     */
    private function siteIdsForCompany(int $companyId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM sites WHERE company_id = :cid'
        );
        $stmt->execute(['cid' => $companyId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map(static fn($id) => (int) $id, $rows);
    }
}
