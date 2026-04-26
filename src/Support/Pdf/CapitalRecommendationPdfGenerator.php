<?php

namespace App\Support\Pdf;

use DateTimeImmutable;

/**
 * Phase 9.4 — customer-facing capital replacement recommendation PDF.
 *
 * Pure builder: consumes the precomputed scenario payload from
 * CapitalPlanService::computeScenario() and renders a printable
 * recommendation document. Does not touch the database — all business
 * data flows through the input payload so the same generator can render
 * a live scenario, a saved snapshot, or a hand-built fixture.
 *
 * Sections:
 *   1) Cover header — shop branding + plan/scenario identity + generated_at
 *   2) Executive summary — assets in scope, total horizon spend (today's
 *      dollars + projected), urgent/action mix, applied overrides count
 *   3) Year-by-year capex breakdown — overdue + horizon years + beyond
 *      with both raw and projected dollar columns plus a grand total row
 *   4) Top recommendations — top-25 highest-risk assets with site,
 *      category, recommended replace year, and projected cost
 *   5) Footer disclaimer
 */
class CapitalRecommendationPdfGenerator extends PdfService
{
    public const TOP_RECOMMENDATIONS_LIMIT = 25;

    /**
     * @param array<string, mixed> $payload  Output of CapitalPlanService::computeScenario()
     * @param array<string, mixed> $settings Shop settings (shop_name, shop_address, shop_phone)
     * @param string|null          $scopeLabel Human-friendly scope label (e.g. "Acme Manufacturing — Portland Plant")
     */
    public function generate(array $payload, array $settings = [], ?string $scopeLabel = null): string
    {
        $html = $this->buildHtml($payload, $settings, $scopeLabel);
        return $this->generateFromHtml($html);
    }

    /**
     * Build the recommendation HTML. Public so tests can assert on the
     * rendered structure without standing up dompdf.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $settings
     */
    public function buildHtml(array $payload, array $settings = [], ?string $scopeLabel = null): string
    {
        $shopName = $this->esc($settings['shop_name'] ?? 'Auto Repair Shop');
        $shopAddress = $this->esc($settings['shop_address'] ?? '');
        $shopPhone = $this->esc($settings['shop_phone'] ?? '');

        $plan = $payload['plan'] ?? [];
        $scenario = $payload['scenario'] ?? [];
        $scoringModel = $payload['scoring_model'] ?? [];
        $totals = $payload['totals'] ?? ['raw_cents' => 0, 'projected_cents' => 0, 'asset_count' => 0];
        $counts = $payload['counts'] ?? [];
        $years = $payload['years'] ?? [];
        $overdue = $payload['overdue'] ?? ['raw_cents' => 0, 'projected_cents' => 0, 'asset_count' => 0];
        $beyond = $payload['beyond'] ?? ['raw_cents' => 0, 'projected_cents' => 0, 'asset_count' => 0];
        $assets = $payload['assets'] ?? [];
        $generatedAt = $payload['generated_at'] ?? (new DateTimeImmutable())->format(DATE_ATOM);

        $planName = $this->esc((string) ($plan['name'] ?? 'Capital Plan'));
        $scenarioName = $this->esc((string) ($scenario['name'] ?? 'Scenario'));
        $isBaseline = !empty($scenario['is_baseline']);
        $baseYear = (int) ($plan['base_year'] ?? (int) date('Y'));
        $horizonYears = (int) ($plan['horizon_years'] ?? 5);
        $endYear = $baseYear + $horizonYears - 1;

        $scopeLine = $scopeLabel !== null && $scopeLabel !== ''
            ? $this->esc($scopeLabel)
            : $this->describeScope($plan);

        $generatedLabel = $this->formatDateTime($generatedAt);
        $inflationPct = number_format(((float) ($scoringModel['annual_inflation_rate'] ?? 0)) * 100, 2) . '%';

        $assetCount = (int) ($counts['assets_in_scope'] ?? $totals['asset_count'] ?? 0);
        $excludedCount = (int) ($counts['excluded'] ?? 0);
        $pinnedCount = (int) ($counts['pinned'] ?? 0);
        $deferredCount = (int) ($counts['deferred'] ?? 0);
        $withOverrides = (int) ($counts['with_overrides'] ?? 0);

        $totalRaw = $this->formatMoney((int) ($totals['raw_cents'] ?? 0));
        $totalProjected = $this->formatMoney((int) ($totals['projected_cents'] ?? 0));

        // ── category mix from asset lines ──
        $categoryMix = ['urgent' => 0, 'action' => 0, 'watch' => 0, 'ok' => 0];
        foreach ($assets as $line) {
            $cat = (string) ($line['category'] ?? 'ok');
            if (!isset($categoryMix[$cat])) {
                $categoryMix[$cat] = 0;
            }
            $categoryMix[$cat]++;
        }

        $html = $this->getBaseStyles();
        $html .= $this->getRecommendationStyles();

        // ── 1) Header ──
        $html .= <<<HTML
<div class="header">
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 60%; border: none;">
                <h1>{$shopName}</h1>
                <div class="company-info">
                    {$shopAddress}<br>
                    {$shopPhone}
                </div>
            </td>
            <td style="width: 40%; border: none; text-align: right;">
                <h2>CAPITAL REPLACEMENT<br>RECOMMENDATION</h2>
                <div class="document-info">
                    <strong>Plan:</strong> {$planName}<br>
                    <strong>Scenario:</strong> {$scenarioName}
HTML;
        if ($isBaseline) {
            $html .= ' <span class="badge badge-baseline">Baseline</span>';
        }
        $html .= <<<HTML
<br>
                    <strong>Generated:</strong> {$generatedLabel}
                </div>
            </td>
        </tr>
    </table>
</div>
HTML;

        // ── 2) Executive summary ──
        $html .= <<<HTML
<div class="exec-summary">
    <h3>Executive Summary</h3>
    <table style="width: 100%; border: none;">
        <tr>
            <td style="width: 50%; border: none; vertical-align: top;">
                <p><strong>Scope:</strong> {$scopeLine}</p>
                <p><strong>Planning horizon:</strong> {$baseYear}&ndash;{$endYear} ({$horizonYears} years)</p>
                <p><strong>Inflation assumption:</strong> {$inflationPct} per year</p>
                <p><strong>Assets evaluated:</strong> {$assetCount}</p>
            </td>
            <td style="width: 50%; border: none; vertical-align: top;">
                <p><strong>Total replacement (today's dollars):</strong> {$totalRaw}</p>
                <p><strong>Total projected (inflated to spend year):</strong> {$totalProjected}</p>
                <p><strong>Overrides applied:</strong> {$withOverrides} ({$pinnedCount} pinned, {$deferredCount} deferred, {$excludedCount} excluded)</p>
            </td>
        </tr>
    </table>
HTML;

        $catUrgent = (int) $categoryMix['urgent'];
        $catAction = (int) $categoryMix['action'];
        $catWatch = (int) $categoryMix['watch'];
        $catOk = (int) $categoryMix['ok'];
        $html .= <<<HTML
    <div class="category-strip">
        <span class="cat-pill cat-urgent">Urgent: {$catUrgent}</span>
        <span class="cat-pill cat-action">Action: {$catAction}</span>
        <span class="cat-pill cat-watch">Watch: {$catWatch}</span>
        <span class="cat-pill cat-ok">OK: {$catOk}</span>
    </div>
</div>
HTML;

        // ── 3) Year-by-year capex breakdown ──
        $html .= <<<HTML
<div class="year-breakdown">
    <h3>Year-by-Year Capital Plan</h3>
    <table>
        <thead>
            <tr>
                <th>Period</th>
                <th class="text-right">Assets</th>
                <th class="text-right">Today's Dollars</th>
                <th class="text-right">Projected (Inflated)</th>
            </tr>
        </thead>
        <tbody>
HTML;
        // Overdue row
        $overdueAssets = (int) ($overdue['asset_count'] ?? 0);
        if ($overdueAssets > 0) {
            $html .= $this->renderYearRow(
                'Overdue (replace immediately)',
                $overdueAssets,
                (int) ($overdue['raw_cents'] ?? 0),
                (int) ($overdue['projected_cents'] ?? 0),
                'row-overdue'
            );
        }

        // Horizon year rows
        foreach ($years as $bucket) {
            $year = (int) ($bucket['year'] ?? 0);
            $html .= $this->renderYearRow(
                (string) $year,
                (int) ($bucket['asset_count'] ?? 0),
                (int) ($bucket['raw_cents'] ?? 0),
                (int) ($bucket['projected_cents'] ?? 0)
            );
        }

        // Beyond row
        $beyondAssets = (int) ($beyond['asset_count'] ?? 0);
        if ($beyondAssets > 0) {
            $html .= $this->renderYearRow(
                'Beyond horizon (after ' . $endYear . ')',
                $beyondAssets,
                (int) ($beyond['raw_cents'] ?? 0),
                (int) ($beyond['projected_cents'] ?? 0),
                'row-beyond'
            );
        }

        $totalAssetCount = (int) ($totals['asset_count'] ?? 0);
        $html .= <<<HTML
        </tbody>
        <tfoot>
            <tr class="row-total">
                <td><strong>Total</strong></td>
                <td class="text-right"><strong>{$totalAssetCount}</strong></td>
                <td class="text-right"><strong>{$totalRaw}</strong></td>
                <td class="text-right"><strong>{$totalProjected}</strong></td>
            </tr>
        </tfoot>
    </table>
</div>
HTML;

        // ── 4) Top recommendations ──
        $topAssets = $this->selectTopRecommendations($assets);
        if ($topAssets !== []) {
            $html .= <<<HTML
<div class="top-recommendations">
    <h3>Priority Replacements (Top {$this->intToWord(count($topAssets))} by Risk)</h3>
    <table>
        <thead>
            <tr>
                <th>Asset</th>
                <th>Site</th>
                <th class="text-center">Category</th>
                <th class="text-right">Replace Year</th>
                <th class="text-right">Projected Cost</th>
            </tr>
        </thead>
        <tbody>
HTML;
            foreach ($topAssets as $line) {
                $assetName = $this->esc((string) ($line['asset_name'] ?? 'Unnamed asset'));
                $siteName = $this->esc((string) ($line['site_name'] ?? '—'));
                $category = (string) ($line['category'] ?? 'ok');
                $catLabel = ucfirst($category);
                $catCss = 'cat-' . preg_replace('/[^a-z]/', '', $category);
                $replaceYear = (int) ($line['replace_year'] ?? 0);
                $bucket = (string) ($line['bucket'] ?? '');
                $yearDisplay = $bucket === 'overdue' ? 'Overdue' : ($bucket === 'beyond' ? 'Beyond' : (string) $replaceYear);
                $projected = $line['projected_cents'] !== null
                    ? $this->formatMoney((int) $line['projected_cents'])
                    : '—';
                $overrideMark = !empty($line['has_override']) ? ' <span class="override-mark" title="Override applied">*</span>' : '';

                $html .= <<<HTML
            <tr>
                <td>{$assetName}{$overrideMark}</td>
                <td>{$siteName}</td>
                <td class="text-center"><span class="cat-pill {$catCss}">{$catLabel}</span></td>
                <td class="text-right">{$yearDisplay}</td>
                <td class="text-right">{$projected}</td>
            </tr>
HTML;
            }
            $html .= <<<HTML
        </tbody>
    </table>
    <p class="override-legend">* Asset has a scenario-specific override (pinned year, deferred, or custom estimate).</p>
</div>
HTML;
        }

        // ── 5) Footer disclaimer ──
        $html .= <<<HTML
<div class="footer">
    <p>This recommendation is generated from current asset condition data and lifecycle projections.
    Actual replacement timing may vary based on operational performance, vendor availability, and budget approvals.
    Projected costs apply an annual inflation rate of {$inflationPct} compounded forward to the planned spend year.</p>
</div>
HTML;

        return $html;
    }

    /**
     * @param array<int, array<string, mixed>> $assets
     * @return array<int, array<string, mixed>>
     */
    private function selectTopRecommendations(array $assets): array
    {
        $sorted = $assets;
        usort($sorted, static function (array $a, array $b): int {
            $catRank = ['urgent' => 4, 'action' => 3, 'watch' => 2, 'ok' => 1];
            $ca = $catRank[$a['category'] ?? 'ok'] ?? 0;
            $cb = $catRank[$b['category'] ?? 'ok'] ?? 0;
            if ($ca !== $cb) {
                return $cb <=> $ca;
            }
            $ra = (float) ($a['risk'] ?? 0);
            $rb = (float) ($b['risk'] ?? 0);
            if ($ra !== $rb) {
                return $rb <=> $ra;
            }
            $pa = (int) ($a['projected_cents'] ?? 0);
            $pb = (int) ($b['projected_cents'] ?? 0);
            return $pb <=> $pa;
        });
        return array_slice($sorted, 0, self::TOP_RECOMMENDATIONS_LIMIT);
    }

    private function renderYearRow(
        string $label,
        int $assetCount,
        int $rawCents,
        int $projectedCents,
        string $rowClass = ''
    ): string {
        $labelEsc = $this->esc($label);
        $raw = $this->formatMoney($rawCents);
        $projected = $this->formatMoney($projectedCents);
        $cls = $rowClass !== '' ? " class=\"{$rowClass}\"" : '';
        return <<<HTML
            <tr{$cls}>
                <td>{$labelEsc}</td>
                <td class="text-right">{$assetCount}</td>
                <td class="text-right">{$raw}</td>
                <td class="text-right">{$projected}</td>
            </tr>
HTML;
    }

    /**
     * @param array<string, mixed> $plan
     */
    private function describeScope(array $plan): string
    {
        $scopeType = (string) ($plan['scope_type'] ?? 'portfolio');
        $scopeId = $plan['scope_id'] ?? null;
        return match ($scopeType) {
            'portfolio' => 'Entire portfolio (all companies and divisions)',
            'company' => 'Company #' . (int) $scopeId,
            'division' => 'Division #' . (int) $scopeId,
            default => 'Unknown scope',
        };
    }

    private function formatMoney(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $abs = abs($cents);
        return $sign . '$' . number_format($abs / 100, 2, '.', ',');
    }

    private function formatDateTime(string $iso): string
    {
        try {
            $dt = new DateTimeImmutable($iso);
            return $dt->format('Y-m-d H:i');
        } catch (\Throwable) {
            return $iso;
        }
    }

    private function intToWord(int $n): string
    {
        return (string) $n;
    }

    private function esc(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function getRecommendationStyles(): string
    {
        return <<<CSS
<style>
    .exec-summary {
        margin: 20px 0;
        padding: 15px;
        background: #f8f9fa;
        border-left: 4px solid #2c3e50;
    }
    .exec-summary p {
        margin: 4px 0;
    }
    .category-strip {
        margin-top: 12px;
    }
    .cat-pill {
        display: inline-block;
        padding: 3px 10px;
        margin-right: 6px;
        border-radius: 12px;
        font-size: 9pt;
        font-weight: bold;
    }
    .cat-urgent { background: #f8d7da; color: #721c24; }
    .cat-action { background: #ffe5b4; color: #7a4d00; }
    .cat-watch  { background: #fff3cd; color: #856404; }
    .cat-ok     { background: #d4edda; color: #155724; }
    .badge {
        display: inline-block;
        padding: 2px 6px;
        border-radius: 3px;
        font-size: 8pt;
        font-weight: bold;
    }
    .badge-baseline { background: #e2e3e5; color: #383d41; }
    .year-breakdown,
    .top-recommendations {
        margin: 25px 0;
        page-break-inside: avoid;
    }
    .row-overdue td { background: #fdf3f4; }
    .row-beyond td  { background: #f3f5f9; color: #555; }
    .row-total td   { border-top: 2px solid #333; background: #f5f5f5; }
    .override-mark  { color: #c0392b; font-weight: bold; }
    .override-legend {
        font-size: 9pt;
        color: #666;
        margin-top: 6px;
    }
</style>
CSS;
    }
}
