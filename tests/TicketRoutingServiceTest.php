<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\SiteAsset;
use App\Models\Ticket;
use App\Models\TicketRoutingRule;
use App\Services\Assets\SiteAssetRepository;
use App\Services\Tickets\TicketRoutingRuleRepository;
use App\Services\Tickets\TicketRoutingService;

/**
 * Phase 3.3 of docs/expansion-plan.md: TicketRoutingService first-match
 * semantics + asset-type lookup.
 */

function makeRule(array $overrides = []): TicketRoutingRule
{
    $rule = new TicketRoutingRule();
    $rule->id = $overrides['id'] ?? 0;
    $rule->name = $overrides['name'] ?? 'test';
    $rule->evaluation_order = $overrides['evaluation_order'] ?? 100;
    $rule->is_active = 1;
    foreach ([
        'match_division_id', 'match_company_id', 'match_site_id',
        'match_category_id', 'match_subcategory_id', 'match_priority',
        'match_source', 'match_asset_type_id',
        'action_assign_queue_id', 'action_assign_user_id', 'action_set_priority',
    ] as $field) {
        if (array_key_exists($field, $overrides)) {
            $rule->{$field} = $overrides[$field];
        }
    }
    return $rule;
}

class FakeRoutingRules extends TicketRoutingRuleRepository
{
    /** @var array<int, TicketRoutingRule> */
    public array $rules = [];

    public function __construct(array $rules)
    {
        $this->rules = $rules;
    }

    public function listAll(bool $activeOnly = false): array
    {
        $out = $activeOnly
            ? array_values(array_filter($this->rules, static fn(TicketRoutingRule $r) => $r->is_active === 1))
            : $this->rules;
        usort($out, static function (TicketRoutingRule $a, TicketRoutingRule $b): int {
            return [$a->evaluation_order, $a->id] <=> [$b->evaluation_order, $b->id];
        });
        return $out;
    }
}

class FakeAssets extends SiteAssetRepository
{
    /** @var array<int, SiteAsset> */
    public array $assets = [];

    public function __construct(array $assets)
    {
        $this->assets = $assets;
    }

    public function findById(int $id): ?SiteAsset
    {
        return $this->assets[$id] ?? null;
    }
}

function makeTicket(array $overrides = []): Ticket
{
    $t = new Ticket();
    $t->id = $overrides['id'] ?? 1;
    $t->priority = $overrides['priority'] ?? 'p3_normal';
    $t->source = $overrides['source'] ?? 'manual';
    foreach (['company_id', 'site_id', 'division_id', 'asset_id', 'category_id', 'subcategory_id'] as $f) {
        if (array_key_exists($f, $overrides)) {
            $t->{$f} = $overrides[$f];
        }
    }
    return $t;
}

function assertEq($expected, $actual, string $msg): void
{
    if ($expected !== $actual) {
        echo "FAIL: {$msg} — expected " . var_export($expected, true)
            . ", got " . var_export($actual, true) . "\n";
        exit(1);
    }
    echo "ok — {$msg}\n";
}

// Case 1: first match wins, even when a later rule is more specific.
$rules = [
    makeRule([
        'id' => 1, 'evaluation_order' => 10,
        'match_priority' => 'p1_critical',
        'action_assign_queue_id' => 42,
    ]),
    makeRule([
        'id' => 2, 'evaluation_order' => 20,
        'match_priority' => 'p1_critical',
        'match_source' => 'portal',
        'action_assign_queue_id' => 99,
    ]),
];
$svc = new TicketRoutingService(new FakeRoutingRules($rules), new FakeAssets([]));
$result = $svc->routeTicket(makeTicket(['priority' => 'p1_critical', 'source' => 'portal']));
assertEq(1, $result['rule']->id, 'first-match wins over more-specific later rule');
assertEq(42, $result['actions']['queue_id'], 'first-match queue_id applied');

// Case 2: no match returns null.
$rules = [makeRule(['id' => 1, 'match_priority' => 'p1_critical'])];
$svc = new TicketRoutingService(new FakeRoutingRules($rules), new FakeAssets([]));
$result = $svc->routeTicket(makeTicket(['priority' => 'p3_normal']));
assertEq(null, $result, 'no match returns null');

// Case 3: asset-type matcher pulls from asset.
$asset = new SiteAsset();
$asset->id = 7;
$asset->asset_type_id = 99;
$rules = [
    makeRule([
        'id' => 1,
        'match_asset_type_id' => 99,
        'action_set_priority' => 'p1_critical',
    ]),
];
$svc = new TicketRoutingService(new FakeRoutingRules($rules), new FakeAssets([7 => $asset]));
$result = $svc->routeTicket(makeTicket(['asset_id' => 7]));
assertEq(1, $result['rule']->id, 'asset-type matcher resolves via asset lookup');
assertEq('p1_critical', $result['actions']['priority'], 'action_set_priority surfaced');

// Case 4: only non-null actions surface.
$rules = [
    makeRule([
        'id' => 1,
        'match_priority' => 'p1_critical',
        'action_assign_queue_id' => null,
        'action_assign_user_id' => 5,
        'action_set_priority' => null,
    ]),
];
$svc = new TicketRoutingService(new FakeRoutingRules($rules), new FakeAssets([]));
$result = $svc->routeTicket(makeTicket(['priority' => 'p1_critical']));
assertEq(['assigned_to_user_id' => 5], $result['actions'], 'only non-null actions surface');

// Case 5: evaluation_order respected even when ids are reversed.
$rules = [
    makeRule(['id' => 5, 'evaluation_order' => 10, 'match_priority' => 'p1_critical', 'action_assign_user_id' => 1]),
    makeRule(['id' => 1, 'evaluation_order' => 5, 'match_priority' => 'p1_critical', 'action_assign_user_id' => 2]),
];
$svc = new TicketRoutingService(new FakeRoutingRules($rules), new FakeAssets([]));
$result = $svc->routeTicket(makeTicket(['priority' => 'p1_critical']));
assertEq(2, $result['actions']['assigned_to_user_id'], 'lower evaluation_order evaluated first');

echo "\nAll TicketRoutingService tests passed.\n";
