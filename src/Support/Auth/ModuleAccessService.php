<?php

namespace App\Support\Auth;

use App\Database\Connection;
use App\Models\User;
use PDO;

/**
 * Service for managing module access control
 *
 * Handles module enablement at the shop level and user group restrictions.
 * Works in conjunction with AccessGate for permission checking.
 */
class ModuleAccessService
{
    private Connection $connection;
    private AccessGate $gate;
    private array $moduleConfig;
    private array $protectedModules;

    /** @var array<string, bool> Cache of module enabled states */
    private array $moduleCache = [];

    /** @var array<int, array> Cache of user groups by user ID */
    private array $userGroupsCache = [];

    public function __construct(
        Connection $connection,
        AccessGate $gate,
        ?array $moduleConfig = null
    ) {
        $this->connection = $connection;
        $this->gate = $gate;

        $config = $moduleConfig ?? (require __DIR__ . '/../../../config/modules.php');
        $this->moduleConfig = $config['modules'] ?? [];
        $this->protectedModules = $config['protected_modules'] ?? ['core'];
    }

    /**
     * Check if a module is enabled at the shop/tenant level
     */
    public function isModuleEnabled(string $moduleKey): bool
    {
        if (isset($this->moduleCache[$moduleKey])) {
            return $this->moduleCache[$moduleKey];
        }

        // Protected modules are always enabled
        if (in_array($moduleKey, $this->protectedModules, true)) {
            $this->moduleCache[$moduleKey] = true;
            return true;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT enabled FROM module_settings WHERE module_key = ?'
        );
        $stmt->execute([$moduleKey]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($result !== false) {
            $enabled = (bool) $result['enabled'];
        } else {
            // Fall back to default from config
            $enabled = $this->moduleConfig[$moduleKey]['default_enabled'] ?? false;
        }

        $this->moduleCache[$moduleKey] = $enabled;
        return $enabled;
    }

    /**
     * Get all enabled modules
     *
     * @return array<string, array>
     */
    public function getEnabledModules(): array
    {
        $enabled = [];

        foreach ($this->moduleConfig as $key => $config) {
            if ($this->isModuleEnabled($key)) {
                $enabled[$key] = $config;
            }
        }

        return $enabled;
    }

    /**
     * Get all modules with their current status
     *
     * @return array<string, array>
     */
    public function getAllModulesWithStatus(): array
    {
        $modules = [];

        foreach ($this->moduleConfig as $key => $config) {
            $modules[$key] = array_merge($config, [
                'key' => $key,
                'enabled' => $this->isModuleEnabled($key),
                'is_protected' => in_array($key, $this->protectedModules, true),
            ]);
        }

        return $modules;
    }

    /**
     * Enable or disable a module
     */
    public function setModuleEnabled(string $moduleKey, bool $enabled, ?int $userId = null): bool
    {
        // Cannot disable protected modules
        if (!$enabled && in_array($moduleKey, $this->protectedModules, true)) {
            return false;
        }

        // Check if module exists in config
        if (!isset($this->moduleConfig[$moduleKey])) {
            return false;
        }

        // Check if module can be disabled
        if (!$enabled && !($this->moduleConfig[$moduleKey]['can_disable'] ?? true)) {
            return false;
        }

        $pdo = $this->connection->pdo();

        // Upsert module setting
        $stmt = $pdo->prepare('
            INSERT INTO module_settings (module_key, enabled, updated_by, updated_at)
            VALUES (?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE enabled = VALUES(enabled), updated_by = VALUES(updated_by), updated_at = NOW()
        ');
        $result = $stmt->execute([$moduleKey, $enabled ? 1 : 0, $userId]);

        if ($result) {
            // Log the change
            $this->logModuleChange($moduleKey, $enabled, $userId);

            // Clear cache
            unset($this->moduleCache[$moduleKey]);
        }

        return $result;
    }

    /**
     * Check if a user can access a specific module
     */
    public function canUserAccessModule(User $user, string $moduleKey): bool
    {
        // Admin always has access to enabled modules
        if ($user->role === 'admin') {
            return $this->isModuleEnabled($moduleKey);
        }

        // 1. Module must be enabled globally
        if (!$this->isModuleEnabled($moduleKey)) {
            return false;
        }

        // 2. Check user's groups for module restrictions
        $userGroups = $this->getUserGroups($user->id);
        foreach ($userGroups as $group) {
            $disabledModules = $group['disabled_modules'];
            if (is_string($disabledModules)) {
                $disabledModules = json_decode($disabledModules, true) ?? [];
            }
            if (in_array($moduleKey, $disabledModules, true)) {
                return false;
            }
        }

        // 3. Check if user has at least one permission in the module
        $prefixes = $this->moduleConfig[$moduleKey]['permissions_prefix'] ?? [];
        foreach ($prefixes as $prefix) {
            // Handle wildcard prefixes like 'dispatch.*'
            $checkPermission = str_ends_with($prefix, '*') ? $prefix : $prefix;
            if ($this->gate->can($user, $checkPermission)) {
                return true;
            }
        }

        // If no permissions_prefix defined, module access follows global enablement
        return empty($prefixes);
    }

    /**
     * Get all modules accessible by a user
     *
     * @return string[]
     */
    public function getAccessibleModulesForUser(User $user): array
    {
        $accessible = [];

        foreach (array_keys($this->moduleConfig) as $moduleKey) {
            if ($this->canUserAccessModule($user, $moduleKey)) {
                $accessible[] = $moduleKey;
            }
        }

        return $accessible;
    }

    /**
     * Get user's groups
     *
     * @return array<int, array>
     */
    public function getUserGroups(int $userId): array
    {
        if (isset($this->userGroupsCache[$userId])) {
            return $this->userGroupsCache[$userId];
        }

        $stmt = $this->connection->pdo()->prepare('
            SELECT g.id, g.name, g.description, g.permissions, g.disabled_modules
            FROM user_groups g
            INNER JOIN user_group_members m ON g.id = m.group_id
            WHERE m.user_id = ?
        ');
        $stmt->execute([$userId]);
        $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $this->userGroupsCache[$userId] = $groups;
        return $groups;
    }

    /**
     * Get additional permissions granted by user's groups
     *
     * @return string[]
     */
    public function getGroupPermissionsForUser(int $userId): array
    {
        $groups = $this->getUserGroups($userId);
        $permissions = [];

        foreach ($groups as $group) {
            $groupPerms = $group['permissions'];
            if (is_string($groupPerms)) {
                $groupPerms = json_decode($groupPerms, true) ?? [];
            }
            $permissions = array_merge($permissions, $groupPerms);
        }

        return array_unique($permissions);
    }

    /**
     * Get module configuration
     */
    public function getModuleConfig(string $moduleKey): ?array
    {
        return $this->moduleConfig[$moduleKey] ?? null;
    }

    /**
     * Get sidebar keys for enabled modules accessible by user
     *
     * @return string[]
     */
    public function getAccessibleSidebarKeys(User $user): array
    {
        $sidebarKeys = [];

        foreach ($this->moduleConfig as $moduleKey => $config) {
            if ($this->canUserAccessModule($user, $moduleKey)) {
                $keys = $config['sidebar_keys'] ?? [];
                $sidebarKeys = array_merge($sidebarKeys, $keys);
            }
        }

        return array_unique($sidebarKeys);
    }

    /**
     * Get routes for enabled modules accessible by user
     *
     * @return string[]
     */
    public function getAccessibleRoutes(User $user): array
    {
        $routes = [];

        foreach ($this->moduleConfig as $moduleKey => $config) {
            if ($this->canUserAccessModule($user, $moduleKey)) {
                $moduleRoutes = $config['routes'] ?? [];
                $routes = array_merge($routes, $moduleRoutes);
            }
        }

        return array_unique($routes);
    }

    /**
     * Check module dependencies before enabling
     *
     * @return string[] Array of missing dependencies
     */
    public function checkModuleDependencies(string $moduleKey): array
    {
        $config = $this->moduleConfig[$moduleKey] ?? null;
        if ($config === null) {
            return [];
        }

        $dependencies = $config['depends_on'] ?? [];
        $missing = [];

        foreach ($dependencies as $depKey) {
            if (!$this->isModuleEnabled($depKey)) {
                $missing[] = $depKey;
            }
        }

        return $missing;
    }

    /**
     * Get modules that depend on a given module
     *
     * @return string[]
     */
    public function getDependentModules(string $moduleKey): array
    {
        $dependents = [];

        foreach ($this->moduleConfig as $key => $config) {
            $dependencies = $config['depends_on'] ?? [];
            if (in_array($moduleKey, $dependencies, true)) {
                $dependents[] = $key;
            }
        }

        return $dependents;
    }

    /**
     * Clear all caches
     */
    public function clearCache(): void
    {
        $this->moduleCache = [];
        $this->userGroupsCache = [];
    }

    /**
     * Log module access change
     */
    private function logModuleChange(string $moduleKey, bool $enabled, ?int $userId): void
    {
        $stmt = $this->connection->pdo()->prepare('
            INSERT INTO module_access_log (action_type, module_key, performed_by, old_value, new_value, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ');

        $stmt->execute([
            'module_toggle',
            $moduleKey,
            $userId,
            json_encode(['enabled' => !$enabled]),
            json_encode(['enabled' => $enabled]),
        ]);
    }
}
