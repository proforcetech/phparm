<?php

namespace App\Services\Integrations\ThirdParty;

use App\Models\ThirdPartyIntegration;
use RuntimeException;

/**
 * Xero accounting adapter. OAuth 2.0 with tenant scoping.
 *
 * Mirrors QuickBooksOnlineAdapter in shape so the calling code can
 * operate uniformly across accounting providers.
 */
class XeroAdapter extends AbstractIntegrationAdapter
{
    public function providerKey(): string
    {
        return 'xero';
    }

    public function displayName(): string
    {
        return 'Xero';
    }

    public function category(): string
    {
        return ThirdPartyIntegration::CATEGORY_ACCOUNTING;
    }

    public function description(): string
    {
        return 'Sync contacts and invoices to/from Xero. OAuth 2.0 + tenant-scoped.';
    }

    public function credentialFields(): array
    {
        return [
            'client_id' => [
                'label' => 'Client ID',
                'required' => true,
                'sensitive' => false,
                'type' => 'string',
            ],
            'client_secret' => [
                'label' => 'Client Secret',
                'required' => true,
                'sensitive' => true,
                'type' => 'string',
            ],
            'refresh_token' => [
                'label' => 'Refresh Token',
                'required' => true,
                'sensitive' => true,
                'type' => 'oauth_token',
            ],
        ];
    }

    public function settingFields(): array
    {
        return [
            'tenant_id' => [
                'label' => 'Tenant ID',
                'required' => true,
                'type' => 'string',
                'help' => 'Xero organization (tenant) UUID — obtained from /connections after the OAuth dance.',
            ],
        ];
    }

    public function defaultCadenceMinutes(): ?int
    {
        return 60;
    }

    public function testConnection(array $credentials, array $settings): array
    {
        $this->requireCredential($credentials, 'client_id');
        $this->requireCredential($credentials, 'client_secret');
        $this->requireCredential($credentials, 'refresh_token');
        $tenant = (string) $this->setting($settings, 'tenant_id', '');
        if ($tenant === '') {
            return ['ok' => false, 'message' => 'tenant_id setting is required'];
        }

        $accessToken = $this->mintAccessToken($credentials);

        $resp = $this->request(
            'GET',
            'https://api.xero.com/api.xro/2.0/Organisation',
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Xero-Tenant-Id' => $tenant,
                'Accept' => 'application/json',
            ]
        );
        if ($resp['status'] >= 400) {
            return [
                'ok' => false,
                'message' => 'Xero API returned HTTP ' . $resp['status'],
                'meta' => ['body_excerpt' => substr($resp['body'], 0, 200)],
            ];
        }
        $decoded = $this->decodeJson($resp['body']);
        $orgName = $decoded['Organisations'][0]['Name'] ?? null;
        return [
            'ok' => true,
            'message' => 'Connected to ' . ($orgName ?? 'Xero tenant ' . $tenant),
            'meta' => ['organization' => $orgName],
        ];
    }

    public function sync(array $credentials, array $settings, array $context = []): array
    {
        $this->requireCredential($credentials, 'client_id');
        $this->requireCredential($credentials, 'client_secret');
        $this->requireCredential($credentials, 'refresh_token');
        $tenant = (string) $this->setting($settings, 'tenant_id', '');
        if ($tenant === '') {
            throw new RuntimeException('tenant_id setting is required');
        }

        $accessToken = $this->mintAccessToken($credentials);
        $since = (string) ($context['since'] ?? '1970-01-01T00:00:00');

        $invoices = $this->countResource(
            'https://api.xero.com/api.xro/2.0/Invoices?statuses=AUTHORISED,PAID',
            $accessToken,
            $tenant,
            $since,
            'Invoices'
        );
        $contacts = $this->countResource(
            'https://api.xero.com/api.xro/2.0/Contacts',
            $accessToken,
            $tenant,
            $since,
            'Contacts'
        );

        return [
            'records_in' => $invoices + $contacts,
            'records_out' => 0,
            'summary' => [
                'invoices' => $invoices,
                'contacts' => $contacts,
                'since' => $since,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function mintAccessToken(array $credentials): string
    {
        $resp = $this->request(
            'POST',
            'https://identity.xero.com/connect/token',
            [
                'Authorization' => 'Basic ' . base64_encode(
                    $credentials['client_id'] . ':' . $credentials['client_secret']
                ),
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ],
            'grant_type=refresh_token&refresh_token=' . urlencode($credentials['refresh_token'])
        );
        if ($resp['status'] >= 400) {
            throw new RuntimeException('Xero token mint failed: HTTP ' . $resp['status']);
        }
        $decoded = $this->decodeJson($resp['body']);
        if (!isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new RuntimeException('Xero token response missing access_token');
        }
        return $decoded['access_token'];
    }

    private function countResource(string $url, string $accessToken, string $tenant, string $since, string $listKey): int
    {
        $resp = $this->request(
            'GET',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Xero-Tenant-Id' => $tenant,
                'Accept' => 'application/json',
                'If-Modified-Since' => $since,
            ]
        );
        if ($resp['status'] === 304) {
            return 0;
        }
        if ($resp['status'] >= 400) {
            throw new RuntimeException('Xero ' . $listKey . ' query failed: HTTP ' . $resp['status']);
        }
        $decoded = $this->decodeJson($resp['body']);
        return is_array($decoded[$listKey] ?? null) ? count($decoded[$listKey]) : 0;
    }
}
