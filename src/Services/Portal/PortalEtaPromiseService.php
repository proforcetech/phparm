<?php

namespace App\Services\Portal;

use App\Database\Connection;
use App\Models\PortalAccount;
use App\Models\PortalEtaPromise;
use App\Models\Ticket;
use App\Models\User;
use App\Models\Workorder;
use App\Services\Customer\CustomerRepository;
use App\Services\Tickets\TicketRepository;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 6.7 of docs/expansion-plan.md — ETA promises for tickets and
 * workorders, readable from the customer portal.
 *
 * Two surfaces in one service:
 *   * Staff-write (publishPromise / cancelCurrent): gated on
 *     tickets.manage / workorders.manage via AccessGate. Staff posts
 *     a new promise, which supersedes any current promise on the
 *     entity. The chain is append-only — superseded rows stay for
 *     history.
 *   * Portal-read (readCurrentFor{Ticket,Workorder}): gated via
 *     portal_account scope (assertUsable + company_id match + optional
 *     site whitelist). Portal users never write ETAs.
 *
 * The two surfaces share scope loaders so cross-company entity access
 * rejects identically in both.
 */
class PortalEtaPromiseService
{
    public const MAX_WINDOW_SPAN_HOURS = 168;

    public function __construct(
        private readonly Connection $connection,
        private readonly PortalEtaPromiseRepository $promises,
        private readonly TicketRepository $tickets,
        private readonly WorkorderRepository $workorders,
        private readonly CustomerRepository $customers,
        private readonly PortalAuthService $portalAuth,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    // ── Staff writes ─────────────────────────────────────────────────────

    /**
     * Publish a new ETA promise for a ticket. Supersedes any current
     * promise on the ticket. Caller must hold tickets.manage.
     *
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function publishForTicket(User $actor, int $ticketId, array $input): array
    {
        $this->gate->assert($actor, 'tickets.manage');
        $ticket = $this->loadTicketForStaff($ticketId);
        return $this->publishPromise(
            $actor,
            PortalEtaPromiseRepository::ENTITY_TICKET,
            $ticket->id,
            (int) ($ticket->company_id ?? 0),
            $input,
            ['ticket_id' => $ticket->id, 'site_id' => $ticket->site_id],
        );
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function publishForWorkorder(User $actor, int $workorderId, array $input): array
    {
        $this->gate->assert($actor, 'workorders.manage');
        $wo = $this->loadWorkorderForStaff($workorderId);
        $customer = $this->customers->find($wo->customer_id);
        $companyId = $customer !== null ? (int) ($customer->company_id ?? 0) : 0;
        if ($companyId <= 0) {
            // WOs on non-company (personal) customers never surface on
            // the portal — fail loud rather than orphan an ETA row.
            throw new InvalidArgumentException(
                "workorder {$workorderId} has no company binding — portal ETA is not applicable"
            );
        }
        return $this->publishPromise(
            $actor,
            PortalEtaPromiseRepository::ENTITY_WORKORDER,
            $wo->id,
            $companyId,
            $input,
            ['workorder_id' => $wo->id, 'customer_id' => $wo->customer_id],
        );
    }

    /**
     * Cancel the current promise on an entity without publishing a
     * replacement. Distinguishable from "here's the new estimate" —
     * staff tells the portal "we no longer have an ETA to give".
     */
    public function cancelCurrentForTicket(User $actor, int $ticketId, ?string $reason): void
    {
        $this->gate->assert($actor, 'tickets.manage');
        $ticket = $this->loadTicketForStaff($ticketId);
        $this->cancelCurrent(
            $actor,
            PortalEtaPromiseRepository::ENTITY_TICKET,
            $ticket->id,
            (int) ($ticket->company_id ?? 0),
            $reason,
        );
    }

    public function cancelCurrentForWorkorder(User $actor, int $workorderId, ?string $reason): void
    {
        $this->gate->assert($actor, 'workorders.manage');
        $wo = $this->loadWorkorderForStaff($workorderId);
        $customer = $this->customers->find($wo->customer_id);
        $companyId = $customer !== null ? (int) ($customer->company_id ?? 0) : 0;
        if ($companyId <= 0) {
            throw new InvalidArgumentException(
                "workorder {$workorderId} has no company binding — portal ETA is not applicable"
            );
        }
        $this->cancelCurrent(
            $actor,
            PortalEtaPromiseRepository::ENTITY_WORKORDER,
            $wo->id,
            $companyId,
            $reason,
        );
    }

    /**
     * Staff-facing history for a ticket (read-only). Requires tickets.view.
     *
     * @return array<string, mixed>
     */
    public function listHistoryForTicketStaff(User $actor, int $ticketId): array
    {
        $this->gate->assert($actor, 'tickets.view');
        $ticket = $this->loadTicketForStaff($ticketId);
        return $this->composeHistory(
            PortalEtaPromiseRepository::ENTITY_TICKET,
            $ticket->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function listHistoryForWorkorderStaff(User $actor, int $workorderId): array
    {
        $this->gate->assert($actor, 'workorders.view');
        $wo = $this->loadWorkorderForStaff($workorderId);
        return $this->composeHistory(
            PortalEtaPromiseRepository::ENTITY_WORKORDER,
            $wo->id,
        );
    }

    // ── Portal reads ─────────────────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    public function readForTicketPortal(User $user, PortalAccount $account, int $ticketId): array
    {
        $ticket = $this->loadScopedTicket($account, $ticketId);
        return $this->composeHistory(
            PortalEtaPromiseRepository::ENTITY_TICKET,
            $ticket->id,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function readForWorkorderPortal(User $user, PortalAccount $account, int $workorderId): array
    {
        $wo = $this->loadScopedWorkorder($account, $workorderId);
        return $this->composeHistory(
            PortalEtaPromiseRepository::ENTITY_WORKORDER,
            $wo->id,
        );
    }

    // ── internals: publish/cancel ────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $auditExtra
     * @return array<string, mixed>
     */
    private function publishPromise(
        User $actor,
        string $entityType,
        int $entityId,
        int $companyId,
        array $input,
        array $auditExtra,
    ): array {
        if ($companyId <= 0) {
            throw new InvalidArgumentException(
                'owning entity is missing a company_id — portal ETA cannot be published'
            );
        }
        $fields = $this->validatePromiseInput($input);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $current = $this->promises->findCurrentForEntity($entityType, $entityId);

            $newId = $this->promises->create([
                'company_id' => $companyId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'window_start_at' => $fields['window_start_at'],
                'window_end_at' => $fields['window_end_at'],
                'source' => $fields['source'],
                'confidence' => $fields['confidence'],
                'note' => $fields['note'],
                'created_by_user_id' => $actor->id,
            ]);

            if ($current !== null) {
                $ok = $this->promises->markSuperseded($current->id, $newId);
                if (!$ok) {
                    // Another writer stamped superseded_at between our
                    // read and our write. Fail loud so the caller can
                    // retry rather than leaving two "current" rows.
                    throw new RuntimeException(
                        'concurrent supersede detected on promise ' . $current->id
                    );
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'portal.eta.published',
            'portal_eta_promise',
            $newId,
            $actor->id,
            array_merge([
                'company_id' => $companyId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'window_start_at' => $fields['window_start_at'],
                'window_end_at' => $fields['window_end_at'],
                'source' => $fields['source'],
                'confidence' => $fields['confidence'],
                'superseded_promise_id' => $current?->id,
            ], $auditExtra),
        ));

        $created = $this->promises->findById($newId);
        if ($created === null) {
            throw new RuntimeException("promise {$newId} vanished after insert");
        }
        return [
            'current' => $this->serialize($created),
            'superseded_promise_id' => $current?->id,
        ];
    }

    private function cancelCurrent(
        User $actor,
        string $entityType,
        int $entityId,
        int $companyId,
        ?string $reason,
    ): void {
        $current = $this->promises->findCurrentForEntity($entityType, $entityId);
        if ($current === null) {
            throw new InvalidArgumentException(
                'no current ETA promise to cancel on this entity'
            );
        }
        $reason = $reason !== null ? trim($reason) : null;
        if ($reason !== null && $reason === '') {
            $reason = null;
        }
        if ($reason !== null && strlen($reason) > 1000) {
            throw new InvalidArgumentException('cancellation reason exceeds 1000 chars');
        }

        $ok = $this->promises->markSuperseded($current->id, null);
        if (!$ok) {
            throw new RuntimeException(
                'concurrent supersede detected on promise ' . $current->id
            );
        }

        $this->audit->log(new AuditEntry(
            'portal.eta.cancelled',
            'portal_eta_promise',
            $current->id,
            $actor->id,
            [
                'company_id' => $companyId,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'reason' => $reason,
            ],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    private function composeHistory(string $entityType, int $entityId): array
    {
        $history = $this->promises->listHistoryForEntity($entityType, $entityId);
        $currentId = null;
        foreach ($history as $p) {
            if ($p->superseded_at === null) {
                $currentId = $p->id;
                break;
            }
        }
        return [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'current' => $currentId !== null
                ? $this->serialize(array_values(array_filter(
                    $history,
                    fn(PortalEtaPromise $p) => $p->id === $currentId
                ))[0])
                : null,
            'history' => array_map(fn(PortalEtaPromise $p) => $this->serialize($p), $history),
        ];
    }

    // ── internals: validation ────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array{window_start_at: string, window_end_at: string, source: string, confidence: ?int, note: ?string}
     */
    private function validatePromiseInput(array $input): array
    {
        $startRaw = $input['window_start_at'] ?? null;
        $endRaw = $input['window_end_at'] ?? null;
        if (!is_string($startRaw) || trim($startRaw) === '') {
            throw new InvalidArgumentException('window_start_at is required');
        }
        if (!is_string($endRaw) || trim($endRaw) === '') {
            throw new InvalidArgumentException('window_end_at is required');
        }

        try {
            $start = new DateTimeImmutable(trim($startRaw));
        } catch (Exception $e) {
            throw new InvalidArgumentException('window_start_at is not a valid datetime: ' . $e->getMessage());
        }
        try {
            $end = new DateTimeImmutable(trim($endRaw));
        } catch (Exception $e) {
            throw new InvalidArgumentException('window_end_at is not a valid datetime: ' . $e->getMessage());
        }
        if ($end < $start) {
            throw new InvalidArgumentException('window_end_at must be >= window_start_at');
        }
        $spanHours = ($end->getTimestamp() - $start->getTimestamp()) / 3600;
        if ($spanHours > self::MAX_WINDOW_SPAN_HOURS) {
            throw new InvalidArgumentException(
                'window span exceeds ' . self::MAX_WINDOW_SPAN_HOURS . ' hours — use a tighter window'
            );
        }

        $source = isset($input['source']) && is_string($input['source'])
            ? trim($input['source'])
            : PortalEtaPromise::SOURCE_MANUAL;
        if (!in_array($source, PortalEtaPromise::ALLOWED_SOURCES, true)) {
            throw new InvalidArgumentException(
                'source must be one of ' . implode(',', PortalEtaPromise::ALLOWED_SOURCES)
            );
        }

        $confidence = null;
        if (array_key_exists('confidence', $input) && $input['confidence'] !== null && $input['confidence'] !== '') {
            if (!is_numeric($input['confidence'])) {
                throw new InvalidArgumentException('confidence must be numeric');
            }
            $confidence = (int) $input['confidence'];
            if ($confidence < 0 || $confidence > 100) {
                throw new InvalidArgumentException('confidence must be between 0 and 100');
            }
        }

        $note = null;
        if (isset($input['note']) && is_string($input['note'])) {
            $note = trim($input['note']);
            if ($note === '') {
                $note = null;
            } elseif (strlen($note) > 1000) {
                throw new InvalidArgumentException('note exceeds 1000 chars');
            }
        }

        return [
            'window_start_at' => $start->format('Y-m-d H:i:s'),
            'window_end_at' => $end->format('Y-m-d H:i:s'),
            'source' => $source,
            'confidence' => $confidence,
            'note' => $note,
        ];
    }

    // ── internals: scope ─────────────────────────────────────────────────

    private function loadTicketForStaff(int $ticketId): Ticket
    {
        if ($ticketId <= 0) {
            throw new InvalidArgumentException('ticket id is required');
        }
        $ticket = $this->tickets->findById($ticketId);
        if ($ticket === null) {
            throw new InvalidArgumentException("ticket {$ticketId} not found");
        }
        return $ticket;
    }

    private function loadWorkorderForStaff(int $workorderId): Workorder
    {
        if ($workorderId <= 0) {
            throw new InvalidArgumentException('workorder id is required');
        }
        $wo = $this->workorders->find($workorderId);
        if ($wo === null) {
            throw new InvalidArgumentException("workorder {$workorderId} not found");
        }
        return $wo;
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
            throw new UnauthorizedException('ticket belongs to a different company');
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
            throw new UnauthorizedException('workorder belongs to a different company');
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
    private function serialize(PortalEtaPromise $p): array
    {
        return [
            'id' => $p->id,
            'entity_type' => $p->entity_type,
            'entity_id' => $p->entity_id,
            'window_start_at' => $p->window_start_at,
            'window_end_at' => $p->window_end_at,
            'source' => $p->source,
            'confidence' => $p->confidence,
            'note' => $p->note,
            'created_by_user_id' => $p->created_by_user_id,
            'created_at' => $p->created_at,
            'superseded_at' => $p->superseded_at,
            'superseded_by_id' => $p->superseded_by_id,
            'is_current' => $p->superseded_at === null,
        ];
    }
}
