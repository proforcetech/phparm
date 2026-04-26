<?php

namespace App\Services\Integrations\ThirdParty;

/**
 * Contract for an external-platform adapter (QuickBooks, Xero, Mapbox,
 * a telematics provider, etc).
 *
 * Adapters are stateless — every call receives both the decrypted
 * credentials and the (plaintext) settings dictionary. They do NOT
 * read the env or the connection directly. This keeps the adapter
 * surface easy to swap out in tests and easy to reason about for
 * security audits.
 *
 * The shape returned by every method is a plain associative array so
 * the orchestrator can serialize it into the sync log without needing
 * to know the adapter's internals.
 */
interface IntegrationAdapterInterface
{
    /**
     * Stable provider key used as the lookup id (e.g., "quickbooks_online").
     */
    public function providerKey(): string;

    /**
     * Human-friendly display name shown in the admin UI.
     */
    public function displayName(): string;

    /**
     * One of ThirdPartyIntegration::CATEGORIES.
     */
    public function category(): string;

    /**
     * Short blurb describing what the integration does. Shown next to
     * the provider in the catalog page.
     */
    public function description(): string;

    /**
     * Required + optional credential fields. Shape:
     *   [
     *     'field_name' => [
     *       'label' => 'Client ID',
     *       'required' => true,
     *       'sensitive' => true,            // hide in UI, never log
     *       'type' => 'string'|'oauth_token'|'api_key',
     *       'help' => 'optional help text'
     *     ],
     *     ...
     *   ]
     *
     * @return array<string, array<string, mixed>>
     */
    public function credentialFields(): array;

    /**
     * Non-secret tunables (sandbox/prod toggle, region, default account
     * mapping, etc). Same shape as credentialFields() but stored
     * unencrypted.
     *
     * @return array<string, array<string, mixed>>
     */
    public function settingFields(): array;

    /**
     * Make a minimal API call to verify credentials are valid + endpoint
     * is reachable. MUST NOT mutate remote state. Return shape:
     *   ['ok' => bool, 'message' => string, 'meta' => array]
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $settings
     * @return array{ok: bool, message: string, meta?: array<string, mixed>}
     */
    public function testConnection(array $credentials, array $settings): array;

    /**
     * Pull / push data per the adapter's contract. Return shape:
     *   ['records_in' => int, 'records_out' => int, 'summary' => array]
     *
     * Throws on error; the orchestrator catches and writes a failed
     * sync log row.
     *
     * @param array<string, mixed> $credentials
     * @param array<string, mixed> $settings
     * @param array<string, mixed> $context
     * @return array{records_in: int, records_out: int, summary: array<string, mixed>}
     */
    public function sync(array $credentials, array $settings, array $context = []): array;

    /**
     * Default sync cadence in minutes — used when a connection is
     * created without an explicit cadence override. Return null for
     * webhook-driven adapters that don't poll.
     */
    public function defaultCadenceMinutes(): ?int;
}
