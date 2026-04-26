<?php

namespace App\Services\Integrations\ThirdParty;

use RuntimeException;

/**
 * In-process catalog of available integration adapters. Adding a new
 * provider is a matter of dropping an adapter class in this directory
 * and registering it in the bootstrap (routes/modules/integrations.php
 * or bin/cron/integration-sync.php).
 *
 * Kept in code rather than a DB table for the same reason
 * ReportCatalogService is in code: the catalog binds to *executable
 * adapters*, and the canonical record of "what providers exist" is
 * the set of adapter classes.
 */
class IntegrationAdapterRegistry
{
    /** @var array<string, IntegrationAdapterInterface> */
    private array $adapters = [];

    public function register(IntegrationAdapterInterface $adapter): void
    {
        $key = $adapter->providerKey();
        if (isset($this->adapters[$key])) {
            throw new RuntimeException("Adapter already registered for provider: {$key}");
        }
        $this->adapters[$key] = $adapter;
    }

    public function has(string $providerKey): bool
    {
        return isset($this->adapters[$providerKey]);
    }

    public function get(string $providerKey): IntegrationAdapterInterface
    {
        if (!isset($this->adapters[$providerKey])) {
            throw new RuntimeException("Unknown integration provider: {$providerKey}");
        }
        return $this->adapters[$providerKey];
    }

    /**
     * @return array<int, IntegrationAdapterInterface>
     */
    public function listAll(): array
    {
        return array_values($this->adapters);
    }

    /**
     * @return array<int, IntegrationAdapterInterface>
     */
    public function listByCategory(string $category): array
    {
        return array_values(array_filter(
            $this->adapters,
            static fn(IntegrationAdapterInterface $a): bool => $a->category() === $category
        ));
    }

    /**
     * Lightweight metadata dump for the catalog UI — no credentials,
     * no executable adapter references, just the descriptive surface.
     *
     * @return array<int, array<string, mixed>>
     */
    public function describeAll(): array
    {
        $out = [];
        foreach ($this->adapters as $a) {
            $out[] = [
                'provider_key' => $a->providerKey(),
                'display_name' => $a->displayName(),
                'category' => $a->category(),
                'description' => $a->description(),
                'credential_fields' => $a->credentialFields(),
                'setting_fields' => $a->settingFields(),
                'default_cadence_minutes' => $a->defaultCadenceMinutes(),
            ];
        }
        return $out;
    }
}
