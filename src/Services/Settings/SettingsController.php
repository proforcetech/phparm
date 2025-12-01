<?php

namespace App\Services\Settings;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use App\Support\SettingsRepository;
use InvalidArgumentException;

class SettingsController
{
    private SettingsRepository $settings;
    private AccessGate $gate;

    public function __construct(SettingsRepository $settings, AccessGate $gate)
    {
        $this->settings = $settings;
        $this->gate = $gate;
    }

    /**
     * Get all settings
     *
     * @return array<string, mixed>
     */
    public function index(User $user): array
    {
        if (!$this->gate->can($user, 'settings.view')) {
            throw new UnauthorizedException('Cannot view settings');
        }

        return $this->settings->all();
    }

    /**
     * Get specific setting
     */
    public function show(User $user, string $key): mixed
    {
        if (!$this->gate->can($user, 'settings.view')) {
            throw new UnauthorizedException('Cannot view settings');
        }

        return $this->settings->get($key);
    }

    /**
     * Update setting
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, string $key, array $data): array
    {
        if (!$this->gate->can($user, 'settings.update')) {
            throw new UnauthorizedException('Cannot update settings');
        }

        if (!isset($data['value'])) {
            throw new InvalidArgumentException('value is required');
        }

        $this->settings->set($key, $data['value'], $user->id);

        return [
            'key' => $key,
            'value' => $data['value'],
        ];
    }

    /**
     * Bulk update settings
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function bulkUpdate(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'settings.update')) {
            throw new UnauthorizedException('Cannot update settings');
        }

        $updated = [];
        foreach ($data as $key => $value) {
            $this->settings->set($key, $value, $user->id);
            $updated[$key] = $value;
        }

        return $updated;
    }
}
