<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\User;
use InvalidArgumentException;

class PortalNotificationPreferenceController
{
    public function __construct(private readonly PortalNotificationPreferenceService $service)
    {
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listMatrix(User $user, PortalAccount $account): array
    {
        return $this->service->listMatrix($user, $account);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function set(User $user, PortalAccount $account, array $body): array
    {
        $prefKey = isset($body['pref_key']) && is_string($body['pref_key']) ? $body['pref_key'] : '';
        $channel = isset($body['channel']) && is_string($body['channel']) ? $body['channel'] : '';
        if (!array_key_exists('enabled', $body)) {
            throw new InvalidArgumentException('enabled is required');
        }
        $enabled = (bool) $body['enabled'];

        $row = $this->service->set($user, $account, $prefKey, $channel, $enabled);
        return [
            'id' => $row->id,
            'pref_key' => $row->pref_key,
            'channel' => $row->channel,
            'enabled' => (bool) $row->enabled,
        ];
    }
}
