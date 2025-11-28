<?php

namespace App\Support;

use App\Database\Connection;
use App\Models\Setting;
use InvalidArgumentException;
use PDO;

class SettingsRepository
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @return array<string, Setting>
     */
    public function all(): array
    {
        $stmt = $this->connection->pdo()->query('SELECT * FROM settings ORDER BY `group`, `key`');
        $settings = [];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $setting = new Setting($row);
            $setting->value = $this->decodeValue($setting->value, $setting->type);
            $settings[$setting->key] = $setting;
        }

        return $settings;
    }

    /**
     * @return mixed
     */
    public function get(string $key, $default = null)
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM settings WHERE `key` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);

        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $default;
        }

        return $this->decodeValue($row['value'], $row['type']);
    }

    public function exists(string $key): bool
    {
        $stmt = $this->connection->pdo()->prepare('SELECT 1 FROM settings WHERE `key` = :key LIMIT 1');
        $stmt->execute(['key' => $key]);

        return (bool) $stmt->fetchColumn();
    }

    /**
     * @param mixed $value
     */
    public function set(string $key, $value, ?string $type = null, string $group = 'general', ?string $description = null): void
    {
        $type ??= $this->inferTypeFromValue($value);
        $encodedValue = $this->encodeValue($value, $type);

        $sql = <<<SQL
            INSERT INTO settings (`key`, `group`, `type`, `value`, `description`, created_at, updated_at)
            VALUES (:key, :group, :type, :value, :description, NOW(), NOW())
            ON DUPLICATE KEY UPDATE `group` = VALUES(`group`), type = VALUES(type), value = VALUES(value), description = VALUES(description), updated_at = NOW()
        SQL;

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute([
            'key' => $key,
            'group' => $group,
            'type' => $type,
            'value' => $encodedValue,
            'description' => $description,
        ]);
    }

    public function delete(string $key): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM settings WHERE `key` = :key');
        $stmt->execute(['key' => $key]);
    }

    /**
     * Seed defaults from config if they do not already exist.
     *
     * @param array<string, array{group: string, type: string, description?: string, value: mixed}> $defaults
     */
    public function seedDefaults(array $defaults): void
    {
        foreach ($defaults as $key => $definition) {
            if ($this->exists($key)) {
                continue;
            }

            $this->set(
                $key,
                $definition['value'],
                $definition['type'],
                $definition['group'],
                $definition['description'] ?? null
            );
        }
    }

    private function inferTypeFromValue($value): string
    {
        return match (true) {
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'float',
            is_array($value) => 'json',
            default => 'string',
        };
    }

    /**
     * @param mixed $value
     */
    private function encodeValue($value, string $type): string
    {
        return match ($type) {
            'json' => json_encode($value, JSON_THROW_ON_ERROR),
            'boolean' => $value ? '1' : '0',
            default => (string) $value,
        };
    }

    private function decodeValue(string $value, string $type)
    {
        return match ($type) {
            'json' => json_decode($value, true, 512, JSON_THROW_ON_ERROR),
            'boolean' => $value === '1' || strtolower($value) === 'true',
            'integer' => (int) $value,
            'float' => (float) $value,
            'string' => $value,
            default => throw new InvalidArgumentException("Unknown settings type: {$type}"),
        };
    }
}
