<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\PortalAccount;
use App\Models\SiteAsset;
use App\Models\Site;
use App\Models\Ticket;
use App\Models\TicketCategory;
use App\Models\TicketEvent;
use App\Models\TicketRoutingRule;
use App\Models\User;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Portal\PortalAuthService;
use App\Services\Portal\PortalRequestWizardService;
use App\Services\Tickets\TicketCategoryRepository;
use App\Services\Tickets\TicketEventRepository;
use App\Services\Tickets\TicketRepository;
use App\Services\Tickets\TicketRoutingRuleRepository;
use App\Services\Tickets\TicketRoutingService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 6.2 of docs/expansion-plan.md — customer-portal request wizard.
 *
 * Covers the three wizard steps plus the portal-specific invariants:
 *   * type/subcategory listing is scoped to portal_visible rows only,
 *     so staff-only categories never leak into the wizard UI;
 *   * submission forces source='portal' + company_id from portal_account,
 *     ignoring any cross-tenant hints in the request body;
 *   * site_id / asset_id are asserted against the portal_account's
 *     allowed_site_ids — cross-company sites and disallowed sites are
 *     both rejected at the service layer;
 *   * routing rules from Phase 3.3 fire for portal-submitted tickets too,
 *     so portal submissions still land in the correct queue.
 */

// ---------------------------------------------------------------------------
// Fakes
// ---------------------------------------------------------------------------

class WizFakeAudit extends AuditLogger
{
    /** @var AuditEntry[] */
    public array $entries = [];
    public function __construct() {}
    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }
}

class WizFakeCategories extends TicketCategoryRepository
{
    /** @var array<int, TicketCategory> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function findById(int $id): ?TicketCategory
    {
        return $this->store[$id] ?? null;
    }
    public function listPortalVisibleRoots(): array
    {
        $out = [];
        foreach ($this->store as $c) {
            if ($c->parent_id === null && $c->is_active === 1 && $c->portal_visible === 1) {
                $out[] = $c;
            }
        }
        usort($out, fn($a, $b) => strcmp($a->name, $b->name));
        return $out;
    }
    public function listPortalVisibleChildren(int $parentId): array
    {
        $out = [];
        foreach ($this->store as $c) {
            if ($c->parent_id === $parentId && $c->is_active === 1 && $c->portal_visible === 1) {
                $out[] = $c;
            }
        }
        usort($out, fn($a, $b) => strcmp($a->name, $b->name));
        return $out;
    }
    public function seed(array $row): TicketCategory
    {
        $c = new TicketCategory();
        $c->id = $row['id'] ?? $this->nextId++;
        $c->parent_id = $row['parent_id'] ?? null;
        $c->code = $row['code'] ?? ('cat-' . $c->id);
        $c->name = $row['name'] ?? ('Cat ' . $c->id);
        $c->is_active = $row['is_active'] ?? 1;
        $c->portal_visible = $row['portal_visible'] ?? 0;
        $c->default_priority = $row['default_priority'] ?? 'p3_normal';
        $this->store[$c->id] = $c;
        return $c;
    }
}

class WizFakeTickets extends TicketRepository
{
    /** @var array<int, Ticket> */
    public array $store = [];
    public int $nextId = 1;
    public function __construct() {}
    public function findById(int $id): ?Ticket
    {
        return $this->store[$id] ?? null;
    }
    public function create(array $data): Ticket
    {
        $t = new Ticket();
        $t->id = $this->nextId++;
        $t->ticket_number = 'T-FAKE-' . $t->id;
        foreach ($data as $k => $v) {
            if (property_exists($t, $k)) {
                $t->{$k} = $v;
            }
        }
        if ($t->priority === '') {
            $t->priority = 'p3_normal';
        }
        if ($t->status === '') {
            $t->status = 'new';
        }
        $this->store[$t->id] = $t;
        return $t;
    }
    public function update(int $id, array $data): Ticket
    {
        $t = $this->store[$id] ?? null;
        if ($t === null) {
            throw new RuntimeException("ticket {$id} missing");
        }
        foreach ($data as $k => $v) {
            if (property_exists($t, $k)) {
                $t->{$k} = $v;
            }
        }
        return $t;
    }
}

class WizFakeEvents extends TicketEventRepository
{
    /** @var array<int, array<string, mixed>> */
    public array $rows = [];
    public int $nextId = 1;
    public function __construct() {}
    public function create(array $data): TicketEvent
    {
        $row = $data + ['id' => $this->nextId++];
        $this->rows[] = $row;
        $e = new TicketEvent();
        $e->id = $row['id'];
        $e->ticket_id = $row['ticket_id'] ?? 0;
        $e->event_kind = $row['event_kind'] ?? 'created';
        $e->actor_user_id = $row['actor_user_id'] ?? null;
        $e->is_internal = (int) ($row['is_internal'] ?? 1);
        $e->message = $row['message'] ?? null;
        $e->payload = is_array($row['payload'] ?? null) ? $row['payload'] : null;
        return $e;
    }
}

class WizFakeRoutingRules extends TicketRoutingRuleRepository
{
    /** @var TicketRoutingRule[] */
    public array $rules = [];
    public function __construct() {}
    public function listAll(bool $activeOnly = true): array
    {
        $out = [];
        foreach ($this->rules as $r) {
            if ($activeOnly && $r->is_active !== 1) {
                continue;
            }
            $out[] = $r;
        }
        usort($out, fn($a, $b) => $a->evaluation_order <=> $b->evaluation_order);
        return $out;
    }
}

class WizFakeSites extends SiteRepository
{
    /** @var array<int, Site> */
    public array $store = [];
    public function __construct() {}
    public function findById(int $id): ?Site
    {
        return $this->store[$id] ?? null;
    }
    public function seed(int $id, int $companyId): Site
    {
        $s = new Site();
        $s->id = $id;
        $s->company_id = $companyId;
        $s->name = 'Site ' . $id;
        $this->store[$id] = $s;
        return $s;
    }
}

class WizFakeAssets extends SiteAssetRepository
{
    /** @var array<int, SiteAsset> */
    public array $store = [];
    public function __construct() {}
    public function findById(int $id): ?SiteAsset
    {
        return $this->store[$id] ?? null;
    }
    public function seed(int $id, int $siteId, int $assetTypeId = 1): SiteAsset
    {
        $a = new SiteAsset();
        $a->id = $id;
        $a->site_id = $siteId;
        $a->asset_type_id = $assetTypeId;
        $this->store[$id] = $a;
        return $a;
    }
}

/**
 * Minimal PortalAuthService that exposes only assertSiteAccess (the one
 * method the wizard calls). We bypass the constructor via reflection
 * since we don't need JwtService / gate / companies / sites here.
 */
function makePortalAuthStub(): PortalAuthService
{
    $ref = new ReflectionClass(PortalAuthService::class);
    /** @var PortalAuthService $svc */
    $svc = $ref->newInstanceWithoutConstructor();
    return $svc;
}

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------

function wizPortalAccount(int $id, int $companyId, ?array $siteIds): PortalAccount
{
    $a = new PortalAccount();
    $a->id = $id;
    $a->company_id = $companyId;
    $a->user_id = 1000 + $id;
    $a->allowed_site_ids = $siteIds;
    $a->is_active = true;
    $a->revoked_at = null;
    return $a;
}

function wizPortalUser(int $id, string $email = 'client@example.test'): User
{
    $u = new User();
    $u->id = $id;
    $u->name = 'Portal Client';
    $u->email = $email;
    $u->role = 'portal_user';
    return $u;
}

function wizMakeEnv(): array
{
    $categories = new WizFakeCategories();
    $tickets = new WizFakeTickets();
    $events = new WizFakeEvents();
    $rulesRepo = new WizFakeRoutingRules();
    $sites = new WizFakeSites();
    $assets = new WizFakeAssets();
    $routingSvc = new TicketRoutingService($rulesRepo, $assets);
    $portalAuth = makePortalAuthStub();
    $audit = new WizFakeAudit();
    $svc = new PortalRequestWizardService(
        $categories, $tickets, $events, $routingSvc, $portalAuth, $sites, $assets, $audit,
        new \App\Services\Tickets\ItHelpdeskService(),
    );
    return compact('categories', 'tickets', 'events', 'rulesRepo', 'sites', 'assets', 'portalAuth', 'audit', 'svc');
}

function wizCheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $ex) {
        echo "  FAIL {$label}: " . $ex->getMessage() . "\n";
        echo $ex->getTraceAsString() . "\n";
        exit(1);
    }
}

function wizExpectThrow(callable $fn, string $exceptionClass, string $needle, string $label): void
{
    try {
        $fn();
        echo "  FAIL {$label}: expected {$exceptionClass} with '{$needle}'\n";
        exit(1);
    } catch (Throwable $ex) {
        if (!($ex instanceof $exceptionClass)) {
            echo "  FAIL {$label}: expected {$exceptionClass}, got " . get_class($ex) . "\n";
            exit(1);
        }
        if ($needle !== '' && !str_contains($ex->getMessage(), $needle)) {
            echo "  FAIL {$label}: wrong message — '" . $ex->getMessage() . "' vs '{$needle}'\n";
            exit(1);
        }
        echo "  PASS {$label}\n";
    }
}

echo "Phase 6.2 — portal request wizard\n";

// ---------------------------------------------------------------------------
// 1. listRequestTypes returns only portal-visible top-level rows
// ---------------------------------------------------------------------------
$env = wizMakeEnv();
$serviceReq = $env['categories']->seed(['id' => 10, 'name' => 'Service Request', 'code' => 'svc', 'portal_visible' => 1]);
$supportReq = $env['categories']->seed(['id' => 11, 'name' => 'Support Incident', 'code' => 'sup', 'portal_visible' => 1]);
$internalOnly = $env['categories']->seed(['id' => 12, 'name' => 'Internal Triage', 'code' => 'int', 'portal_visible' => 0]);
$inactive = $env['categories']->seed(['id' => 13, 'name' => 'Deprecated', 'code' => 'old', 'portal_visible' => 1, 'is_active' => 0]);
$account = wizPortalAccount(1, 42, [500, 501]);
$user = wizPortalUser(200);

wizCheck(function () use ($env, $account) {
    $types = $env['svc']->listRequestTypes($account);
    if (count($types) !== 2) {
        throw new RuntimeException('expected 2 portal-visible roots, got ' . count($types));
    }
    $codes = array_column($types, 'code');
    if ($codes !== ['svc', 'sup']) {
        throw new RuntimeException('expected svc,sup in name order; got: ' . implode(',', $codes));
    }
}, 'listRequestTypes surfaces only active+portal_visible top-level categories');

// ---------------------------------------------------------------------------
// 2. listCategoriesForType rejects non-portal-visible root, and returns only
//    portal-visible children of a valid root
// ---------------------------------------------------------------------------
$serviceChild1 = $env['categories']->seed(['id' => 20, 'parent_id' => 10, 'name' => 'Plumbing', 'code' => 'svc-plumb', 'portal_visible' => 1]);
$serviceChild2 = $env['categories']->seed(['id' => 21, 'parent_id' => 10, 'name' => 'HVAC', 'code' => 'svc-hvac', 'portal_visible' => 1]);
$serviceChildHidden = $env['categories']->seed(['id' => 22, 'parent_id' => 10, 'name' => 'Back office', 'code' => 'svc-bo', 'portal_visible' => 0]);

wizCheck(function () use ($env, $account) {
    $subs = $env['svc']->listCategoriesForType($account, 10);
    if (count($subs) !== 2) {
        throw new RuntimeException('expected 2 children, got ' . count($subs));
    }
    $codes = array_column($subs, 'code');
    if ($codes !== ['svc-hvac', 'svc-plumb']) {
        throw new RuntimeException('wrong sort/filter: ' . implode(',', $codes));
    }
}, 'listCategoriesForType returns only portal_visible children in name order');

wizExpectThrow(
    fn() => $env['svc']->listCategoriesForType($account, 12),
    InvalidArgumentException::class,
    'request type not found',
    'listCategoriesForType rejects non-portal-visible root'
);

wizExpectThrow(
    fn() => $env['svc']->listCategoriesForType($account, 20),
    InvalidArgumentException::class,
    'request type not found',
    'listCategoriesForType rejects passing a child as the root'
);

// ---------------------------------------------------------------------------
// 3. submitRequest happy path — forces source=portal, company from account,
//    applies assertSiteAccess against allowed_site_ids, and emits audit + event
// ---------------------------------------------------------------------------
$env = wizMakeEnv();
$env['categories']->seed(['id' => 10, 'name' => 'Service', 'code' => 'svc', 'portal_visible' => 1]);
$env['categories']->seed(['id' => 20, 'parent_id' => 10, 'name' => 'Plumb', 'code' => 'svc-plumb', 'portal_visible' => 1]);
$env['sites']->seed(500, 42);
$account = wizPortalAccount(1, 42, [500]);
$user = wizPortalUser(200);

wizCheck(function () use ($env, $account, $user) {
    $ticket = $env['svc']->submitRequest($user, $account, [
        'category_id' => 10,
        'subcategory_id' => 20,
        'site_id' => 500,
        'title' => 'Leaking sink in lobby',
        'description' => 'Water pooling',
        // The following should be IGNORED — portal input is untrusted.
        'company_id' => 999,
        'assigned_to_user_id' => 777,
        'queue_id' => 888,
        'parent_ticket_id' => 555,
        'status' => 'closed',
        'source' => 'manual',
    ]);
    if ($ticket->company_id !== 42) {
        throw new RuntimeException('company_id should be forced from account, got ' . $ticket->company_id);
    }
    if ($ticket->source !== 'portal') {
        throw new RuntimeException('source should be forced to portal, got ' . $ticket->source);
    }
    if ($ticket->status !== 'new') {
        throw new RuntimeException('status should be new, got ' . $ticket->status);
    }
    if ($ticket->reported_by_user_id !== 200) {
        throw new RuntimeException('reporter should be the portal user, got ' . $ticket->reported_by_user_id);
    }
    if ($ticket->assigned_to_user_id !== null) {
        throw new RuntimeException('assigned_to_user_id should NOT be honored from body');
    }
    if ($ticket->queue_id !== null) {
        throw new RuntimeException('queue_id should NOT be honored from body');
    }
    if ($ticket->parent_ticket_id !== null) {
        throw new RuntimeException('parent_ticket_id should NOT be honored from body');
    }
    if ($ticket->source_ref !== 'portal_account:1') {
        throw new RuntimeException('source_ref wrong: ' . $ticket->source_ref);
    }
    // Event recorded with is_internal=0 so the portal user can see their own submission.
    if (count($env['events']->rows) < 1) {
        throw new RuntimeException('no ticket_event written');
    }
    $firstEvent = $env['events']->rows[0];
    if ($firstEvent['event_kind'] !== 'created' || (int) $firstEvent['is_internal'] !== 0) {
        throw new RuntimeException('created event should be non-internal for portal submissions');
    }
    // Audit trail.
    if (count($env['audit']->entries) !== 1) {
        throw new RuntimeException('expected 1 audit entry, got ' . count($env['audit']->entries));
    }
    $audit = $env['audit']->entries[0];
    if ($audit->event !== 'portal.request.submitted') {
        throw new RuntimeException('wrong audit event: ' . $audit->event);
    }
    if (($audit->context['company_id'] ?? null) !== 42) {
        throw new RuntimeException('audit missing company_id');
    }
    if (($audit->context['portal_account_id'] ?? null) !== 1) {
        throw new RuntimeException('audit missing portal_account_id');
    }
}, 'submitRequest forces portal scope + ignores privileged body fields');

// ---------------------------------------------------------------------------
// 4. title missing / too long
// ---------------------------------------------------------------------------
wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, ['category_id' => 10, 'site_id' => 500, 'title' => '']),
    InvalidArgumentException::class,
    'title is required',
    'submitRequest rejects empty title'
);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, [
        'category_id' => 10, 'site_id' => 500, 'title' => str_repeat('x', 300),
    ]),
    InvalidArgumentException::class,
    '255',
    'submitRequest rejects over-length title'
);

// ---------------------------------------------------------------------------
// 5. category_id missing / unknown / wrong level
// ---------------------------------------------------------------------------
$env = wizMakeEnv();
$env['categories']->seed(['id' => 10, 'name' => 'Service', 'code' => 'svc', 'portal_visible' => 1]);
$env['categories']->seed(['id' => 11, 'name' => 'Internal', 'code' => 'int', 'portal_visible' => 0]);
$env['categories']->seed(['id' => 20, 'parent_id' => 10, 'name' => 'Plumb', 'code' => 'svc-plumb', 'portal_visible' => 1]);
$env['sites']->seed(500, 42);
$account = wizPortalAccount(1, 42, null);   // null = all sites
$user = wizPortalUser(200);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, ['site_id' => 500, 'title' => 'x']),
    InvalidArgumentException::class,
    'category_id is required',
    'submitRequest rejects missing category_id'
);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, ['category_id' => 9999, 'site_id' => 500, 'title' => 'x']),
    InvalidArgumentException::class,
    'portal-visible top-level',
    'submitRequest rejects unknown category_id'
);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, ['category_id' => 11, 'site_id' => 500, 'title' => 'x']),
    InvalidArgumentException::class,
    'portal-visible top-level',
    'submitRequest rejects non-portal-visible category'
);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, ['category_id' => 20, 'site_id' => 500, 'title' => 'x']),
    InvalidArgumentException::class,
    'portal-visible top-level',
    'submitRequest rejects subcategory passed as top-level'
);

// ---------------------------------------------------------------------------
// 6. subcategory_id must be a portal-visible child of category_id
// ---------------------------------------------------------------------------
$env['categories']->seed(['id' => 30, 'name' => 'Other Root', 'code' => 'oth', 'portal_visible' => 1]);
$env['categories']->seed(['id' => 31, 'parent_id' => 30, 'name' => 'Wrong Parent Child', 'code' => 'oth-wp', 'portal_visible' => 1]);
$env['categories']->seed(['id' => 32, 'parent_id' => 10, 'name' => 'Hidden Child', 'code' => 'svc-hidden', 'portal_visible' => 0]);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, [
        'category_id' => 10, 'subcategory_id' => 31, 'site_id' => 500, 'title' => 'x',
    ]),
    InvalidArgumentException::class,
    'portal-visible child of category_id',
    'submitRequest rejects subcategory of a different root'
);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, [
        'category_id' => 10, 'subcategory_id' => 32, 'site_id' => 500, 'title' => 'x',
    ]),
    InvalidArgumentException::class,
    'portal-visible child of category_id',
    'submitRequest rejects non-portal-visible subcategory'
);

// ---------------------------------------------------------------------------
// 7. site access enforcement
// ---------------------------------------------------------------------------
$env = wizMakeEnv();
$env['categories']->seed(['id' => 10, 'name' => 'Service', 'code' => 'svc', 'portal_visible' => 1]);
$env['sites']->seed(500, 42);   // owned by company 42
$env['sites']->seed(501, 42);   // also company 42 but not in allowed list
$env['sites']->seed(700, 99);   // owned by DIFFERENT company
$account = wizPortalAccount(1, 42, [500]);
$user = wizPortalUser(200);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, ['category_id' => 10, 'site_id' => 9999, 'title' => 'x']),
    InvalidArgumentException::class,
    'not found',
    'submitRequest rejects unknown site_id'
);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, ['category_id' => 10, 'site_id' => 700, 'title' => 'x']),
    UnauthorizedException::class,
    'does not belong to the portal account',
    'submitRequest rejects cross-company site'
);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, ['category_id' => 10, 'site_id' => 501, 'title' => 'x']),
    UnauthorizedException::class,
    'cannot access site',
    'submitRequest rejects in-company site not in allowed_site_ids'
);

// Null allowed_site_ids means "all sites in company" — should accept 501.
$env['tickets']->store = [];
$env['tickets']->nextId = 1;
$env['events']->rows = [];
$env['audit']->entries = [];
$accountAll = wizPortalAccount(2, 42, null);
wizCheck(function () use ($env, $accountAll, $user) {
    $ticket = $env['svc']->submitRequest($user, $accountAll, [
        'category_id' => 10, 'site_id' => 501, 'title' => 'widescope submission',
    ]);
    if ($ticket->site_id !== 501) {
        throw new RuntimeException('site 501 should be accepted when allowed_site_ids=null');
    }
}, 'submitRequest with allowed_site_ids=null accepts any in-company site');

// ---------------------------------------------------------------------------
// 8. asset scoping + site default from asset
// ---------------------------------------------------------------------------
$env = wizMakeEnv();
$env['categories']->seed(['id' => 10, 'name' => 'Service', 'code' => 'svc', 'portal_visible' => 1]);
$env['sites']->seed(500, 42);
$env['sites']->seed(700, 99);
$env['assets']->seed(10, 500);      // asset on allowed site
$env['assets']->seed(11, 700);      // asset on cross-company site
$account = wizPortalAccount(1, 42, [500]);
$user = wizPortalUser(200);

wizCheck(function () use ($env, $account, $user) {
    // No site_id in body — expect it to default from the asset.
    $ticket = $env['svc']->submitRequest($user, $account, [
        'category_id' => 10, 'asset_id' => 10, 'title' => 'Broken HVAC',
    ]);
    if ($ticket->site_id !== 500) {
        throw new RuntimeException('site_id should default to asset.site_id, got ' . var_export($ticket->site_id, true));
    }
    if ($ticket->asset_id !== 10) {
        throw new RuntimeException('asset_id not persisted');
    }
}, 'submitRequest defaults site_id from asset when only asset_id supplied');

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, [
        'category_id' => 10, 'asset_id' => 11, 'title' => 'cross-tenant',
    ]),
    UnauthorizedException::class,
    'outside the portal account',
    'submitRequest rejects asset on cross-company site'
);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $account, [
        'category_id' => 10, 'asset_id' => 9999, 'title' => 'ghost asset',
    ]),
    InvalidArgumentException::class,
    'not found',
    'submitRequest rejects unknown asset_id'
);

// ---------------------------------------------------------------------------
// 9. priority clamp — portal user cannot self-escalate to p1/p2
// ---------------------------------------------------------------------------
$env['tickets']->store = [];
$env['tickets']->nextId = 1;
$env['events']->rows = [];
$env['audit']->entries = [];

wizCheck(function () use ($env, $account, $user) {
    $ticket = $env['svc']->submitRequest($user, $account, [
        'category_id' => 10, 'site_id' => 500, 'title' => 'urgent',
        'priority' => 'p1_critical',
    ]);
    if ($ticket->priority !== 'p3_normal') {
        throw new RuntimeException('p1 should be clamped to root default (p3_normal), got ' . $ticket->priority);
    }
}, 'submitRequest clamps disallowed priority to root default (no self-escalation)');

// Allowed priority passes through.
$env['tickets']->store = [];
$env['tickets']->nextId = 1;
wizCheck(function () use ($env, $account, $user) {
    $ticket = $env['svc']->submitRequest($user, $account, [
        'category_id' => 10, 'site_id' => 500, 'title' => 'low',
        'priority' => 'p4_low',
    ]);
    if ($ticket->priority !== 'p4_low') {
        throw new RuntimeException('p4_low should pass through, got ' . $ticket->priority);
    }
}, 'submitRequest honors allowed priority (p4_low)');

// ---------------------------------------------------------------------------
// 10. routing rule fires — portal-submitted tickets inherit Phase 3.3 routing
// ---------------------------------------------------------------------------
$env = wizMakeEnv();
$env['categories']->seed(['id' => 10, 'name' => 'Service', 'code' => 'svc', 'portal_visible' => 1]);
$env['sites']->seed(500, 42);
$account = wizPortalAccount(1, 42, [500]);
$user = wizPortalUser(200);

$rule = new TicketRoutingRule();
$rule->id = 1;
$rule->name = 'Portal submissions → triage queue';
$rule->is_active = 1;
$rule->evaluation_order = 10;
$rule->match_source = 'portal';
$rule->action_assign_queue_id = 77;
$env['rulesRepo']->rules[] = $rule;

wizCheck(function () use ($env, $account, $user) {
    $ticket = $env['svc']->submitRequest($user, $account, [
        'category_id' => 10, 'site_id' => 500, 'title' => 'routed submission',
    ]);
    if ($ticket->queue_id !== 77) {
        throw new RuntimeException('routing rule should assign queue_id=77, got ' . var_export($ticket->queue_id, true));
    }
    // auto_routed timeline event must be present.
    $kinds = array_column($env['events']->rows, 'event_kind');
    if (!in_array('auto_routed', $kinds, true)) {
        throw new RuntimeException('auto_routed event missing; kinds=' . implode(',', $kinds));
    }
    // Audit should note routing_rule_id.
    $last = end($env['audit']->entries);
    if (($last->context['routing_rule_id'] ?? null) !== 1) {
        throw new RuntimeException('audit routing_rule_id missing');
    }
}, 'submitRequest runs TicketRoutingService and records the match');

// ---------------------------------------------------------------------------
// 11. inactive / revoked portal_account cannot submit
// ---------------------------------------------------------------------------
$env = wizMakeEnv();
$env['categories']->seed(['id' => 10, 'name' => 'Service', 'code' => 'svc', 'portal_visible' => 1]);
$env['sites']->seed(500, 42);
$deadAccount = wizPortalAccount(1, 42, [500]);
$deadAccount->is_active = false;
$deadAccount->revoked_at = '2026-04-20 10:00:00';
$user = wizPortalUser(200);

wizExpectThrow(
    fn() => $env['svc']->submitRequest($user, $deadAccount, ['category_id' => 10, 'site_id' => 500, 'title' => 'x']),
    UnauthorizedException::class,
    'not usable',
    'submitRequest rejects revoked portal_account'
);

echo "Phase 6.2 portal request wizard — all tests passed\n";
