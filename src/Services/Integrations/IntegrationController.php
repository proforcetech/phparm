<?php

namespace App\Services\Integrations;

use App\Models\IntegrationSyncLog;
use App\Models\IntegrationWebhookEvent;
use App\Models\ThirdPartyIntegration;
use App\Models\User;
use App\Services\Integrations\ThirdParty\IntegrationAdapterRegistry;
use App\Support\Auth\AccessGate;
use RuntimeException;

/**
 * Thin controller surface — turns request payloads into service
 * calls and serializes the results. Credentials are NEVER serialized
 * back out (credential fields are stripped in toApi() responses).
 */
class IntegrationController
{
    public function __construct(
        private IntegrationService $service,
        private IntegrationWebhookService $webhooks,
        private IntegrationAdapterRegistry $registry,
        private ThirdPartyIntegrationRepository $repo,
        private AccessGate $gate
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listProviders(User $user): array
    {
        $this->gate->assert($user, 'integrations.view');
        return ['data' => $this->registry->describeAll()];
    }

    /**
     * @return array<string, mixed>
     */
    public function describeProvider(User $user, string $providerKey): array
    {
        $this->gate->assert($user, 'integrations.view');
        if (!$this->registry->has($providerKey)) {
            throw new RuntimeException('Unknown provider: ' . $providerKey);
        }
        $a = $this->registry->get($providerKey);
        return [
            'data' => [
                'provider_key' => $a->providerKey(),
                'display_name' => $a->displayName(),
                'category' => $a->category(),
                'description' => $a->description(),
                'credential_fields' => $a->credentialFields(),
                'setting_fields' => $a->settingFields(),
                'default_cadence_minutes' => $a->defaultCadenceMinutes(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listIntegrations(User $user): array
    {
        $rows = $this->service->listIntegrations($user);
        return ['data' => array_map([$this, 'toApi'], $rows)];
    }

    /**
     * @return array<string, mixed>
     */
    public function getIntegration(User $user, int $id): array
    {
        $row = $this->service->find($user, $id);
        if ($row === null) {
            throw new RuntimeException('Integration not found');
        }
        return ['data' => $this->toApi($row)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function register(User $user, array $payload): array
    {
        $row = $this->service->register($user, $payload);
        return ['data' => $this->toApi($row)];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $payload): array
    {
        $row = $this->service->update($user, $id, $payload);
        if ($row === null) {
            throw new RuntimeException('Integration not found');
        }
        return ['data' => $this->toApi($row)];
    }

    /**
     * @return array<string, mixed>
     */
    public function test(User $user, int $id): array
    {
        return ['data' => $this->service->testConnection($user, $id)];
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(User $user, int $id): array
    {
        return ['data' => $this->service->runSync($user, $id, IntegrationSyncLog::TRIGGER_MANUAL)];
    }

    /**
     * @return array<string, mixed>
     */
    public function disconnect(User $user, int $id): array
    {
        return ['data' => ['disconnected' => $this->service->disconnect($user, $id)]];
    }

    /**
     * @return array<string, mixed>
     */
    public function delete(User $user, int $id): array
    {
        return ['data' => ['deleted' => $this->service->delete($user, $id)]];
    }

    /**
     * @return array<string, mixed>
     */
    public function listLogs(User $user, int $id): array
    {
        $logs = $this->service->listLogs($user, $id);
        return [
            'data' => array_map(static function (IntegrationSyncLog $l): array {
                return [
                    'id' => $l->id,
                    'integration_id' => $l->integration_id,
                    'triggered_by' => $l->triggered_by,
                    'user_id' => $l->user_id,
                    'direction' => $l->direction,
                    'status' => $l->status,
                    'records_in' => $l->records_in,
                    'records_out' => $l->records_out,
                    'duration_ms' => $l->duration_ms,
                    'error_message' => $l->error_message,
                    'summary' => $l->summary,
                    'started_at' => $l->started_at,
                    'finished_at' => $l->finished_at,
                ];
            }, $logs),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function listWebhookEvents(User $user, int $id): array
    {
        $events = $this->webhooks->listForIntegration($user, $id);
        return [
            'data' => array_map(static function (IntegrationWebhookEvent $e): array {
                return [
                    'id' => $e->id,
                    'provider_key' => $e->provider_key,
                    'event_type' => $e->event_type,
                    'external_id' => $e->external_id,
                    'status' => $e->status,
                    'error_message' => $e->error_message,
                    'received_at' => $e->received_at,
                    'processed_at' => $e->processed_at,
                ];
            }, $events),
        ];
    }

    /**
     * Public webhook receipt — no auth gate (signature verification
     * is the adapter's job once it lands). Stores the body and returns
     * the dedup'd row id.
     *
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public function receiveWebhook(string $providerKey, string $rawBody, array $headers): array
    {
        $event = $this->webhooks->receive($providerKey, $rawBody, $headers);
        return ['data' => ['event_id' => $event->id, 'status' => $event->status]];
    }

    /**
     * Strip the `credentials` blob — never returned over the API.
     *
     * @return array<string, mixed>
     */
    private function toApi(ThirdPartyIntegration $row): array
    {
        return [
            'id' => $row->id,
            'provider_key' => $row->provider_key,
            'name' => $row->name,
            'category' => $row->category,
            'status' => $row->status,
            'settings' => $row->settings,
            'sync_cadence_minutes' => $row->sync_cadence_minutes,
            'last_sync_at' => $row->last_sync_at,
            'last_sync_status' => $row->last_sync_status,
            'last_sync_error' => $row->last_sync_error,
            'next_sync_at' => $row->next_sync_at,
            'owner_user_id' => $row->owner_user_id,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'has_credentials' => $row->credentials !== null && $row->credentials !== '',
        ];
    }
}
