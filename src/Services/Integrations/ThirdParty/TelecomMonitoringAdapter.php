<?php

namespace App\Services\Integrations\ThirdParty;

use App\Models\ThirdPartyIntegration;
use RuntimeException;

/**
 * Telecom monitoring adapter — call recording / VoIP analytics
 * platforms (Twilio Voice Insights, RingCentral Analytics, 8x8, etc).
 *
 * Generic over the auth scheme: most of these platforms expose
 * Basic-Auth or Bearer endpoints; we let the operator wire the
 * right header format via settings rather than ship a per-vendor
 * class for every contender.
 */
class TelecomMonitoringAdapter extends AbstractIntegrationAdapter
{
    public function providerKey(): string
    {
        return 'telecom_monitoring';
    }

    public function displayName(): string
    {
        return 'Telecom Monitoring';
    }

    public function category(): string
    {
        return ThirdPartyIntegration::CATEGORY_TELECOM;
    }

    public function description(): string
    {
        return 'Call records and VoIP analytics ingestion (Twilio/RingCentral/8x8-shaped APIs).';
    }

    public function credentialFields(): array
    {
        return [
            'account_sid' => [
                'label' => 'Account SID / API Key ID',
                'required' => true,
                'sensitive' => false,
                'type' => 'string',
            ],
            'auth_token' => [
                'label' => 'Auth Token / API Secret',
                'required' => true,
                'sensitive' => true,
                'type' => 'string',
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
                'help' => 'e.g. https://api.twilio.com/2010-04-01/Accounts/{sid}',
            ],
            'auth_scheme' => [
                'label' => 'Auth Scheme',
                'required' => false,
                'type' => 'enum',
                'options' => ['basic', 'bearer'],
                'default' => 'basic',
            ],
            'calls_path' => [
                'label' => 'Calls Endpoint Path',
                'required' => false,
                'type' => 'string',
                'default' => '/Calls.json',
            ],
        ];
    }

    public function defaultCadenceMinutes(): ?int
    {
        return 30;
    }

    public function testConnection(array $credentials, array $settings): array
    {
        $sid = $this->requireCredential($credentials, 'account_sid');
        $token = $this->requireCredential($credentials, 'auth_token');
        $baseUrl = (string) $this->setting($settings, 'base_url', '');
        if ($baseUrl === '') {
            return ['ok' => false, 'message' => 'base_url setting is required'];
        }

        $headers = $this->authHeaders($sid, $token, (string) $this->setting($settings, 'auth_scheme', 'basic'));
        $headers['Accept'] = 'application/json';

        $callsPath = (string) $this->setting($settings, 'calls_path', '/Calls.json');
        $resp = $this->request(
            'GET',
            rtrim($baseUrl, '/') . $callsPath . '?PageSize=1',
            $headers
        );
        if ($resp['status'] >= 400) {
            return [
                'ok' => false,
                'message' => 'Telecom API returned HTTP ' . $resp['status'],
            ];
        }
        return ['ok' => true, 'message' => 'Telecom endpoint is reachable.'];
    }

    public function sync(array $credentials, array $settings, array $context = []): array
    {
        $sid = $this->requireCredential($credentials, 'account_sid');
        $token = $this->requireCredential($credentials, 'auth_token');
        $baseUrl = (string) $this->setting($settings, 'base_url', '');
        if ($baseUrl === '') {
            throw new RuntimeException('base_url setting is required');
        }

        $headers = $this->authHeaders($sid, $token, (string) $this->setting($settings, 'auth_scheme', 'basic'));
        $headers['Accept'] = 'application/json';

        $callsPath = (string) $this->setting($settings, 'calls_path', '/Calls.json');
        $since = (string) ($context['since'] ?? date('Y-m-d', strtotime('-1 day')));

        $resp = $this->request(
            'GET',
            rtrim($baseUrl, '/') . $callsPath . '?StartTime%3E=' . urlencode($since) . '&PageSize=200',
            $headers
        );
        if ($resp['status'] >= 400) {
            throw new RuntimeException('Telecom sync failed: HTTP ' . $resp['status']);
        }
        $decoded = $this->decodeJson($resp['body']);
        $calls = $decoded['calls'] ?? $decoded['data'] ?? [];
        $count = is_array($calls) ? count($calls) : 0;

        return [
            'records_in' => $count,
            'records_out' => 0,
            'summary' => [
                'calls_pulled' => $count,
                'since' => $since,
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $sid, string $token, string $scheme): array
    {
        if ($scheme === 'bearer') {
            return ['Authorization' => 'Bearer ' . $token];
        }
        return ['Authorization' => 'Basic ' . base64_encode($sid . ':' . $token)];
    }
}
