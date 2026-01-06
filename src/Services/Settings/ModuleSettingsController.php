<?php

namespace App\Services\Settings;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\ModuleAccessService;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Controller for module settings management
 *
 * Allows admins to enable/disable application modules
 */
class ModuleSettingsController
{
    private ModuleAccessService $moduleService;
    private AccessGate $gate;

    public function __construct(ModuleAccessService $moduleService, AccessGate $gate)
    {
        $this->moduleService = $moduleService;
        $this->gate = $gate;
    }

    /**
     * List all modules with their current status
     *
     * @return array<string, mixed>
     */
    public function index(User $user): array
    {
        $this->assertPermission($user, 'settings.view');

        $modules = $this->moduleService->getAllModulesWithStatus();

        // Add dependency info
        foreach ($modules as $key => &$module) {
            $module['missing_dependencies'] = $this->moduleService->checkModuleDependencies($key);
            $module['dependent_modules'] = $this->moduleService->getDependentModules($key);
        }

        return [
            'modules' => array_values($modules),
        ];
    }

    /**
     * Get a single module's status and configuration
     *
     * @return array<string, mixed>
     */
    public function show(User $user, string $moduleKey): array
    {
        $this->assertPermission($user, 'settings.view');

        $config = $this->moduleService->getModuleConfig($moduleKey);
        if ($config === null) {
            throw new InvalidArgumentException("Unknown module: {$moduleKey}");
        }

        return [
            'key' => $moduleKey,
            'enabled' => $this->moduleService->isModuleEnabled($moduleKey),
            'config' => $config,
            'missing_dependencies' => $this->moduleService->checkModuleDependencies($moduleKey),
            'dependent_modules' => $this->moduleService->getDependentModules($moduleKey),
        ];
    }

    /**
     * Enable or disable a module
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, string $moduleKey, array $data): array
    {
        $this->assertPermission($user, 'settings.update');

        $config = $this->moduleService->getModuleConfig($moduleKey);
        if ($config === null) {
            throw new InvalidArgumentException("Unknown module: {$moduleKey}");
        }

        if (!isset($data['enabled'])) {
            throw new InvalidArgumentException('The "enabled" field is required');
        }

        $enabled = (bool) $data['enabled'];

        // Check if module can be disabled
        if (!$enabled && !($config['can_disable'] ?? true)) {
            throw new InvalidArgumentException("The '{$moduleKey}' module cannot be disabled");
        }

        // If enabling, check dependencies
        if ($enabled) {
            $missing = $this->moduleService->checkModuleDependencies($moduleKey);
            if (!empty($missing)) {
                throw new InvalidArgumentException(
                    "Cannot enable '{$moduleKey}': missing dependencies: " . implode(', ', $missing)
                );
            }
        }

        // If disabling, warn about dependent modules
        if (!$enabled) {
            $dependents = $this->moduleService->getDependentModules($moduleKey);
            $enabledDependents = array_filter($dependents, fn($key) => $this->moduleService->isModuleEnabled($key));

            if (!empty($enabledDependents) && !($data['force'] ?? false)) {
                return [
                    'warning' => true,
                    'message' => "Disabling '{$moduleKey}' will affect these modules: " . implode(', ', $enabledDependents),
                    'affected_modules' => $enabledDependents,
                    'requires_force' => true,
                ];
            }
        }

        $success = $this->moduleService->setModuleEnabled($moduleKey, $enabled, $user->id);

        if (!$success) {
            throw new InvalidArgumentException("Failed to update module '{$moduleKey}'");
        }

        // Clear cache to ensure fresh state
        $this->moduleService->clearCache();

        return [
            'success' => true,
            'key' => $moduleKey,
            'enabled' => $this->moduleService->isModuleEnabled($moduleKey),
        ];
    }

    /**
     * Bulk update multiple modules
     *
     * @param array<string, bool> $modules Key => enabled pairs
     * @return array<string, mixed>
     */
    public function bulkUpdate(User $user, array $modules): array
    {
        $this->assertPermission($user, 'settings.update');

        $results = [];
        $errors = [];

        foreach ($modules as $moduleKey => $enabled) {
            try {
                $result = $this->update($user, $moduleKey, ['enabled' => $enabled, 'force' => true]);
                $results[$moduleKey] = $result;
            } catch (\Throwable $e) {
                $errors[$moduleKey] = $e->getMessage();
            }
        }

        return [
            'results' => $results,
            'errors' => $errors,
            'success' => empty($errors),
        ];
    }

    /**
     * Get modules accessible by the current user
     *
     * This is used for the frontend to know which modules to show
     *
     * @return array<string, mixed>
     */
    public function accessible(User $user): array
    {
        $accessibleKeys = $this->moduleService->getAccessibleModulesForUser($user);
        $sidebarKeys = $this->moduleService->getAccessibleSidebarKeys($user);
        $routes = $this->moduleService->getAccessibleRoutes($user);

        return [
            'modules' => $accessibleKeys,
            'sidebar_keys' => $sidebarKeys,
            'routes' => $routes,
        ];
    }

    /**
     * Assert that the user has a specific permission
     */
    private function assertPermission(User $user, string $permission): void
    {
        if (!$this->gate->can($user, $permission)) {
            throw new UnauthorizedException("Permission denied: {$permission}");
        }
    }
}
