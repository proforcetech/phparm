<?php

namespace App\Services\Integrations\ThirdParty;

use App\Models\ThirdPartyIntegration;

/**
 * Mapbox adapter — alternative mapping provider with a different
 * credential model (single token) and per-style billing.
 */
class MapboxAdapter extends AbstractIntegrationAdapter
{
    public function providerKey(): string
    {
        return 'mapbox';
    }

    public function displayName(): string
    {
        return 'Mapbox';
    }

    public function category(): string
    {
        return ThirdPartyIntegration::CATEGORY_MAPPING;
    }

    public function description(): string
    {
        return 'Geocoding, directions, and map tile rendering via Mapbox.';
    }

    public function credentialFields(): array
    {
        return [
            'access_token' => [
                'label' => 'Access Token',
                'required' => true,
                'sensitive' => true,
                'type' => 'api_key',
                'help' => 'A secret token with the geocoding/directions scopes.',
            ],
        ];
    }

    public function settingFields(): array
    {
        return [
            'username' => [
                'label' => 'Mapbox Username',
                'required' => true,
                'type' => 'string',
                'help' => 'Used to scope the token-info probe.',
            ],
        ];
    }

    public function defaultCadenceMinutes(): ?int
    {
        return 1440;
    }

    public function testConnection(array $credentials, array $settings): array
    {
        $token = $this->requireCredential($credentials, 'access_token');
        $username = (string) $this->setting($settings, 'username', '');
        if ($username === '') {
            return ['ok' => false, 'message' => 'username setting is required'];
        }

        $resp = $this->request(
            'GET',
            'https://api.mapbox.com/tokens/v2/' . rawurlencode($username) . '?access_token=' . urlencode($token),
            ['Accept' => 'application/json']
        );
        if ($resp['status'] >= 400) {
            return [
                'ok' => false,
                'message' => 'Mapbox token info returned HTTP ' . $resp['status'],
            ];
        }
        $decoded = $this->decodeJson($resp['body']);
        $count = is_array($decoded) ? count($decoded) : 0;
        return [
            'ok' => true,
            'message' => 'Mapbox credentials valid (' . $count . ' tokens visible).',
            'meta' => ['tokens_visible' => $count],
        ];
    }

    public function sync(array $credentials, array $settings, array $context = []): array
    {
        $probe = $this->testConnection($credentials, $settings);
        return [
            'records_in' => 0,
            'records_out' => 0,
            'summary' => [
                'health_probe' => $probe['ok'] ? 'ok' : 'failed',
                'message' => $probe['message'],
            ],
        ];
    }
}
