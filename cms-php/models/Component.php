<?php
/**
 * Component Model
 * Manages reusable content components (header, footer, navigation, etc.)
 * FixItForUs CMS
 */

namespace CMS\Models;

use CMS\Config\Database;

class Component
{
    private Database $db;
    private string $table = 'components';

    // Component types
    public const TYPE_HEADER = 'header';
    public const TYPE_FOOTER = 'footer';
    public const TYPE_NAVIGATION = 'navigation';
    public const TYPE_SIDEBAR = 'sidebar';
    public const TYPE_WIDGET = 'widget';
    public const TYPE_CUSTOM = 'custom';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all components
     */
    public function getAll(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM {$this->table}";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY type, name";
        return $this->db->query($sql);
    }

    /**
     * Get components by type
     */
    public function getByType(string $type, bool $activeOnly = true): array
    {
        $sql = "SELECT * FROM {$this->table} WHERE type = ?";
        if ($activeOnly) {
            $sql .= " AND is_active = 1";
        }
        $sql .= " ORDER BY name";
        return $this->db->query($sql, [$type]);
    }

    /**
     * Get component by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ?";
        return $this->db->queryOne($sql, [$id]);
    }

    /**
     * Get component by slug
     */
    public function getBySlug(string $slug): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE slug = ?";
        return $this->db->queryOne($sql, [$slug]);
    }

    /**
     * Get active component by slug (commonly used for rendering)
     */
    public function getActiveBySlug(string $slug): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE slug = ? AND is_active = 1";
        return $this->db->queryOne($sql, [$slug]);
    }

    /**
     * Create new component
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO {$this->table}
                (name, slug, type, description, content, css, javascript, is_active, cache_ttl, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $data['name'],
            $this->generateSlug($data['name'], $data['slug'] ?? null),
            $data['type'] ?? self::TYPE_CUSTOM,
            $data['description'] ?? null,
            $data['content'],
            $data['css'] ?? null,
            $data['javascript'] ?? null,
            $data['is_active'] ?? 1,
            $data['cache_ttl'] ?? 3600,
            $data['created_by'] ?? currentUserId(),
        ];

        $this->db->execute($sql, $params);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update component
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        // Build dynamic update query
        $allowedFields = ['name', 'slug', 'type', 'description', 'content', 'css', 'javascript', 'is_active', 'cache_ttl'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
            }
        }

        if (empty($fields)) {
            return false;
        }

        // Add updated_by
        $fields[] = "updated_by = ?";
        $params[] = currentUserId();

        // Add ID for WHERE clause
        $params[] = $id;

        $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE id = ?";
        return $this->db->execute($sql, $params) > 0;
    }

    /**
     * Delete component
     */
    public function delete(int $id): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE id = ?";
        return $this->db->execute($sql, [$id]) > 0;
    }

    /**
     * Toggle active status
     */
    public function toggleActive(int $id): bool
    {
        $sql = "UPDATE {$this->table} SET is_active = NOT is_active, updated_by = ? WHERE id = ?";
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
    public function generateSlug(string $name, ?string $preferredSlug = null): string
    {
        $slug = $preferredSlug ?: $this->slugify($name);
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
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        return trim($slug, '-');
    }

    /**
     * Get rendered component content (with CSS and JS)
     */
    public function render(string $slug): string
    {
        $component = $this->getActiveBySlug($slug);

        if (!$component) {
            return '';
        }

        $output = '';

        // Add component CSS
        if (!empty($component['css'])) {
            $output .= '<style data-component="' . e($slug) . '">' . $component['css'] . '</style>';
        }

        // Add component content
        $output .= $component['content'];

        // Add component JavaScript
        if (!empty($component['javascript'])) {
            $output .= '<script data-component="' . e($slug) . '">' . $component['javascript'] . '</script>';
        }

        return $output;
    }

    /**
     * Get all component types
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_HEADER => 'Header',
            self::TYPE_FOOTER => 'Footer',
            self::TYPE_NAVIGATION => 'Navigation',
            self::TYPE_SIDEBAR => 'Sidebar',
            self::TYPE_WIDGET => 'Widget',
            self::TYPE_CUSTOM => 'Custom',
        ];
    }

    /**
     * Search components
     */
    public function search(string $query): array
    {
        $sql = "SELECT * FROM {$this->table}
                WHERE name LIKE ? OR description LIKE ? OR slug LIKE ?
                ORDER BY type, name";
        $searchTerm = "%{$query}%";
        return $this->db->query($sql, [$searchTerm, $searchTerm, $searchTerm]);
    }

    /**
     * Count components
     */
    public function count(bool $activeOnly = false): int
    {
        $sql = "SELECT COUNT(*) as count FROM {$this->table}";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $result = $this->db->queryOne($sql);
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Duplicate component
     */
    public function duplicate(int $id): ?int
    {
        $component = $this->getById($id);
        if (!$component) {
            return null;
        }

        $newData = [
            'name' => $component['name'] . ' (Copy)',
            'type' => $component['type'],
            'description' => $component['description'],
            'content' => $component['content'],
            'css' => $component['css'],
            'javascript' => $component['javascript'],
            'is_active' => 0, // Duplicate as inactive
            'cache_ttl' => $component['cache_ttl'],
        ];

        return $this->create($newData);
    }
}
