<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketCloseReason;
use App\Models\TicketEscalationRule;
use App\Models\TicketEvent;
use App\Models\TicketFailureCode;
use App\Models\TicketQueue;
use App\Models\TicketResolutionCode;
use App\Models\TicketRoutingRule;
use App\Models\TicketSlaPolicy;
use App\Models\TicketWorkorderLink;
use App\Models\User;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Crm\SiteRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Support-desk ticket CRUD (Phase 3.1 of docs/expansion-plan.md).
 *
 * Gates:
 *   - tickets.view / tickets.manage — general access
 *   - tickets.assign                — reassigning to a different user/queue
 *   - ticket_categories.view / ticket_categories.manage — catalog ops
 *
 * Every mutation appends a `ticket_events` row *and* a generic `audit_logs`
 * entry. The timeline is user-visible; audit_logs is the tamper-evident
 * system record (Phase 1.4).
 */
class TicketController
{
    public const STATUSES = [
        'new', 'triaged', 'assigned', 'in_progress',
        'pending_customer', 'resolved', 'closed', 'cancelled',
    ];

    public const PRIORITIES = ['p1_critical', 'p2_high', 'p3_normal', 'p4_low'];

    public const SOURCES = ['manual', 'portal', 'email', 'phone', 'scan', 'sms', 'api'];

    /**
     * Statuses that put a ticket into a terminal state — they stamp
     * resolved_at/closed_at automatically and require close metadata.
     */
    public const TERMINAL_STATUSES = ['resolved', 'closed', 'cancelled'];

    public function __construct(
        private readonly TicketRepository $tickets,
        private readonly TicketCategoryRepository $categories,
        private readonly TicketEventRepository $events,
        private readonly SiteRepository $sites,
        private readonly SiteAssetRepository $assets,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
        private readonly SlaClockService $sla,
        private readonly TicketSlaPolicyRepository $slaPolicies,
        private readonly TicketRoutingService $routing,
        private readonly TicketRoutingRuleRepository $routingRules,
        private readonly TicketQueueRepository $queues,
        private readonly TicketEscalationRuleRepository $escalationRules,
        private readonly TicketWorkorderLinkRepository $workorderLinks,
        private readonly TicketCloseReasonRepository $closeReasons,
        private readonly TicketResolutionCodeRepository $resolutionCodes,
        private readonly TicketFailureCodeRepository $failureCodes,
        private readonly ItHelpdeskService $itHelpdesk,
    ) {
    }

    // ───────────────────────────────────────── Categories ───────────────────

    /**
     * @return array<string, mixed>
     */
    public function listCategories(User $user, ?int $divisionId, bool $includeInactive): array
    {
        $this->gate->assert($user, 'ticket_categories.view');
        $cats = $this->categories->listAll($divisionId, !$includeInactive);
        return ['data' => array_map(static fn(TicketCategory $c) => $c->toArray(), $cats)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createCategory(User $user, array $body): array
    {
        $this->gate->assert($user, 'ticket_categories.manage');
        $this->assertCategoryFields($body);
        if (!empty($body['parent_id'])) {
            $parent = $this->categories->findById((int) $body['parent_id']);
            if ($parent === null) {
                throw new InvalidArgumentException('parent_id not found');
            }
            if ($parent->parent_id !== null) {
                throw new InvalidArgumentException('categories may nest only one level');
            }
        }
        $cat = $this->categories->create($body);
        $this->logAudit('ticket_category.created', 'ticket_category', $cat->id, $user, [
            'code' => $cat->code,
        ]);
        return ['data' => $cat->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateCategory(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'ticket_categories.manage');
        $cat = $this->categories->update($id, $body);
        $this->logAudit('ticket_category.updated', 'ticket_category', $id, $user, [
            'fields' => array_keys($body),
        ]);
        return ['data' => $cat->toArray()];
    }

    public function deleteCategory(User $user, int $id): void
    {
        $this->gate->assert($user, 'ticket_categories.manage');
        $this->categories->delete($id);
        $this->logAudit('ticket_category.deleted', 'ticket_category', $id, $user, []);
    }

    // ───────────────────────────────────────── Tickets ──────────────────────

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listTickets(User $user, array $filters): array
    {
        $this->gate->assert($user, 'tickets.view');
        $result = $this->tickets->search($filters);
        return [
            'data' => array_map(static fn(Ticket $t) => $t->toArray(), $result['data']),
            'meta' => ['total' => $result['total']],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getTicket(User $user, int $id): array
    {
        $this->gate->assert($user, 'tickets.view');
        $ticket = $this->tickets->findById($id);
        if ($ticket === null) {
            throw new InvalidArgumentException("Ticket {$id} not found");
        }
        $children = $this->tickets->listChildren($id);
        $timeline = $this->events->listForTicket($id, true);
        $links = $this->workorderLinks->listForTicket($id);
        return [
            'data' => [
                'ticket' => $ticket->toArray(),
                'children' => array_map(static fn(Ticket $t) => $t->toArray(), $children),
                'timeline' => array_map(static fn(TicketEvent $e) => $e->toArray(), $timeline),
                'workorder_links' => array_map(static fn(TicketWorkorderLink $l) => $l->toArray(), $links),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createTicket(User $user, array $body): array
    {
        $this->gate->assert($user, 'tickets.manage');
        $this->assertTicketFields($body, isCreate: true);

        if (!empty($body['site_id']) && $this->sites->findById((int) $body['site_id']) === null) {
            throw new InvalidArgumentException("Site {$body['site_id']} not found");
        }
        if (!empty($body['asset_id']) && $this->assets->findById((int) $body['asset_id']) === null) {
            throw new InvalidArgumentException("Asset {$body['asset_id']} not found");
        }
        if (!empty($body['category_id'])) {
            $cat = $this->categories->findById((int) $body['category_id']);
            if ($cat === null) {
                throw new InvalidArgumentException('category_id not found');
            }
            // Apply default priority if caller didn't specify one.
            if (empty($body['priority'])) {
                $body['priority'] = $cat->default_priority;
            }
        }
        if (!empty($body['subcategory_id'])) {
            $sub = $this->categories->findById((int) $body['subcategory_id']);
            if ($sub === null) {
                throw new InvalidArgumentException('subcategory_id not found');
            }
            if ($sub->parent_id === null) {
                throw new InvalidArgumentException('subcategory_id must point to a nested category');
            }
        }
        if (!empty($body['parent_ticket_id'])) {
            $parent = $this->tickets->findById((int) $body['parent_ticket_id']);
            if ($parent === null) {
                throw new InvalidArgumentException('parent_ticket_id not found');
            }
            // One level deep — no grandchildren.
            if ($parent->parent_ticket_id !== null) {
                throw new InvalidArgumentException('parent_ticket_id must not itself have a parent');
            }
        }

        // IT-helpdesk overlay (no-op for non-IT tickets). May enrich severity
        // and validate business_impact / it_request_kind / affected count.
        $body = $this->itHelpdesk->applyRules($body, existing: null);

        $ticket = $this->tickets->create($body + ['reported_by_user_id' => $user->id ?? null]);
        $this->events->create([
            'ticket_id' => $ticket->id,
            'event_kind' => 'created',
            'actor_user_id' => $user->id ?? null,
            'is_internal' => 1,
            'payload' => [
                'priority' => $ticket->priority,
                'status' => $ticket->status,
                'category_id' => $ticket->category_id,
            ],
        ]);

        // Auto-routing: apply the first matching rule (caller can still
        // pre-set queue_id / assigned_to_user_id to opt out of rule actions
        // for the fields they explicitly provided).
        $routed = $this->routing->routeTicket($ticket);
        if ($routed !== null && $routed['actions'] !== []) {
            $pending = [];
            foreach ($routed['actions'] as $field => $value) {
                // Don't overwrite explicit caller choices.
                if (array_key_exists($field, $body) && $body[$field] !== null && $body[$field] !== '') {
                    continue;
                }
                $pending[$field] = $value;
            }
            if ($pending !== []) {
                $ticket = $this->tickets->update($ticket->id, $pending);
                $this->events->create([
                    'ticket_id' => $ticket->id,
                    'event_kind' => 'auto_routed',
                    'actor_user_id' => $user->id ?? null,
                    'payload' => [
                        'rule_id' => $routed['rule']->id,
                        'rule_name' => $routed['rule']->name,
                        'applied' => $pending,
                    ],
                ]);
            }
        }

        $resolvedPolicy = $this->sla->resolvePolicy($ticket);
        $this->sla->attachForTicket($ticket);
        if ($resolvedPolicy !== null && $resolvedPolicy['source'] === 'contract') {
            $this->events->create([
                'ticket_id' => $ticket->id,
                'event_kind' => 'sla_tier_contract',
                'actor_user_id' => $user->id ?? null,
                'payload' => [
                    'policy_id' => $resolvedPolicy['policy']->id,
                    'policy_name' => $resolvedPolicy['policy']->name,
                    'contract_id' => $resolvedPolicy['contract_id'],
                    'entitlement_id' => $resolvedPolicy['entitlement_id'],
                    'response_minutes' => $resolvedPolicy['policy']->response_minutes,
                    'arrival_minutes' => $resolvedPolicy['policy']->arrival_minutes,
                    'resolution_minutes' => $resolvedPolicy['policy']->resolution_minutes,
                ],
            ]);
        }
        $this->logAudit('ticket.created', 'ticket', $ticket->id, $user, [
            'ticket_number' => $ticket->ticket_number,
            'priority' => $ticket->priority,
            'routing_rule_id' => $routed['rule']->id ?? null,
            'sla_policy_id' => $resolvedPolicy['policy']->id ?? null,
            'sla_source' => $resolvedPolicy['source'] ?? null,
            'sla_contract_id' => $resolvedPolicy['contract_id'] ?? null,
        ]);
        return ['data' => $ticket->toArray()];
    }

    /**
     * Update the editable fields on a ticket. Status changes that hit a
     * terminal state auto-stamp the resolved_at / closed_at timestamps and
     * record per-kind timeline entries.
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateTicket(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'tickets.manage');
        $existing = $this->tickets->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Ticket {$id} not found");
        }
        $this->assertTicketFields($body, isCreate: false);

        if (array_key_exists('parent_ticket_id', $body) && !empty($body['parent_ticket_id'])) {
            $parentId = (int) $body['parent_ticket_id'];
            if ($parentId === $id) {
                throw new InvalidArgumentException('a ticket cannot be its own parent');
            }
            $parent = $this->tickets->findById($parentId);
            if ($parent === null) {
                throw new InvalidArgumentException('parent_ticket_id not found');
            }
            if ($parent->parent_ticket_id !== null) {
                throw new InvalidArgumentException('parent_ticket_id must not itself have a parent');
            }
            // Also refuse if the proposed parent is already a child of this
            // ticket (prevents A↔B cycles even without deep nesting).
            if ($parent->parent_ticket_id === $id) {
                throw new InvalidArgumentException('cyclic parent/child relation');
            }
        }

        // Reassignment requires an extra gate.
        $reassigns = array_key_exists('assigned_to_user_id', $body)
            && (int) ($body['assigned_to_user_id'] ?? 0) !== (int) ($existing->assigned_to_user_id ?? 0);
        $queueChange = array_key_exists('queue_id', $body)
            && (int) ($body['queue_id'] ?? 0) !== (int) ($existing->queue_id ?? 0);
        if ($reassigns || $queueChange) {
            $this->gate->assert($user, 'tickets.assign');
        }

        // Auto-stamp terminal timestamps.
        $newStatus = $body['status'] ?? $existing->status;
        $now = date('Y-m-d H:i:s');
        if ($newStatus !== $existing->status) {
            if ($newStatus === 'resolved' && $existing->resolved_at === null) {
                $body['resolved_at'] = $now;
            }
            if ($newStatus === 'closed' && $existing->closed_at === null) {
                $body['closed_at'] = $now;
                if ($existing->resolved_at === null) {
                    $body['resolved_at'] = $body['resolved_at'] ?? $now;
                }
            }
            // Reopening clears the terminal timestamps.
            if (!in_array($newStatus, self::TERMINAL_STATUSES, true)) {
                if ($existing->closed_at !== null) {
                    $body['closed_at'] = null;
                }
                if ($existing->resolved_at !== null && $newStatus !== 'resolved') {
                    $body['resolved_at'] = null;
                }
            }
        }

        // Catalog enforcement on terminal transitions. Captures close_note
        // before it's stripped so we can persist it as a timeline event.
        $closeNote = isset($body['close_note']) ? trim((string) $body['close_note']) : '';
        $this->enforceCloseCatalog($body, $existing, $newStatus);

        // IT-helpdesk overlay — re-evaluate severity/business_impact against
        // the merged (existing + body) view.
        $body = $this->itHelpdesk->applyRules($body, existing: $existing);

        $ticket = $this->tickets->update($id, $body);

        // SLA clock lifecycle hooks.
        if ($newStatus !== $existing->status) {
            if ($newStatus === 'pending_customer' && $existing->status !== 'pending_customer') {
                $this->sla->pauseAll($id);
            } elseif ($existing->status === 'pending_customer' && $newStatus !== 'pending_customer') {
                $this->sla->resumeAll($id);
            }
            if ($newStatus === 'resolved') {
                $this->sla->stopKind($id, 'resolution');
            }
            if (in_array($newStatus, ['closed', 'cancelled'], true)) {
                $this->sla->stopAll($id);
            }
        }
        if (
            array_key_exists('first_response_at', $body)
            && $body['first_response_at'] !== null
            && $existing->first_response_at === null
        ) {
            $this->sla->stopKind($id, 'response');
        }

        // Timeline entries per meaningful change.
        if ($newStatus !== $existing->status) {
            $this->events->create([
                'ticket_id' => $id,
                'event_kind' => 'status_changed',
                'actor_user_id' => $user->id ?? null,
                'payload' => ['from' => $existing->status, 'to' => $newStatus],
            ]);
        }
        if (array_key_exists('priority', $body) && $body['priority'] !== $existing->priority) {
            $this->events->create([
                'ticket_id' => $id,
                'event_kind' => 'priority_changed',
                'actor_user_id' => $user->id ?? null,
                'payload' => ['from' => $existing->priority, 'to' => $body['priority']],
            ]);
        }
        if ($reassigns) {
            $this->events->create([
                'ticket_id' => $id,
                'event_kind' => $existing->assigned_to_user_id === null ? 'assigned' : 'reassigned',
                'actor_user_id' => $user->id ?? null,
                'payload' => [
                    'from_user_id' => $existing->assigned_to_user_id,
                    'to_user_id' => $body['assigned_to_user_id'] ?? null,
                ],
            ]);
        }
        if ($closeNote !== '' && $newStatus === 'closed' && $existing->status !== 'closed') {
            $this->events->create([
                'ticket_id' => $id,
                'event_kind' => 'close_note',
                'actor_user_id' => $user->id ?? null,
                'message' => $closeNote,
                'is_internal' => 0,
            ]);
        }

        $this->logAudit('ticket.updated', 'ticket', $id, $user, ['fields' => array_keys($body)]);
        return ['data' => $ticket->toArray()];
    }

    public function deleteTicket(User $user, int $id): void
    {
        $this->gate->assert($user, 'tickets.manage');
        $this->tickets->delete($id);
        $this->logAudit('ticket.deleted', 'ticket', $id, $user, []);
    }

    /**
     * Add a comment (internal note or customer-visible).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function addComment(User $user, int $ticketId, array $body): array
    {
        $this->gate->assert($user, 'tickets.manage');
        $ticket = $this->tickets->findById($ticketId);
        if ($ticket === null) {
            throw new InvalidArgumentException("Ticket {$ticketId} not found");
        }
        $message = trim((string) ($body['message'] ?? ''));
        if ($message === '') {
            throw new InvalidArgumentException('message is required');
        }
        $isInternal = isset($body['is_internal']) ? (bool) $body['is_internal'] : true;
        $event = $this->events->create([
            'ticket_id' => $ticketId,
            'event_kind' => 'comment',
            'actor_user_id' => $user->id ?? null,
            'message' => $message,
            'is_internal' => $isInternal,
        ]);

        // A customer-visible comment from an agent counts as first response.
        if (!$isInternal && $ticket->first_response_at === null && ($user->id ?? null) !== null) {
            $this->tickets->update($ticketId, ['first_response_at' => date('Y-m-d H:i:s')]);
            $this->sla->stopKind($ticketId, 'response');
        }

        $this->logAudit('ticket.comment', 'ticket', $ticketId, $user, [
            'event_id' => $event->id,
            'is_internal' => $event->is_internal,
        ]);
        return ['data' => $event->toArray()];
    }

    // ───────────────────────────────────────── SLA Policies ─────────────────

    /**
     * @return array<string, mixed>
     */
    public function listSlaPolicies(User $user, ?int $divisionId, bool $activeOnly): array
    {
        $this->gate->assert($user, 'ticket_sla_policies.view');
        $policies = $this->slaPolicies->listAll($divisionId, $activeOnly);
        return ['data' => array_map(static fn(TicketSlaPolicy $p) => $p->toArray(), $policies)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createSlaPolicy(User $user, array $body): array
    {
        $this->gate->assert($user, 'ticket_sla_policies.manage');
        $this->assertSlaPolicyFields($body);
        $policy = $this->slaPolicies->create($body);
        $this->logAudit('ticket_sla_policy.created', 'ticket_sla_policy', $policy->id, $user, [
            'division_id' => $policy->division_id,
            'priority' => $policy->priority,
        ]);
        return ['data' => $policy->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateSlaPolicy(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'ticket_sla_policies.manage');
        if (array_key_exists('priority', $body) && !in_array($body['priority'], self::PRIORITIES, true)) {
            throw new InvalidArgumentException(
                'priority must be one of: ' . implode(', ', self::PRIORITIES)
            );
        }
        $policy = $this->slaPolicies->update($id, $body);
        $this->logAudit('ticket_sla_policy.updated', 'ticket_sla_policy', $id, $user, [
            'fields' => array_keys($body),
        ]);
        return ['data' => $policy->toArray()];
    }

    public function deleteSlaPolicy(User $user, int $id): void
    {
        $this->gate->assert($user, 'ticket_sla_policies.manage');
        $this->slaPolicies->delete($id);
        $this->logAudit('ticket_sla_policy.deleted', 'ticket_sla_policy', $id, $user, []);
    }

    /**
     * @return array<string, mixed>
     */
    public function getTicketSla(User $user, int $ticketId): array
    {
        $this->gate->assert($user, 'tickets.view');
        if ($this->tickets->findById($ticketId) === null) {
            throw new InvalidArgumentException("Ticket {$ticketId} not found");
        }
        return ['data' => $this->sla->snapshot($ticketId)];
    }

    // ───────────────────────────────────────── Validation ───────────────────

    /**
     * @param array<string, mixed> $body
     */
    private function assertTicketFields(array $body, bool $isCreate): void
    {
        if ($isCreate) {
            if (trim((string) ($body['title'] ?? '')) === '') {
                throw new InvalidArgumentException('title is required');
            }
        } elseif (array_key_exists('title', $body) && trim((string) $body['title']) === '') {
            throw new InvalidArgumentException('title cannot be blank');
        }
        if (array_key_exists('priority', $body) && !in_array($body['priority'], self::PRIORITIES, true)) {
            throw new InvalidArgumentException(
                'priority must be one of: ' . implode(', ', self::PRIORITIES)
            );
        }
        if (array_key_exists('status', $body) && !in_array($body['status'], self::STATUSES, true)) {
            throw new InvalidArgumentException(
                'status must be one of: ' . implode(', ', self::STATUSES)
            );
        }
        if (!empty($body['source']) && !in_array($body['source'], self::SOURCES, true)) {
            throw new InvalidArgumentException(
                'source must be one of: ' . implode(', ', self::SOURCES)
            );
        }
        if (!empty($body['reporter_email']) && !filter_var($body['reporter_email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('reporter_email must be a valid email');
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertCategoryFields(array $body): void
    {
        if (trim((string) ($body['code'] ?? '')) === '') {
            throw new InvalidArgumentException('code is required');
        }
        if (trim((string) ($body['name'] ?? '')) === '') {
            throw new InvalidArgumentException('name is required');
        }
        if (!empty($body['default_priority']) && !in_array($body['default_priority'], self::PRIORITIES, true)) {
            throw new InvalidArgumentException(
                'default_priority must be one of: ' . implode(', ', self::PRIORITIES)
            );
        }
    }

    // ───────────────────────────────────────── Workorder Links ─────────────

    /**
     * @return array<string, mixed>
     */
    public function listWorkorderLinks(User $user, int $ticketId): array
    {
        $this->gate->assert($user, 'tickets.view');
        if ($this->tickets->findById($ticketId) === null) {
            throw new InvalidArgumentException("Ticket {$ticketId} not found");
        }
        $links = $this->workorderLinks->listForTicket($ticketId);
        return ['data' => array_map(static fn(TicketWorkorderLink $l) => $l->toArray(), $links)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function linkWorkorder(User $user, int $ticketId, array $body): array
    {
        $this->gate->assert($user, 'tickets.manage');
        $ticket = $this->tickets->findById($ticketId);
        if ($ticket === null) {
            throw new InvalidArgumentException("Ticket {$ticketId} not found");
        }
        $workorderId = (int) ($body['workorder_id'] ?? 0);
        if ($workorderId <= 0) {
            throw new InvalidArgumentException('workorder_id is required');
        }
        $linkKind = (string) ($body['link_kind'] ?? 'spawned');
        if (!in_array($linkKind, TicketWorkorderLinkRepository::KINDS, true)) {
            throw new InvalidArgumentException(
                'link_kind must be one of: ' . implode(', ', TicketWorkorderLinkRepository::KINDS)
            );
        }
        $existing = $this->workorderLinks->findByIds($ticketId, $workorderId, $linkKind);
        if ($existing !== null) {
            return ['data' => $existing->toArray()];
        }
        $link = $this->workorderLinks->create([
            'ticket_id' => $ticketId,
            'workorder_id' => $workorderId,
            'link_kind' => $linkKind,
            'linked_by_user_id' => $user->id ?? null,
            'note' => $body['note'] ?? null,
        ]);
        $this->events->create([
            'ticket_id' => $ticketId,
            'event_kind' => 'workorder_linked',
            'actor_user_id' => $user->id ?? null,
            'payload' => [
                'workorder_id' => $workorderId,
                'link_kind' => $linkKind,
            ],
        ]);
        $this->logAudit('ticket.workorder_linked', 'ticket', $ticketId, $user, [
            'workorder_id' => $workorderId,
            'link_kind' => $linkKind,
        ]);
        return ['data' => $link->toArray()];
    }

    public function unlinkWorkorder(User $user, int $ticketId, int $linkId): void
    {
        $this->gate->assert($user, 'tickets.manage');
        if ($this->tickets->findById($ticketId) === null) {
            throw new InvalidArgumentException("Ticket {$ticketId} not found");
        }
        $this->workorderLinks->delete($linkId);
        $this->events->create([
            'ticket_id' => $ticketId,
            'event_kind' => 'workorder_unlinked',
            'actor_user_id' => $user->id ?? null,
            'payload' => ['link_id' => $linkId],
        ]);
        $this->logAudit('ticket.workorder_unlinked', 'ticket', $ticketId, $user, ['link_id' => $linkId]);
    }

    // ───────────────────────────────────────── Queues ──────────────────────

    /**
     * @return array<string, mixed>
     */
    public function listQueues(User $user, ?int $divisionId, bool $activeOnly): array
    {
        $this->gate->assert($user, 'ticket_queues.view');
        $queues = $this->queues->listAll($divisionId, $activeOnly);
        return ['data' => array_map(static fn(TicketQueue $q) => $q->toArray(), $queues)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createQueue(User $user, array $body): array
    {
        $this->gate->assert($user, 'ticket_queues.manage');
        if (trim((string) ($body['code'] ?? '')) === '') {
            throw new InvalidArgumentException('code is required');
        }
        if (trim((string) ($body['name'] ?? '')) === '') {
            throw new InvalidArgumentException('name is required');
        }
        $queue = $this->queues->create($body);
        $this->logAudit('ticket_queue.created', 'ticket_queue', $queue->id, $user, [
            'code' => $queue->code,
        ]);
        return ['data' => $queue->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateQueue(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'ticket_queues.manage');
        $queue = $this->queues->update($id, $body);
        $this->logAudit('ticket_queue.updated', 'ticket_queue', $id, $user, [
            'fields' => array_keys($body),
        ]);
        return ['data' => $queue->toArray()];
    }

    public function deleteQueue(User $user, int $id): void
    {
        $this->gate->assert($user, 'ticket_queues.manage');
        $this->queues->delete($id);
        $this->logAudit('ticket_queue.deleted', 'ticket_queue', $id, $user, []);
    }

    // ───────────────────────────────────────── Escalation Rules ────────────

    /**
     * @return array<string, mixed>
     */
    public function listEscalationRules(User $user, bool $activeOnly): array
    {
        $this->gate->assert($user, 'ticket_escalation_rules.view');
        $rules = $this->escalationRules->listAll($activeOnly);
        return ['data' => array_map(static fn(TicketEscalationRule $r) => $r->toArray(), $rules)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createEscalationRule(User $user, array $body): array
    {
        $this->gate->assert($user, 'ticket_escalation_rules.manage');
        $this->assertEscalationRuleFields($body);
        $rule = $this->escalationRules->create($body);
        $this->logAudit('ticket_escalation_rule.created', 'ticket_escalation_rule', $rule->id, $user, [
            'name' => $rule->name,
            'trigger_kind' => $rule->trigger_kind,
        ]);
        return ['data' => $rule->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateEscalationRule(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'ticket_escalation_rules.manage');
        $this->assertEscalationRuleFields($body, isCreate: false);
        $rule = $this->escalationRules->update($id, $body);
        $this->logAudit('ticket_escalation_rule.updated', 'ticket_escalation_rule', $id, $user, [
            'fields' => array_keys($body),
        ]);
        return ['data' => $rule->toArray()];
    }

    public function deleteEscalationRule(User $user, int $id): void
    {
        $this->gate->assert($user, 'ticket_escalation_rules.manage');
        $this->escalationRules->delete($id);
        $this->logAudit('ticket_escalation_rule.deleted', 'ticket_escalation_rule', $id, $user, []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertEscalationRuleFields(array $body, bool $isCreate = true): void
    {
        if ($isCreate && trim((string) ($body['name'] ?? '')) === '') {
            throw new InvalidArgumentException('name is required');
        }
        if ($isCreate && empty($body['trigger_kind'])) {
            throw new InvalidArgumentException('trigger_kind is required');
        }
        if (
            array_key_exists('trigger_kind', $body)
            && !in_array($body['trigger_kind'], TicketEscalationService::TRIGGERS, true)
        ) {
            throw new InvalidArgumentException(
                'trigger_kind must be one of: ' . implode(', ', TicketEscalationService::TRIGGERS)
            );
        }
        if (
            !empty($body['trigger_sla_kind'])
            && !in_array($body['trigger_sla_kind'], SlaClockService::KINDS, true)
        ) {
            throw new InvalidArgumentException(
                'trigger_sla_kind must be one of: ' . implode(', ', SlaClockService::KINDS)
            );
        }
        foreach (['match_priority', 'action_raise_priority_to'] as $key) {
            if (!empty($body[$key]) && !in_array($body[$key], self::PRIORITIES, true)) {
                throw new InvalidArgumentException(
                    "{$key} must be one of: " . implode(', ', self::PRIORITIES)
                );
            }
        }
        if (!empty($body['match_severity']) && !in_array($body['match_severity'], Ticket::SEVERITIES, true)) {
            throw new InvalidArgumentException(
                'match_severity must be one of: ' . implode(', ', Ticket::SEVERITIES)
            );
        }
        if (!empty($body['match_status']) && !in_array($body['match_status'], self::STATUSES, true)) {
            throw new InvalidArgumentException(
                'match_status must be one of: ' . implode(', ', self::STATUSES)
            );
        }
    }

    // ───────────────────────────────────────── Routing Rules ───────────────

    /**
     * @return array<string, mixed>
     */
    public function listRoutingRules(User $user, bool $activeOnly): array
    {
        $this->gate->assert($user, 'ticket_routing_rules.view');
        $rules = $this->routingRules->listAll($activeOnly);
        return ['data' => array_map(static fn(TicketRoutingRule $r) => $r->toArray(), $rules)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createRoutingRule(User $user, array $body): array
    {
        $this->gate->assert($user, 'ticket_routing_rules.manage');
        $this->assertRoutingRuleFields($body);
        $rule = $this->routingRules->create($body);
        $this->logAudit('ticket_routing_rule.created', 'ticket_routing_rule', $rule->id, $user, [
            'name' => $rule->name,
            'evaluation_order' => $rule->evaluation_order,
        ]);
        return ['data' => $rule->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateRoutingRule(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'ticket_routing_rules.manage');
        $this->assertRoutingRulePriorityFields($body);
        $rule = $this->routingRules->update($id, $body);
        $this->logAudit('ticket_routing_rule.updated', 'ticket_routing_rule', $id, $user, [
            'fields' => array_keys($body),
        ]);
        return ['data' => $rule->toArray()];
    }

    public function deleteRoutingRule(User $user, int $id): void
    {
        $this->gate->assert($user, 'ticket_routing_rules.manage');
        $this->routingRules->delete($id);
        $this->logAudit('ticket_routing_rule.deleted', 'ticket_routing_rule', $id, $user, []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertRoutingRuleFields(array $body): void
    {
        if (trim((string) ($body['name'] ?? '')) === '') {
            throw new InvalidArgumentException('name is required');
        }
        $this->assertRoutingRulePriorityFields($body);
        if (!empty($body['match_source']) && !in_array($body['match_source'], self::SOURCES, true)) {
            throw new InvalidArgumentException(
                'match_source must be one of: ' . implode(', ', self::SOURCES)
            );
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertRoutingRulePriorityFields(array $body): void
    {
        foreach (['match_priority', 'action_set_priority'] as $key) {
            if (!empty($body[$key]) && !in_array($body[$key], self::PRIORITIES, true)) {
                throw new InvalidArgumentException(
                    "{$key} must be one of: " . implode(', ', self::PRIORITIES)
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertSlaPolicyFields(array $body): void
    {
        if (trim((string) ($body['name'] ?? '')) === '') {
            throw new InvalidArgumentException('name is required');
        }
        if (empty($body['priority']) || !in_array($body['priority'], self::PRIORITIES, true)) {
            throw new InvalidArgumentException(
                'priority must be one of: ' . implode(', ', self::PRIORITIES)
            );
        }
        foreach (['response_minutes', 'arrival_minutes', 'resolution_minutes'] as $key) {
            if (array_key_exists($key, $body) && $body[$key] !== null && (int) $body[$key] < 0) {
                throw new InvalidArgumentException("{$key} must be non-negative");
            }
        }
    }

    // ───────────────────────────────────────── Close Reasons ───────────────

    /**
     * @return array<string, mixed>
     */
    public function listCloseReasons(User $user, bool $activeOnly): array
    {
        $this->gate->assert($user, 'ticket_close_reasons.view');
        $reasons = $this->closeReasons->listAll($activeOnly);
        return ['data' => array_map(static fn(TicketCloseReason $r) => $r->toArray(), $reasons)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createCloseReason(User $user, array $body): array
    {
        $this->gate->assert($user, 'ticket_close_reasons.manage');
        $this->assertCatalogFields($body);
        $reason = $this->closeReasons->create($body);
        $this->logAudit('ticket_close_reason.created', 'ticket_close_reason', $reason->id, $user, [
            'code' => $reason->code,
        ]);
        return ['data' => $reason->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateCloseReason(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'ticket_close_reasons.manage');
        $reason = $this->closeReasons->update($id, $body);
        $this->logAudit('ticket_close_reason.updated', 'ticket_close_reason', $id, $user, [
            'fields' => array_keys($body),
        ]);
        return ['data' => $reason->toArray()];
    }

    public function deleteCloseReason(User $user, int $id): void
    {
        $this->gate->assert($user, 'ticket_close_reasons.manage');
        $this->closeReasons->delete($id);
        $this->logAudit('ticket_close_reason.deleted', 'ticket_close_reason', $id, $user, []);
    }

    // ───────────────────────────────────────── Resolution Codes ────────────

    /**
     * @return array<string, mixed>
     */
    public function listResolutionCodes(User $user, bool $activeOnly): array
    {
        $this->gate->assert($user, 'ticket_resolution_codes.view');
        $codes = $this->resolutionCodes->listAll($activeOnly);
        return ['data' => array_map(static fn(TicketResolutionCode $c) => $c->toArray(), $codes)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createResolutionCode(User $user, array $body): array
    {
        $this->gate->assert($user, 'ticket_resolution_codes.manage');
        $this->assertCatalogFields($body);
        $code = $this->resolutionCodes->create($body);
        $this->logAudit('ticket_resolution_code.created', 'ticket_resolution_code', $code->id, $user, [
            'code' => $code->code,
        ]);
        return ['data' => $code->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateResolutionCode(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'ticket_resolution_codes.manage');
        $code = $this->resolutionCodes->update($id, $body);
        $this->logAudit('ticket_resolution_code.updated', 'ticket_resolution_code', $id, $user, [
            'fields' => array_keys($body),
        ]);
        return ['data' => $code->toArray()];
    }

    public function deleteResolutionCode(User $user, int $id): void
    {
        $this->gate->assert($user, 'ticket_resolution_codes.manage');
        $this->resolutionCodes->delete($id);
        $this->logAudit('ticket_resolution_code.deleted', 'ticket_resolution_code', $id, $user, []);
    }

    // ───────────────────────────────────────── Failure Codes ───────────────

    /**
     * @return array<string, mixed>
     */
    public function listFailureCodes(User $user, bool $activeOnly): array
    {
        $this->gate->assert($user, 'ticket_failure_codes.view');
        $codes = $this->failureCodes->listAll($activeOnly);
        return ['data' => array_map(static fn(TicketFailureCode $c) => $c->toArray(), $codes)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createFailureCode(User $user, array $body): array
    {
        $this->gate->assert($user, 'ticket_failure_codes.manage');
        $this->assertCatalogFields($body);
        $code = $this->failureCodes->create($body);
        $this->logAudit('ticket_failure_code.created', 'ticket_failure_code', $code->id, $user, [
            'code' => $code->code,
        ]);
        return ['data' => $code->toArray()];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateFailureCode(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'ticket_failure_codes.manage');
        $code = $this->failureCodes->update($id, $body);
        $this->logAudit('ticket_failure_code.updated', 'ticket_failure_code', $id, $user, [
            'fields' => array_keys($body),
        ]);
        return ['data' => $code->toArray()];
    }

    public function deleteFailureCode(User $user, int $id): void
    {
        $this->gate->assert($user, 'ticket_failure_codes.manage');
        $this->failureCodes->delete($id);
        $this->logAudit('ticket_failure_code.deleted', 'ticket_failure_code', $id, $user, []);
    }

    /**
     * @param array<string, mixed> $body
     */
    private function assertCatalogFields(array $body): void
    {
        if (trim((string) ($body['code'] ?? '')) === '') {
            throw new InvalidArgumentException('code is required');
        }
        if (trim((string) ($body['name'] ?? '')) === '') {
            throw new InvalidArgumentException('name is required');
        }
    }

    /**
     * Enforces close-metadata requirements when a ticket transitions into a
     * terminal state, or when resolution/failure codes are being edited on
     * a ticket in-flight. Validates catalog membership and (for close reasons
     * flagged `requires_detail`) a non-empty close_note on the transition.
     *
     * @param array<string, mixed> $body
     */
    private function enforceCloseCatalog(array &$body, Ticket $existing, string $newStatus): void
    {
        $transitionsToResolved = $newStatus === 'resolved' && $existing->status !== 'resolved';
        $transitionsToClosed = $newStatus === 'closed' && $existing->status !== 'closed';

        if ($transitionsToResolved) {
            $resolutionCode = array_key_exists('resolution_code', $body)
                ? (string) ($body['resolution_code'] ?? '')
                : (string) ($existing->resolution_code ?? '');
            if ($resolutionCode === '') {
                throw new InvalidArgumentException(
                    'resolution_code is required when resolving a ticket'
                );
            }
            if ($this->resolutionCodes->findByCode($resolutionCode) === null) {
                throw new InvalidArgumentException(
                    "resolution_code '{$resolutionCode}' is not a known code"
                );
            }
        } elseif (array_key_exists('resolution_code', $body) && !empty($body['resolution_code'])) {
            if ($this->resolutionCodes->findByCode((string) $body['resolution_code']) === null) {
                throw new InvalidArgumentException(
                    "resolution_code '{$body['resolution_code']}' is not a known code"
                );
            }
        }

        if (array_key_exists('failure_code', $body) && !empty($body['failure_code'])) {
            if ($this->failureCodes->findByCode((string) $body['failure_code']) === null) {
                throw new InvalidArgumentException(
                    "failure_code '{$body['failure_code']}' is not a known code"
                );
            }
        }

        if ($transitionsToClosed) {
            $closeReasonCode = array_key_exists('close_reason', $body)
                ? (string) ($body['close_reason'] ?? '')
                : (string) ($existing->close_reason ?? '');
            if ($closeReasonCode === '') {
                throw new InvalidArgumentException(
                    'close_reason is required when closing a ticket'
                );
            }
            $reason = $this->closeReasons->findByCode($closeReasonCode);
            if ($reason === null) {
                throw new InvalidArgumentException(
                    "close_reason '{$closeReasonCode}' is not a known reason"
                );
            }
            if ($reason->requires_detail === 1) {
                $note = trim((string) ($body['close_note'] ?? ''));
                if ($note === '') {
                    throw new InvalidArgumentException(
                        "close_reason '{$closeReasonCode}' requires a close_note"
                    );
                }
            }
        } elseif (array_key_exists('close_reason', $body) && !empty($body['close_reason'])) {
            if ($this->closeReasons->findByCode((string) $body['close_reason']) === null) {
                throw new InvalidArgumentException(
                    "close_reason '{$body['close_reason']}' is not a known reason"
                );
            }
        }

        // close_note isn't a ticket column — remove it so the repository
        // update doesn't try to persist it.
        unset($body['close_note']);
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAudit(string $event, string $entityType, int $entityId, User $user, array $context): void
    {
        $this->audit->log(new AuditEntry(
            $event,
            $entityType,
            $entityId,
            (int) ($user->id ?? 0),
            $context
        ));
    }
}
