<?php

namespace App\Services\Tickets;

use App\Database\Connection;
use App\Models\TicketCategory;
use PDO;
use RuntimeException;

/**
 * Two-level ticket category tree (Phase 3.1 of docs/expansion-plan.md).
 * `parent_id` NULL means top-level; one level of nesting is enforced at the
 * controller layer so UI rendering stays simple.
 */
class TicketCategoryRepository
{
    private const COLUMNS = 'id, division_id, parent_id, code, name, description,
        is_active, portal_visible, default_priority, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, TicketCategory>
     */
    public function listAll(?int $divisionId = null, bool $activeOnly = true): array
    {
        $where = ['1=1'];
        $params = [];
        if ($divisionId !== null) {
            $where[] = '(division_id = :div OR division_id IS NULL)';
            $params['div'] = $divisionId;
        }
        if ($activeOnly) {
            $where[] = 'is_active = 1';
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM ticket_categories WHERE '
            . implode(' AND ', $where) . ' ORDER BY parent_id IS NULL DESC, name ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(
            static fn(array $r) => new TicketCategory($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Portal wizard — top-level portal-visible categories. These become the
     * "request types" the customer-portal user picks from (Phase 6.2).
     * Only active+portal_visible rows with parent_id IS NULL qualify.
     *
     * @return array<int, TicketCategory>
     */
    public function listPortalVisibleRoots(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . self::COLUMNS . ' FROM ticket_categories
             WHERE parent_id IS NULL AND is_active = 1 AND portal_visible = 1
             ORDER BY name ASC'
        );
        return array_map(
            static fn(array $r) => new TicketCategory($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Portal wizard — active+portal_visible subcategories of a given root.
     * Paired with listPortalVisibleRoots so the wizard can route a
     * submission to a concrete subcategory without exposing internal rows.
     *
     * @return array<int, TicketCategory>
     */
    public function listPortalVisibleChildren(int $parentId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_categories
             WHERE parent_id = :pid AND is_active = 1 AND portal_visible = 1
             ORDER BY name ASC'
        );
        $stmt->execute(['pid' => $parentId]);
        return array_map(
            static fn(array $r) => new TicketCategory($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?TicketCategory
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM ticket_categories WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new TicketCategory($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): TicketCategory
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO ticket_categories (division_id, parent_id, code, name, description,
                is_active, portal_visible, default_priority)
             VALUES (:division_id, :parent_id, :code, :name, :description,
                :is_active, :portal_visible, :default_priority)'
        );
        $stmt->execute([
            'division_id' => $data['division_id'] ?? null,
            'parent_id' => $data['parent_id'] ?? null,
            'code' => $data['code'],
            'name' => $data['name'],
            'description' => $data['description'] ?? null,
            'is_active' => isset($data['is_active']) ? (int) (bool) $data['is_active'] : 1,
            'portal_visible' => isset($data['portal_visible']) ? (int) (bool) $data['portal_visible'] : 0,
            'default_priority' => $data['default_priority'] ?? 'p3_normal',
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('ticket_categories insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): TicketCategory
    {
        $fields = [];
        $params = ['id' => $id];
        foreach (['division_id', 'parent_id', 'code', 'name', 'description', 'default_priority'] as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = $data[$col];
            }
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = (int) (bool) $data['is_active'];
        }
        if (array_key_exists('portal_visible', $data)) {
            $fields[] = 'portal_visible = :portal_visible';
            $params['portal_visible'] = (int) (bool) $data['portal_visible'];
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("ticket_category {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE ticket_categories SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("ticket_category {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM ticket_categories WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
