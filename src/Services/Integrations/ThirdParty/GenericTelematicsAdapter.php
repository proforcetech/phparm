<?php

namespace App\Services\Integrations\ThirdParty;

use App\Models\ThirdPartyIntegration;
use RuntimeException;

/**
 * Generic IoT telematics / fleet-tracking adapter.
 *
 * Many providers in this space (Geotab, Samsara, Verizon Connect,
 * Fleetio, etc.) expose REST APIs with similar shapes — auth via
 * api_key or OAuth, GET /vehicles, GET /trips, GET /events. Rather
 * than hard-code one provider, this adapter takes the base URL and
 * key as configuration and pulls a configurable endpoint set.
 *
 * Subclass it (or copy and specialize) to wire to a specific vendor.
 */
class GenericTelematicsAdapter extends AbstractIntegrationAdapter
{
    public function providerKey(): string
    {
        return 'generic_telematics';
    }

    public function displayName(): string
    {
        return 'Generic Telematics';
    }

    public function category(): string
    {
        return ThirdPartyIntegration::CATEGORY_IOT;
    }

    public function description(): string
    {
        return 'REST-based fleet telematics ingestion (Geotab/Samsara/Verizon-shaped APIs).';
    }

    public function credentialFields(): array
    {
        return [
            'api_key' => [
                'label' => 'API Key',
                'required' => true,
                'sensitive' => true,
                'type' => 'api_key',
            ],
        ];
    }

    public function settingFields(): array
    {
        return [
            'base_url' => [
                'label' => 'API Base URL',
                'required' => true,
                'type' => 'url',
                'help' => 'e.g. https://api.fleet-vendor.example/v1',
            ],
            'vehicles_path' => [
                'label' => 'Vehicles Endpoint Path',
                'required' => false,
                'type' => 'string',
                'default' => '/vehicles',
            ],
            'trips_path' => [
                'label' => 'Trips Endpoint Path',
                'required' => false,
                'type' => 'string',
                'default' => '/trips',
            ],
            'auth_header' => [
                'label' => 'Auth Header Format',
                'required' => false,
                'type' => 'string',
                'default' => 'Bearer {api_key}',
                'help' => 'Token included verbatim — {api_key} is substituted.',
            ],
        ];
    }

    public function defaultCadenceMinutes(): ?int
    {
        return 15;
    }

    public function testConnection(array $credentials, array $settings): array
    {
        $apiKey = $this->requireCredential($credentials, 'api_key');
        $baseUrl = (string) $this->setting($settings, 'base_url', '');
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'base_url setting is required'];
        }

        $vehiclesPath = (string) $this->setting($settings, 'vehicles_path', '/vehicles');
        $authHeader = (string) $this->setting($settings, 'auth_header', 'Bearer {api_key}');

        $resp = $this->request(
            'GET',
            rtrim($baseUrl, '/') . $vehiclesPath . '?limit=1',
            [
                'Authorization' => str_replace('{api_key}', $apiKey, $authHeader),
                'Accept' => 'application/json',
            ]
        );
        if ($resp['status'] >= 400) {
            return [
                'ok' => false,
                'message' => 'Telematics API returned HTTP ' . $resp['status'],
            ];
        }
        return ['ok' => true, 'message' => 'Telematics endpoint is reachable.'];
    }

    public function sync(array $credentials, array $settings, array $context = []): array
    {
        $apiKey = $this->requireCredential($credentials, 'api_key');
        $baseUrl = (string) $this->setting($settings, 'base_url', '');
        if ($baseUrl === '') {
            throw new RuntimeException('base_url setting is required');
        }

        $authHeader = (string) $this->setting($settings, 'auth_header', 'Bearer {api_key}');
        $headers = [
            'Authorization' => str_replace('{api_key}', $apiKey, $authHeader),
            'Accept' => 'application/json',
        ];

        $vehicles = $this->countList($baseUrl, (string) $this->setting($settings, 'vehicles_path', '/vehicles'), $headers);
        $trips = $this->countList(
            $baseUrl,
            (string) $this->setting($settings, 'trips_path', '/trips') . '?since=' . urlencode((string) ($context['since'] ?? date('c', strtotime('-1 hour')))),
            $headers
        );

        return [
            'records_in' => $vehicles + $trips,
            'records_out' => 0,
            'summary' => [
                'vehicles' => $vehicles,
                'trips' => $trips,
            ],
        ];
    }

    /**
     * @param array<string, string> $headers
     */
    private function countList(string $baseUrl, string $path, array $headers): int
    {
        $resp = $this->request('GET', rtrim($baseUrl, '/') . $path, $headers);
        if ($resp['status'] >= 400) {
            throw new RuntimeException('Telematics ' . $path . ' failed: HTTP ' . $resp['status']);
        }
        $decoded = $this->decodeJson($resp['body']);
        if (isset($decoded['data']) && is_array($decoded['data'])) {
            return count($decoded['data']);
        }
        if (isset($decoded['items']) && is_array($decoded['items'])) {
            return count($decoded['items']);
        }
        return is_array($decoded) ? count($decoded) : 0;
    }
}
