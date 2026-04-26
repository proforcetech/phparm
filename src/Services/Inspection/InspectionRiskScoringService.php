<?php

namespace App\Services\Inspection;

use App\Database\Connection;
use App\Models\InspectionRiskScore;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Phase 8.4 of docs/expansion-plan.md — risk scoring + trend analysis.
 *
 * Consumes InspectionEstimateBridgeService::identifyFailedItems (same
 * severity vocabulary as Phase 8.2/8.3) and folds each failed item
 * into a weighted score using InspectionRiskScore::SEVERITY_WEIGHTS +
 * a compliance-tag multiplier. Persists one row per report in
 * inspection_risk_scores via upsert so a manual rescore simply
 * overwrites the previous snapshot.
 *
 * Trend surfaces:
 *   - vehicleTrend: chronological series of a vehicle's scored
 *     inspections + aggregate direction (improving / stable /
 *     deteriorating) + delta against the prior window
 *   - divisionTrend: per-bucket aggregates + rollup for a date window,
 *     useful for "is our DOT compliance posture getting better or
 *     worse" management dashboards
 *
 * Gates: scoreReport + rescore → inspections.manage.
 * getReportScore + trends → inspections.view.
 * scoreReportOnCompletion is the completion-hook path: no User in
 * scope, swallows Throwable so a scoring bug never blocks report
 * completion.
 */
class InspectionRiskScoringService
{
    private const MAX_TREND_LIMIT = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly InspectionRiskScoreRepository $repo,
        private readonly InspectionEstimateBridgeService $bridge,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * Manual score / rescore path. Gated inspections.manage.
     *
     * @return array<string, mixed>
     */
    public function scoreReport(User $actor, int $reportId): array
    {
        $this->gate->assert($actor, 'inspections.manage');
        $score = $this->runScoring($reportId, $actor->id);
        if ($score === null) {
            throw new InvalidArgumentException("inspection report {$reportId} not found");
        }
        return $this->serialize($score);
    }

    /**
     * Completion-hook variant. Swallows Throwable + emits hook_failure
     * audit so a scoring bug never blocks report completion. Returns
     * null if the report can't be scored (not found, or exception).
     *
     * @return array<string, mixed>|null
     */
    public function scoreReportOnCompletion(int $reportId, ?int $actorId): ?array
    {
        try {
            $score = $this->runScoring($reportId, $actorId);
            return $score !== null ? $this->serialize($score) : null;
        } catch (Throwable $e) {
            $this->log('inspection.risk_score.hook_failure', $reportId, $actorId, [
                'message' => $e->getMessage(),
            ]);
            return null;
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getReportScore(User $actor, int $reportId): ?array
    {
        $this->gate->assert($actor, 'inspections.view');
        $score = $this->repo->findByReportId($reportId);
        return $score !== null ? $this->serialize($score) : null;
    }

    /**
     * Per-vehicle trend series. Returns chronological scores +
     * summary statistics + a qualitative direction.
     *
     * @return array<string, mixed>
     */
    public function vehicleTrend(
        User $actor,
        int $vehicleId,
        ?string $from = null,
        ?string $to = null,
        int $limit = 100,
    ): array {
        $this->gate->assert($actor, 'inspections.view');
        if ($vehicleId <= 0) {
            throw new InvalidArgumentException('vehicle_id must be a positive integer');
        }
        [$fromN, $toN] = $this->normalizeWindow($from, $to);
        $limit = max(1, min($limit, self::MAX_TREND_LIMIT));

        $scores = $this->repo->listForVehicle($vehicleId, $fromN, $toN, $limit);
        $series = array_map(fn(InspectionRiskScore $s) => $this->serializeSeriesPoint($s), $scores);

        return [
            'vehicle_id' => $vehicleId,
            'from' => $fromN,
            'to' => $toN,
            'count' => count($series),
            'series' => $series,
            'summary' => $this->summarize($scores),
            'direction' => $this->direction($scores),
        ];
    }

    /**
     * Division-scoped trend aggregate. `from` + `to` are required to
     * bound the aggregate window since division-level data volume can
     * be substantial.
     *
     * @return array<string, mixed>
     */
    public function divisionTrend(
        User $actor,
        int $divisionId,
        string $from,
        string $to,
        int $limit = 500,
    ): array {
        $this->gate->assert($actor, 'inspections.view');
        if ($divisionId <= 0) {
            throw new InvalidArgumentException('division_id must be a positive integer');
        }
        [$fromN, $toN] = $this->normalizeWindow($from, $to);
        if ($fromN === null || $toN === null) {
            throw new InvalidArgumentException('from and to are required for division trend');
        }
        $limit = max(1, min($limit, self::MAX_TREND_LIMIT));

        $scores = $this->repo->listForDivision($divisionId, $fromN, $toN, $limit);

        $byLevel = [
            InspectionRiskScore::RISK_LEVEL_LOW => 0,
            InspectionRiskScore::RISK_LEVEL_MODERATE => 0,
            InspectionRiskScore::RISK_LEVEL_ELEVATED => 0,
            InspectionRiskScore::RISK_LEVEL_HIGH => 0,
            InspectionRiskScore::RISK_LEVEL_CRITICAL => 0,
        ];
        foreach ($scores as $s) {
            $byLevel[$s->risk_level] = ($byLevel[$s->risk_level] ?? 0) + 1;
        }

        return [
            'division_id' => $divisionId,
            'from' => $fromN,
            'to' => $toN,
            'count' => count($scores),
            'summary' => $this->summarize($scores),
            'direction' => $this->direction($scores),
            'by_risk_level' => $byLevel,
            'series' => array_map(fn(InspectionRiskScore $s) => $this->serializeSeriesPoint($s), $scores),
        ];
    }

    // ── Internal scoring pipeline ────────────────────────────────────────

    private function runScoring(int $reportId, ?int $actorId): ?InspectionRiskScore
    {
        $context = $this->fetchReportContext($reportId);
        if ($context === null) {
            return null;
        }

        $failedItems = $this->bridge->identifyFailedItems($reportId);
        $itemIds = array_map(static fn(array $i) => (int) $i['id'], $failedItems);
        $tagByReportItem = $this->fetchComplianceTagByReportItem($itemIds);

        $total = 0.0;
        $failed = 0;
        $counts = ['critical' => 0, 'high' => 0, 'medium' => 0, 'low' => 0];
        $taggedCount = 0;

        foreach ($failedItems as $item) {
            $severity = strtolower((string) ($item['severity'] ?? 'low'));
            $weight = InspectionRiskScore::SEVERITY_WEIGHTS[$severity] ?? 0.0;
            if ($weight <= 0.0) {
                continue;
            }
            $hasTag = isset($tagByReportItem[(int) $item['id']])
                && $tagByReportItem[(int) $item['id']] !== null;
            if ($hasTag) {
                $weight *= InspectionRiskScore::COMPLIANCE_TAG_MULTIPLIER;
                $taggedCount++;
            }
            $total += $weight;
            $failed++;
            if (isset($counts[$severity])) {
                $counts[$severity]++;
            }
        }

        $totalRounded = round($total, 2);
        $level = $this->bucketLevel($totalRounded);

        $data = [
            'inspection_report_id' => $reportId,
            'vehicle_id' => $context['vehicle_id'],
            'customer_id' => $context['customer_id'],
            'division_id' => $context['division_id'],
            'total_score' => $totalRounded,
            'risk_level' => $level,
            'failed_item_count' => $failed,
            'critical_count' => $counts['critical'],
            'high_count' => $counts['high'],
            'medium_count' => $counts['medium'],
            'low_count' => $counts['low'],
            'compliance_tagged_count' => $taggedCount,
            'scored_by_user_id' => $actorId,
        ];

        $existing = $this->repo->findByReportId($reportId);
        $id = $this->repo->upsert($data);

        $this->log(
            $existing === null ? 'inspection.risk_score.created' : 'inspection.risk_score.updated',
            $reportId,
            $actorId,
            [
                'total_score' => $totalRounded,
                'risk_level' => $level,
                'failed_item_count' => $failed,
                'critical_count' => $counts['critical'],
            ],
        );

        return $this->repo->findByReportId($reportId);
    }

    private function bucketLevel(float $score): string
    {
        if ($score <= 0.0) {
            return InspectionRiskScore::RISK_LEVEL_LOW;
        }
        if ($score >= InspectionRiskScore::LEVEL_THRESHOLDS[InspectionRiskScore::RISK_LEVEL_CRITICAL]) {
            return InspectionRiskScore::RISK_LEVEL_CRITICAL;
        }
        if ($score >= InspectionRiskScore::LEVEL_THRESHOLDS[InspectionRiskScore::RISK_LEVEL_HIGH]) {
            return InspectionRiskScore::RISK_LEVEL_HIGH;
        }
        if ($score >= InspectionRiskScore::LEVEL_THRESHOLDS[InspectionRiskScore::RISK_LEVEL_ELEVATED]) {
            return InspectionRiskScore::RISK_LEVEL_ELEVATED;
        }
        return InspectionRiskScore::RISK_LEVEL_MODERATE;
    }

    /**
     * @return array{vehicle_id:?int, customer_id:?int, division_id:?int}|null
     */
    private function fetchReportContext(int $reportId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT r.id AS report_id,
                    r.vehicle_id AS vehicle_id,
                    r.customer_id AS customer_id,
                    t.division_id AS division_id
             FROM inspection_reports r
             LEFT JOIN inspection_templates t ON t.id = r.template_id
             WHERE r.id = :id
             LIMIT 1'
        );
        $stmt->execute(['id' => $reportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return [
            'vehicle_id' => $row['vehicle_id'] !== null ? (int) $row['vehicle_id'] : null,
            'customer_id' => $row['customer_id'] !== null ? (int) $row['customer_id'] : null,
            'division_id' => $row['division_id'] !== null ? (int) $row['division_id'] : null,
        ];
    }

    /**
     * @param array<int, int> $reportItemIds
     * @return array<int, ?int>
     */
    private function fetchComplianceTagByReportItem(array $reportItemIds): array
    {
        if ($reportItemIds === []) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($reportItemIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ri.id AS report_item_id, ii.compliance_tag_id AS compliance_tag_id
             FROM inspection_report_items ri
             JOIN inspection_items ii ON ii.id = ri.template_item_id
             WHERE ri.id IN (' . $placeholders . ')'
        );
        $stmt->execute($reportItemIds);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $out[(int) $row['report_item_id']] = $row['compliance_tag_id'] !== null
                ? (int) $row['compliance_tag_id']
                : null;
        }
        return $out;
    }

    // ── Trend helpers ────────────────────────────────────────────────────

    /**
     * @param array<int, InspectionRiskScore> $scores
     * @return array<string, mixed>
     */
    private function summarize(array $scores): array
    {
        if ($scores === []) {
            return [
                'avg_score' => null,
                'min_score' => null,
                'max_score' => null,
                'total_failed_items' => 0,
                'total_critical' => 0,
                'total_high' => 0,
                'total_tagged' => 0,
            ];
        }
        $sum = 0.0;
        $min = PHP_FLOAT_MAX;
        $max = -PHP_FLOAT_MAX;
        $failed = 0;
        $crit = 0;
        $high = 0;
        $tagged = 0;
        foreach ($scores as $s) {
            $sum += $s->total_score;
            if ($s->total_score < $min) {
                $min = $s->total_score;
            }
            if ($s->total_score > $max) {
                $max = $s->total_score;
            }
            $failed += $s->failed_item_count;
            $crit += $s->critical_count;
            $high += $s->high_count;
            $tagged += $s->compliance_tagged_count;
        }
        return [
            'avg_score' => round($sum / count($scores), 2),
            'min_score' => round($min, 2),
            'max_score' => round($max, 2),
            'total_failed_items' => $failed,
            'total_critical' => $crit,
            'total_high' => $high,
            'total_tagged' => $tagged,
        ];
    }

    /**
     * Compares the average of the first half of the series to the
     * average of the second half. A widened threshold (10% of the
     * aggregate avg, floor 1.0) prevents "stable" reports from
     * flickering between improving/deteriorating on small noise.
     *
     * @param array<int, InspectionRiskScore> $scores
     * @return array<string, mixed>
     */
    private function direction(array $scores): array
    {
        $count = count($scores);
        if ($count < 2) {
            return [
                'label' => 'insufficient_data',
                'earlier_avg' => null,
                'recent_avg' => null,
                'delta' => null,
            ];
        }

        $mid = intdiv($count, 2);
        $earlier = array_slice($scores, 0, $mid === 0 ? 1 : $mid);
        $recent = array_slice($scores, $count - ($count - ($mid === 0 ? 1 : $mid)));
        if ($earlier === [] || $recent === []) {
            return [
                'label' => 'insufficient_data',
                'earlier_avg' => null,
                'recent_avg' => null,
                'delta' => null,
            ];
        }

        $eAvg = array_sum(array_map(fn(InspectionRiskScore $s) => $s->total_score, $earlier)) / count($earlier);
        $rAvg = array_sum(array_map(fn(InspectionRiskScore $s) => $s->total_score, $recent)) / count($recent);
        $delta = $rAvg - $eAvg;

        $aggregateAvg = ($eAvg + $rAvg) / 2.0;
        $threshold = max(1.0, $aggregateAvg * 0.1);

        if ($delta < -$threshold) {
            $label = 'improving';
        } elseif ($delta > $threshold) {
            $label = 'deteriorating';
        } else {
            $label = 'stable';
        }

        return [
            'label' => $label,
            'earlier_avg' => round($eAvg, 2),
            'recent_avg' => round($rAvg, 2),
            'delta' => round($delta, 2),
        ];
    }

    /**
     * @return array{0:?string, 1:?string}
     */
    private function normalizeWindow(?string $from, ?string $to): array
    {
        $fromN = null;
        $toN = null;
        if ($from !== null && $from !== '') {
            $fromN = $this->parseDate($from, 'from');
        }
        if ($to !== null && $to !== '') {
            $toN = $this->parseDate($to, 'to');
        }
        if ($fromN !== null && $toN !== null && $fromN > $toN) {
            throw new InvalidArgumentException('from must be <= to');
        }
        return [$fromN, $toN];
    }

    private function parseDate(string $raw, string $fieldName): string
    {
        try {
            $dt = new DateTimeImmutable($raw);
        } catch (Exception $e) {
            throw new InvalidArgumentException("{$fieldName} is not a valid date");
        }
        return $dt->format('Y-m-d');
    }

    // ── Serialization + logging ──────────────────────────────────────────

    /**
     * @return array<string, mixed>
     */
    private function serialize(InspectionRiskScore $s): array
    {
        return [
            'id' => $s->id,
            'inspection_report_id' => $s->inspection_report_id,
            'vehicle_id' => $s->vehicle_id,
            'customer_id' => $s->customer_id,
            'division_id' => $s->division_id,
            'total_score' => $s->total_score,
            'risk_level' => $s->risk_level,
            'failed_item_count' => $s->failed_item_count,
            'critical_count' => $s->critical_count,
            'high_count' => $s->high_count,
            'medium_count' => $s->medium_count,
            'low_count' => $s->low_count,
            'compliance_tagged_count' => $s->compliance_tagged_count,
            'scored_at' => $s->scored_at,
            'scored_by_user_id' => $s->scored_by_user_id,
            'created_at' => $s->created_at,
            'updated_at' => $s->updated_at,
        ];
    }

    /**
     * Lighter shape for the trend series — the UI doesn't need every
     * column on every chart point, but does care about the bucket
     * counts for bar-stack rendering.
     *
     * @return array<string, mixed>
     */
    private function serializeSeriesPoint(InspectionRiskScore $s): array
    {
        return [
            'inspection_report_id' => $s->inspection_report_id,
            'scored_at' => $s->scored_at,
            'total_score' => $s->total_score,
            'risk_level' => $s->risk_level,
            'failed_item_count' => $s->failed_item_count,
            'critical_count' => $s->critical_count,
            'high_count' => $s->high_count,
            'compliance_tagged_count' => $s->compliance_tagged_count,
        ];
    }

    /**
     * @param array<string, mixed> $context
     */
    private function log(string $event, int $entityId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }
        $this->audit->log(new AuditEntry($event, 'inspection_risk_score', (string) $entityId, $actorId, $context));
    }
}
