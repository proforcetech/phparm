<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Ticket;
use App\Models\TicketEscalationEvent;
use App\Models\TicketEscalationRule;
use App\Services\Tickets\SlaClockService;
use App\Services\Tickets\TicketEscalationEventRepository;
use App\Services\Tickets\TicketEscalationRuleRepository;
use App\Services\Tickets\TicketEscalationService;
use App\Services\Tickets\TicketEventRepository;
use App\Services\Tickets\TicketRepository;
use App\Services\Tickets\TicketSlaClockRepository;
use App\Services\Tickets\TicketSlaPolicyRepository;

/**
 * Phase 3.4 of docs/expansion-plan.md: TicketEscalationService trigger
 * semantics + cooldown gate.
 */

function esc_rule(array $o = []): TicketEscalationRule
{
    $r = new TicketEscalationRule();
    $r->id = $o['id'] ?? 1;
    $r->name = $o['name'] ?? 'test';
    $r->is_active = 1;
    $r->trigger_kind = $o['trigger_kind'] ?? 'stale';
    $r->trigger_minutes = $o['trigger_minutes'] ?? null;
    $r->trigger_seconds = $o['trigger_seconds'] ?? null;
    $r->trigger_sla_kind = $o['trigger_sla_kind'] ?? null;
    $r->match_priority = $o['match_priority'] ?? null;
    $r->match_status = $o['match_status'] ?? null;
    $r->action_raise_priority_to = $o['action_raise_priority_to'] ?? null;
    $r->action_reassign_queue_id = $o['action_reassign_queue_id'] ?? null;
    $r->action_notify_user_id = $o['action_notify_user_id'] ?? null;
    $r->cooldown_minutes = $o['cooldown_minutes'] ?? 60;
    return $r;
}

function esc_ticket(array $o = []): Ticket
{
    $t = new Ticket();
    $t->id = $o['id'] ?? 1;
    $t->status = $o['status'] ?? 'new';
    $t->priority = $o['priority'] ?? 'p3_normal';
    $t->updated_at = $o['updated_at'] ?? date('Y-m-d H:i:s', time() - 3600); // default 1 hr ago
    return $t;
}

class FakeTicketsRepo extends TicketRepository
{
    public array $open = [];
    public array $updates = [];
    public function __construct(array $open) { $this->open = $open; }
    public function listOpen(): array { return $this->open; }
    public function update(int $id, array $data): Ticket
    {
        $this->updates[] = ['id' => $id, 'data' => $data];
        return esc_ticket(['id' => $id] + $data);
    }
}

class FakeEscRulesRepo extends TicketEscalationRuleRepository
{
    public array $rules = [];
    public function __construct(array $rules) { $this->rules = $rules; }
    public function listAll(bool $activeOnly = false): array { return $this->rules; }
}

class FakeEscEventsRepo extends TicketEscalationEventRepository
{
    public array $records = [];
    public ?string $lastAt = null;
    public function __construct() {}
    public function lastFiredAt(int $ticketId, int $ruleId): ?string { return $this->lastAt; }
    public function record(int $ticketId, int $ruleId, array $actions): TicketEscalationEvent
    {
        $this->records[] = ['ticket_id' => $ticketId, 'rule_id' => $ruleId, 'actions' => $actions];
        $e = new TicketEscalationEvent();
        $e->ticket_id = $ticketId;
        $e->rule_id = $ruleId;
        $e->actions_applied = $actions;
        return $e;
    }
}

class FakeTimelineRepo extends TicketEventRepository
{
    public array $events = [];
    public function __construct() {}
    public function create(array $data): \App\Models\TicketEvent
    {
        $this->events[] = $data;
        $e = new \App\Models\TicketEvent();
        $e->id = count($this->events);
        return $e;
    }
}

class FakeSla extends SlaClockService
{
    public array $snap;
    public function __construct(array $snap) { $this->snap = $snap; }
    public function snapshot(int $ticketId): array { return $this->snap; }
}

function eq($expected, $actual, string $msg): void
{
    if ($expected !== $actual) {
        echo "FAIL: {$msg} — expected " . var_export($expected, true)
            . ", got " . var_export($actual, true) . "\n";
        exit(1);
    }
    echo "ok — {$msg}\n";
}

// Case 1: stale trigger fires when idle > threshold.
$tickets = new FakeTicketsRepo([
    esc_ticket(['id' => 1, 'updated_at' => date('Y-m-d H:i:s', time() - 3600)]), // 60m idle
]);
$rules = new FakeEscRulesRepo([
    esc_rule(['trigger_kind' => 'stale', 'trigger_minutes' => 30, 'action_raise_priority_to' => 'p1_critical']),
]);
$events = new FakeEscEventsRepo();
$timeline = new FakeTimelineRepo();
$svc = new TicketEscalationService($tickets, $rules, $events, $timeline, new FakeSla([]));
$summary = $svc->runOnce();
eq(1, $summary['fired'], 'stale trigger fires when idle > threshold');
eq('p1_critical', $tickets->updates[0]['data']['priority'], 'priority raised on stale ticket');
eq(1, count($timeline->events), 'timeline event logged');

// Case 2: stale trigger does NOT fire inside threshold.
$tickets = new FakeTicketsRepo([
    esc_ticket(['id' => 1, 'updated_at' => date('Y-m-d H:i:s', time() - 60)]), // 1m idle
]);
$rules = new FakeEscRulesRepo([
    esc_rule(['trigger_kind' => 'stale', 'trigger_minutes' => 30]),
]);
$svc = new TicketEscalationService($tickets, $rules, new FakeEscEventsRepo(), new FakeTimelineRepo(), new FakeSla([]));
eq(0, $svc->runOnce()['fired'], 'stale trigger idle below threshold → no fire');

// Case 3: cooldown blocks re-firing.
$tickets = new FakeTicketsRepo([
    esc_ticket(['id' => 5, 'updated_at' => date('Y-m-d H:i:s', time() - 7200)]), // 120m idle
]);
$rules = new FakeEscRulesRepo([
    esc_rule(['trigger_kind' => 'stale', 'trigger_minutes' => 30, 'cooldown_minutes' => 60]),
]);
$events = new FakeEscEventsRepo();
$events->lastAt = date('Y-m-d H:i:s', time() - 600); // fired 10m ago
$svc = new TicketEscalationService($tickets, $rules, $events, new FakeTimelineRepo(), new FakeSla([]));
$summary = $svc->runOnce();
eq(0, $summary['fired'], 'cooldown blocks re-fire');
eq(1, $summary['skipped_cooldown'], 'cooldown skip counted');

// Case 4: cooldown expired → fires again.
$events2 = new FakeEscEventsRepo();
$events2->lastAt = date('Y-m-d H:i:s', time() - 3600 * 2); // 2h ago, cooldown 60m
$svc = new TicketEscalationService($tickets, $rules, $events2, new FakeTimelineRepo(), new FakeSla([]));
eq(1, $svc->runOnce()['fired'], 'cooldown expired → re-fires');

// Case 5: match_priority scope gates.
$tickets = new FakeTicketsRepo([
    esc_ticket(['id' => 1, 'priority' => 'p3_normal', 'updated_at' => date('Y-m-d H:i:s', time() - 3600)]),
]);
$rules = new FakeEscRulesRepo([
    esc_rule(['trigger_kind' => 'stale', 'trigger_minutes' => 30, 'match_priority' => 'p1_critical']),
]);
$svc = new TicketEscalationService($tickets, $rules, new FakeEscEventsRepo(), new FakeTimelineRepo(), new FakeSla([]));
eq(0, $svc->runOnce()['fired'], 'match_priority scope excludes non-matching ticket');

// Case 6: sla_breached trigger fires when snapshot has breached clock.
$tickets = new FakeTicketsRepo([esc_ticket(['id' => 1])]);
$rules = new FakeEscRulesRepo([
    esc_rule(['trigger_kind' => 'sla_breached', 'trigger_sla_kind' => 'response']),
]);
$sla = new FakeSla([
    ['clock_kind' => 'response', 'status' => 'running', 'is_breached' => true, 'remaining_seconds' => -100],
]);
$svc = new TicketEscalationService($tickets, $rules, new FakeEscEventsRepo(), new FakeTimelineRepo(), $sla);
eq(1, $svc->runOnce()['fired'], 'sla_breached trigger fires on breached clock');

// Case 7: sla_breach_imminent fires when remaining < threshold.
$tickets = new FakeTicketsRepo([esc_ticket(['id' => 1])]);
$rules = new FakeEscRulesRepo([
    esc_rule([
        'trigger_kind' => 'sla_breach_imminent',
        'trigger_seconds' => 600,
        'trigger_sla_kind' => 'response',
    ]),
]);
$sla = new FakeSla([
    ['clock_kind' => 'response', 'status' => 'running', 'is_breached' => false, 'remaining_seconds' => 300],
]);
$svc = new TicketEscalationService($tickets, $rules, new FakeEscEventsRepo(), new FakeTimelineRepo(), $sla);
eq(1, $svc->runOnce()['fired'], 'sla_breach_imminent fires inside threshold');

// Case 8: sla_breach_imminent does not fire when remaining > threshold.
$sla = new FakeSla([
    ['clock_kind' => 'response', 'status' => 'running', 'is_breached' => false, 'remaining_seconds' => 1200],
]);
$svc = new TicketEscalationService($tickets, $rules, new FakeEscEventsRepo(), new FakeTimelineRepo(), $sla);
eq(0, $svc->runOnce()['fired'], 'sla_breach_imminent outside threshold → no fire');

echo "\nAll TicketEscalationService tests passed.\n";
