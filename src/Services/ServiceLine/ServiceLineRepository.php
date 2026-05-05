<?php

namespace App\Services\ServiceLine;

use App\Database\Connection;
use App\Models\ServiceLine;
use InvalidArgumentException;
use PDO;
use PDOException;
use RuntimeException;

/**
 * Data-access layer for the service_lines / user_service_lines tables and
 * the users.primary_service_line_id column. Mirrors {@see DivisionRepository}.
 *
 * All SQL is parameterized; no string interpolation of caller-supplied values.
 */
class ServiceLineRepository
{
    /**
     * Whitelist of values allowed in service_lines.subject_column. Mirrors the
     * subject FK columns the application actually understands (see
     * SubjectResolver::allSubjectColumns). NULL is also allowed and means
     * "no subject FK required for this line."
     */
    public const ALLOWED_SUBJECT_COLUMNS = ['vehicle_id', 'site_asset_id'];

    /** Column list shared by every SELECT so adding a column is a one-line edit. */
    private const COLUMNS = 'id, slug, name, description, icon, sort_order, is_active,
                            subject_column, subject_required, subject_label,
                            created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, ServiceLine>
     */
    public function listActive(): array
    {
        $sql = 'SELECT ' . self::COLUMNS . '
                FROM service_lines
                WHERE is_active = 1
                ORDER BY sort_order, name';

        $stmt = $this->connection->pdo()->query($sql);
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn (array $row) => ServiceLine::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, ServiceLine>
     */
    public function listAll(): array
    {
        $sql = 'SELECT ' . self::COLUMNS . '
                FROM service_lines
                ORDER BY sort_order, name';

        $stmt = $this->connection->pdo()->query($sql);
        if ($stmt === false) {
            return [];
        }

        return array_map(
            static fn (array $row) => ServiceLine::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?ServiceLine
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM service_lines WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ServiceLine::fromRow($row) : null;
    }

    public function findBySlug(string $slug): ?ServiceLine
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . '
             FROM service_lines WHERE slug = :slug LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ServiceLine::fromRow($row) : null;
    }

    /**
     * @param array{
     *     slug: string,
     *     name: string,
     *     description?: ?string,
     *     icon?: ?string,
     *     sort_order?: int,
     *     is_active?: bool,
     *     subject_column?: ?string,
     *     subject_required?: bool,
     *     subject_label?: ?string
     * } $data
     */
    public function create(array $data): ServiceLine
    {
        if ($this->findBySlug($data['slug']) !== null) {
            throw new InvalidArgumentException("Service line slug '{$data['slug']}' already exists");
        }

        $subjectColumn = $this->normalizeSubjectColumn($data['subject_column'] ?? null);

        try {
            $stmt = $this->connection->pdo()->prepare(
                'INSERT INTO service_lines (slug, name, description, icon, sort_order, is_active,
                                            subject_column, subject_required, subject_label)
                 VALUES (:slug, :name, :description, :icon, :sort_order, :is_active,
                         :subject_column, :subject_required, :subject_label)'
            );
            $stmt->execute([
                'slug' => $data['slug'],
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
                'icon' => $data['icon'] ?? null,
                'sort_order' => (int) ($data['sort_order'] ?? 0),
                'is_active' => (int) ($data['is_active'] ?? true),
                'subject_column' => $subjectColumn,
                'subject_required' => (int) (bool) ($data['subject_required'] ?? false),
                'subject_label' => $this->normalizeSubjectLabel($data['subject_label'] ?? null),
            ]);
        } catch (PDOException $e) {
            // 23000 covers MySQL's duplicate-key error on the unique slug index.
            if ($e->getCode() === '23000') {
                throw new InvalidArgumentException(
                    "Service line slug '{$data['slug']}' already exists",
                    0,
                    $e
                );
            }
            throw $e;
        }

        $id = (int) $this->connection->pdo()->lastInsertId();
        $serviceLine = $this->findById($id);

        if ($serviceLine === null) {
            throw new RuntimeException('Failed to load newly created service line');
        }

        return $serviceLine;
    }

    /**
     * Partial update. Only keys present in $data are written to the row.
     *
     * @param array{
     *     slug?: string,
     *     name?: string,
     *     description?: ?string,
     *     icon?: ?string,
     *     sort_order?: int,
     *     is_active?: bool,
     *     subject_column?: ?string,
     *     subject_required?: bool,
     *     subject_label?: ?string
     * } $data
     */
    public function update(int $id, array $data): ServiceLine
    {
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new RuntimeException("Service line {$id} not found");
        }

        $fields = [];
        $params = ['id' => $id];

        foreach (['slug', 'name', 'description', 'icon'] as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (array_key_exists('sort_order', $data)) {
            $fields[] = 'sort_order = :sort_order';
            $params['sort_order'] = (int) $data['sort_order'];
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) $data['is_active'];
        }
        if (array_key_exists('subject_column', $data)) {
            $fields[] = 'subject_column = :subject_column';
            $params['subject_column'] = $this->normalizeSubjectColumn($data['subject_column']);
        }
        if (array_key_exists('subject_required', $data)) {
            $fields[] = 'subject_required = :subject_required';
            $params['subject_required'] = (int) (bool) $data['subject_required'];
        }
        if (array_key_exists('subject_label', $data)) {
            $fields[] = 'subject_label = :subject_label';
            $params['subject_label'] = $this->normalizeSubjectLabel($data['subject_label']);
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE service_lines SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $updated = $this->findById($id);
        if ($updated === null) {
            throw new RuntimeException("Service line {$id} not found after update");
        }

        return $updated;
    }

    /**
     * Distinct non-null subject_column values currently in use across all
     * service lines. Used by SubjectResolver::allSubjectColumns so repositories
     * know which optional FK columns to read on a row.
     *
     * @return array<int, string>
     */
    public function distinctSubjectColumns(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT DISTINCT subject_column FROM service_lines
             WHERE subject_column IS NOT NULL AND subject_column != ""'
        );
        if ($stmt === false) {
            return [];
        }
        $cols = [];
        while (($val = $stmt->fetchColumn()) !== false) {
            if (is_string($val) && $val !== '') {
                $cols[] = $val;
            }
        }
        return $cols;
    }

    /**
     * Service lines a user has explicit membership in via user_service_lines.
     *
     * @return array<int, ServiceLine>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT sl.id, sl.slug, sl.name, sl.description, sl.icon, sl.sort_order,
                    sl.is_active, sl.subject_column, sl.subject_required,
                    sl.subject_label, sl.created_at, sl.updated_at
             FROM service_lines sl
             INNER JOIN user_service_lines usl ON usl.service_line_id = sl.id
             WHERE usl.user_id = :user_id
             ORDER BY sl.sort_order, sl.name'
        );
        $stmt->execute(['user_id' => $userId]);

        return array_map(
            static fn (array $row) => ServiceLine::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function getPrimaryForUser(int $userId): ?ServiceLine
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT sl.id, sl.slug, sl.name, sl.description, sl.icon, sl.sort_order,
                    sl.is_active, sl.subject_column, sl.subject_required,
                    sl.subject_label, sl.created_at, sl.updated_at
             FROM service_lines sl
             INNER JOIN users u ON u.primary_service_line_id = sl.id
             WHERE u.id = :user_id
             LIMIT 1'
        );
        $stmt->execute(['user_id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? ServiceLine::fromRow($row) : null;
    }

    /**
     * Set the user's primary line. Requires the user to already be a member
     * of that line (assignUser must have been called first). This mirrors
     * the FK invariant we want to enforce at the application level.
     */
    public function setPrimaryForUser(int $userId, int $serviceLineId): void
    {
        $check = $this->connection->pdo()->prepare(
            'SELECT 1 FROM user_service_lines
             WHERE user_id = :user_id AND service_line_id = :service_line_id
             LIMIT 1'
        );
        $check->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);

        if ($check->fetchColumn() === false) {
            throw new InvalidArgumentException(
                "User {$userId} is not a member of service line {$serviceLineId}"
            );
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE users SET primary_service_line_id = :service_line_id WHERE id = :user_id'
        );
        $stmt->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);
    }

    /**
     * Idempotent membership grant. Safe to call repeatedly.
     */
    public function assignUser(int $userId, int $serviceLineId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT IGNORE INTO user_service_lines (user_id, service_line_id)
             VALUES (:user_id, :service_line_id)'
        );
        $stmt->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);
    }

    /**
     * Remove membership; if the removed line was the user's primary, clear it.
     */
    public function unassignUser(int $userId, int $serviceLineId): void
    {
        $delete = $this->connection->pdo()->prepare(
            'DELETE FROM user_service_lines
             WHERE user_id = :user_id AND service_line_id = :service_line_id'
        );
        $delete->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);

        $clearPrimary = $this->connection->pdo()->prepare(
            'UPDATE users
                SET primary_service_line_id = NULL
              WHERE id = :user_id AND primary_service_line_id = :service_line_id'
        );
        $clearPrimary->execute([
            'user_id' => $userId,
            'service_line_id' => $serviceLineId,
        ]);
    }

    /**
     * Coerce empty strings to null and reject any non-whitelisted value before
     * it hits the DB. The DB column is loose VARCHAR (so a future column can
     * be added without a schema change), so the application enforces the
     * vocabulary instead.
     */
    private function normalizeSubjectColumn(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        if (!in_array($trimmed, self::ALLOWED_SUBJECT_COLUMNS, true)) {
            throw new InvalidArgumentException(
                "Invalid subject_column '{$trimmed}' — allowed: "
                . implode(', ', self::ALLOWED_SUBJECT_COLUMNS) . ', or null.'
            );
        }
        return $trimmed;
    }

    private function normalizeSubjectLabel(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim($value);
        return $trimmed === '' ? null : $trimmed;
    }
}
