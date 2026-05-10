<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalAccount;
use App\Models\User;
use App\Services\Customer\CustomerRepository;
use App\Support\Auth\UnauthorizedException;
use PDO;

/**
 * Phase 2f — portal-side audit trail.
 *
 * Read-only window over `audit_logs` scoped to entities the portal_account
 * legitimately owns. The visibility model is the union of:
 *
 *   - Domain rows scoped via customers in the account's company:
 *       workorders, invoices, estimates  (joined by customer_id)
 *   - Domain rows scoped via the company directly:
 *       contracts, tickets               (have company_id)
 *   - Portal-account self rows:
 *       portal_account, portal_csat_response,
 *       portal_notification_preference, portal_api_token
 *       (all FK back to portal_accounts.id)
 *
 * Sanitization: actor_id is stripped and the context is shown verbatim
 * minus a small denylist (anything that looks like a token/secret/IP).
 * We don't want to leak which staff user took an action — that's noise
 * for the customer and could be used for harassment.
 *
 * Performance: each entity_type's ID set is queried once per request and
 * capped at 2000 rows. For accounts that exceed that cap the audit
 * timeline is best-effort (newer entries first). Tables are indexed on
 * (entity_type, entity_id) so the IN clause stays cheap.
 */
class PortalAuditService
{
    private const ID_CAP = 2000;
    private const ROW_LIMIT = 200;

    /**
     * Context keys we never echo back to a portal user — they may carry
     * staff PII, raw OIDC payloads, IPs of other portal users in the
     * company, or token fingerprints. The denylist is a substring match
     * to catch nested keys like `*_token`, `*_secret`, etc.
     */
    private const REDACT_KEY_SUBSTRINGS = [
        'token', 'secret', 'password', 'hash', 'key',
        'ip_address', 'ip', 'user_agent', 'authorization',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly CustomerRepository $customers,
    ) {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForAccount(User $user, PortalAccount $account, int $limit = self::ROW_LIMIT): array
    {
        $this->assertUsable($account);
        $limit = max(1, min(500, $limit));

        $customerIds = $this->customers->listIdsForCompany($account->company_id);

        $woIds = $this->idsByCustomer('workorders', $customerIds);
        $invoiceIds = $this->idsByCustomer('invoices', $customerIds);
        $estimateIds = $this->idsByCustomer('estimates', $customerIds);
        $contractIds = $this->idsByCompany('contracts', $account->company_id);
        $ticketIds = $this->idsByCompany('tickets', $account->company_id);
        $csatIds = $this->idsByPortalAccount('portal_csat_responses', $account->id);
        $notifIds = $this->idsByPortalAccount('portal_notification_preferences', $account->id);
        $tokenIds = $this->idsByPortalAccount('portal_api_tokens', $account->id);

        $clauses = [];
        $params = [];

        $this->appendInClause($clauses, $params, 'workorder', $woIds, 'wo');
        $this->appendInClause($clauses, $params, 'invoice', $invoiceIds, 'inv');
        $this->appendInClause($clauses, $params, 'estimate', $estimateIds, 'est');
        $this->appendInClause($clauses, $params, 'contract', $contractIds, 'con');
        $this->appendInClause($clauses, $params, 'ticket', $ticketIds, 'tic');
        $this->appendInClause($clauses, $params, 'portal_csat_response', $csatIds, 'csat');
        $this->appendInClause($clauses, $params, 'portal_notification_preference', $notifIds, 'np');
        $this->appendInClause($clauses, $params, 'portal_api_token', $tokenIds, 'pat');

        // The portal_account self-row is always visible to its owner.
        $clauses[] = '(entity_type = :pa_type AND entity_id = :pa_id)';
        $params['pa_type'] = 'portal_account';
        $params['pa_id'] = (string) $account->id;

        $sql = 'SELECT id, event, entity_type, entity_id, context, created_at
                FROM audit_logs
                WHERE ' . implode(' OR ', $clauses) . '
                ORDER BY created_at DESC, id DESC
                LIMIT ' . $limit;

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        $entries = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $context = $this->redactContext(
                json_decode((string) ($row['context'] ?? '[]'), true) ?: []
            );
            $entries[] = [
                'id' => (int) $row['id'],
                'event' => $row['event'],
                'entity_type' => $row['entity_type'],
                'entity_id' => $row['entity_id'],
                'context' => $context,
                'created_at' => $row['created_at'],
            ];
        }
        return $entries;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    /**
     * @param array<int, int> $customerIds
     * @return array<int, int>
     */
    private function idsByCustomer(string $table, array $customerIds): array
    {
        if ($customerIds === []) {
            return [];
        }
        $placeholders = $this->placeholders($customerIds, 'c');
        $sql = "SELECT id FROM {$table} WHERE customer_id IN ({$placeholders})
                ORDER BY id DESC LIMIT " . self::ID_CAP;
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($this->bindList($customerIds, 'c'));
        return $this->intColumn($stmt);
    }

    /**
     * @return array<int, int>
     */
    private function idsByCompany(string $table, int $companyId): array
    {
        $sql = "SELECT id FROM {$table} WHERE company_id = :cid
                ORDER BY id DESC LIMIT " . self::ID_CAP;
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['cid' => $companyId]);
        return $this->intColumn($stmt);
    }

    /**
     * @return array<int, int>
     */
    private function idsByPortalAccount(string $table, int $accountId): array
    {
        $sql = "SELECT id FROM {$table} WHERE portal_account_id = :a
                ORDER BY id DESC LIMIT " . self::ID_CAP;
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['a' => $accountId]);
        return $this->intColumn($stmt);
    }

    /**
     * @param array<int, int> $ids
     * @param array<string, mixed> $params (mutated)
     * @param array<int, string> $clauses (mutated)
     */
    private function appendInClause(array &$clauses, array &$params, string $entityType, array $ids, string $prefix): void
    {
        if ($ids === []) {
            return;
        }
        $typeKey = $prefix . '_type';
        $params[$typeKey] = $entityType;
        $placeholders = $this->placeholders($ids, $prefix);
        // entity_id column is VARCHAR; cast to string when binding so the
        // index is usable rather than relying on MySQL implicit coercion.
        foreach (array_values($ids) as $i => $id) {
            $params[$prefix . $i] = (string) $id;
        }
        $clauses[] = "(entity_type = :{$typeKey} AND entity_id IN ({$placeholders}))";
    }

    /**
     * @param array<int, int> $ids
     */
    private function placeholders(array $ids, string $prefix): string
    {
        $names = [];
        foreach (array_values($ids) as $i => $_) {
            $names[] = ':' . $prefix . $i;
        }
        return implode(', ', $names);
    }

    /**
     * @param array<int, int> $ids
     * @return array<string, int>
     */
    private function bindList(array $ids, string $prefix): array
    {
        $out = [];
        foreach (array_values($ids) as $i => $v) {
            $out[$prefix . $i] = (int) $v;
        }
        return $out;
    }

    /**
     * @return array<int, int>
     */
    private function intColumn(\PDOStatement $stmt): array
    {
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = (int) $row['id'];
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function redactContext(array $context): array
    {
        $denylist = self::REDACT_KEY_SUBSTRINGS;
        array_walk_recursive($context, function (&$value, $key) use ($denylist) {
            if (!is_string($key)) {
                return;
            }
            $lower = strtolower($key);
            foreach ($denylist as $needle) {
                if (str_contains($lower, $needle)) {
                    $value = '[REDACTED]';
                    return;
                }
            }
        });
        return $context;
    }
}
