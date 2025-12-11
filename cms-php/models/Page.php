<?php
/**
 * Page Model
 * Manages CMS pages with templates and components
 * FixItForUs CMS
 */

namespace CMS\Models;

use CMS\Config\Database;

class Page
{
    private Database $db;
    private string $table;
    private string $templatesTable;
    private string $componentsTable;
    private string $pageComponentsTable;

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->table = $this->db->prefix('pages');
        $this->templatesTable = $this->db->prefix('templates');
        $this->componentsTable = $this->db->prefix('components');
        $this->pageComponentsTable = $this->db->prefix('page_components');
    }

    /**
     * Get all pages
     */
    public function getAll(bool $publishedOnly = false): array
    {
        $sql = "SELECT p.*, t.name as template_name
                FROM {$this->table} p
                LEFT JOIN {$this->templatesTable} t ON p.template_id = t.id";
        if ($publishedOnly) {
            $sql .= " WHERE p.is_published = 1";
        }
        $sql .= " ORDER BY p.sort_order, p.title";
        return $this->db->query($sql);
    }

    /**
     * Get pages with pagination
     */
    public function getPaginated(int $page = 1, int $perPage = 20, bool $publishedOnly = false): array
    {
        $offset = ($page - 1) * $perPage;

        $countSql = "SELECT COUNT(*) as total FROM {$this->table}";
        if ($publishedOnly) {
            $countSql .= " WHERE is_published = 1";
        }
        $totalResult = $this->db->queryOne($countSql);
        $total = (int) ($totalResult['total'] ?? 0);

        $sql = "SELECT p.*, t.name as template_name
                FROM {$this->table} p
                LEFT JOIN {$this->templatesTable} t ON p.template_id = t.id";
        if ($publishedOnly) {
            $sql .= " WHERE p.is_published = 1";
        }
        $sql .= " ORDER BY p.sort_order, p.title LIMIT ? OFFSET ?";

        $pages = $this->db->query($sql, [$perPage, $offset]);

        return [
            'data' => $pages,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => ceil($total / $perPage),
        ];
    }

    /**
     * Get page by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT p.*, t.name as template_name, t.structure as template_structure,
                       t.default_css, t.default_js
                FROM {$this->table} p
                LEFT JOIN {$this->templatesTable} t ON p.template_id = t.id
                WHERE p.id = ?";
        return $this->db->queryOne($sql, [$id]);
    }

    /**
     * Get page by slug
     */
    public function getBySlug(string $slug): ?array
    {
        $sql = "SELECT p.*, t.name as template_name, t.structure as template_structure,
                       t.default_css, t.default_js
                FROM {$this->table} p
                LEFT JOIN {$this->templatesTable} t ON p.template_id = t.id
                WHERE p.slug = ?";
        return $this->db->queryOne($sql, [$slug]);
    }

    /**
     * Get published page by slug (for front-end)
     */
    public function getPublishedBySlug(string $slug): ?array
    {
        $sql = "SELECT p.*, t.name as template_name, t.structure as template_structure,
                       t.default_css, t.default_js,
                       hc.content as header_content, hc.css as header_css, hc.javascript as header_js,
                       fc.content as footer_content, fc.css as footer_css, fc.javascript as footer_js
                FROM {$this->table} p
                LEFT JOIN {$this->templatesTable} t ON p.template_id = t.id
                LEFT JOIN {$this->componentsTable} hc ON p.header_component_id = hc.id AND hc.is_active = 1
                LEFT JOIN {$this->componentsTable} fc ON p.footer_component_id = fc.id AND fc.is_active = 1
                WHERE p.slug = ? AND p.is_published = 1";
        return $this->db->queryOne($sql, [$slug]);
    }

    /**
     * Get child pages
     */
    public function getChildren(int $parentId): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE parent_id = ? ORDER BY sort_order, title";
        return $this->db->query($sql, [$parentId]);
    }

    /**
     * Get parent page
     */
    public function getParent(int $pageId): ?array
    {
        $sql = "SELECT parent.* FROM {$this->table} page
                JOIN {$this->table} parent ON page.parent_id = parent.id
                WHERE page.id = ?";
        return $this->db->queryOne($sql, [$pageId]);
    }

    /**
     * Get page hierarchy (breadcrumbs)
     */
    public function getHierarchy(int $pageId): array
    {
        $hierarchy = [];
        $currentId = $pageId;
        $maxDepth = 10; // Prevent infinite loops

        while ($currentId && $maxDepth > 0) {
            $page = $this->getById($currentId);
            if (!$page) break;

            array_unshift($hierarchy, [
                'id' => $page['id'],
                'title' => $page['title'],
                'slug' => $page['slug'],
            ]);

            $currentId = $page['parent_id'];
            $maxDepth--;
        }

        return $hierarchy;
    }

    /**
     * Create new page
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO {$this->table}
                (slug, title, meta_description, meta_keywords, template_id, content,
                 custom_css, custom_js, header_component_id, footer_component_id,
                 breadcrumbs, is_published, publish_date, cache_ttl, sort_order, parent_id, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $this->generateSlug($data['title'], $data['slug'] ?? null),
            $data['title'],
            $data['meta_description'] ?? null,
            $data['meta_keywords'] ?? null,
            $data['template_id'] ?? null,
            $data['content'],
            $data['custom_css'] ?? null,
            $data['custom_js'] ?? null,
            $data['header_component_id'] ?? null,
            $data['footer_component_id'] ?? null,
            isset($data['breadcrumbs']) ? json_encode($data['breadcrumbs']) : null,
            $data['is_published'] ?? 0,
            $data['publish_date'] ?? null,
            $data['cache_ttl'] ?? 3600,
            $data['sort_order'] ?? 0,
            $data['parent_id'] ?? null,
            $data['created_by'] ?? currentUserId(),
        ];

        $this->db->execute($sql, $params);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update page
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowedFields = [
            'slug', 'title', 'meta_description', 'meta_keywords', 'template_id',
            'content', 'custom_css', 'custom_js', 'header_component_id', 'footer_component_id',
            'breadcrumbs', 'is_published', 'publish_date', 'cache_ttl', 'sort_order', 'parent_id'
        ];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                if ($field === 'breadcrumbs' && is_array($data[$field])) {
                    $params[] = json_encode($data[$field]);
                } else {
                    $params[] = $data[$field];
                }
            }
        }

        if (empty($fields)) {
            return false;
        }

        $fields[] = "updated_by = ?";
        $params[] = currentUserId();
        $params[] = $id;

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = ?";
        return $this->db->execute($sql, $params) > 0;
    }

    /**
     * Delete page
     */
    public function delete(int $id): bool
    {
        // First, update children to have no parent
        $this->db->execute(
            "UPDATE {$this->table} SET parent_id = NULL WHERE parent_id = ?",
            [$id]
        );

        $sql = "DELETE FROM {$this->table} WHERE id = ?";
        return $this->db->execute($sql, [$id]) > 0;
    }

    /**
     * Publish page
     */
    public function publish(int $id): bool
    {
        $sql = "UPDATE {$this->table}
                SET is_published = 1, publish_date = NOW(), updated_by = ?
                WHERE id = ?";
        return $this->db->execute($sql, [currentUserId(), $id]) > 0;
    }

    /**
     * Unpublish page
     */
    public function unpublish(int $id): bool
    {
        $sql = "UPDATE {$this->table}
                SET is_published = 0, updated_by = ?
                WHERE id = ?";
        return $this->db->execute($sql, [currentUserId(), $id]) > 0;
    }

    /**
     * Toggle publish status
     */
    public function togglePublish(int $id): bool
    {
        $sql = "UPDATE {$this->table}
                SET is_published = NOT is_published,
                    publish_date = IF(is_published = 0, NOW(), publish_date),
                    updated_by = ?
                WHERE id = ?";
        return $this->db->execute($sql, [currentUserId(), $id]) > 0;
    }

    /**
     * Check if slug exists
     */
    public function slugExists(string $slug, ?int $excludeId = null): bool
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table} WHERE slug = ?";
        $params = [$slug];

        if ($excludeId !== null) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }

        $result = $this->db->queryOne($sql, $params);
        return ($result['count'] ?? 0) > 0;
    }

    /**
     * Generate unique slug
     */
    public function generateSlug(string $title, ?string $preferredSlug = null): string
    {
        $slug = $preferredSlug ?: $this->slugify($title);
        $originalSlug = $slug;
        $counter = 1;

        while ($this->slugExists($slug)) {
            $slug = $originalSlug . '-' . $counter;
            $counter++;
        }

        return $slug;
    }

    /**
     * Convert string to slug
     */
    private function slugify(string $string): string
    {
        $slug = strtolower(trim($string));
        $slug = preg_replace('/[^a-z0-9-\/]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Get components attached to a page
     */
    public function getPageComponents(int $pageId): array
    {
        $sql = "SELECT c.*, pc.position, pc.sort_order
                FROM {$this->pageComponentsTable} pc
                JOIN {$this->componentsTable} c ON pc.component_id = c.id
                WHERE pc.page_id = ? AND c.is_active = 1
                ORDER BY pc.position, pc.sort_order";
        return $this->db->query($sql, [$pageId]);
    }

    /**
     * Attach component to page
     */
    public function attachComponent(int $pageId, int $componentId, string $position = 'content', int $sortOrder = 0): bool
    {
        $sql = "INSERT INTO {$this->pageComponentsTable} (page_id, component_id, position, sort_order)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE sort_order = ?";
        return $this->db->execute($sql, [$pageId, $componentId, $position, $sortOrder, $sortOrder]) > 0;
    }

    /**
     * Detach component from page
     */
    public function detachComponent(int $pageId, int $componentId, string $position = 'content'): bool
    {
        $sql = "DELETE FROM {$this->pageComponentsTable} WHERE page_id = ? AND component_id = ? AND position = ?";
        return $this->db->execute($sql, [$pageId, $componentId, $position]) > 0;
    }

    /**
     * Search pages
     */
    public function search(string $query, bool $publishedOnly = false): array
    {
        $sql = "SELECT p.*, t.name as template_name
                FROM {$this->table} p
                LEFT JOIN {$this->templatesTable} t ON p.template_id = t.id
                WHERE (p.title LIKE ? OR p.content LIKE ? OR p.slug LIKE ?)";
        if ($publishedOnly) {
            $sql .= " AND p.is_published = 1";
        }
        $sql .= " ORDER BY p.title";

        $searchTerm = "%{$query}%";
        return $this->db->query($sql, [$searchTerm, $searchTerm, $searchTerm]);
    }

    /**
     * Count pages
     */
    public function count(bool $publishedOnly = false): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        if ($publishedOnly) {
            $sql .= " WHERE is_published = 1";
        }
        $result = $this->db->queryOne($sql);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Duplicate page
     */
    public function duplicate(int $id): ?int
    {
        $page = $this->getById($id);
        if (!$page) {
            return null;
        }

        $newData = [
            'title' => $page['title'] . ' (Copy)',
            'meta_description' => $page['meta_description'],
            'meta_keywords' => $page['meta_keywords'],
            'template_id' => $page['template_id'],
            'content' => $page['content'],
            'custom_css' => $page['custom_css'],
            'custom_js' => $page['custom_js'],
            'header_component_id' => $page['header_component_id'],
            'footer_component_id' => $page['footer_component_id'],
            'cache_ttl' => $page['cache_ttl'],
            'parent_id' => $page['parent_id'],
            'is_published' => 0, // Duplicate as draft
        ];

        return $this->create($newData);
    }

    /**
     * Get pages tree structure
     */
    public function getTree(?int $parentId = null): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE parent_id " .
               ($parentId === null ? "IS NULL" : "= ?") .
               " ORDER BY sort_order, title";

        $params = $parentId !== null ? [$parentId] : [];
        $pages = $this->db->query($sql, $params);

        foreach ($pages as &$page) {
            $page['children'] = $this->getTree($page['id']);
        }

        return $pages;
    }

    /**
     * Update sort order
     */
    public function updateSortOrder(array $orderMap): bool
    {
        $this->db->beginTransaction();
        try {
            foreach ($orderMap as $id => $order) {
                $this->db->execute(
                    "UPDATE {$this->table} SET sort_order = ? WHERE id = ?",
                    [(int) $order, (int) $id]
                );
            }
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            return false;
        }
    }
}
