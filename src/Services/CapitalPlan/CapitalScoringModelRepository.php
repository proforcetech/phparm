<?php

namespace App\Services\CapitalPlan;

use App\Database\Connection;
use App\Models\CapitalScoringModel;
use PDO;
use RuntimeException;

/**
 * Phase 9.2 — CRUD + active-model resolution for capital_scoring_models.
 *
 * Resolution preference for a given division (highest priority first):
 *   1) division-specific row, is_default = 1
 *   2) division-specific row (any, by id desc)
 *   3) global row (division_id IS NULL), is_default = 1
 *   4) global row (any, by id desc)
 *   5) CapitalScoringModel::fallback()  (built-in defaults)
 *
 * setDefault is a transactional toggle: only one row per (division_id) can
 * have is_default = 1 at a time.
 */
class CapitalScoringModelRepository
{
    private const COLUMNS = 'id, division_id, name, is_default,
        condition_weight, age_weight, replace_by_weight,
        watch_threshold, action_threshold, urgent_threshold,
        annual_inflation_rate, notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, CapitalScoringModel>
     */
    public function listAll(?int $divisionId = null, bool $globalOnly = false): array
    {
        $where = '1=1';
        $params = [];
        if ($globalOnly) {
            $where .= ' AND division_id IS NULL';
        } elseif ($divisionId !== null) {
            $where .= ' AND (division_id = :division_id OR division_id IS NULL)';
            $params['division_id'] = $divisionId;
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . " FROM capital_scoring_models
             WHERE {$where} ORDER BY division_id IS NULL, division_id, is_default DESC, id ASC"
        );
        $stmt->execute($params);
        return array_map(
            static fn(array $r) => self::hydrate($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?CapitalScoringModel
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM capital_scoring_models WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrate($row) : null;
    }

    /**
     * Pick the model that should govern scoring for a given division. Never
     * returns null — falls back to built-in defaults so the caller can blindly
     * use the result without a null guard.
     */
    public function findActiveForDivision(?int $divisionId): CapitalScoringModel
    {
        $pdo = $this->connection->pdo();
        if ($divisionId !== null) {
            // 1) division-specific default
            $stmt = $pdo->prepare(
                'SELECT ' . self::COLUMNS . ' FROM capital_scoring_models
                 WHERE division_id = :d AND is_default = 1
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['d' => $divisionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return self::hydrate($row);
            }
            // 2) any division-specific row
            $stmt = $pdo->prepare(
                'SELECT ' . self::COLUMNS . ' FROM capital_scoring_models
                 WHERE division_id = :d
                 ORDER BY id DESC LIMIT 1'
            );
            $stmt->execute(['d' => $divisionId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                return self::hydrate($row);
            }
        }
        // 3) global default
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM capital_scoring_models
             WHERE division_id IS NULL AND is_default = 1
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return self::hydrate($row);
        }
        // 4) any global row
        $stmt = $pdo->prepare(
            'SELECT ' . self::COLUMNS . ' FROM capital_scoring_models
             WHERE division_id IS NULL
             ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            return self::hydrate($row);
        }
        // 5) built-in
        return CapitalScoringModel::fallback();
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): CapitalScoringModel
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO capital_scoring_models
             (division_id, name, is_default,
              condition_weight, age_weight, replace_by_weight,
              watch_threshold, action_threshold, urgent_threshold,
              annual_inflation_rate, notes)
             VALUES
             (:division_id, :name, :is_default,
              :condition_weight, :age_weight, :replace_by_weight,
              :watch_threshold, :action_threshold, :urgent_threshold,
              :annual_inflation_rate, :notes)'
        );
        $stmt->execute(self::writableParams($data));
        $id = (int) $this->connection->pdo()->lastInsertId();
        if (!empty($data['is_default'])) {
            $this->setDefault($id);
        }
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('capital_scoring_models insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): CapitalScoringModel
    {
        $writable = [
            'division_id', 'name', 'is_default',
            'condition_weight', 'age_weight', 'replace_by_weight',
            'watch_threshold', 'action_threshold', 'urgent_threshold',
            'annual_inflation_rate', 'notes',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = self::castColumnValue($col, $data[$col]);
            }
        }
        if ($fields !== []) {
            $sql = 'UPDATE capital_scoring_models SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        if (array_key_exists('is_default', $data) && !empty($data['is_default'])) {
            $this->setDefault($id);
        }
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("capital_scoring_models {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM capital_scoring_models WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    /**
     * Make $id the default for its division. Clears is_default on every other
     * row in the same division (including the NULL "global" cohort).
     */
    public function setDefault(int $id): void
    {
        $current = $this->findById($id);
        if ($current === null) {
            throw new RuntimeException("capital_scoring_models {$id} not found");
        }
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            if ($current->division_id === null) {
                $stmt = $pdo->prepare(
                    'UPDATE capital_scoring_models SET is_default = 0
                     WHERE division_id IS NULL AND id <> :id'
                );
                $stmt->execute(['id' => $id]);
            } else {
                $stmt = $pdo->prepare(
                    'UPDATE capital_scoring_models SET is_default = 0
                     WHERE division_id = :d AND id <> :id'
                );
                $stmt->execute(['d' => $current->division_id, 'id' => $id]);
            }
            $stmt = $pdo->prepare('UPDATE capital_scoring_models SET is_default = 1 WHERE id = :id');
            $stmt->execute(['id' => $id]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function writableParams(array $data): array
    {
        return [
            'division_id' => isset($data['division_id']) && $data['division_id'] !== null
                ? (int) $data['division_id'] : null,
            'name' => (string) ($data['name'] ?? ''),
            'is_default' => !empty($data['is_default']) ? 1 : 0,
            'condition_weight' => self::clampWeight($data['condition_weight']
                ?? CapitalScoringModel::DEFAULT_CONDITION_WEIGHT),
            'age_weight' => self::clampWeight($data['age_weight']
                ?? CapitalScoringModel::DEFAULT_AGE_WEIGHT),
            'replace_by_weight' => self::clampWeight($data['replace_by_weight']
                ?? CapitalScoringModel::DEFAULT_REPLACE_BY_WEIGHT),
            'watch_threshold' => self::clampThreshold($data['watch_threshold']
                ?? CapitalScoringModel::DEFAULT_WATCH_THRESHOLD),
            'action_threshold' => self::clampThreshold($data['action_threshold']
                ?? CapitalScoringModel::DEFAULT_ACTION_THRESHOLD),
            'urgent_threshold' => self::clampThreshold($data['urgent_threshold']
                ?? CapitalScoringModel::DEFAULT_URGENT_THRESHOLD),
            'annual_inflation_rate' => self::clampInflation($data['annual_inflation_rate']
                ?? CapitalScoringModel::DEFAULT_INFLATION_RATE),
            'notes' => isset($data['notes']) && $data['notes'] !== '' ? (string) $data['notes'] : null,
        ];
    }

    private static function castColumnValue(string $col, mixed $value): mixed
    {
        if ($col === 'division_id') {
            return $value === null ? null : (int) $value;
        }
        if ($col === 'is_default') {
            return !empty($value) ? 1 : 0;
        }
        if (in_array($col, ['condition_weight', 'age_weight', 'replace_by_weight'], true)) {
            return self::clampWeight($value);
        }
        if (in_array($col, ['watch_threshold', 'action_threshold', 'urgent_threshold'], true)) {
            return self::clampThreshold($value);
        }
        if ($col === 'annual_inflation_rate') {
            return self::clampInflation($value);
        }
        if ($col === 'notes') {
            return $value === null || $value === '' ? null : (string) $value;
        }
        return $value;
    }

    private static function clampWeight(mixed $value): float
    {
        return round(max(0.0, min(1.0, (float) $value)), 3);
    }

    private static function clampThreshold(mixed $value): float
    {
        return round(max(0.0, min(100.0, (float) $value)), 2);
    }

    /** Sanity bound on inflation: -50% to +50% per year. */
    private static function clampInflation(mixed $value): float
    {
        return round(max(-0.5, min(0.5, (float) $value)), 4);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrate(array $row): CapitalScoringModel
    {
        // BaseModel handles int/float casts; bool needs explicit truthy mapping
        // because the DB sends '0'/'1' as strings.
        $row['is_default'] = (bool) ($row['is_default'] ?? 0);
        return new CapitalScoringModel($row);
    }
}
