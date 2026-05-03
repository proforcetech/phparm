<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\User;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Tickets\ItHelpdeskService;
use App\Services\Tickets\TicketCategoryRepository;
use App\Services\Tickets\TicketEventRepository;
use App\Services\Tickets\TicketRepository;
use App\Services\Tickets\TicketRoutingService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Phase 6.2 of docs/expansion-plan.md — customer-portal request wizard.
 *
 * The wizard is a three-step funnel:
 *   1. pick a request TYPE (a portal_visible top-level ticket_categories row)
 *   2. pick a CATEGORY within that type (a portal_visible subcategory)
 *   3. submit the request — which mints a ticket with source='portal',
 *      scopes it to the portal_account's company_id + selected site_id
 *      (asserted via PortalAuthService::assertSiteAccess), runs the
 *      existing TicketRoutingService against it so routing rules from
 *      Phase 3.3 also apply to portal-submitted tickets.
 *
 * Why this lives outside TicketController:
 *   * TicketController gates on tickets.manage, which portal_user role
 *     deliberately does NOT have. Portal submissions are a distinct
 *     authorization path — the portal_account row is the gate, not a
 *     staff permission. Keeping the wizard isolated avoids a permission-
 *     split foot-gun in TicketController::createTicket.
 *   * Portal input is untrusted: caller cannot set company_id,
 *     assigned_to_user_id, queue_id, parent_ticket_id, status, or
 *     reporter_* fields; those are derived from the portal_account
 *     and the authenticated user so there is no room for privilege
 *     escalation via request body.
 */
class PortalRequestWizardService
{
    public const ALLOWED_PRIORITIES = ['p3_normal', 'p4_low'];

    public function __construct(
        private readonly TicketCategoryRepository $categories,
        private readonly TicketRepository $tickets,
        private readonly TicketEventRepository $events,
        private readonly TicketRoutingService $routing,
        private readonly PortalAuthService $portalAuth,
        private readonly SiteRepository $sites,
        private readonly SiteAssetRepository $assets,
        private readonly AuditLogger $audit,
        private readonly ItHelpdeskService $itHelpdesk,
    ) {
    }

    /**
     * Top-level portal-visible categories = "request types" displayed in
     * the wizard's first step. Same list for every portal_account because
     * ticket_categories are a shared catalog curated by staff; the
     * PortalAccount argument is taken to allow future per-company filtering
     * without breaking callers.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listRequestTypes(PortalAccount $account): array
    {
        return array_map(
            fn(TicketCategory $c) => $this->serializeCategory($c),
            $this->categories->listPortalVisibleRoots(),
        );
    }

    /**
     * Step 2: portal-visible subcategories for the chosen type. Rejects if
     * the supplied rootCategoryId is not itself a portal-visible top-level
     * category — otherwise a caller could enumerate non-portal-visible
     * children under a portal-visible root.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listCategoriesForType(PortalAccount $account, int $rootCategoryId): array
    {
        $root = $this->categories->findById($rootCategoryId);
        if ($root === null || $root->parent_id !== null
            || $root->is_active !== 1 || $root->portal_visible !== 1
        ) {
            throw new InvalidArgumentException(
                'request type not found or not visible to portal users'
            );
        }
        return array_map(
            fn(TicketCategory $c) => $this->serializeCategory($c),
            $this->categories->listPortalVisibleChildren($rootCategoryId),
        );
    }

    /**
     * Step 3: create the ticket.
     *
     * Invariants (enforced here, not trusted from $input):
     *   * source is forced to 'portal'
     *   * company_id is forced from $account (ignores body)
     *   * reported_by_user_id = $user->id (the authenticated portal user)
     *   * reporter_{name,email} = user contact details
     *   * assigned_to_user_id / queue_id / status / parent_ticket_id are
     *     ignored if caller attempts to set them
     *   * site_id must pass PortalAuthService::assertSiteAccess
     *   * asset_id (if set) must exist AND belong to a site the account
     *     can see
     *   * category_id must be a portal_visible top-level category, and if
     *     subcategory_id is provided it must be a portal_visible child of
     *     that root
     *   * priority is clamped to ALLOWED_PRIORITIES so portal users cannot
     *     self-escalate to p1_critical
     *
     * @param array<string, mixed> $input
     */
    public function submitRequest(User $user, PortalAccount $account, array $input): Ticket
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable for new requests');
        }

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('title is required');
        }
        if (mb_strlen($title) > 255) {
            throw new InvalidArgumentException('title exceeds 255 characters');
        }

        $description = array_key_exists('description', $input)
            ? (string) $input['description']
            : null;
        if ($description !== null && mb_strlen($description) > 20000) {
            throw new InvalidArgumentException('description exceeds 20000 characters');
        }

        $categoryId = (int) ($input['category_id'] ?? 0);
        if ($categoryId <= 0) {
            throw new InvalidArgumentException('category_id is required');
        }
        $root = $this->categories->findById($categoryId);
        if ($root === null || $root->parent_id !== null
            || $root->is_active !== 1 || $root->portal_visible !== 1
        ) {
            throw new InvalidArgumentException(
                'category_id must reference a portal-visible top-level category'
            );
        }

        $subcategoryId = null;
        if (!empty($input['subcategory_id'])) {
            $subcategoryId = (int) $input['subcategory_id'];
            $sub = $this->categories->findById($subcategoryId);
            if ($sub === null || $sub->parent_id !== $categoryId
                || $sub->is_active !== 1 || $sub->portal_visible !== 1
            ) {
                throw new InvalidArgumentException(
                    'subcategory_id must reference a portal-visible child of category_id'
                );
            }
        }

        $siteId = null;
        if (!empty($input['site_id'])) {
            $siteId = (int) $input['site_id'];
            $site = $this->sites->findById($siteId);
            if ($site === null) {
                throw new InvalidArgumentException("site id={$siteId} not found");
            }
            if ((int) ($site->company_id ?? 0) !== $account->company_id) {
                throw new UnauthorizedException(
                    'site does not belong to the portal account\'s company'
                );
            }
            $this->portalAuth->assertSiteAccess($account, $siteId);
        }

        $assetId = null;
        if (!empty($input['asset_id'])) {
            $assetId = (int) $input['asset_id'];
            $asset = $this->assets->findById($assetId);
            if ($asset === null) {
                throw new InvalidArgumentException("asset id={$assetId} not found");
            }
            $assetSiteId = (int) ($asset->site_id ?? 0);
            if ($assetSiteId <= 0) {
                throw new InvalidArgumentException(
                    "asset id={$assetId} has no site and cannot be targeted from the portal"
                );
            }
            $assetSite = $this->sites->findById($assetSiteId);
            if ($assetSite === null
                || (int) ($assetSite->company_id ?? 0) !== $account->company_id
            ) {
                throw new UnauthorizedException(
                    'asset belongs to a site outside the portal account\'s company'
                );
            }
            $this->portalAuth->assertSiteAccess($account, $assetSiteId);
            // Default site_id to the asset's site if caller didn't pin one.
            if ($siteId === null) {
                $siteId = $assetSiteId;
            }
        }

        $priority = (string) ($input['priority'] ?? $root->default_priority);
        if (!in_array($priority, self::ALLOWED_PRIORITIES, true)) {
            // Portal cannot self-escalate to p1/p2. Clamp to the root's
            // default if allowed, else fall back to p3_normal.
            $priority = in_array($root->default_priority, self::ALLOWED_PRIORITIES, true)
                ? $root->default_priority
                : 'p3_normal';
        }

        $createPayload = [
            'company_id' => $account->company_id,
            'site_id' => $siteId,
            'asset_id' => $assetId,
            'category_id' => $categoryId,
            'subcategory_id' => $subcategoryId,
            'priority' => $priority,
            'status' => 'new',
            'title' => $title,
            'description' => $description,
            'reported_by_user_id' => $user->id,
            'reporter_name' => $user->name,
            'reporter_email' => $user->email,
            'source' => 'portal',
            'source_ref' => 'portal_account:' . $account->id,
        ];

        // IT-helpdesk overlay: when the request is an IT incident/request/
        // question/outage, accept the IT-specific narrative fields and let
        // ItHelpdeskService validate + auto-derive severity. Non-IT requests
        // see no behavioral change (the call is a no-op when there's no
        // it_request_kind on the input).
        $itPayload = [];
        foreach (['it_request_kind', 'severity', 'affected_users_count', 'business_impact'] as $field) {
            if (array_key_exists($field, $input)) {
                $itPayload[$field] = $input[$field];
            }
        }
        if ($itPayload !== []) {
            $createPayload = array_merge($createPayload, $this->itHelpdesk->applyRules($itPayload));
        }

        $ticket = $this->tickets->create($createPayload);

        $this->events->create([
            'ticket_id' => $ticket->id,
            'event_kind' => 'created',
            'actor_user_id' => $user->id,
            'is_internal' => 0,
            'message' => 'Submitted via customer portal wizard',
            'payload' => [
                'portal_account_id' => $account->id,
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
                'priority' => $priority,
            ],
        ]);

        // Auto-routing: apply the first matching rule exactly like the
        // staff path does. Portal tickets carry source='portal' so rules
        // can target them specifically if desired.
        $routed = $this->routing->routeTicket($ticket);
        if ($routed !== null && $routed['actions'] !== []) {
            $pending = [];
            foreach ($routed['actions'] as $field => $value) {
                $pending[$field] = $value;
            }
            if ($pending !== []) {
                $ticket = $this->tickets->update($ticket->id, $pending);
                $this->events->create([
                    'ticket_id' => $ticket->id,
                    'event_kind' => 'auto_routed',
                    'actor_user_id' => $user->id,
                    'is_internal' => 1,
                    'payload' => [
                        'rule_id' => $routed['rule']->id,
                        'rule_name' => $routed['rule']->name,
                        'applied' => $pending,
                    ],
                ]);
            }
        }

        $this->audit->log(new AuditEntry(
            'portal.request.submitted',
            'ticket',
            $ticket->id,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'company_id' => $account->company_id,
                'site_id' => $siteId,
                'asset_id' => $assetId,
                'category_id' => $categoryId,
                'subcategory_id' => $subcategoryId,
                'priority' => $priority,
                'routing_rule_id' => $routed['rule']->id ?? null,
            ]
        ));

        return $ticket;
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCategory(TicketCategory $c): array
    {
        return [
            'id' => $c->id,
            'code' => $c->code,
            'name' => $c->name,
            'description' => $c->description,
            'default_priority' => $c->default_priority,
            'parent_id' => $c->parent_id,
        ];
    }
}
