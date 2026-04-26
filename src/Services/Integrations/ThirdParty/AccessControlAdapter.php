<?php

namespace App\Services\Integrations\ThirdParty;

use App\Models\ThirdPartyIntegration;
use RuntimeException;

/**
 * Generic access-control / gate-system adapter (Brivo, Openpath, ProdataKey,
 * Avigilon Alta, etc). The hot path here is: list active credentials,
 * push a granted/revoked badge to a controller, audit who walked
 * through which gate when.
 *
 * The sync() pulls "events since last run" so the local audit log
 * stays current. Push direction (granting access to a new tech) is
 * left to a follow-up that wires this to the user/employee onboarding
 * flow.
 */
class AccessControlAdapter extends AbstractIntegrationAdapter
{
    public function providerKey(): string
    {
        return 'access_control';
    }

    public function displayName(): string
    {
        return 'Access Control Platform';
    }

    public function category(): string
    {
        return ThirdPartyIntegration::CATEGORY_ACCESS_CONTROL;
    }

    public function description(): string
    {
        return 'Gate / door / badge system event log + credential management.';
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
                'help' => 'e.g. https://api.access-vendor.example/v2',
            ],
            'site_id' => [
                'label' => 'Site / Account ID',
                'required' => true,
                'type' => 'string',
            ],
            'events_path' => [
                'label' => 'Events Endpoint Path',
                'required' => false,
                'type' => 'string',
                'default' => '/sites/{site_id}/events',
            ],
        ];
    }

    public function defaultCadenceMinutes(): ?int
    {
        return 5;
    }

    public function testConnection(array $credentials, array $settings): array
    {
        $apiKey = $this->requireCredential($credentials, 'api_key');
        $baseUrl = (string) $this->setting($settings, 'base_url', '');
        $siteId = (string) $this->setting($settings, 'site_id', '');
        if ($baseUrl === '' || $siteId === '') {
            return ['ok' => false, 'message' => 'base_url and site_id settings are required'];
        }

        $resp = $this->request(
            'GET',
            rtrim($baseUrl, '/') . '/sites/' . rawurlencode($siteId),
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ]
        );
        if ($resp['status'] >= 400) {
            return [
                'ok' => false,
                'message' => 'Access control API returned HTTP ' . $resp['status'],
            ];
        }
        return ['ok' => true, 'message' => 'Access control site is reachable.'];
    }

    public function sync(array $credentials, array $settings, array $context = []): array
    {
        $apiKey = $this->requireCredential($credentials, 'api_key');
        $baseUrl = (string) $this->setting($settings, 'base_url', '');
        $siteId = (string) $this->setting($settings, 'site_id', '');
        if ($baseUrl === '' || $siteId === '') {
            throw new RuntimeException('base_url and site_id settings are required');
        }

        $eventsPath = (string) $this->setting($settings, 'events_path', '/sites/{site_id}/events');
        $eventsPath = str_replace('{site_id}', rawurlencode($siteId), $eventsPath);
        $since = (string) ($context['since'] ?? date('c', strtotime('-1 hour')));

        $resp = $this->request(
            'GET',
            rtrim($baseUrl, '/') . $eventsPath . '?since=' . urlencode($since),
            [
                'Authorization' => 'Bearer ' . $apiKey,
                'Accept' => 'application/json',
            ]
        );
        if ($resp['status'] >= 400) {
            throw new RuntimeException('Access control sync failed: HTTP ' . $resp['status']);
        }
        $decoded = $this->decodeJson($resp['body']);
        $events = $decoded['events'] ?? $decoded['data'] ?? [];
        $count = is_array($events) ? count($events) : 0;

        return [
            'records_in' => $count,
            'records_out' => 0,
            'summary' => [
                'events_pulled' => $count,
                'since' => $since,
            ],
        ];
    }
}
