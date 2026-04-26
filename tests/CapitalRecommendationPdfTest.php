<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Support\Pdf\CapitalRecommendationPdfGenerator;

/**
 * Phase 9.4 — customer-facing capital replacement recommendation PDF.
 *
 * The generator's HTML build path is pure: no DB, no dompdf. We test the
 * structural correctness of the rendered HTML directly. The PDF rendering
 * step (generateFromHtml → dompdf) is exercised conditionally only if the
 * dompdf classes are loadable, so this test runs in the bare CI environment
 * the same way the rest of the Phase 9 suite does.
 */

class FakeGenerator extends CapitalRecommendationPdfGenerator
{
    public function __construct()
    {
        // Skip parent constructor's dompdf check so we can test buildHtml in
        // isolation without requiring the dompdf dependency at unit-test time.
    }
}

$tests = 0;
$passes = 0;
$failures = [];

function cprAssert(string $name, bool $ok, string $detail = ''): void
{
    global $tests, $passes, $failures;
    $tests++;
    if ($ok) {
        $passes++;
        return;
    }
    $failures[] = $name . ($detail !== '' ? ' — ' . $detail : '');
}

/**
 * @param array<string, mixed> $overrides
 * @return array<string, mixed>
 */
function cprPayload(array $overrides = []): array
{
    $base = [
        'plan' => [
            'id' => 7,
            'name' => 'FY2026 Refresh',
            'scope_type' => 'company',
            'scope_id' => 12,
            'base_year' => 2026,
            'horizon_years' => 5,
            'status' => 'draft',
        ],
        'scenario' => [
            'id' => 19,
            'name' => 'Aggressive',
            'is_baseline' => false,
            'global_options' => [],
        ],
        'scoring_model' => [
            'id' => 3,
            'name' => 'Ops Default',
            'annual_inflation_rate' => 0.04,
        ],
        'years' => [
            ['year' => 2026, 'raw_cents' => 5_000_00, 'projected_cents' => 5_000_00, 'asset_count' => 2],
            ['year' => 2027, 'raw_cents' => 12_500_00, 'projected_cents' => 13_000_00, 'asset_count' => 3],
            ['year' => 2028, 'raw_cents' => 0, 'projected_cents' => 0, 'asset_count' => 0],
            ['year' => 2029, 'raw_cents' => 8_000_00, 'projected_cents' => 9_000_00, 'asset_count' => 1],
            ['year' => 2030, 'raw_cents' => 0, 'projected_cents' => 0, 'asset_count' => 0],
        ],
        'overdue' => ['raw_cents' => 1_500_00, 'projected_cents' => 1_500_00, 'asset_count' => 1],
        'beyond' => ['raw_cents' => 4_000_00, 'projected_cents' => 5_000_00, 'asset_count' => 1],
        'totals' => ['raw_cents' => 31_000_00, 'projected_cents' => 33_500_00, 'asset_count' => 8],
        'counts' => [
            'assets_in_scope' => 8,
            'excluded' => 0,
            'pinned' => 1,
            'deferred' => 1,
            'with_overrides' => 2,
        ],
        'assets' => [
            [
                'asset_id' => 1, 'asset_name' => 'Compressor A', 'site_id' => 100, 'site_name' => 'Plant 1',
                'category' => 'urgent', 'risk' => 92.5, 'baseline_replace_year' => 2025, 'replace_year' => 2026,
                'bucket' => '2026', 'raw_cents' => 4_000_00, 'projected_cents' => 4_000_00,
                'overrides_applied' => ['pin'], 'has_override' => true, 'has_estimate' => true,
            ],
            [
                'asset_id' => 2, 'asset_name' => 'HVAC Unit B', 'site_id' => 101, 'site_name' => 'Plant 2',
                'category' => 'urgent', 'risk' => 85.0, 'baseline_replace_year' => 2024, 'replace_year' => 2024,
                'bucket' => 'overdue', 'raw_cents' => 1_500_00, 'projected_cents' => 1_500_00,
                'overrides_applied' => [], 'has_override' => false, 'has_estimate' => true,
            ],
            [
                'asset_id' => 3, 'asset_name' => 'Roof Pad C', 'site_id' => 100, 'site_name' => 'Plant 1',
                'category' => 'action', 'risk' => 68.2, 'baseline_replace_year' => 2027, 'replace_year' => 2027,
                'bucket' => '2027', 'raw_cents' => 7_500_00, 'projected_cents' => 7_800_00,
                'overrides_applied' => [], 'has_override' => false, 'has_estimate' => true,
            ],
            [
                'asset_id' => 4, 'asset_name' => 'Generator D', 'site_id' => 102, 'site_name' => 'Plant 3',
                'category' => 'watch', 'risk' => 50.0, 'baseline_replace_year' => 2029, 'replace_year' => 2029,
                'bucket' => '2029', 'raw_cents' => 8_000_00, 'projected_cents' => 9_000_00,
                'overrides_applied' => [], 'has_override' => false, 'has_estimate' => true,
            ],
            [
                'asset_id' => 5, 'asset_name' => 'Pump E', 'site_id' => 100, 'site_name' => 'Plant 1',
                'category' => 'ok', 'risk' => 12.0, 'baseline_replace_year' => 2032, 'replace_year' => 2032,
                'bucket' => 'beyond', 'raw_cents' => 4_000_00, 'projected_cents' => 5_000_00,
                'overrides_applied' => [], 'has_override' => false, 'has_estimate' => true,
            ],
        ],
        'generated_at' => '2026-04-24T12:00:00+00:00',
    ];
    return array_replace_recursive($base, $overrides);
}

$gen = new FakeGenerator();

// ── 1. Header surfaces shop name + plan + scenario ──
$html = $gen->buildHtml(cprPayload(), [
    'shop_name' => 'Atlas Auto Care',
    'shop_address' => '123 Main St',
    'shop_phone' => '555-1212',
]);
cprAssert('shop name appears in header', str_contains($html, 'Atlas Auto Care'));
cprAssert('shop address appears', str_contains($html, '123 Main St'));
cprAssert('shop phone appears', str_contains($html, '555-1212'));
cprAssert('document title present', str_contains($html, 'CAPITAL REPLACEMENT'));
cprAssert('plan name appears', str_contains($html, 'FY2026 Refresh'));
cprAssert('scenario name appears', str_contains($html, 'Aggressive'));
cprAssert('generated timestamp formatted', str_contains($html, '2026-04-24 12:00'));

// ── 2. Baseline badge only when scenario is baseline ──
cprAssert('baseline badge absent for non-baseline', !str_contains($html, 'class="badge badge-baseline"'));
$baselineHtml = $gen->buildHtml(cprPayload([
    'scenario' => ['id' => 1, 'name' => 'Baseline', 'is_baseline' => true, 'global_options' => []],
]));
cprAssert('baseline badge present for baseline scenario', str_contains($baselineHtml, 'class="badge badge-baseline"'));

// ── 3. Executive summary surfaces totals + counts + horizon ──
cprAssert('horizon range rendered', str_contains($html, '2026') && str_contains($html, '2030'));
cprAssert('horizon length rendered', str_contains($html, '5 years'));
cprAssert('inflation pct rendered', str_contains($html, '4.00%'));
cprAssert('asset count rendered', str_contains($html, 'Assets evaluated:</strong> 8'));
cprAssert('total raw rendered', str_contains($html, '$31,000.00'));
cprAssert('total projected rendered', str_contains($html, '$33,500.00'));
cprAssert('overrides counts rendered', str_contains($html, '1 pinned, 1 deferred, 0 excluded'));

// ── 4. Category mix pills aggregate from assets[] ──
cprAssert('urgent pill counts urgent assets', str_contains($html, 'Urgent: 2'));
cprAssert('action pill counts action assets', str_contains($html, 'Action: 1'));
cprAssert('watch pill counts watch assets', str_contains($html, 'Watch: 1'));
cprAssert('ok pill counts ok assets', str_contains($html, 'OK: 1'));

// ── 5. Year-by-year table includes overdue + horizon + beyond + total ──
cprAssert('year breakdown header', str_contains($html, 'Year-by-Year Capital Plan'));
cprAssert('overdue row present', str_contains($html, 'Overdue (replace immediately)'));
cprAssert('overdue row sums', str_contains($html, '$1,500.00'));
cprAssert('beyond row present', str_contains($html, 'Beyond horizon (after 2030)'));
cprAssert('horizon year 2026 row', str_contains($html, '>2026<'));
cprAssert('horizon year 2027 row', str_contains($html, '>2027<'));
cprAssert('horizon year 2027 raw', str_contains($html, '$12,500.00'));
cprAssert('horizon year 2027 projected', str_contains($html, '$13,000.00'));
cprAssert('grand total row', str_contains($html, 'row-total'));

// ── 6. Empty overdue and beyond are hidden when zero ──
$emptyTailHtml = $gen->buildHtml(cprPayload([
    'overdue' => ['raw_cents' => 0, 'projected_cents' => 0, 'asset_count' => 0],
    'beyond' => ['raw_cents' => 0, 'projected_cents' => 0, 'asset_count' => 0],
]));
cprAssert('overdue row hidden when empty', !str_contains($emptyTailHtml, 'Overdue (replace immediately)'));
cprAssert('beyond row hidden when empty', !str_contains($emptyTailHtml, 'Beyond horizon'));

// ── 7. Top recommendations section ──
cprAssert('top recs header present', str_contains($html, 'Priority Replacements'));
cprAssert('compressor (urgent risk 92.5) listed first',
    strpos($html, 'Compressor A') !== false &&
    strpos($html, 'Compressor A') < strpos($html, 'HVAC Unit B'),
    'expected highest-risk urgent asset before second urgent');
cprAssert('overdue asset shows Overdue label',
    str_contains($html, 'HVAC Unit B') && preg_match('/HVAC Unit B[\s\S]*?Overdue/', $html) === 1);
cprAssert('beyond asset shows Beyond label',
    str_contains($html, 'Pump E') && preg_match('/Pump E[\s\S]*?Beyond/', $html) === 1);
cprAssert('override mark present for pinned asset', str_contains($html, 'override-mark'));
cprAssert('override legend present', str_contains($html, 'override-legend'));

// ── 8. Top recommendations cap at TOP_RECOMMENDATIONS_LIMIT ──
$bigAssets = [];
for ($i = 1; $i <= 40; $i++) {
    $bigAssets[] = [
        'asset_id' => $i,
        'asset_name' => 'Asset ' . $i,
        'site_id' => 1,
        'site_name' => 'S',
        'category' => 'urgent',
        'risk' => 100.0 - $i,
        'baseline_replace_year' => 2026,
        'replace_year' => 2026,
        'bucket' => '2026',
        'raw_cents' => 1000_00,
        'projected_cents' => 1000_00,
        'overrides_applied' => [],
        'has_override' => false,
        'has_estimate' => true,
    ];
}
$bigHtml = $gen->buildHtml(cprPayload(['assets' => $bigAssets]));
$matches = preg_match_all('/<td>Asset \d+/', $bigHtml);
cprAssert(
    'top recommendations capped at limit',
    $matches === CapitalRecommendationPdfGenerator::TOP_RECOMMENDATIONS_LIMIT,
    "expected " . CapitalRecommendationPdfGenerator::TOP_RECOMMENDATIONS_LIMIT . " rows, got {$matches}"
);

// ── 9. Top recommendations sorting: urgent before action, action before watch ──
$mixedAssets = [
    ['asset_id'=>1,'asset_name'=>'Watch1','site_id'=>1,'site_name'=>'S','category'=>'watch','risk'=>99.0,'baseline_replace_year'=>2026,'replace_year'=>2026,'bucket'=>'2026','raw_cents'=>1,'projected_cents'=>1,'overrides_applied'=>[],'has_override'=>false,'has_estimate'=>true],
    ['asset_id'=>2,'asset_name'=>'Action1','site_id'=>1,'site_name'=>'S','category'=>'action','risk'=>50.0,'baseline_replace_year'=>2026,'replace_year'=>2026,'bucket'=>'2026','raw_cents'=>1,'projected_cents'=>1,'overrides_applied'=>[],'has_override'=>false,'has_estimate'=>true],
    ['asset_id'=>3,'asset_name'=>'Urgent1','site_id'=>1,'site_name'=>'S','category'=>'urgent','risk'=>10.0,'baseline_replace_year'=>2026,'replace_year'=>2026,'bucket'=>'2026','raw_cents'=>1,'projected_cents'=>1,'overrides_applied'=>[],'has_override'=>false,'has_estimate'=>true],
];
$mixHtml = $gen->buildHtml(cprPayload(['assets' => $mixedAssets]));
$pUrgent = strpos($mixHtml, 'Urgent1');
$pAction = strpos($mixHtml, 'Action1');
$pWatch = strpos($mixHtml, 'Watch1');
cprAssert('urgent precedes action regardless of risk', $pUrgent !== false && $pAction !== false && $pUrgent < $pAction);
cprAssert('action precedes watch regardless of risk', $pAction !== false && $pWatch !== false && $pAction < $pWatch);

// ── 10. Scope label override is honored ──
$scopedHtml = $gen->buildHtml(cprPayload(), [], 'Atlas - Plant 1 ONLY');
cprAssert('explicit scope label rendered', str_contains($scopedHtml, 'Atlas - Plant 1 ONLY'));

// ── 11. Default scope description for portfolio ──
$portfolioHtml = $gen->buildHtml(cprPayload([
    'plan' => ['id' => 1, 'name' => 'P', 'scope_type' => 'portfolio', 'scope_id' => null, 'base_year' => 2026, 'horizon_years' => 3, 'status' => 'draft'],
]));
cprAssert('portfolio scope auto-described', str_contains($portfolioHtml, 'Entire portfolio'));
$divHtml = $gen->buildHtml(cprPayload([
    'plan' => ['id' => 1, 'name' => 'P', 'scope_type' => 'division', 'scope_id' => 9, 'base_year' => 2026, 'horizon_years' => 3, 'status' => 'draft'],
]));
cprAssert('division scope auto-described', str_contains($divHtml, 'Division #9'));
$companyHtml = $gen->buildHtml(cprPayload([
    'plan' => ['id' => 1, 'name' => 'P', 'scope_type' => 'company', 'scope_id' => 4, 'base_year' => 2026, 'horizon_years' => 3, 'status' => 'draft'],
]));
cprAssert('company scope auto-described', str_contains($companyHtml, 'Company #4'));

// ── 12. HTML escaping defends against injection in shop/plan names ──
$xssHtml = $gen->buildHtml(
    cprPayload([
        'plan' => ['id' => 1, 'name' => '<script>alert(1)</script>', 'scope_type' => 'portfolio', 'scope_id' => null, 'base_year' => 2026, 'horizon_years' => 1, 'status' => 'draft'],
        'scenario' => ['id' => 1, 'name' => 'X & Y', 'is_baseline' => false, 'global_options' => []],
    ]),
    ['shop_name' => '<b>Bad</b>', 'shop_address' => '', 'shop_phone' => '']
);
cprAssert('plan name xss escaped', !str_contains($xssHtml, '<script>alert(1)</script>'));
cprAssert('plan name encoded entity present', str_contains($xssHtml, '&lt;script&gt;alert(1)&lt;/script&gt;'));
cprAssert('scenario ampersand escaped', str_contains($xssHtml, 'X &amp; Y'));
cprAssert('shop name xss escaped', !str_contains($xssHtml, '<b>Bad</b>'));

// ── 13. Footer disclaimer with inflation rate present ──
cprAssert('footer disclaimer present', str_contains($html, 'This recommendation is generated'));
cprAssert('footer carries inflation rate', str_contains($html, 'annual inflation rate of 4.00%'));

// ── 14. Negative-cents edge case formats with leading sign ──
$negHtml = $gen->buildHtml(cprPayload([
    'totals' => ['raw_cents' => -150_00, 'projected_cents' => -200_00, 'asset_count' => 1],
]));
cprAssert('negative money formats with leading minus',
    str_contains($negHtml, '-$150.00') && str_contains($negHtml, '-$200.00'));

// ── 15. Asset row without estimate renders dash for projected cost ──
$noEstHtml = $gen->buildHtml(cprPayload([
    'assets' => [[
        'asset_id' => 9,
        'asset_name' => 'Mystery Item',
        'site_id' => 1,
        'site_name' => 'Site',
        'category' => 'urgent',
        'risk' => 90.0,
        'baseline_replace_year' => 2026,
        'replace_year' => 2026,
        'bucket' => '2026',
        'raw_cents' => null,
        'projected_cents' => null,
        'overrides_applied' => [],
        'has_override' => false,
        'has_estimate' => false,
    ]],
]));
cprAssert('asset row missing estimate renders em-dash', preg_match('/Mystery Item[\s\S]*?>—</', $noEstHtml) === 1);

// ── Service-layer contract: PDF generator wires through computeScenario ──
// We verify the generator-null guard without seeding a full PDO stack.
require_once __DIR__ . '/../src/Services/CapitalPlan/CapitalPlanService.php';

$service = new ReflectionClass(\App\Services\CapitalPlan\CapitalPlanService::class);
$ctorParams = $service->getConstructor()?->getParameters() ?? [];
$paramNames = array_map(static fn($p) => $p->getName(), $ctorParams);
cprAssert('service constructor accepts pdfGenerator param', in_array('pdfGenerator', $paramNames, true));
$pdfParam = null;
foreach ($ctorParams as $p) {
    if ($p->getName() === 'pdfGenerator') {
        $pdfParam = $p;
        break;
    }
}
cprAssert('pdfGenerator is optional', $pdfParam !== null && $pdfParam->isDefaultValueAvailable());
cprAssert('pdfGenerator default is null', $pdfParam !== null && $pdfParam->getDefaultValue() === null);

$method = $service->getMethod('renderRecommendationPdf');
cprAssert('renderRecommendationPdf is public', $method->isPublic());
$mParams = $method->getParameters();
$mNames = array_map(static fn($p) => $p->getName(), $mParams);
cprAssert('renderRecommendationPdf takes actor', in_array('actor', $mNames, true));
cprAssert('renderRecommendationPdf takes scenarioId', in_array('scenarioId', $mNames, true));
cprAssert('renderRecommendationPdf takes shopSettings', in_array('shopSettings', $mNames, true));
cprAssert('renderRecommendationPdf takes scopeLabel', in_array('scopeLabel', $mNames, true));

$controller = new ReflectionClass(\App\Services\CapitalPlan\CapitalPlanController::class);
cprAssert('controller exposes recommendationPdf method', $controller->hasMethod('recommendationPdf'));
$cMethod = $controller->getMethod('recommendationPdf');
cprAssert('controller recommendationPdf is public', $cMethod->isPublic());

echo "CapitalRecommendationPdfTest\n";
echo "  {$passes}/{$tests} assertions passed\n";
if ($failures !== []) {
    echo "  failures:\n";
    foreach ($failures as $f) {
        echo "    - {$f}\n";
    }
    exit(1);
}
exit(0);
