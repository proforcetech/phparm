<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Ticket;
use App\Models\TicketCloseReason;
use App\Models\TicketFailureCode;
use App\Models\TicketResolutionCode;
use App\Services\Tickets\TicketCloseReasonRepository;
use App\Services\Tickets\TicketController;
use App\Services\Tickets\TicketFailureCodeRepository;
use App\Services\Tickets\TicketResolutionCodeRepository;

/**
 * Phase 3.6 of docs/expansion-plan.md: catalog enforcement when a ticket
 * transitions into a terminal state (resolved/closed).
 *
 * The public TicketController::updateTicket() path would require standing up
 * every collaborator, so these tests exercise the private enforceCloseCatalog
 * helper through reflection — its contract (throw or mutate $body) is the
 * unit under test.
 */

class FakeCloseReasons extends TicketCloseReasonRepository
{
    /** @var array<string, TicketCloseReason> */
    public array $byCode = [];

    public function __construct(array $reasons = [])
    {
        foreach ($reasons as $r) {
            $this->byCode[$r->code] = $r;
        }
    }

    public function findByCode(string $code): ?TicketCloseReason
    {
        return $this->byCode[$code] ?? null;
    }
}

class FakeResolutionCodes extends TicketResolutionCodeRepository
{
    /** @var array<string, TicketResolutionCode> */
    public array $byCode = [];

    public function __construct(array $codes = [])
    {
        foreach ($codes as $c) {
            $this->byCode[$c->code] = $c;
        }
    }

    public function findByCode(string $code): ?object
    {
        return $this->byCode[$code] ?? null;
    }
}

class FakeFailureCodes extends TicketFailureCodeRepository
{
    /** @var array<string, TicketFailureCode> */
    public array $byCode = [];

    public function __construct(array $codes = [])
    {
        foreach ($codes as $c) {
            $this->byCode[$c->code] = $c;
        }
    }

    public function findByCode(string $code): ?object
    {
        return $this->byCode[$code] ?? null;
    }
}

function closeReason(string $code, int $requiresDetail = 0): TicketCloseReason
{
    $r = new TicketCloseReason();
    $r->code = $code;
    $r->name = $code;
    $r->requires_detail = $requiresDetail;
    $r->is_active = 1;
    return $r;
}

function resCode(string $code): TicketResolutionCode
{
    $c = new TicketResolutionCode();
    $c->code = $code;
    $c->name = $code;
    $c->is_active = 1;
    return $c;
}

function failCode(string $code): TicketFailureCode
{
    $c = new TicketFailureCode();
    $c->code = $code;
    $c->name = $code;
    $c->is_active = 1;
    return $c;
}

function existingTicket(array $o = []): Ticket
{
    $t = new Ticket();
    $t->id = 1;
    $t->status = $o['status'] ?? 'in_progress';
    $t->resolution_code = $o['resolution_code'] ?? null;
    $t->close_reason = $o['close_reason'] ?? null;
    return $t;
}

/**
 * Construct TicketController with only the 3 catalog repos populated —
 * everything else is stubbed via newInstanceWithoutConstructor, which is
 * safe because enforceCloseCatalog only touches $closeReasons,
 * $resolutionCodes, $failureCodes.
 */
function ctlWithCatalogs(
    FakeCloseReasons $closeReasons,
    FakeResolutionCodes $resolutionCodes,
    FakeFailureCodes $failureCodes
): TicketController {
    $ctl = (new ReflectionClass(TicketController::class))->newInstanceWithoutConstructor();
    foreach ([
        'closeReasons' => $closeReasons,
        'resolutionCodes' => $resolutionCodes,
        'failureCodes' => $failureCodes,
    ] as $prop => $value) {
        $rp = new ReflectionProperty(TicketController::class, $prop);
        $rp->setAccessible(true);
        $rp->setValue($ctl, $value);
    }
    return $ctl;
}

function invokeEnforce(TicketController $ctl, array &$body, Ticket $existing, string $newStatus): void
{
    $m = new ReflectionMethod(TicketController::class, 'enforceCloseCatalog');
    $m->setAccessible(true);
    $m->invokeArgs($ctl, [&$body, $existing, $newStatus]);
}

function assertThrows(callable $fn, string $expectedFragment, string $label): void
{
    try {
        $fn();
        throw new RuntimeException("FAIL [{$label}]: expected InvalidArgumentException matching '{$expectedFragment}', nothing thrown");
    } catch (InvalidArgumentException $e) {
        if (!str_contains($e->getMessage(), $expectedFragment)) {
            throw new RuntimeException("FAIL [{$label}]: expected message to contain '{$expectedFragment}', got '{$e->getMessage()}'");
        }
        echo "  PASS {$label}\n";
    }
}

function assertNoThrow(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $e) {
        throw new RuntimeException("FAIL [{$label}]: unexpected throw: " . $e->getMessage());
    }
}

echo "Phase 3.6 — close enforcement\n";

// ── Resolved ────────────────────────────────────────────────────────────

$resolutionCodes = new FakeResolutionCodes([resCode('fixed')]);

// 1. Transition to resolved without a code → throws.
$ctl = ctlWithCatalogs(new FakeCloseReasons(), $resolutionCodes, new FakeFailureCodes());
assertThrows(function () use ($ctl) {
    $body = [];
    invokeEnforce($ctl, $body, existingTicket(), 'resolved');
}, 'resolution_code is required', 'resolved without code throws');

// 2. Transition to resolved with unknown code → throws.
assertThrows(function () use ($ctl) {
    $body = ['resolution_code' => 'nonesuch'];
    invokeEnforce($ctl, $body, existingTicket(), 'resolved');
}, "'nonesuch' is not a known code", 'unknown resolution_code throws');

// 3. Transition to resolved with known code → ok.
assertNoThrow(function () use ($ctl) {
    $body = ['resolution_code' => 'fixed'];
    invokeEnforce($ctl, $body, existingTicket(), 'resolved');
}, 'known resolution_code passes');

// 4. Already resolved + body lacks resolution_code → no throw (no transition).
assertNoThrow(function () use ($ctl) {
    $body = ['description' => 'updated'];
    invokeEnforce($ctl, $body, existingTicket(['status' => 'resolved']), 'resolved');
}, 'steady-state resolved edit skips enforcement');

// 5. Inherit resolution_code from existing record on transition.
assertNoThrow(function () use ($ctl) {
    $body = [];
    invokeEnforce(
        $ctl,
        $body,
        existingTicket(['status' => 'in_progress', 'resolution_code' => 'fixed']),
        'resolved'
    );
}, 'inherit resolution_code from existing on transition');

// ── Closed ──────────────────────────────────────────────────────────────

$closeReasonsPlain = new FakeCloseReasons([closeReason('duplicate')]);
$closeReasonsDetailed = new FakeCloseReasons([
    closeReason('duplicate'),
    closeReason('unresolved', 1),
]);

// 6. Closed without reason → throws.
$ctl2 = ctlWithCatalogs($closeReasonsPlain, $resolutionCodes, new FakeFailureCodes());
assertThrows(function () use ($ctl2) {
    $body = [];
    invokeEnforce($ctl2, $body, existingTicket(['status' => 'resolved']), 'closed');
}, 'close_reason is required', 'closed without reason throws');

// 7. Closed with unknown reason → throws.
assertThrows(function () use ($ctl2) {
    $body = ['close_reason' => 'nope'];
    invokeEnforce($ctl2, $body, existingTicket(['status' => 'resolved']), 'closed');
}, "'nope' is not a known reason", 'unknown close_reason throws');

// 8. Closed with known reason, requires_detail=0, no note → ok.
assertNoThrow(function () use ($ctl2) {
    $body = ['close_reason' => 'duplicate'];
    invokeEnforce($ctl2, $body, existingTicket(['status' => 'resolved']), 'closed');
}, 'plain close_reason without note passes');

// 9. Closed with requires_detail=1 and no note → throws.
$ctl3 = ctlWithCatalogs($closeReasonsDetailed, $resolutionCodes, new FakeFailureCodes());
assertThrows(function () use ($ctl3) {
    $body = ['close_reason' => 'unresolved'];
    invokeEnforce($ctl3, $body, existingTicket(['status' => 'resolved']), 'closed');
}, "requires a close_note", 'requires_detail without note throws');

// 10. Closed with requires_detail=1 and whitespace-only note → throws.
assertThrows(function () use ($ctl3) {
    $body = ['close_reason' => 'unresolved', 'close_note' => '   '];
    invokeEnforce($ctl3, $body, existingTicket(['status' => 'resolved']), 'closed');
}, "requires a close_note", 'requires_detail with blank note throws');

// 11. Closed with requires_detail=1 and real note → ok + close_note stripped.
assertNoThrow(function () use ($ctl3) {
    $body = ['close_reason' => 'unresolved', 'close_note' => 'customer ghosted us'];
    invokeEnforce($ctl3, $body, existingTicket(['status' => 'resolved']), 'closed');
    if (array_key_exists('close_note', $body)) {
        throw new RuntimeException('expected close_note to be unset from $body');
    }
}, 'requires_detail with note passes and strips close_note');

// 12. Failure code validation (optional, but must be known when present).
$ctl4 = ctlWithCatalogs(
    $closeReasonsPlain,
    $resolutionCodes,
    new FakeFailureCodes([failCode('hardware_failure')])
);
assertThrows(function () use ($ctl4) {
    $body = ['resolution_code' => 'fixed', 'failure_code' => 'unknown_cause'];
    invokeEnforce($ctl4, $body, existingTicket(), 'resolved');
}, "failure_code 'unknown_cause' is not a known code", 'unknown failure_code throws');

assertNoThrow(function () use ($ctl4) {
    $body = ['resolution_code' => 'fixed', 'failure_code' => 'hardware_failure'];
    invokeEnforce($ctl4, $body, existingTicket(), 'resolved');
}, 'known failure_code passes');

// 13. Already-closed ticket, unrelated edit → no throw.
assertNoThrow(function () use ($ctl3) {
    $body = ['description' => 'late edit'];
    invokeEnforce(
        $ctl3,
        $body,
        existingTicket(['status' => 'closed', 'close_reason' => 'duplicate']),
        'closed'
    );
}, 'steady-state closed edit skips enforcement');

// 14. Non-terminal transition with bogus close_reason still validated.
assertThrows(function () use ($ctl2) {
    $body = ['close_reason' => 'nope'];
    invokeEnforce($ctl2, $body, existingTicket(), 'in_progress');
}, "'nope' is not a known reason", 'stray close_reason still validated');

echo "All Phase 3.6 close-enforcement tests passed.\n";
