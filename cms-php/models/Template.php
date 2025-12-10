<?php
/**
 * Template Model
 * Manages page templates/layouts
 * FixItForUs CMS
 */

namespace CMS\Models;

use CMS\Config\Database;

class Template
{
    private Database $db;
    private string $table = 'templates';

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * Get all templates
     */
    public function getAll(bool $activeOnly = false): array
    {
        $sql = "SELECT * FROM {$this->table}";
        if ($activeOnly) {
            $sql .= " WHERE is_active = 1";
        }
        $sql .= " ORDER BY name";
        return $this->db->query($sql);
    }

    /**
     * Get template by ID
     */
    public function getById(int $id): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE id = ?";
        return $this->db->queryOne($sql, [$id]);
    }

    /**
     * Get template by slug
     */
    public function getBySlug(string $slug): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE slug = ?";
        return $this->db->queryOne($sql, [$slug]);
    }

    /**
     * Get active template by slug
     */
    public function getActiveBySlug(string $slug): ?array
    {
        $sql = "SELECT * FROM {$this->table} WHERE slug = ? AND is_active = 1";
        return $this->db->queryOne($sql, [$slug]);
    }

    /**
     * Create new template
     */
    public function create(array $data): int
    {
        $sql = "INSERT INTO {$this->table}
                (name, slug, description, structure, default_css, default_js, is_active, created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)";

        $params = [
            $data['name'],
            $this->generateSlug($data['name'], $data['slug'] ?? null),
            $data['description'] ?? null,
            $data['structure'],
            $data['default_css'] ?? null,
            $data['default_js'] ?? null,
            $data['is_active'] ?? 1,
            $data['created_by'] ?? currentUserId(),
        ];

        $this->db->execute($sql, $params);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Update template
     */
    public function update(int $id, array $data): bool
    {
        $fields = [];
        $params = [];

        $allowedFields = ['name', 'slug', 'description', 'structure', 'default_css', 'default_js', 'is_active'];

        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $fields[] = "{$field} = ?";
                $params[] = $data[$field];
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
     * Delete template
     */
    public function delete(int $id): bool
    {
        // Check if any pages use this template
        $check = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM pages WHERE template_id = ?",
            [$id]
        );

        if (($check['count'] ?? 0) > 0) {
            return false; // Cannot delete template in use
        }

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
     * Count templates
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
     * Get pages using this template
     */
    public function getPages(int $templateId): array
    {
        $sql = "SELECT id, title, slug, is_published FROM pages WHERE template_id = ? ORDER BY title";
        return $this->db->query($sql, [$templateId]);
    }

    /**
     * Count pages using this template
     */
    public function countPages(int $templateId): int
    {
        $result = $this->db->queryOne(
            "SELECT COUNT(*) as count FROM pages WHERE template_id = ?",
            [$templateId]
        );
        return (int) ($result['count'] ?? 0);
    }

    /**
     * Duplicate template
     */
    public function duplicate(int $id): ?int
    {
        $template = $this->getById($id);
        if (!$template) {
            return null;
        }

        $newData = [
            'name' => $template['name'] . ' (Copy)',
            'description' => $template['description'],
            'structure' => $template['structure'],
            'default_css' => $template['default_css'],
            'default_js' => $template['default_js'],
            'is_active' => 0,
        ];

        return $this->create($newData);
    }

    /**
     * Get available placeholders from template structure
     */
    public function getPlaceholders(int $id): array
    {
        $template = $this->getById($id);
        if (!$template) {
            return [];
        }

        preg_match_all('/\{\{(\w+)\}\}/', $template['structure'], $matches);
        return array_unique($matches[1] ?? []);
    }

    /**
     * Validate template structure
     */
    public function validateStructure(string $structure): array
    {
        $errors = [];
        $requiredPlaceholders = ['content'];

        foreach ($requiredPlaceholders as $placeholder) {
            if (strpos($structure, '{{' . $placeholder . '}}') === false) {
                $errors[] = "Missing required placeholder: {{{$placeholder}}}";
            }
        }

        // Check for unclosed placeholders
        if (preg_match('/\{\{[^}]*$/', $structure)) {
            $errors[] = "Unclosed placeholder tag found";
        }

        return $errors;
    }
}
