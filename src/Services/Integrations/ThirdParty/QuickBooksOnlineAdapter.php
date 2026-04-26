<?php

namespace App\Services\Integrations\ThirdParty;

use App\Models\ThirdPartyIntegration;
use RuntimeException;

/**
 * QuickBooks Online accounting adapter.
 *
 * OAuth 2.0 with realm scoping. Credentials carry the long-lived
 * refresh_token; we do NOT store the access_token (it's derived on
 * each call and held only in memory). Settings carry the realm_id
 * and the env (sandbox|production), which dictates the API base URL.
 *
 * Sync flow: pulls invoices and customers updated since the last
 * successful sync. Only counts records — actual data persistence to
 * local invoices/customers is left to a follow-up that maps QB
 * entities to PHPArm Customer + Invoice rows.
 */
class QuickBooksOnlineAdapter extends AbstractIntegrationAdapter
{
    public function providerKey(): string
    {
        return 'quickbooks_online';
    }

    public function displayName(): string
    {
        return 'QuickBooks Online';
    }

    public function category(): string
    {
        return ThirdPartyIntegration::CATEGORY_ACCOUNTING;
    }

    public function description(): string
    {
        return 'Sync customers and invoices to/from QuickBooks Online. OAuth 2.0 + realm-scoped.';
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
                'help' => 'Long-lived refresh token from the OAuth dance — used to mint short-lived access tokens.',
            ],
        ];
    }

    public function settingFields(): array
    {
        return [
            'realm_id' => [
                'label' => 'Realm ID (Company ID)',
                'required' => true,
                'type' => 'string',
            ],
            'environment' => [
                'label' => 'Environment',
                'required' => true,
                'type' => 'enum',
                'options' => ['sandbox', 'production'],
                'default' => 'sandbox',
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
        $realm = (string) $this->setting($settings, 'realm_id', '');
        if ($realm === '') {
            return ['ok' => false, 'message' => 'realm_id setting is required'];
        }

        $env = (string) $this->setting($settings, 'environment', 'sandbox');
        $baseUrl = $env === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';

        $accessToken = $this->mintAccessToken($credentials, $env);

        $resp = $this->request(
            'GET',
            $baseUrl . '/v3/company/' . rawurlencode($realm) . '/companyinfo/' . rawurlencode($realm),
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ]
        );

        if ($resp['status'] >= 400) {
            return [
                'ok' => false,
                'message' => 'QuickBooks API returned HTTP ' . $resp['status'],
                'meta' => ['body_excerpt' => substr($resp['body'], 0, 200)],
            ];
        }
        $decoded = $this->decodeJson($resp['body']);
        $companyName = $decoded['CompanyInfo']['CompanyName'] ?? null;
        return [
            'ok' => true,
            'message' => 'Connected to ' . ($companyName ?? 'QuickBooks company ' . $realm),
            'meta' => ['company_name' => $companyName],
        ];
    }

    public function sync(array $credentials, array $settings, array $context = []): array
    {
        $this->requireCredential($credentials, 'client_id');
        $this->requireCredential($credentials, 'client_secret');
        $this->requireCredential($credentials, 'refresh_token');
        $realm = (string) $this->setting($settings, 'realm_id', '');
        if ($realm === '') {
            throw new RuntimeException('realm_id setting is required');
        }

        $env = (string) $this->setting($settings, 'environment', 'sandbox');
        $baseUrl = $env === 'production'
            ? 'https://quickbooks.api.intuit.com'
            : 'https://sandbox-quickbooks.api.intuit.com';

        $accessToken = $this->mintAccessToken($credentials, $env);
        $since = (string) ($context['since'] ?? '1970-01-01T00:00:00Z');

        $invoices = $this->countQuery($baseUrl, $realm, $accessToken, "select count(*) from Invoice where MetaData.LastUpdatedTime > '{$since}'");
        $customers = $this->countQuery($baseUrl, $realm, $accessToken, "select count(*) from Customer where MetaData.LastUpdatedTime > '{$since}'");

        return [
            'records_in' => $invoices + $customers,
            'records_out' => 0,
            'summary' => [
                'invoices' => $invoices,
                'customers' => $customers,
                'since' => $since,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function mintAccessToken(array $credentials, string $env): string
    {
        $tokenUrl = $env === 'production'
            ? 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer'
            : 'https://oauth.platform.intuit.com/oauth2/v1/tokens/bearer';

        $resp = $this->request(
            'POST',
            $tokenUrl,
            [
                'Authorization' => 'Basic ' . base64_encode(
                    $credentials['client_id'] . ':' . $credentials['client_secret']
                ),
                'Accept' => 'application/json',
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'grant_type=refresh_token&refresh_token=' . urlencode($credentials['refresh_token'])
        );
        if ($resp['status'] >= 400) {
            throw new RuntimeException('QuickBooks token mint failed: HTTP ' . $resp['status']);
        }
        $decoded = $this->decodeJson($resp['body']);
        if (!isset($decoded['access_token']) || !is_string($decoded['access_token'])) {
            throw new RuntimeException('QuickBooks token response missing access_token');
        }
        return $decoded['access_token'];
    }

    private function countQuery(string $baseUrl, string $realm, string $accessToken, string $query): int
    {
        $url = $baseUrl . '/v3/company/' . rawurlencode($realm)
            . '/query?query=' . rawurlencode($query) . '&minorversion=65';
        $resp = $this->request(
            'GET',
            $url,
            [
                'Authorization' => 'Bearer ' . $accessToken,
                'Accept' => 'application/json',
            ]
        );
        if ($resp['status'] >= 400) {
            throw new RuntimeException('QuickBooks query failed: HTTP ' . $resp['status']);
        }
        $decoded = $this->decodeJson($resp['body']);
        return (int) ($decoded['QueryResponse']['totalCount'] ?? 0);
    }
}
