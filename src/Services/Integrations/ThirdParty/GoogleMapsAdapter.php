<?php

namespace App\Services\Integrations\ThirdParty;

use App\Models\ThirdPartyIntegration;

/**
 * Google Maps Platform adapter — geocoding, distance matrix, and
 * directions. The "sync" semantics here are different from the
 * accounting adapters: there's nothing to pull on a schedule; the
 * adapter exists so the rest of the system can resolve "is the API
 * key healthy?" and so the credential lives in one canonical place
 * instead of leaking into ten different services.
 *
 * sync() runs a small API-quota probe so the admin UI can surface
 * "you have ~X of your daily quota remaining" without each consumer
 * doing it themselves.
 */
class GoogleMapsAdapter extends AbstractIntegrationAdapter
{
    public function providerKey(): string
    {
        return 'google_maps';
    }

    public function displayName(): string
    {
        return 'Google Maps Platform';
    }

    public function category(): string
    {
        return ThirdPartyIntegration::CATEGORY_MAPPING;
    }

    public function description(): string
    {
        return 'Geocoding, directions, and distance matrix via Google Maps Platform.';
    }

    public function credentialFields(): array
    {
        return [
            'api_key' => [
                'label' => 'API Key',
                'required' => true,
                'sensitive' => true,
                'type' => 'api_key',
                'help' => 'A server-side API key with Geocoding API + Distance Matrix API enabled.',
            ],
        ];
    }

    public function settingFields(): array
    {
        return [
            'region' => [
                'label' => 'Default Region Bias',
                'required' => false,
                'type' => 'string',
                'help' => 'ISO 3166-1 alpha-2 (e.g. "us") to bias geocoding results.',
            ],
        ];
    }

    public function defaultCadenceMinutes(): ?int
    {
        // Health-probe daily — quota burn from this is a single call.
        return 1440;
    }

    public function testConnection(array $credentials, array $settings): array
    {
        $apiKey = $this->requireCredential($credentials, 'api_key');

        $resp = $this->request(
            'GET',
            'https://maps.googleapis.com/maps/api/geocode/json?address=Mountain+View,CA&key=' . urlencode($apiKey),
            ['Accept' => 'application/json']
        );
        if ($resp['status'] >= 400) {
            return [
                'ok' => false,
                'message' => 'Google Maps returned HTTP ' . $resp['status'],
            ];
        }
        $decoded = $this->decodeJson($resp['body']);
        $status = $decoded['status'] ?? 'UNKNOWN';
        if ($status !== 'OK') {
            return [
                'ok' => false,
                'message' => 'Google Maps reported status ' . $status . ': ' . ($decoded['error_message'] ?? 'no detail'),
            ];
        }
        return [
            'ok' => true,
            'message' => 'API key is valid (geocoding probe succeeded).',
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
