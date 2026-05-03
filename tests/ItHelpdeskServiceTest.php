<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Ticket;
use App\Services\Tickets\ItHelpdeskService;

/**
 * Phase 14 / M8 of docs/woms-expansion-plan.md: ItHelpdeskService overlay
 * rules — auto-derive severity, enforce business_impact on P1/P2, and
 * pass-through cleanly for non-IT tickets.
 */

function itok(bool $cond, string $msg): void
{
    if (!$cond) {
        echo "FAIL: {$msg}\n";
        exit(1);
    }
    echo "ok — {$msg}\n";
}

function itthrows(callable $fn, string $expectSubstr, string $msg): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($expectSubstr === '' || str_contains($e->getMessage(), $expectSubstr)) {
            echo "ok — {$msg}\n";
            return;
        }
        echo "FAIL: {$msg} — wrong message: {$e->getMessage()}\n";
        exit(1);
    }
    echo "FAIL: {$msg} — expected throw but got none\n";
    exit(1);
}

$svc = new ItHelpdeskService();

// 1. Non-IT ticket: pass-through.
$out = $svc->applyRules(['title' => 'oil change', 'priority' => 'p3_normal']);
itok(!array_key_exists('severity', $out), 'non-IT ticket gets no severity assigned');

// 2. Outage with no users specified → defaults to P2; needs business_impact.
itthrows(
    fn() => $svc->applyRules(['it_request_kind' => 'outage', 'title' => 'X']),
    'business_impact is required',
    'outage without business_impact is rejected'
);

// 3. Outage with business_impact → severity P2.
$out = $svc->applyRules([
    'it_request_kind' => 'outage',
    'business_impact' => 'Sales floor down, ~20 users',
    'title' => 'POS offline',
]);
itok($out['severity'] === 'P2', 'outage defaults to P2');

// 4. Incident with 60 affected users → bumped to P2 (needs business_impact).
itthrows(
    fn() => $svc->applyRules([
        'it_request_kind' => 'incident',
        'affected_users_count' => 60,
        'title' => 'mail down',
    ]),
    'business_impact is required',
    'incident with 60 users → P2 → requires business_impact'
);

// 5. Incident with 250 affected → P1.
$out = $svc->applyRules([
    'it_request_kind' => 'incident',
    'affected_users_count' => 250,
    'business_impact' => 'Site-wide email outage',
    'title' => 'mail outage',
]);
itok($out['severity'] === 'P1', 'incident with 250 users escalates to P1');

// 6. Caller-specified high severity overrides low derivation (and triggers
//    business_impact requirement).
itthrows(
    fn() => $svc->applyRules([
        'it_request_kind' => 'request',  // would derive P4
        'severity' => 'P1',              // operator says P1
        'title' => 'CEO laptop',
    ]),
    'business_impact is required',
    'caller-specified P1 still requires business_impact'
);

// 7. Caller-specified severity is never LOWERED by derivation.
$out = $svc->applyRules([
    'it_request_kind' => 'request',
    'severity' => 'P2',
    'business_impact' => 'CEO presentation in 30 min',
    'title' => 'CEO laptop',
]);
itok($out['severity'] === 'P2', 'caller P2 not lowered by request-kind P4 derivation');

// 8. Question with no users → P4, no business_impact required.
$out = $svc->applyRules([
    'it_request_kind' => 'question',
    'title' => 'how to reset password',
]);
itok($out['severity'] === 'P4', 'question defaults to P4');

// 9. Junk severity rejected.
itthrows(
    fn() => $svc->applyRules(['it_request_kind' => 'incident', 'severity' => 'X9', 'title' => 't']),
    'severity must be one of',
    'invalid severity rejected'
);

// 10. Junk it_request_kind rejected.
itthrows(
    fn() => $svc->applyRules(['it_request_kind' => 'meltdown', 'title' => 't']),
    'it_request_kind must be one of',
    'invalid it_request_kind rejected'
);

// 11. Negative affected users rejected.
itthrows(
    fn() => $svc->applyRules([
        'it_request_kind' => 'incident',
        'affected_users_count' => -1,
        'title' => 't',
    ]),
    'affected_users_count must be non-negative',
    'negative affected_users_count rejected'
);

// 12. Existing P1 ticket in update path — body doesn't restate severity, but
//     existing.business_impact carries through, so no error.
$existing = new Ticket();
$existing->severity = 'P1';
$existing->it_request_kind = 'incident';
$existing->business_impact = 'previously documented impact';
$out = $svc->applyRules(['title' => 'updated title'], existing: $existing);
itok($out['severity'] === 'P1', 'update preserves existing P1 severity when body silent');

// 13. Direct deriveSeverity smoke tests.
itok($svc->deriveSeverity('outage', null) === 'P2', 'derive outage/null = P2');
itok($svc->deriveSeverity('outage', 250) === 'P1', 'derive outage/250 = P1');
itok($svc->deriveSeverity('request', 60) === 'P2', 'derive request/60 = P2 (escalated from P4)');
itok($svc->deriveSeverity('question', null) === 'P4', 'derive question/null = P4');

echo "\nAll ItHelpdeskService tests passed.\n";
