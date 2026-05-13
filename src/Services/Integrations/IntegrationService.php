<?php

namespace App\Services\Integrations;

use App\Models\IntegrationSyncLog;
use App\Models\ThirdPartyIntegration;
use App\Models\User;
use App\Services\Integrations\ThirdParty\IntegrationAdapterInterface;
use App\Services\Integrations\ThirdParty\IntegrationAdapterRegistry;
use App\Support\Auth\AccessGate;
use App\Support\Crypto\FieldCipher;
use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Orchestrates the integration lifecycle:
 *
 *   register → testConnection → sync (manual or scheduled) → disconnect
 *
 * Permission gates:
 *   - integrations.view : list, find, see logs
 *   - integrations.manage : create, update, test, run sync, disconnect
 *
 * Credentials at rest: every credential map is JSON-encoded then
 * passed through FieldCipher::encrypt() before write. The decrypt
 * happens at sync/test time only; plaintext is held in a local
 * variable that drops out of scope as soon as the adapter call
 * returns. Credentials never appear in log rows.
 */
class IntegrationService
{
    public function __construct(
        private ThirdPartyIntegrationRepository $repo,
        private IntegrationSyncLogRepository $logs,
        private IntegrationAdapterRegistry $registry,
        private FieldCipher $cipher,
        private AccessGate $gate
    ) {
    }

    /**
     * @return array<int, IntegrationAdapterInterface>
     */
    public function listAvailableProviders(User $user): array
    {
        $this->gate->assert($user, 'integrations.view');
        return $this->registry->listAll();
    }

    /**
     * @return array<int, ThirdPartyIntegration>
     */
    public function listIntegrations(User $user): array
    {
        $this->gate->assert($user, 'integrations.view');
        return $this->repo->listAll();
    }

    public function find(User $user, int $id): ?ThirdPartyIntegration
    {
        $this->gate->assert($user, 'integrations.view');
        return $this->repo->find($id);
    }

    /**
     * Create a new integration row. Validates the provider exists,
     * checks required credential fields, encrypts the credential blob,
     * and seeds next_sync_at so the cron can pick it up immediately.
     *
     * @param array<string, mixed> $payload {
     *     provider_key: string,
     *     name?: string,
     *     credentials: array<string, mixed>,
     *     settings?: array<string, mixed>,
     *     sync_cadence_minutes?: int|null,
     * }
     */
    public function register(User $user, array $payload): ThirdPartyIntegration
    {
        $this->gate->assert($user, 'integrations.manage');

        $providerKey = (string) ($payload['provider_key'] ?? '');
        if ($providerKey === '' || !$this->registry->has($providerKey)) {
            throw new InvalidArgumentException('Unknown integration provider: ' . $providerKey);
        }
        $adapter = $this->registry->get($providerKey);

        $credentials = $payload['credentials'] ?? null;
        if (!is_array($credentials)) {
            throw new InvalidArgumentException('credentials must be an associative array');
        }
        $this->validateCredentials($adapter, $credentials);

        $settings = $payload['settings'] ?? [];
        if (!is_array($settings)) {
            throw new InvalidArgumentException('settings must be an associative array');
        }

        if (!$this->cipher->isAvailable()) {
            throw new RuntimeException(
                'FieldCipher is not configured — set INTEGRATION_CREDENTIALS_ENCRYPTION_KEY before storing integration credentials.'
            );
        }

        $cadence = $payload['sync_cadence_minutes'] ?? $adapter->defaultCadenceMinutes();
        $cadenceInt = is_int($cadence) ? max(0, $cadence) : null;

        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $nextSync = $cadenceInt !== null && $cadenceInt > 0
            ? $now->format('Y-m-d H:i:s')
            : null;

        return $this->repo->create([
            'provider_key' => $providerKey,
            'name' => (string) ($payload['name'] ?? $adapter->displayName()),
            'category' => $adapter->category(),
            'status' => ThirdPartyIntegration::STATUS_PENDING,
            'credentials' => $this->cipher->encrypt(
                json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            ),
            'settings' => $settings,
            'sync_cadence_minutes' => $cadenceInt,
            'next_sync_at' => $nextSync,
            'owner_user_id' => $user->id,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(User $user, int $id, array $payload): ?ThirdPartyIntegration
    {
        $this->gate->assert($user, 'integrations.manage');
        $existing = $this->repo->find($id);
        if ($existing === null) {
            return null;
        }
        $adapter = $this->registry->get($existing->provider_key);

        $update = [];

        if (array_key_exists('name', $payload)) {
            $update['name'] = (string) $payload['name'];
        }
        if (array_key_exists('settings', $payload)) {
            if (!is_array($payload['settings'])) {
                throw new InvalidArgumentException('settings must be an associative array');
            }
            $update['settings'] = $payload['settings'];
        }
        if (array_key_exists('sync_cadence_minutes', $payload)) {
            $cadence = $payload['sync_cadence_minutes'];
            $update['sync_cadence_minutes'] = $cadence === null ? null : max(0, (int) $cadence);
        }
        if (array_key_exists('status', $payload)) {
            $status = (string) $payload['status'];
            if (!in_array($status, ThirdPartyIntegration::STATUSES, true)) {
                throw new InvalidArgumentException('Invalid status: ' . $status);
            }
            $update['status'] = $status;
        }
        if (array_key_exists('credentials', $payload)) {
            $credentials = $payload['credentials'];
            if (!is_array($credentials)) {
                throw new InvalidArgumentException('credentials must be an associative array');
            }
            $this->validateCredentials($adapter, $credentials);
            if (!$this->cipher->isAvailable()) {
                throw new RuntimeException('FieldCipher is not configured');
            }
            $update['credentials'] = $this->cipher->encrypt(
                json_encode($credentials, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            );
        }

        return $this->repo->update($id, $update);
    }

    /**
     * Verify credentials against the remote without mutating state.
     * On success the integration's status flips to 'connected'; on
     * failure it lands at 'error' with the message captured.
     *
     * @return array{ok: bool, message: string, meta?: array<string, mixed>}
     */
    public function testConnection(User $user, int $id): array
    {
        $this->gate->assert($user, 'integrations.manage');
        $row = $this->repo->find($id);
        if ($row === null) {
            throw new RuntimeException('Integration not found: ' . $id);
        }
        $adapter = $this->registry->get($row->provider_key);
        $creds = $this->decryptCredentials($row);

        try {
            $result = $adapter->testConnection($creds, $row->settings ?? []);
        } catch (Throwable $e) {
            $this->repo->update($id, [
                'status' => ThirdPartyIntegration::STATUS_ERROR,
                'last_sync_status' => 'failed',
                'last_sync_error' => $e->getMessage(),
            ]);
            return ['ok' => false, 'message' => $e->getMessage()];
        }

        $this->repo->update($id, [
            'status' => ($result['ok'] ?? false)
                ? ThirdPartyIntegration::STATUS_CONNECTED
                : ThirdPartyIntegration::STATUS_ERROR,
            'last_sync_error' => ($result['ok'] ?? false) ? null : ($result['message'] ?? 'unknown'),
        ]);

        return $result;
    }

    /**
     * Run a sync (manual or via the cron). Always writes a sync log row.
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function runSync(?User $user, int $id, string $triggeredBy = IntegrationSyncLog::TRIGGER_MANUAL, array $context = []): array
    {
        if ($user !== null) {
            $this->gate->assert($user, 'integrations.manage');
        }
        $row = $this->repo->find($id);
        if ($row === null) {
            throw new RuntimeException('Integration not found: ' . $id);
        }
        $adapter = $this->registry->get($row->provider_key);

        $logId = $this->logs->start(
            $row->id ?? 0,
            $triggeredBy,
            $user?->id,
            IntegrationSyncLog::DIRECTION_PULL,
            ['since' => $row->last_sync_at]
        );

        $startedAt = microtime(true);
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        $context = array_merge(['since' => $row->last_sync_at ?? '1970-01-01T00:00:00Z'], $context);

        try {
            $creds = $this->decryptCredentials($row);
            $result = $adapter->sync($creds, $row->settings ?? [], $context);
            $duration = (int) round((microtime(true) - $startedAt) * 1000);

            $this->logs->finish(
                $logId,
                IntegrationSyncLog::STATUS_SUCCEEDED,
                $result['records_in'] ?? 0,
                $result['records_out'] ?? 0,
                $duration,
                null,
                $result['summary'] ?? null,
                $now->format('Y-m-d H:i:s')
            );

            $next = $this->computeNextSync($row, $now);
            $this->repo->recordSync(
                $row->id ?? 0,
                'succeeded',
                null,
                $now->format('Y-m-d H:i:s'),
                $next
            );
            // Side-effect: a successful sync brings the row to 'connected'
            // even if it had been parked at 'pending' or 'error'.
            $this->repo->update($row->id ?? 0, ['status' => ThirdPartyIntegration::STATUS_CONNECTED]);

            return [
                'status' => 'succeeded',
                'duration_ms' => $duration,
                'records_in' => $result['records_in'] ?? 0,
                'records_out' => $result['records_out'] ?? 0,
                'summary' => $result['summary'] ?? null,
                'log_id' => $logId,
            ];
        } catch (Throwable $e) {
            $duration = (int) round((microtime(true) - $startedAt) * 1000);
            $this->logs->finish(
                $logId,
                IntegrationSyncLog::STATUS_FAILED,
                null,
                null,
                $duration,
                $e->getMessage(),
                null,
                $now->format('Y-m-d H:i:s')
            );
            $next = $this->computeNextSync($row, $now);
            $this->repo->recordSync(
                $row->id ?? 0,
                'failed',
                $e->getMessage(),
                $now->format('Y-m-d H:i:s'),
                $next
            );
            $this->repo->update($row->id ?? 0, ['status' => ThirdPartyIntegration::STATUS_ERROR]);

            return [
                'status' => 'failed',
                'duration_ms' => $duration,
                'error' => $e->getMessage(),
                'log_id' => $logId,
            ];
        }
    }

    /**
     * Walk every connected integration whose next_sync_at has passed
     * and run their sync. Returns the per-integration result list.
     *
     * @return array<int, array<string, mixed>>
     */
    public function processDueSyncs(?DateTimeImmutable $now = null): array
    {
        $now = $now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $due = $this->repo->listDue($now->format('Y-m-d H:i:s'));
        $results = [];
        foreach ($due as $integration) {
            $results[] = array_merge(
                ['integration_id' => $integration->id],
                $this->runSync(null, $integration->id ?? 0, IntegrationSyncLog::TRIGGER_SCHEDULED)
            );
        }
        return $results;
    }

    public function disconnect(User $user, int $id): bool
    {
        $this->gate->assert($user, 'integrations.manage');
        $row = $this->repo->find($id);
        if ($row === null) {
            return false;
        }
        $this->repo->update($id, [
            'status' => ThirdPartyIntegration::STATUS_DISABLED,
            'next_sync_at' => null,
        ]);
        return true;
    }

    public function delete(User $user, int $id): bool
    {
        $this->gate->assert($user, 'integrations.manage');
        return $this->repo->delete($id);
    }

    /**
     * @return array<int, IntegrationSyncLog>
     */
    public function listLogs(User $user, int $id, int $limit = 50): array
    {
        $this->gate->assert($user, 'integrations.view');
        return $this->logs->listForIntegration($id, $limit);
    }

    /**
     * @return array<string, mixed>
     */
    private function decryptCredentials(ThirdPartyIntegration $row): array
    {
        if ($row->credentials === null || $row->credentials === '') {
            return [];
        }
        if (!$this->cipher->isAvailable()) {
            throw new RuntimeException(
                'FieldCipher is not configured — cannot decrypt stored credentials.'
            );
        }
        $plain = $this->cipher->decrypt($row->credentials);
        $decoded = json_decode($plain, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function computeNextSync(ThirdPartyIntegration $row, DateTimeImmutable $from): ?string
    {
        if ($row->sync_cadence_minutes === null || $row->sync_cadence_minutes <= 0) {
            return null;
        }
        return $from->modify('+' . $row->sync_cadence_minutes . ' minutes')->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $credentials
     */
    private function validateCredentials(IntegrationAdapterInterface $adapter, array $credentials): void
    {
        foreach ($adapter->credentialFields() as $name => $spec) {
            $required = $spec['required'] ?? false;
            if ($required && (!isset($credentials[$name]) || $credentials[$name] === '')) {
                throw new InvalidArgumentException(
                    'Missing required credential field "' . $name . '" for ' . $adapter->providerKey()
                );
            }
        }
    }
}
