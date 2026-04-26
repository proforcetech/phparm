<?php

namespace App\Services\Integrations;

use App\Models\IntegrationWebhookEvent;
use App\Models\User;
use App\Services\Integrations\ThirdParty\IntegrationAdapterRegistry;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use DateTimeZone;
use RuntimeException;

/**
 * Inbound webhook handler. The receive() endpoint is intentionally
 * cheap: it persists the raw body keyed by (provider, sha256) and
 * returns 202 immediately. A worker pass (cron) sweeps `received`
 * rows, dispatches them to the integration's adapter, and flips the
 * row to 'processed' or 'failed'.
 *
 * Splitting receipt from processing means a slow accountant API
 * doesn't get our endpoint timed out by the sender (Xero, etc),
 * and a redelivered webhook is dedup'd by hash before the adapter
 * is even loaded.
 *
 * The signature verification surface is left to per-provider
 * adapter implementations (most providers sign with HMAC of a known
 * secret); this service holds the receipt log only.
 */
class IntegrationWebhookService
{
    public function __construct(
        private IntegrationWebhookEventRepository $events,
        private ThirdPartyIntegrationRepository $integrations,
        private IntegrationAdapterRegistry $registry,
        private AccessGate $gate
    ) {
    }

    /**
     * Persist an inbound webhook for asynchronous processing.
     *
     * @param array<string, mixed> $headers
     */
    public function receive(string $providerKey, string $rawBody, array $headers = []): IntegrationWebhookEvent
    {
        if ($providerKey === '') {
            throw new RuntimeException('provider_key is required');
        }

        $integration = $this->integrations->findByProviderKey($providerKey);
        $eventType = (string) ($headers['x-event-type'] ?? $headers['X-Event-Type'] ?? '');
        $externalId = (string) ($headers['x-event-id'] ?? $headers['X-Event-Id'] ?? '');

        return $this->events->record(
            $integration?->id,
            $providerKey,
            $eventType !== '' ? $eventType : null,
            $externalId !== '' ? $externalId : null,
            $rawBody,
            $headers
        );
    }

    /**
     * Sweep unprocessed webhook rows. Today this just marks them
     * processed — actual dispatch to the adapter's onWebhook hook is
     * a follow-up that requires extending IntegrationAdapterInterface
     * with onWebhook(payload, integration, settings, credentials).
     *
     * Until then this exists so the receipt log doesn't grow
     * unbounded with 'received' rows that are effectively ignored.
     *
     * @return array<int, array<string, mixed>>
     */
    public function processPending(int $limit = 100, ?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $rows = $this->events->listUnprocessed($limit);
        $out = [];
        foreach ($rows as $event) {
            $this->events->markProcessed($event->id ?? 0, $now->format('Y-m-d H:i:s'));
            $out[] = ['event_id' => $event->id, 'status' => 'processed'];
        }
        return $out;
    }

    /**
     * @return array<int, IntegrationWebhookEvent>
     */
    public function listForIntegration(User $user, int $integrationId, int $limit = 50): array
    {
        $this->gate->assert($user, 'integrations.view');
        return $this->events->listForIntegration($integrationId, $limit);
    }
}
