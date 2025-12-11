<?php
/**
 * Setting Model
 * Manages CMS configuration settings
 * FixItForUs CMS
 */

namespace CMS\Models;

use CMS\Config\Database;

class Setting
{
    private Database $db;
    private string $table;
    private static array $cache = [];

    // Setting types
    public const TYPE_STRING = 'string';
    public const TYPE_INTEGER = 'integer';
    public const TYPE_BOOLEAN = 'boolean';
    public const TYPE_JSON = 'json';
    public const TYPE_HTML = 'html';

    public function __construct()
    {
        $this->db = Database::getInstance();
        $this->table = $this->db->prefix('settings');
    }

    /**
     * Get all settings
     */
    public function getAll(): array
    {
        $sql = "SELECT * FROM {$this->table} ORDER BY setting_key";
        return $this->db->query($sql);
    }

    /**
     * Get public settings only
     */
    public function getPublic(): array
    {
        $sql = "SELECT setting_key, setting_value, setting_type
                FROM {$this->table}
                WHERE is_public = 1
                ORDER BY setting_key";
        return $this->db->query($sql);
    }

    /**
     * Get setting value by key
     */
    public function get(string $key, $default = null)
    {
        // Check cache first
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        $sql = "SELECT setting_value, setting_type FROM {$this->table} WHERE setting_key = ?";
        $result = $this->db->queryOne($sql, [$key]);

        if (!$result) {
            return $default;
        }

        $value = $this->castValue($result['setting_value'], $result['setting_type']);
        self::$cache[$key] = $value;

        return $value;
    }

    /**
     * Set setting value
     */
    public function set(string $key, $value, ?string $type = null, ?string $description = null, ?bool $isPublic = null): bool
    {
        // Check if setting exists
        $existing = $this->db->queryOne(
            "SELECT id, setting_type FROM {$this->table} WHERE setting_key = ?",
            [$key]
        );

        if ($existing) {
            // Update existing
            $fields = ['setting_value = ?'];
            $params = [$this->serializeValue($value, $type ?? $existing['setting_type'])];

            if ($type !== null) {
                $fields[] = 'setting_type = ?';
                $params[] = $type;
            }

            if ($description !== null) {
                $fields[] = 'description = ?';
                $params[] = $description;
            }

            if ($isPublic !== null) {
                $fields[] = 'is_public = ?';
                $params[] = $isPublic ? 1 : 0;
            }

            $params[] = $key;
            $sql = "UPDATE {$this->table} SET " . implode(', ', $fields) . " WHERE setting_key = ?";
        } else {
            // Insert new
            $sql = "INSERT INTO {$this->table}
                    (setting_key, setting_value, setting_type, description, is_public)
                    VALUES (?, ?, ?, ?, ?)";
            $params = [
                $key,
                $this->serializeValue($value, $type ?? self::TYPE_STRING),
                $type ?? self::TYPE_STRING,
                $description,
                $isPublic ? 1 : 0,
            ];
        }

        $result = $this->db->execute($sql, $params) > 0;

        // Update cache
        if ($result) {
            self::$cache[$key] = $value;
        }

        return $result;
    }

    /**
     * Delete setting
     */
    public function delete(string $key): bool
    {
        $sql = "DELETE FROM {$this->table} WHERE setting_key = ?";
        $result = $this->db->execute($sql, [$key]) > 0;

        // Remove from cache
        if ($result) {
            unset(self::$cache[$key]);
        }

        return $result;
    }

    /**
     * Get multiple settings as key-value array
     */
    public function getMultiple(array $keys): array
    {
        if (empty($keys)) {
            return [];
        }

        $placeholders = str_repeat('?,', count($keys) - 1) . '?';
        $sql = "SELECT setting_key, setting_value, setting_type
                FROM {$this->table}
                WHERE setting_key IN ({$placeholders})";

        $results = $this->db->query($sql, $keys);
        $settings = [];

        foreach ($results as $row) {
            $settings[$row['setting_key']] = $this->castValue($row['setting_value'], $row['setting_type']);
        }

        return $settings;
    }

    /**
     * Update multiple settings at once
     */
    public function setMultiple(array $settings): bool
    {
        $this->db->beginTransaction();

        try {
            foreach ($settings as $key => $value) {
                $this->set($key, $value);
            }
            $this->db->commit();
            return true;
        } catch (\Exception $e) {
            $this->db->rollback();
            return false;
        }
    }

    /**
     * Cast value to appropriate type
     */
    private function castValue(?string $value, string $type)
    {
        if ($value === null) {
            return null;
        }

        switch ($type) {
            case self::TYPE_INTEGER:
                return (int) $value;

            case self::TYPE_BOOLEAN:
                return $value === '1' || strtolower($value) === 'true';

            case self::TYPE_JSON:
                return json_decode($value, true);

            case self::TYPE_HTML:
            case self::TYPE_STRING:
            default:
                return $value;
        }
    }

    /**
     * Serialize value for storage
     */
    private function serializeValue($value, string $type): string
    {
        switch ($type) {
            case self::TYPE_INTEGER:
                return (string) (int) $value;

            case self::TYPE_BOOLEAN:
                return $value ? '1' : '0';

            case self::TYPE_JSON:
                return is_string($value) ? $value : json_encode($value);

            case self::TYPE_HTML:
            case self::TYPE_STRING:
            default:
                return (string) $value;
        }
    }

    /**
     * Clear settings cache
     */
    public function clearCache(): void
    {
        self::$cache = [];
    }

    /**
     * Get setting types
     */
    public static function getTypes(): array
    {
        return [
            self::TYPE_STRING => 'String',
            self::TYPE_INTEGER => 'Integer',
            self::TYPE_BOOLEAN => 'Boolean',
            self::TYPE_JSON => 'JSON',
            self::TYPE_HTML => 'HTML',
        ];
    }

    /**
     * Get grouped settings for admin display
     */
    public function getGrouped(): array
    {
        $all = $this->getAll();
        $grouped = [
            'site' => [],
            'cache' => [],
            'contact' => [],
            'other' => [],
        ];

        foreach ($all as $setting) {
            $key = $setting['setting_key'];

            if (strpos($key, 'site_') === 0) {
                $grouped['site'][] = $setting;
            } elseif (strpos($key, 'cache_') === 0) {
                $grouped['cache'][] = $setting;
            } elseif (strpos($key, 'contact_') === 0) {
                $grouped['contact'][] = $setting;
            } else {
                $grouped['other'][] = $setting;
            }
        }

        return $grouped;
    }
}
