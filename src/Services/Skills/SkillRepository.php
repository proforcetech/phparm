<?php

namespace App\Services\Skills;

use App\Database\Connection;
use App\Models\Skill;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `skills` — Phase 17 / S11 of docs/woms-expansion-plan.md.
 *
 * The skills catalog is small (typical deployment is hundreds, not millions)
 * so list() returns flat arrays without paging by default. The dispatch board
 * (Phase 17 / M10) will join through user_skills to find matching technicians
 * — see SkillMatrixService::usersForSkill().
 */
class SkillRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{service_line_id?: int, category?: string, is_active?: bool,
     *              search?: string} $filters
     * @return array<int, Skill>
     */
    public function list(array $filters = [], int $limit = 500, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM skills ' . $where
            . ' ORDER BY sort_order ASC, name ASC, id ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min(1000, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => Skill::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM skills ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?Skill
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM skills WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Skill::fromRow($row) : null;
    }

    public function findBySlug(string $slug): ?Skill
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM skills WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? Skill::fromRow($row) : null;
    }

    /**
     * @param array<int, int> $ids
     * @return array<int, Skill> keyed by id
     */
    public function findManyById(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $ids = array_values(array_unique(array_map('intval', $ids)));
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM skills WHERE id IN (' . $placeholders . ')'
        );
        $stmt->execute($ids);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $skill = Skill::fromRow($row);
            $out[$skill->id] = $skill;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Skill
    {
        $slug = trim((string) ($data['slug'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));
        if ($slug === '' || $name === '') {
            throw new InvalidArgumentException('slug and name are required');
        }
        if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
            throw new InvalidArgumentException('slug must be lowercase letters, digits, or underscores');
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO skills
                (slug, name, description, service_line_id, category, sort_order, is_active)
             VALUES
                (:slug, :name, :description, :service_line_id, :category, :sort_order, :is_active)'
        );
        $stmt->execute([
            'slug' => $slug,
            'name' => $name,
            'description' => $this->nullableString($data['description'] ?? null),
            'service_line_id' => $this->nullableInt($data['service_line_id'] ?? null),
            'category' => $this->nullableString($data['category'] ?? null),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => !empty($data['is_active'] ?? 1) ? 1 : 0,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created skill');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Skill
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("skill {$id} not found");
        }
        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('name cannot be empty');
            }
            $fields[] = 'name = :name';
            $params['name'] = $name;
        }
        foreach (['description', 'category'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $this->nullableString($data[$key]);
            }
        }
        if (array_key_exists('service_line_id', $data)) {
            $fields[] = 'service_line_id = :service_line_id';
            $params['service_line_id'] = $this->nullableInt($data['service_line_id']);
        }
        if (array_key_exists('sort_order', $data)) {
            $fields[] = 'sort_order = :sort_order';
            $params['sort_order'] = (int) $data['sort_order'];
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = !empty($data['is_active']) ? 1 : 0;
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE skills SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("skill {$id} not found after update");
        }
        return $row;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM skills WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (array_key_exists('service_line_id', $filters)) {
            if ($filters['service_line_id'] === null || $filters['service_line_id'] === '' || $filters['service_line_id'] === 'null') {
                $where .= ' AND service_line_id IS NULL';
            } else {
                $where .= ' AND service_line_id = :service_line_id';
                $params['service_line_id'] = (int) $filters['service_line_id'];
            }
        }
        if (!empty($filters['category'])) {
            $where .= ' AND category = :category';
            $params['category'] = (string) $filters['category'];
        }
        if (array_key_exists('is_active', $filters)) {
            $where .= ' AND is_active = :is_active';
            $params['is_active'] = !empty($filters['is_active']) ? 1 : 0;
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (name LIKE :search OR slug LIKE :search OR description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        return [$where, $params];
    }
}
