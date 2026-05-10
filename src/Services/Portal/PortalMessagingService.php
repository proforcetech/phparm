<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalAccount;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workorder;
use App\Services\Customer\CustomerRepository;
use App\Services\Tickets\TicketRepository;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use PDO;

/**
 * Phase 6.5 of docs/expansion-plan.md — customer portal messaging.
 *
 * Reuses the Phase-051 MessageThread / message_messages tables with a
 * thin portal-facing wrapper that enforces portal scope, filters out
 * staff-internal notes, and tags portal-originated posts.
 *
 * Scope layers (belt+suspenders — Middleware::portalAuth already gates,
 * but each entrypoint re-asserts in case someone invokes the service
 * outside the HTTP path):
 *   * assertUsable(account) rejects revoked/inactive accounts;
 *   * tickets scope via tickets.company_id (direct FK — unlike estimates/
 *     invoices which bridge through customers.company_id); if the ticket
 *     has a site_id, PortalAuthService.assertSiteAccess also runs so an
 *     allowed_site_ids whitelist narrows the view;
 *   * workorders scope via customers.company_id (legacy workorders table
 *     has no company_id column — same bridge Phase 6.3/6.4 use for
 *     estimates/invoices);
 *   * thread scope re-validates linkage: a thread must be linked to a
 *     ticket or workorder that passes the above checks, AND the portal
 *     user must already be a participant. Portal users can START a
 *     thread on their own ticket/workorder; staff join by posting
 *     (MessagingService.postMessage auto-adds the sender as participant
 *     if they weren't already… actually it does NOT, staff adds them
 *     explicitly — but this is a staff-side concern, out of portal scope).
 *
 * Internal-message filtering: listMessages hides rows where
 * is_internal=1. Portal writes are always is_internal=0. Staff still
 * uses the existing MessagingService; when staff marks a message
 * internal (UI flag) the portal simply won't see it.
 */
class PortalMessagingService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly TicketRepository $tickets,
        private readonly WorkorderRepository $workorders,
        private readonly CustomerRepository $customers,
        private readonly PortalAuthService $portalAuth,
        private readonly AuditLogger $audit,
    ) {
    }

    // ── Read: threads ────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listThreadsForTicket(
        User $user,
        PortalAccount $account,
        int $ticketId,
    ): array {
        $this->loadScopedTicket($account, $ticketId);
        return $this->listThreadsForEntity($user, 'ticket_id', $ticketId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listThreadsForWorkorder(
        User $user,
        PortalAccount $account,
        int $workorderId,
    ): array {
        $this->loadScopedWorkorder($account, $workorderId);
        return $this->listThreadsForEntity($user, 'workorder_id', $workorderId);
    }

    /**
     * Phase 2c — standalone inbox aggregating threads across every ticket
     * and workorder owned by the portal_account's company. Same row shape
     * as listThreadsForEntity, plus an entity_kind discriminator so the
     * UI knows whether to deep-link to /p/work-orders/X or a future
     * /p/tickets/X view.
     *
     * Participant filter is preserved: a portal user only sees threads
     * they're already on. This matches the per-entity endpoints and
     * avoids surprising "your coworker's private chat is suddenly in your
     * inbox" leaks if multiple portal users share a company.
     *
     * @param array{limit?: int, offset?: int, unread_only?: bool} $query
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function inbox(User $user, PortalAccount $account, array $query = []): array
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }

        // Resolve the entity sets the portal user's company owns. We pull
        // ids only — the inbox query joins them in via IN(...) clauses,
        // not full row hydration, so this stays cheap even for large
        // companies.
        $ticketIds = $this->ticketIdsForCompany($account->company_id);
        $workorderIds = $this->workorderIdsForCompany($account->company_id);
        if ($ticketIds === [] && $workorderIds === []) {
            return ['data' => [], 'total' => 0];
        }

        $limit = max(1, min(200, (int) ($query['limit'] ?? 50)));
        $offset = max(0, (int) ($query['offset'] ?? 0));
        $unreadOnly = (bool) ($query['unread_only'] ?? false);

        // Build an OR clause across ticket_id and workorder_id IN(...).
        // Either side may be empty so we guard each branch independently.
        $clauses = [];
        $params = ['pid' => $user->id];
        if ($ticketIds !== []) {
            $tPlaceholders = [];
            foreach ($ticketIds as $i => $tid) {
                $key = 'tid_' . $i;
                $tPlaceholders[] = ':' . $key;
                $params[$key] = $tid;
            }
            $clauses[] = 't.ticket_id IN (' . implode(',', $tPlaceholders) . ')';
        }
        if ($workorderIds !== []) {
            $wPlaceholders = [];
            foreach ($workorderIds as $i => $wid) {
                $key = 'wid_' . $i;
                $wPlaceholders[] = ':' . $key;
                $params[$key] = $wid;
            }
            $clauses[] = 't.workorder_id IN (' . implode(',', $wPlaceholders) . ')';
        }
        $entityWhere = '(' . implode(' OR ', $clauses) . ')';
        $unreadFilter = $unreadOnly
            ? ' HAVING unread_count > 0'
            : '';

        $sql = "SELECT t.id, t.subject, t.ticket_id, t.workorder_id, t.created_by,
                       t.created_at, t.updated_at,
                       (SELECT m.body FROM message_messages m
                        WHERE m.thread_id = t.id AND m.is_internal = 0
                        ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message,
                       (SELECT m.created_at FROM message_messages m
                        WHERE m.thread_id = t.id AND m.is_internal = 0
                        ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message_at,
                       (SELECT COUNT(*)
                        FROM message_messages m
                        LEFT JOIN message_reads r
                            ON r.thread_id = t.id AND r.participant_id = :pid
                        WHERE m.thread_id = t.id
                          AND m.is_internal = 0
                          AND m.sender_id != :pid
                          AND (r.last_read_message_id IS NULL
                               OR m.id > r.last_read_message_id)
                       ) AS unread_count
                FROM message_threads t
                JOIN message_participants p ON p.thread_id = t.id
                    AND p.participant_id = :pid
                WHERE {$entityWhere}
                {$unreadFilter}
                ORDER BY COALESCE(last_message_at, t.created_at) DESC";

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $total = count($rows);
        $page = array_slice($rows, $offset, $limit);

        return [
            'data' => array_map(
                function (array $row) {
                    $serialized = $this->serializeThread($row);
                    $serialized['entity_kind'] = $row['workorder_id'] !== null
                        ? 'workorder'
                        : ($row['ticket_id'] !== null ? 'ticket' : 'standalone');
                    return $serialized;
                },
                $page,
            ),
            'total' => $total,
        ];
    }

    /**
     * @return array<int, int>
     */
    private function ticketIdsForCompany(int $companyId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM tickets WHERE company_id = :cid'
        );
        $stmt->execute(['cid' => $companyId]);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    /**
     * @return array<int, int>
     */
    private function workorderIdsForCompany(int $companyId): array
    {
        $customerIds = $this->customers->listIdsForCompany($companyId);
        if ($customerIds === []) {
            return [];
        }
        $placeholders = [];
        $bindings = [];
        foreach ($customerIds as $i => $cid) {
            $key = 'cid_' . $i;
            $placeholders[] = ':' . $key;
            $bindings[$key] = $cid;
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM workorders WHERE customer_id IN ('
            . implode(',', $placeholders) . ')'
        );
        $stmt->execute($bindings);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    }

    // ── Read: messages ───────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMessages(
        User $user,
        PortalAccount $account,
        int $threadId,
    ): array {
        $this->loadScopedThread($user, $account, $threadId);

        $stmt = $this->connection->pdo()->prepare(
            'SELECT m.id, m.thread_id, m.sender_id, m.body, m.is_internal,
                    m.portal_account_id, m.created_at
             FROM message_messages m
             WHERE m.thread_id = :thread_id
               AND m.is_internal = 0
             ORDER BY m.created_at ASC, m.id ASC'
        );
        $stmt->execute(['thread_id' => $threadId]);

        return array_map(
            fn(array $row) => $this->serializeMessage($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    // ── Write: start thread ──────────────────────────────────────────────

    /**
     * Start a new thread linked to a ticket. The portal user is added as
     * the only initial participant; staff join by being added when they
     * respond (done on the staff side — portal never enumerates or adds
     * staff participants). An initial message body is required so the
     * resulting thread always has something to render.
     *
     * @return array<string, mixed>
     */
    public function startThreadForTicket(
        User $user,
        PortalAccount $account,
        int $ticketId,
        string $body,
        ?string $subject = null,
    ): array {
        $ticket = $this->loadScopedTicket($account, $ticketId);
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('message body is required');
        }
        if (strlen($body) > 20000) {
            throw new InvalidArgumentException('message body exceeds 20000 chars');
        }
        $subject = $this->sanitizeSubject($subject, $ticket->title);

        return $this->createThreadAndPost(
            $user, $account,
            ['ticket_id' => $ticket->id, 'workorder_id' => null],
            $subject, $body,
            [
                'entity_type' => 'ticket',
                'entity_id' => $ticket->id,
                'company_id' => (int) ($ticket->company_id ?? 0),
                'site_id' => $ticket->site_id,
            ],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function startThreadForWorkorder(
        User $user,
        PortalAccount $account,
        int $workorderId,
        string $body,
        ?string $subject = null,
    ): array {
        $wo = $this->loadScopedWorkorder($account, $workorderId);
        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('message body is required');
        }
        if (strlen($body) > 20000) {
            throw new InvalidArgumentException('message body exceeds 20000 chars');
        }
        $subject = $this->sanitizeSubject($subject, 'WO-' . $wo->number);

        return $this->createThreadAndPost(
            $user, $account,
            ['ticket_id' => null, 'workorder_id' => $wo->id],
            $subject, $body,
            [
                'entity_type' => 'workorder',
                'entity_id' => $wo->id,
                'customer_id' => $wo->customer_id,
            ],
        );
    }

    // ── Write: post message + mark read ──────────────────────────────────

    /**
     * Post a reply to an existing thread the portal user participates in.
     *
     * @return array<string, mixed>
     */
    public function postMessage(
        User $user,
        PortalAccount $account,
        int $threadId,
        string $body,
    ): array {
        $thread = $this->loadScopedThread($user, $account, $threadId);

        $body = trim($body);
        if ($body === '') {
            throw new InvalidArgumentException('message body is required');
        }
        if (strlen($body) > 20000) {
            throw new InvalidArgumentException('message body exceeds 20000 chars');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $insert = $pdo->prepare(
                'INSERT INTO message_messages
                    (thread_id, sender_id, body, is_internal, portal_account_id, created_at)
                 VALUES (:tid, :sid, :body, 0, :pid, CURRENT_TIMESTAMP)'
            );
            $insert->execute([
                'tid' => $threadId,
                'sid' => $user->id,
                'body' => $body,
                'pid' => $account->id,
            ]);
            $messageId = (int) $pdo->lastInsertId();

            $touch = $pdo->prepare(
                'UPDATE message_threads SET updated_at = CURRENT_TIMESTAMP WHERE id = :tid'
            );
            $touch->execute(['tid' => $threadId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'portal.message.posted',
            'message_thread',
            $threadId,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'message_id' => $messageId,
                'ticket_id' => $thread['ticket_id'],
                'workorder_id' => $thread['workorder_id'],
                'body_length' => strlen($body),
            ]
        ));

        return $this->fetchMessageById($messageId);
    }

    public function markRead(User $user, PortalAccount $account, int $threadId): void
    {
        $this->loadScopedThread($user, $account, $threadId);

        $pdo = $this->connection->pdo();
        $latest = $pdo->prepare(
            'SELECT id, created_at FROM message_messages
             WHERE thread_id = :tid AND is_internal = 0
             ORDER BY created_at DESC, id DESC LIMIT 1'
        );
        $latest->execute(['tid' => $threadId]);
        $row = $latest->fetch(PDO::FETCH_ASSOC) ?: [];
        $lastId = $row['id'] ?? null;
        $lastAt = $row['created_at'] ?? null;

        $exists = $pdo->prepare(
            'SELECT id FROM message_reads
             WHERE thread_id = :tid AND participant_id = :pid'
        );
        $exists->execute(['tid' => $threadId, 'pid' => $user->id]);
        $existingId = $exists->fetchColumn();

        if ($existingId) {
            $upd = $pdo->prepare(
                'UPDATE message_reads
                 SET last_read_message_id = :mid, last_read_at = :at, updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id'
            );
            $upd->execute([
                'mid' => $lastId, 'at' => $lastAt, 'id' => $existingId,
            ]);
            return;
        }
        $ins = $pdo->prepare(
            'INSERT INTO message_reads
                (thread_id, participant_id, last_read_message_id, last_read_at, created_at)
             VALUES (:tid, :pid, :mid, :at, CURRENT_TIMESTAMP)'
        );
        $ins->execute([
            'tid' => $threadId,
            'pid' => $user->id,
            'mid' => $lastId,
            'at' => $lastAt,
        ]);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /**
     * @return array<int, array<string, mixed>>
     */
    private function listThreadsForEntity(User $user, string $column, int $entityId): array
    {
        if ($column !== 'ticket_id' && $column !== 'workorder_id') {
            throw new InvalidArgumentException('column must be ticket_id or workorder_id');
        }
        $stmt = $this->connection->pdo()->prepare(
            "SELECT t.id, t.subject, t.ticket_id, t.workorder_id, t.created_by,
                    t.created_at, t.updated_at,
                    (SELECT m.body FROM message_messages m
                     WHERE m.thread_id = t.id AND m.is_internal = 0
                     ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message,
                    (SELECT m.created_at FROM message_messages m
                     WHERE m.thread_id = t.id AND m.is_internal = 0
                     ORDER BY m.created_at DESC, m.id DESC LIMIT 1) AS last_message_at,
                    (SELECT COUNT(*)
                     FROM message_messages m
                     LEFT JOIN message_reads r
                         ON r.thread_id = t.id AND r.participant_id = :pid
                     WHERE m.thread_id = t.id
                       AND m.is_internal = 0
                       AND m.sender_id != :pid
                       AND (r.last_read_message_id IS NULL
                            OR m.id > r.last_read_message_id)
                    ) AS unread_count
             FROM message_threads t
             JOIN message_participants p ON p.thread_id = t.id
                 AND p.participant_id = :pid
             WHERE t.{$column} = :eid
             ORDER BY COALESCE(last_message_at, t.created_at) DESC"
        );
        $stmt->execute(['pid' => $user->id, 'eid' => $entityId]);

        return array_map(
            fn(array $row) => $this->serializeThread($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array{ticket_id: ?int, workorder_id: ?int} $link
     * @param array<string, mixed> $auditContext
     * @return array<string, mixed>
     */
    private function createThreadAndPost(
        User $user,
        PortalAccount $account,
        array $link,
        ?string $subject,
        string $body,
        array $auditContext,
    ): array {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $ins = $pdo->prepare(
                'INSERT INTO message_threads
                    (subject, ticket_id, workorder_id, created_by, created_at)
                 VALUES (:subj, :tid, :wid, :uid, CURRENT_TIMESTAMP)'
            );
            $ins->execute([
                'subj' => $subject,
                'tid' => $link['ticket_id'],
                'wid' => $link['workorder_id'],
                'uid' => $user->id,
            ]);
            $threadId = (int) $pdo->lastInsertId();

            $part = $pdo->prepare(
                'INSERT INTO message_participants
                    (thread_id, participant_id, created_at)
                 VALUES (:tid, :uid, CURRENT_TIMESTAMP)'
            );
            $part->execute(['tid' => $threadId, 'uid' => $user->id]);

            $msg = $pdo->prepare(
                'INSERT INTO message_messages
                    (thread_id, sender_id, body, is_internal, portal_account_id, created_at)
                 VALUES (:tid, :sid, :body, 0, :pid, CURRENT_TIMESTAMP)'
            );
            $msg->execute([
                'tid' => $threadId,
                'sid' => $user->id,
                'body' => $body,
                'pid' => $account->id,
            ]);
            $messageId = (int) $pdo->lastInsertId();

            $touch = $pdo->prepare(
                'UPDATE message_threads SET updated_at = CURRENT_TIMESTAMP WHERE id = :tid'
            );
            $touch->execute(['tid' => $threadId]);

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'portal.message.thread_started',
            'message_thread',
            $threadId,
            $user->id,
            array_merge([
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'message_id' => $messageId,
                'ticket_id' => $link['ticket_id'],
                'workorder_id' => $link['workorder_id'],
            ], $auditContext)
        ));

        return [
            'id' => $threadId,
            'subject' => $subject,
            'ticket_id' => $link['ticket_id'],
            'workorder_id' => $link['workorder_id'],
            'created_by' => $user->id,
            'initial_message' => $this->fetchMessageById($messageId),
        ];
    }

    /**
     * Load a thread the portal user is allowed to see: thread must be
     * linked to a ticket or workorder that passes scope, AND the portal
     * user must be a participant on the thread.
     *
     * @return array<string, mixed>
     */
    private function loadScopedThread(User $user, PortalAccount $account, int $threadId): array
    {
        $this->assertUsable($account);
        if ($threadId <= 0) {
            throw new InvalidArgumentException('thread id is required');
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, subject, ticket_id, workorder_id, created_by, created_at, updated_at
             FROM message_threads WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $threadId]);
        $thread = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($thread === false) {
            throw new InvalidArgumentException("thread {$threadId} not found");
        }

        // Thread must link to at least one entity the portal user can see.
        $linked = false;
        $ticketId = $thread['ticket_id'] !== null ? (int) $thread['ticket_id'] : null;
        $workorderId = $thread['workorder_id'] !== null ? (int) $thread['workorder_id'] : null;
        if ($ticketId !== null) {
            $this->loadScopedTicket($account, $ticketId);
            $linked = true;
        }
        if ($workorderId !== null) {
            $this->loadScopedWorkorder($account, $workorderId);
            $linked = true;
        }
        if (!$linked) {
            // A thread with no ticket/workorder link is staff-only by
            // definition — portal has no business seeing it.
            throw new UnauthorizedException(
                'thread is not linked to a portal-accessible ticket or workorder'
            );
        }

        // Portal user must already be a participant. Portal never adds
        // itself to arbitrary threads — start a new one instead.
        $check = $this->connection->pdo()->prepare(
            'SELECT 1 FROM message_participants
             WHERE thread_id = :tid AND participant_id = :uid LIMIT 1'
        );
        $check->execute(['tid' => $threadId, 'uid' => $user->id]);
        if (!$check->fetchColumn()) {
            throw new UnauthorizedException(
                'user is not a participant in this thread'
            );
        }

        return [
            'id' => (int) $thread['id'],
            'subject' => $thread['subject'],
            'ticket_id' => $ticketId,
            'workorder_id' => $workorderId,
            'created_by' => (int) $thread['created_by'],
            'created_at' => $thread['created_at'],
            'updated_at' => $thread['updated_at'],
        ];
    }

    private function loadScopedTicket(PortalAccount $account, int $ticketId): Ticket
    {
        $this->assertUsable($account);
        if ($ticketId <= 0) {
            throw new InvalidArgumentException('ticket id is required');
        }
        $ticket = $this->tickets->findById($ticketId);
        if ($ticket === null) {
            throw new InvalidArgumentException("ticket {$ticketId} not found");
        }
        if ((int) ($ticket->company_id ?? 0) !== $account->company_id) {
            throw new UnauthorizedException(
                'ticket belongs to a different company'
            );
        }
        if ($ticket->site_id !== null) {
            $this->portalAuth->assertSiteAccess($account, (int) $ticket->site_id);
        }
        return $ticket;
    }

    private function loadScopedWorkorder(PortalAccount $account, int $workorderId): Workorder
    {
        $this->assertUsable($account);
        if ($workorderId <= 0) {
            throw new InvalidArgumentException('workorder id is required');
        }
        $wo = $this->workorders->find($workorderId);
        if ($wo === null) {
            throw new InvalidArgumentException("workorder {$workorderId} not found");
        }
        $customer = $this->customers->find($wo->customer_id);
        if ($customer === null
            || (int) ($customer->company_id ?? 0) !== $account->company_id
        ) {
            throw new UnauthorizedException(
                'workorder belongs to a different company'
            );
        }
        return $wo;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchMessageById(int $messageId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id, thread_id, sender_id, body, is_internal, portal_account_id, created_at
             FROM message_messages WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $messageId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new \RuntimeException("message {$messageId} vanished after insert");
        }
        return $this->serializeMessage($row);
    }

    private function sanitizeSubject(?string $subject, string $fallback): ?string
    {
        $subject = $subject !== null ? trim($subject) : '';
        if ($subject === '') {
            $subject = trim($fallback);
        }
        if ($subject === '') {
            return null;
        }
        if (strlen($subject) > 255) {
            $subject = substr($subject, 0, 255);
        }
        return $subject;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeMessage(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'thread_id' => (int) $row['thread_id'],
            'sender_id' => (int) $row['sender_id'],
            'body' => (string) $row['body'],
            'is_from_portal' => $row['portal_account_id'] !== null,
            'created_at' => $row['created_at'] ?? null,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function serializeThread(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'subject' => $row['subject'],
            'ticket_id' => $row['ticket_id'] !== null ? (int) $row['ticket_id'] : null,
            'workorder_id' => $row['workorder_id'] !== null ? (int) $row['workorder_id'] : null,
            'created_by' => (int) $row['created_by'],
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'last_message' => $row['last_message'] ?? null,
            'last_message_at' => $row['last_message_at'] ?? null,
            'unread_count' => (int) ($row['unread_count'] ?? 0),
        ];
    }
}
