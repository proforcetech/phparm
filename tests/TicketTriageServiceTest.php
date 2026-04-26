<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "TicketTriageServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\Ticket;
use App\Models\TicketTriageSuggestion;
use App\Models\User;
use App\Services\Tickets\HeuristicTicketTriageScorer;
use App\Services\Tickets\TicketRepository;
use App\Services\Tickets\TicketTriageController;
use App\Services\Tickets\TicketTriageRepository;
use App\Services\Tickets\TicketTriageScorerInterface;
use App\Services\Tickets\TicketTriageService;
use App\Services\Tickets\TriageScore;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 10.5 of docs/expansion-plan.md — AI triage suggestions on tickets.
 *
 * Covers:
 *   - HeuristicTicketTriageScorer scores urgency keywords + sentiment polarity
 *     correctly and produces priority bucket from urgency.
 *   - TicketTriageRepository CRUD + listForTicket + listPending +
 *     markPriorPendingStale.
 *   - TicketTriageService.generateForTicket marks prior pending stale,
 *     persists the new suggestion, validates priority vocabulary against
 *     a bogus scorer, requires .generate.
 *   - acceptSuggestion writes back to the ticket via TicketRepository,
 *     records applied_changes, supports selective apply_priority/_category/
 *     _assignee flags, refuses re-accept, requires .manage.
 *   - rejectSuggestion records rejection reason, requires non-blank reason,
 *     refuses re-reject, requires .manage.
 *   - State machine: pending → accepted, pending → rejected, pending →
 *     stale; illegal transitions (accept-after-stale) are rejected.
 *   - Controller envelope shape; list filtering by generated_by; pending
 *     queue ordering.
 */

class TtInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function ttSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Tickets table — only the columns TicketRepository's COLUMNS const reads.
    $pdo->exec("CREATE TABLE tickets (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_number TEXT NOT NULL,
        company_id INTEGER NULL,
        site_id INTEGER NULL,
        division_id INTEGER NULL,
        asset_id INTEGER NULL,
        parent_ticket_id INTEGER NULL,
        category_id INTEGER NULL,
        subcategory_id INTEGER NULL,
        priority TEXT NOT NULL DEFAULT 'p3_normal',
        status TEXT NOT NULL DEFAULT 'new',
        title TEXT NOT NULL,
        description TEXT NULL,
        reported_by_contact_id INTEGER NULL,
        reported_by_user_id INTEGER NULL,
        reporter_name TEXT NULL,
        reporter_email TEXT NULL,
        reporter_phone TEXT NULL,
        assigned_to_user_id INTEGER NULL,
        queue_id INTEGER NULL,
        source TEXT NOT NULL DEFAULT 'manual',
        source_ref TEXT NULL,
        close_reason TEXT NULL,
        resolution_code TEXT NULL,
        failure_code TEXT NULL,
        first_response_at TEXT NULL,
        resolved_at TEXT NULL,
        closed_at TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE ticket_triage_suggestions (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        ticket_id INTEGER NOT NULL,
        generated_at TEXT NULL,
        generated_by TEXT NOT NULL DEFAULT 'heuristic_v1',
        suggested_priority TEXT NULL,
        suggested_category_id INTEGER NULL,
        suggested_assignee_user_id INTEGER NULL,
        sentiment_score REAL NULL,
        urgency_score REAL NULL,
        confidence REAL NULL,
        reasoning TEXT NULL,
        status TEXT NOT NULL DEFAULT 'pending',
        accepted_by_user_id INTEGER NULL,
        accepted_at TEXT NULL,
        applied_changes TEXT NULL,
        rejected_by_user_id INTEGER NULL,
        rejected_at TEXT NULL,
        rejection_reason TEXT NULL,
        notes TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )");

    return $pdo;
}

class TtPermissiveGate extends AccessGate
{
    /** @var array<string, bool> */
    public array $denials = [];
    public function __construct()
    {
    }
    public function can(User $user, string $permission, mixed $resource = null): bool
    {
        return empty($this->denials[$permission]);
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (!empty($this->denials[$permission])) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}

/** Test scorer that returns a canned TriageScore — for forcing edge cases. */
class TtCannedScorer implements TicketTriageScorerInterface
{
    public function __construct(
        private readonly TriageScore $score,
        private readonly string $label = 'canned_v1',
    ) {
    }
    public function score(Ticket $ticket): TriageScore
    {
        return $this->score;
    }
    public function label(): string
    {
        return $this->label;
    }
}

function makeTtFixture(?TicketTriageScorerInterface $scorer = null): array
{
    $pdo = ttSetUpDatabase();
    $conn = new TtInMemoryConnection($pdo);
    $gate = new TtPermissiveGate();
    $repo = new TicketTriageRepository($conn);
    $tickets = new TicketRepository($conn);
    $scorer = $scorer ?? new HeuristicTicketTriageScorer();
    $service = new TicketTriageService($repo, $tickets, $scorer, $gate);
    $controller = new TicketTriageController($service);
    return compact('pdo', 'conn', 'gate', 'repo', 'tickets', 'scorer', 'service', 'controller');
}

function ttAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function ttAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function ttAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new RuntimeException("FAIL {$msg}: got " . get_class($e) . " expected {$expectedClass}");
        }
        return;
    }
    throw new RuntimeException("FAIL {$msg}: no exception thrown (expected {$expectedClass})");
}

function makeTtUser(int $id = 7, string $role = 'manager'): User
{
    $u = new User();
    $u->id = $id;
    $u->role = $role;
    return $u;
}

function makeTtTicket(TicketRepository $repo, string $title, ?string $description = null): Ticket
{
    return $repo->create(['title' => $title, 'description' => $description]);
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Model constants ────

$tests['statuses_published'] = function () {
    ttAssertSame(['pending', 'accepted', 'rejected', 'stale'], TicketTriageSuggestion::STATUSES);
};

$tests['allowed_transitions_published'] = function () {
    ttAssertSame(
        ['pending'],
        TicketTriageSuggestion::ALLOWED_TRANSITIONS[TicketTriageSuggestion::STATUS_ACCEPTED]
    );
    ttAssertSame(
        ['pending'],
        TicketTriageSuggestion::ALLOWED_TRANSITIONS[TicketTriageSuggestion::STATUS_REJECTED]
    );
    ttAssertSame(
        ['pending'],
        TicketTriageSuggestion::ALLOWED_TRANSITIONS[TicketTriageSuggestion::STATUS_STALE]
    );
};

$tests['suggested_priorities_published'] = function () {
    ttAssertSame(
        ['p1_critical', 'p2_high', 'p3_normal', 'p4_low'],
        TicketTriageSuggestion::SUGGESTED_PRIORITIES
    );
};

// ──── Heuristic scorer ────

$tests['heuristic_scorer_label'] = function () {
    $scorer = new HeuristicTicketTriageScorer();
    ttAssertSame('heuristic_v1', $scorer->label());
};

$tests['heuristic_score_p1_on_safety_outage'] = function () {
    $scorer = new HeuristicTicketTriageScorer();
    $t = new Ticket(['title' => 'Truck on fire on the lot', 'description' => 'smoke and dangerous fumes — asap']);
    $score = $scorer->score($t);
    ttAssertSame('p1_critical', $score->suggestedPriority);
    ttAssertTrue($score->urgencyScore >= 0.85, 'urgency clamps to p1 bucket');
};

$tests['heuristic_score_p2_on_no_brakes'] = function () {
    $scorer = new HeuristicTicketTriageScorer();
    $t = new Ticket(['title' => 'No brakes on the truck', 'description' => 'pedal goes to the floor']);
    $score = $scorer->score($t);
    ttAssertTrue(in_array($score->suggestedPriority, ['p1_critical', 'p2_high'], true),
        "expected p1/p2, got {$score->suggestedPriority}");
};

$tests['heuristic_score_p4_on_benign_ticket'] = function () {
    $scorer = new HeuristicTicketTriageScorer();
    $t = new Ticket(['title' => 'Question about service hours', 'description' => 'just curious']);
    $score = $scorer->score($t);
    ttAssertSame('p4_low', $score->suggestedPriority);
};

$tests['heuristic_negative_sentiment_bumps_p3_to_p2'] = function () {
    $scorer = new HeuristicTicketTriageScorer();
    // 1 urgency keyword (=0.30) puts us in the p3 bucket; strong negative
    // sentiment then bumps to p2.
    $t = new Ticket([
        'title' => 'Leak in shop bay',
        'description' => 'absolutely terrible service, ruined my day, frustrated and angry',
    ]);
    $score = $scorer->score($t);
    ttAssertSame('p2_high', $score->suggestedPriority);
};

$tests['heuristic_sentiment_null_when_no_tokens_match'] = function () {
    $scorer = new HeuristicTicketTriageScorer();
    $t = new Ticket(['title' => 'Routine inspection', 'description' => 'come by next week']);
    $score = $scorer->score($t);
    ttAssertSame(null, $score->sentimentScore);
};

$tests['heuristic_confidence_grows_with_signals'] = function () {
    $scorer = new HeuristicTicketTriageScorer();
    $weak = $scorer->score(new Ticket(['title' => 'hello', 'description' => '']));
    $strong = $scorer->score(new Ticket([
        'title' => 'fire outage leak',
        'description' => 'broken broken angry frustrated',
    ]));
    ttAssertTrue($strong->confidence > $weak->confidence, 'more matches → higher confidence');
};

$tests['heuristic_reasoning_trace_present'] = function () {
    $scorer = new HeuristicTicketTriageScorer();
    $score = $scorer->score(new Ticket(['title' => 'fire', 'description' => '']));
    ttAssertTrue(is_string($score->reasoning) && str_contains($score->reasoning, 'urgency keywords'));
};

// ──── Repository basics ────

$tests['repo_listForTicket_returns_descending'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'sample');
    $f['repo']->create(['ticket_id' => $t->id, 'status' => 'rejected']);
    $f['repo']->create(['ticket_id' => $t->id, 'status' => 'pending']);
    $rows = $f['repo']->listForTicket($t->id);
    ttAssertSame(2, count($rows));
    ttAssertSame('pending', $rows[0]->status, 'newest id first');
};

$tests['repo_markPriorPendingStale_only_targets_pending'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'sample');
    $f['repo']->create(['ticket_id' => $t->id, 'status' => 'pending']);
    $f['repo']->create(['ticket_id' => $t->id, 'status' => 'accepted']);
    $f['repo']->create(['ticket_id' => $t->id, 'status' => 'pending']);
    $count = $f['repo']->markPriorPendingStale($t->id);
    ttAssertSame(2, $count);
    $rows = $f['repo']->listForTicket($t->id);
    $statuses = array_map(static fn($r) => $r->status, $rows);
    sort($statuses);
    ttAssertSame(['accepted', 'stale', 'stale'], $statuses);
};

$tests['repo_listPending_filters_by_generator'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'sample');
    $f['repo']->create(['ticket_id' => $t->id, 'generated_by' => 'heuristic_v1', 'status' => 'pending']);
    $f['repo']->create(['ticket_id' => $t->id, 'generated_by' => 'openai_v2', 'status' => 'pending']);
    $heur = $f['repo']->listPending(['generated_by' => 'heuristic_v1']);
    ttAssertSame(1, count($heur));
    ttAssertSame('heuristic_v1', $heur[0]->generated_by);
};

// ──── Generation ────

$tests['generate_persists_suggestion_with_scorer_label'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'truck on fire', 'smoking and unsafe');
    $now = new DateTimeImmutable('2026-04-25 10:00:00');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id, $now);
    ttAssertSame($t->id, $sug->ticket_id);
    ttAssertSame('heuristic_v1', $sug->generated_by);
    ttAssertSame('2026-04-25 10:00:00', $sug->generated_at);
    ttAssertSame('pending', $sug->status);
    ttAssertSame('p1_critical', $sug->suggested_priority);
};

$tests['generate_rejects_unknown_ticket'] = function () {
    $f = makeTtFixture();
    ttAssertThrows(
        fn() => $f['service']->generateForTicket(makeTtUser(7), 9999),
        InvalidArgumentException::class
    );
};

$tests['generate_marks_prior_pending_stale'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'truck on fire');
    $first = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $second = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $rows = $f['repo']->listForTicket($t->id);
    ttAssertSame(2, count($rows));
    $byId = [];
    foreach ($rows as $r) {
        $byId[$r->id] = $r->status;
    }
    ttAssertSame('stale', $byId[$first->id], 'first suggestion is staled');
    ttAssertSame('pending', $byId[$second->id], 'second is the live pending');
};

$tests['generate_clamps_unknown_priority_to_null'] = function () {
    // Plug in a buggy scorer that returns an out-of-vocabulary priority.
    $scorer = new TtCannedScorer(new TriageScore(suggestedPriority: 'p99_critical'));
    $f = makeTtFixture($scorer);
    $t = makeTtTicket($f['tickets'], 'sample');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    ttAssertSame(null, $sug->suggested_priority, 'unknown priority is rejected, not persisted');
};

$tests['generate_persists_scorer_label_from_canned'] = function () {
    $scorer = new TtCannedScorer(new TriageScore(), 'openai_gpt4_v3');
    $f = makeTtFixture($scorer);
    $t = makeTtTicket($f['tickets'], 'sample');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    ttAssertSame('openai_gpt4_v3', $sug->generated_by);
};

// ──── Accept ────

$tests['accept_writes_priority_back_to_ticket'] = function () {
    $scorer = new TtCannedScorer(new TriageScore(
        suggestedPriority: 'p1_critical',
        sentimentScore: -0.5,
        urgencyScore: 0.95,
        confidence: 0.9,
    ));
    $f = makeTtFixture($scorer);
    $t = makeTtTicket($f['tickets'], 'sample');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);

    $accepted = $f['service']->acceptSuggestion(makeTtUser(7), $sug->id);

    ttAssertSame('accepted', $accepted->status);
    ttAssertSame(7, $accepted->accepted_by_user_id);
    $reloaded = $f['tickets']->findById($t->id);
    ttAssertSame('p1_critical', $reloaded->priority, 'ticket priority was updated');

    $applied = json_decode($accepted->applied_changes, true);
    ttAssertSame('p1_critical', $applied['priority']);
};

$tests['accept_writes_category_and_assignee_back'] = function () {
    $scorer = new TtCannedScorer(new TriageScore(
        suggestedPriority: 'p2_high',
        suggestedCategoryId: 42,
        suggestedAssigneeUserId: 99,
    ));
    $f = makeTtFixture($scorer);
    $t = makeTtTicket($f['tickets'], 'sample');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);

    $f['service']->acceptSuggestion(makeTtUser(7), $sug->id);
    $reloaded = $f['tickets']->findById($t->id);
    ttAssertSame(42, $reloaded->category_id);
    ttAssertSame(99, $reloaded->assigned_to_user_id);
    ttAssertSame('p2_high', $reloaded->priority);
};

$tests['accept_supports_selective_apply_priority_only'] = function () {
    $scorer = new TtCannedScorer(new TriageScore(
        suggestedPriority: 'p1_critical',
        suggestedCategoryId: 42,
        suggestedAssigneeUserId: 99,
    ));
    $f = makeTtFixture($scorer);
    $t = makeTtTicket($f['tickets'], 'sample');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);

    $accepted = $f['service']->acceptSuggestion(makeTtUser(7), $sug->id, [
        'apply_priority' => true,
        'apply_category' => false,
        'apply_assignee' => false,
    ]);

    $reloaded = $f['tickets']->findById($t->id);
    ttAssertSame('p1_critical', $reloaded->priority);
    ttAssertSame(null, $reloaded->category_id, 'category not applied');
    ttAssertSame(null, $reloaded->assigned_to_user_id, 'assignee not applied');

    $applied = json_decode($accepted->applied_changes, true);
    ttAssertSame(['priority' => 'p1_critical'], $applied);
};

$tests['accept_with_no_suggested_fields_is_no_op_on_ticket'] = function () {
    $scorer = new TtCannedScorer(new TriageScore());
    $f = makeTtFixture($scorer);
    $t = makeTtTicket($f['tickets'], 'sample');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);

    $accepted = $f['service']->acceptSuggestion(makeTtUser(7), $sug->id);
    ttAssertSame('accepted', $accepted->status);

    $reloaded = $f['tickets']->findById($t->id);
    ttAssertSame('p3_normal', $reloaded->priority, 'priority unchanged');
    $applied = json_decode($accepted->applied_changes, true);
    ttAssertSame([], $applied);
};

$tests['accept_blocked_when_already_accepted'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $f['service']->acceptSuggestion(makeTtUser(7), $sug->id);
    ttAssertThrows(
        fn() => $f['service']->acceptSuggestion(makeTtUser(7), $sug->id),
        InvalidArgumentException::class
    );
};

$tests['accept_blocked_when_already_stale'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $first = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    // Re-generate makes first stale.
    $f['service']->generateForTicket(makeTtUser(7), $t->id);
    ttAssertThrows(
        fn() => $f['service']->acceptSuggestion(makeTtUser(7), $first->id),
        InvalidArgumentException::class,
        'cannot accept a stale suggestion'
    );
};

// ──── Reject ────

$tests['reject_records_reason'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $rejected = $f['service']->rejectSuggestion(makeTtUser(7), $sug->id, 'false positive — old ticket');
    ttAssertSame('rejected', $rejected->status);
    ttAssertSame('false positive — old ticket', $rejected->rejection_reason);
    ttAssertSame(7, $rejected->rejected_by_user_id);
};

$tests['reject_requires_non_blank_reason'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    ttAssertThrows(
        fn() => $f['service']->rejectSuggestion(makeTtUser(7), $sug->id, '   '),
        InvalidArgumentException::class
    );
};

$tests['reject_blocked_when_already_rejected'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $f['service']->rejectSuggestion(makeTtUser(7), $sug->id, 'no');
    ttAssertThrows(
        fn() => $f['service']->rejectSuggestion(makeTtUser(7), $sug->id, 'no again'),
        InvalidArgumentException::class
    );
};

$tests['accept_after_reject_is_blocked'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $f['service']->rejectSuggestion(makeTtUser(7), $sug->id, 'no');
    ttAssertThrows(
        fn() => $f['service']->acceptSuggestion(makeTtUser(7), $sug->id),
        InvalidArgumentException::class
    );
};

// ──── Permissions ────

$tests['view_requires_view_permission'] = function () {
    $f = makeTtFixture();
    $f['gate']->denials['ticket_triage.view'] = true;
    ttAssertThrows(
        fn() => $f['service']->listForTicket(makeTtUser(7), 1),
        UnauthorizedException::class
    );
};

$tests['generate_requires_generate_permission'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $f['gate']->denials['ticket_triage.generate'] = true;
    ttAssertThrows(
        fn() => $f['service']->generateForTicket(makeTtUser(7), $t->id),
        UnauthorizedException::class
    );
};

$tests['accept_requires_manage_permission'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $f['gate']->denials['ticket_triage.manage'] = true;
    ttAssertThrows(
        fn() => $f['service']->acceptSuggestion(makeTtUser(7), $sug->id),
        UnauthorizedException::class
    );
};

$tests['reject_requires_manage_permission'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $f['gate']->denials['ticket_triage.manage'] = true;
    ttAssertThrows(
        fn() => $f['service']->rejectSuggestion(makeTtUser(7), $sug->id, 'no'),
        UnauthorizedException::class
    );
};

// ──── Service-level reads ────

$tests['list_for_ticket_returns_descending'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire');
    $a = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $b = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $rows = $f['service']->listForTicket(makeTtUser(7), $t->id);
    ttAssertSame(2, count($rows));
    ttAssertSame($b->id, $rows[0]->id, 'newest first');
};

$tests['list_pending_excludes_accepted_and_stale'] = function () {
    $f = makeTtFixture();
    $t1 = makeTtTicket($f['tickets'], 'one');
    $t2 = makeTtTicket($f['tickets'], 'two');
    $t3 = makeTtTicket($f['tickets'], 'three');
    $s1 = $f['service']->generateForTicket(makeTtUser(7), $t1->id);
    $s2 = $f['service']->generateForTicket(makeTtUser(7), $t2->id);
    $s3 = $f['service']->generateForTicket(makeTtUser(7), $t3->id);
    $f['service']->acceptSuggestion(makeTtUser(7), $s1->id);
    // Re-scoring t2 stales s2.
    $f['service']->generateForTicket(makeTtUser(7), $t2->id);

    $pending = $f['service']->listPending(makeTtUser(7));
    $ids = array_map(static fn($r) => $r->id, $pending);
    ttAssertTrue(!in_array($s1->id, $ids, true), 's1 (accepted) excluded');
    ttAssertTrue(!in_array($s2->id, $ids, true), 's2 (staled) excluded');
    ttAssertTrue(in_array($s3->id, $ids, true), 's3 (still pending) included');
};

$tests['find_unknown_suggestion_throws'] = function () {
    $f = makeTtFixture();
    ttAssertThrows(
        fn() => $f['service']->findSuggestion(makeTtUser(7), 9999),
        InvalidArgumentException::class
    );
};

// ──── Controller envelope ────

$tests['controller_generate_returns_data_envelope'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'fire on lot');
    $resp = $f['controller']->generateForTicket(makeTtUser(7), $t->id);
    ttAssertTrue(array_key_exists('data', $resp));
    ttAssertSame('pending', $resp['data']['status']);
    ttAssertSame($t->id, $resp['data']['ticket_id']);
};

$tests['controller_accept_passes_apply_flags_through'] = function () {
    $scorer = new TtCannedScorer(new TriageScore(
        suggestedPriority: 'p1_critical',
        suggestedCategoryId: 5,
    ));
    $f = makeTtFixture($scorer);
    $t = makeTtTicket($f['tickets'], 'sample');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $resp = $f['controller']->acceptSuggestion(makeTtUser(7), $sug->id, [
        'apply_priority' => true,
        'apply_category' => false,
    ]);
    ttAssertSame('accepted', $resp['data']['status']);
    $applied = json_decode($resp['data']['applied_changes'], true);
    ttAssertSame(['priority' => 'p1_critical'], $applied);
};

$tests['controller_reject_requires_reason_in_body'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'sample');
    $sug = $f['service']->generateForTicket(makeTtUser(7), $t->id);
    ttAssertThrows(
        fn() => $f['controller']->rejectSuggestion(makeTtUser(7), $sug->id, []),
        InvalidArgumentException::class
    );
};

$tests['controller_list_pending_returns_data_envelope'] = function () {
    $f = makeTtFixture();
    $t = makeTtTicket($f['tickets'], 'sample');
    $f['service']->generateForTicket(makeTtUser(7), $t->id);
    $resp = $f['controller']->listPending(makeTtUser(7), []);
    ttAssertTrue(array_key_exists('data', $resp));
    ttAssertSame(1, count($resp['data']));
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "TicketTriageServiceTest\n";
$pass = 0;
$fail = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  ✓ {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  ✗ {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}
echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    exit(1);
}
