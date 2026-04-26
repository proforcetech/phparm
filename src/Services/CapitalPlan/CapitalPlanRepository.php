<?php

namespace App\Services\CapitalPlan;

use App\Database\Connection;
use App\Models\CapitalPlan;
use App\Models\CapitalPlanScenario;
use App\Models\CapitalPlanScenarioOverride;
use PDO;
use RuntimeException;

/**
 * Phase 9.3 — CRUD for capital_plans + capital_plan_scenarios +
 * capital_plan_scenario_overrides.
 *
 * The CapitalPlanService composes scenarios on top of the live aging-report
 * snapshot at compute time; this repo is just persistence and lookup.
 *
 * Convention: `findById` returns null on miss; the service decides whether to
 * raise. Cascade deletes on plan and scenario are enforced at the DB layer
 * (see migration 139), so this layer only issues the top-level DELETE.
 */
class CapitalPlanRepository
{
    private const PLAN_COLUMNS = 'id, name, scope_type, scope_id, base_year, horizon_years,
        scoring_model_id, status, notes, created_by_user_id, created_at, updated_at';
    private const SCENARIO_COLUMNS = 'id, capital_plan_id, name, is_baseline, global_options,
        notes, created_at, updated_at';
    private const OVERRIDE_COLUMNS = 'id, scenario_id, site_asset_id, defer_months, pin_to_year,
        replacement_estimate_cents_override, excluded, notes, created_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    // ─────────────────────────────────────────────────────────── plans ────

    /**
     * @param array{scope_type?: string, scope_id?: ?int, status?: string, limit?: int, offset?: int} $filters
     * @return array<int, CapitalPlan>
     */
    public function listPlans(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['scope_type'])) {
            $where[] = 'scope_type = :scope_type';
            $params['scope_type'] = $filters['scope_type'];
        }
        if (array_key_exists('scope_id', $filters)) {
            if ($filters['scope_id'] === null) {
                $where[] = 'scope_id IS NULL';
            } else {
                $where[] = 'scope_id = :scope_id';
                $params['scope_id'] = (int) $filters['scope_id'];
            }
        }
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = implode(' AND ', $where);

        $sql = 'SELECT ' . self::PLAN_COLUMNS . " FROM capital_plans
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => self::hydratePlan($r), $rows);
    }

    public function findPlan(int $id): ?CapitalPlan
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::PLAN_COLUMNS . ' FROM capital_plans WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydratePlan($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createPlan(array $data): CapitalPlan
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO capital_plans
             (name, scope_type, scope_id, base_year, horizon_years,
              scoring_model_id, status, notes, created_by_user_id)
             VALUES
             (:name, :scope_type, :scope_id, :base_year, :horizon_years,
              :scoring_model_id, :status, :notes, :created_by_user_id)'
        );
        $stmt->execute(self::writablePlanParams($data));
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findPlan($id);
        if ($found === null) {
            throw new RuntimeException('capital_plans insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updatePlan(int $id, array $data): CapitalPlan
    {
        $writable = ['name', 'scope_type', 'scope_id', 'base_year', 'horizon_years',
            'scoring_model_id', 'status', 'notes'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = self::castPlanColumn($col, $data[$col]);
            }
        }
        if ($fields !== []) {
            $sql = 'UPDATE capital_plans SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findPlan($id);
        if ($found === null) {
            throw new RuntimeException("capital_plans {$id} not found");
        }
        return $found;
    }

    public function deletePlan(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM capital_plans WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────────────────────── scenarios ────

    /**
     * @return array<int, CapitalPlanScenario>
     */
    public function listScenariosForPlan(int $planId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::SCENARIO_COLUMNS . " FROM capital_plan_scenarios
             WHERE capital_plan_id = :p
             ORDER BY is_baseline DESC, id ASC"
        );
        $stmt->execute(['p' => $planId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => self::hydrateScenario($r), $rows);
    }

    public function findScenario(int $id): ?CapitalPlanScenario
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::SCENARIO_COLUMNS . ' FROM capital_plan_scenarios
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateScenario($row) : null;
    }

    public function findBaselineScenario(int $planId): ?CapitalPlanScenario
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::SCENARIO_COLUMNS . ' FROM capital_plan_scenarios
             WHERE capital_plan_id = :p AND is_baseline = 1
             ORDER BY id ASC LIMIT 1'
        );
        $stmt->execute(['p' => $planId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateScenario($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createScenario(int $planId, array $data): CapitalPlanScenario
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO capital_plan_scenarios
             (capital_plan_id, name, is_baseline, global_options, notes)
             VALUES (:plan, :name, :is_baseline, :global_options, :notes)'
        );
        $stmt->execute([
            'plan' => $planId,
            'name' => (string) ($data['name'] ?? ''),
            'is_baseline' => !empty($data['is_baseline']) ? 1 : 0,
            'global_options' => self::encodeOptions($data['global_options'] ?? null),
            'notes' => self::nullableString($data['notes'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findScenario($id);
        if ($found === null) {
            throw new RuntimeException('capital_plan_scenarios insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateScenario(int $id, array $data): CapitalPlanScenario
    {
        $writable = ['name', 'global_options', 'notes'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            if ($col === 'global_options') {
                $fields[] = 'global_options = :global_options';
                $params['global_options'] = self::encodeOptions($data['global_options']);
            } elseif ($col === 'notes') {
                $fields[] = 'notes = :notes';
                $params['notes'] = self::nullableString($data['notes']);
            } else {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if ($fields !== []) {
            $sql = 'UPDATE capital_plan_scenarios SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findScenario($id);
        if ($found === null) {
            throw new RuntimeException("capital_plan_scenarios {$id} not found");
        }
        return $found;
    }

    public function deleteScenario(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM capital_plan_scenarios WHERE id = :id AND is_baseline = 0'
        );
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────────────────────── overrides ────

    /**
     * @return array<int, CapitalPlanScenarioOverride>
     */
    public function listOverridesForScenario(int $scenarioId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::OVERRIDE_COLUMNS . " FROM capital_plan_scenario_overrides
             WHERE scenario_id = :s
             ORDER BY id ASC"
        );
        $stmt->execute(['s' => $scenarioId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => self::hydrateOverride($r), $rows);
    }

    public function findOverride(int $id): ?CapitalPlanScenarioOverride
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::OVERRIDE_COLUMNS . ' FROM capital_plan_scenario_overrides
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateOverride($row) : null;
    }

    /**
     * Insert-or-update: an override is unique per (scenario_id, site_asset_id),
     * so a planner toggling the same asset twice should overwrite.
     *
     * @param array<string, mixed> $data
     */
    public function upsertOverride(int $scenarioId, int $assetId, array $data): CapitalPlanScenarioOverride
    {
        $params = [
            'scenario' => $scenarioId,
            'asset' => $assetId,
            'defer_months' => isset($data['defer_months']) && $data['defer_months'] !== ''
                ? (int) $data['defer_months'] : null,
            'pin_to_year' => isset($data['pin_to_year']) && $data['pin_to_year'] !== ''
                ? max(0, (int) $data['pin_to_year']) : null,
            'override_cents' => isset($data['replacement_estimate_cents_override'])
                && $data['replacement_estimate_cents_override'] !== ''
                ? max(0, (int) $data['replacement_estimate_cents_override']) : null,
            'excluded' => !empty($data['excluded']) ? 1 : 0,
            'notes' => self::nullableString($data['notes'] ?? null),
        ];

        $existingId = null;
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM capital_plan_scenario_overrides
             WHERE scenario_id = :s AND site_asset_id = :a LIMIT 1'
        );
        $stmt->execute(['s' => $scenarioId, 'a' => $assetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $existingId = (int) $row['id'];
        }

        if ($existingId !== null) {
            $upd = $this->connection->pdo()->prepare(
                'UPDATE capital_plan_scenario_overrides SET
                    defer_months = :defer_months,
                    pin_to_year = :pin_to_year,
                    replacement_estimate_cents_override = :override_cents,
                    excluded = :excluded,
                    notes = :notes
                 WHERE id = :id'
            );
            $params['id'] = $existingId;
            $upd->execute($params);
            $found = $this->findOverride($existingId);
        } else {
            $ins = $this->connection->pdo()->prepare(
                'INSERT INTO capital_plan_scenario_overrides
                    (scenario_id, site_asset_id, defer_months, pin_to_year,
                     replacement_estimate_cents_override, excluded, notes)
                 VALUES
                    (:scenario, :asset, :defer_months, :pin_to_year,
                     :override_cents, :excluded, :notes)'
            );
            $ins->execute($params);
            $newId = (int) $this->connection->pdo()->lastInsertId();
            $found = $this->findOverride($newId);
        }

        if ($found === null) {
            throw new RuntimeException('capital_plan_scenario_overrides upsert did not return a row');
        }
        return $found;
    }

    public function deleteOverride(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM capital_plan_scenario_overrides WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    // ────────────────────────────────────────────────────────── helpers ────

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function writablePlanParams(array $data): array
    {
        return [
            'name' => (string) ($data['name'] ?? ''),
            'scope_type' => (string) ($data['scope_type'] ?? CapitalPlan::SCOPE_PORTFOLIO),
            'scope_id' => isset($data['scope_id']) && $data['scope_id'] !== ''
                ? (int) $data['scope_id'] : null,
            'base_year' => max(1900, min(2999, (int) ($data['base_year'] ?? (int) date('Y')))),
            'horizon_years' => max(1, min(30, (int) ($data['horizon_years'] ?? 5))),
            'scoring_model_id' => isset($data['scoring_model_id']) && $data['scoring_model_id'] !== ''
                ? (int) $data['scoring_model_id'] : null,
            'status' => (string) ($data['status'] ?? CapitalPlan::STATUS_DRAFT),
            'notes' => self::nullableString($data['notes'] ?? null),
            'created_by_user_id' => isset($data['created_by_user_id']) && $data['created_by_user_id'] !== ''
                ? (int) $data['created_by_user_id'] : null,
        ];
    }

    private static function castPlanColumn(string $col, mixed $value): mixed
    {
        if ($col === 'scope_id' || $col === 'scoring_model_id') {
            return $value === null || $value === '' ? null : (int) $value;
        }
        if ($col === 'base_year') {
            return max(1900, min(2999, (int) $value));
        }
        if ($col === 'horizon_years') {
            return max(1, min(30, (int) $value));
        }
        if ($col === 'notes') {
            return self::nullableString($value);
        }
        return $value;
    }

    private static function encodeOptions(mixed $value): ?string
    {
        if ($value === null || $value === '' || $value === []) {
            return null;
        }
        if (is_string($value)) {
            // Allow caller to pre-encode if they want; validate it parses.
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $value : null;
        }
        if (is_array($value)) {
            $encoded = json_encode($value);
            return $encoded === false ? null : $encoded;
        }
        return null;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        return $s === '' ? null : $s;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydratePlan(array $row): CapitalPlan
    {
        return new CapitalPlan($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateScenario(array $row): CapitalPlanScenario
    {
        $row['is_baseline'] = (bool) ($row['is_baseline'] ?? 0);
        return new CapitalPlanScenario($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateOverride(array $row): CapitalPlanScenarioOverride
    {
        $row['excluded'] = (bool) ($row['excluded'] ?? 0);
        return new CapitalPlanScenarioOverride($row);
    }
}
