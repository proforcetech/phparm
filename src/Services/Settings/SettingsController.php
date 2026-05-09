<?php

namespace App\Services\Settings;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\SensitiveSettings;
use App\Support\Auth\StepUpService;
use App\Support\Auth\UnauthorizedException;
use App\Support\SettingsRepository;
use InvalidArgumentException;

class SettingsController
{
    private SettingsRepository $settings;
    private AccessGate $gate;
    private ?StepUpService $stepUp;

    public function __construct(
        SettingsRepository $settings,
        AccessGate $gate,
        ?StepUpService $stepUp = null
    ) {
        $this->settings = $settings;
        $this->gate = $gate;
        $this->stepUp = $stepUp;
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

        if (SensitiveSettings::isSensitive($key) && $this->stepUp !== null) {
            $this->stepUp->assertFresh($user->id);
        }

        $this->settings->set($key, $data['value']);

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

        // One step-up check per bulk write rather than per-key — a single
        // bulk save like "save Integrations" should only prompt once.
        if (
            $this->stepUp !== null
            && SensitiveSettings::anyAreSensitive(array_keys($data))
        ) {
            $this->stepUp->assertFresh($user->id);
        }

        $updated = [];
        foreach ($data as $key => $value) {
            $this->settings->set($key, $value);
            $updated[$key] = $value;
        }

        return $updated;
    }
}
