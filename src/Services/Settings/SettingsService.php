<?php

namespace App\Services\Settings;

use App\Database\Connection;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class SettingsService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateProfile(array $payload, int $actorId): void
    {
        $this->saveSettings('profile', $payload, $actorId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateTerms(array $payload, int $actorId): void
    {
        $this->saveSettings('terms', $payload, $actorId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updatePricingDefaults(array $payload, int $actorId): void
    {
        $this->saveSettings('pricing', $payload, $actorId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateIntegrations(array $payload, int $actorId): void
    {
        $allowed = ['stripe', 'square', 'paypal', 'twilio', 'smtp', 'recaptcha', 'maps', 'partstech'];
        foreach (array_keys($payload) as $provider) {
            if (!in_array($provider, $allowed, true)) {
                throw new InvalidArgumentException('Unknown provider ' . $provider);
            }
        }

        $this->saveSettings('integrations', $payload, $actorId, true);
    }

    public function get(string $namespace): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT setting_key, setting_value FROM settings WHERE namespace = :namespace');
        $stmt->execute(['namespace' => $namespace]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = json_decode((string) $row['setting_value'], true);
        }

        return $settings;
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function saveSettings(string $namespace, array $settings, int $actorId, bool $maskSensitive = false): void
    {
        $pdo = $this->connection->pdo();
        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare('REPLACE INTO settings (namespace, setting_key, setting_value) VALUES (:namespace, :key, :value)');
            $stmt->execute([
                'namespace' => $namespace,
                'key' => $key,
                'value' => json_encode($value),
            ]);
        }

        $this->log("settings.{$namespace}_updated", $actorId, $settings, $maskSensitive);
    }

    private function log(string $action, int $actorId, array $payload, bool $maskSensitive): void
    {
        if ($this->audit === null) {
            return;
        }

        if ($maskSensitive) {
            foreach ($payload as $key => &$value) {
                if (is_string($value) && strlen($value) > 4) {
                    $value = str_repeat('*', strlen($value) - 4) . substr($value, -4);
                }
            }
        }

        $this->audit->log(new AuditEntry($action, 'setting', 0, $actorId, $payload));
    }
}
