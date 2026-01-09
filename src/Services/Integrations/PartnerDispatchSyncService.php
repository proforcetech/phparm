<?php

namespace App\Services\Integrations;

use App\Database\Connection;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use RuntimeException;

class PartnerDispatchSyncService
{
    private Connection $connection;
    private ?AuditLogger $audit;
    private PartnerDispatchAdapterRegistry $registry;
    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(
        Connection $connection,
        ?AuditLogger $audit,
        PartnerDispatchAdapterRegistry $registry,
        array $config = []
    ) {
        $this->connection = $connection;
        $this->audit = $audit;
        $this->registry = $registry;
        $this->config = $config;
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function acceptDispatch(
        string $partner,
        string $dispatchReference,
        array $context = [],
        ?int $actorId = null
    ): array {
        $dispatch = $this->fetchDispatch($partner, $dispatchReference);
        $adapter = $this->requireAdapter($partner);
        $protocol = $this->resolveProtocol($dispatch, $context);

        $payload = $adapter->buildAcceptancePayload($dispatch, $context);
        try {
            $result = $this->sendToPartner($partner, $protocol, 'accept', $payload);
        } catch (RuntimeException $exception) {
            $this->updateDispatchSyncState(
                $dispatch['id'],
                [
                    'sync_attempts' => $this->retryAttemptsFor($partner),
                    'sync_error' => $exception->getMessage(),
                ]
            );
            throw $exception;
        }

        $this->updateDispatchSyncState(
            $dispatch['id'],
            [
                'accepted_at' => date('Y-m-d H:i:s'),
                'accepted_by' => $actorId,
                'last_partner_status' => 'accepted',
                'sync_attempts' => $result['attempts'],
                'sync_error' => null,
                'last_synced_at' => date('Y-m-d H:i:s'),
            ]
        );

        $this->logAudit('integration.partner_dispatch.accepted', (int) $dispatch['id'], [
            'partner' => $partner,
            'protocol' => $protocol,
            'dispatch_reference' => $dispatch['dispatch_reference'],
            'external_reference' => $dispatch['external_reference'],
            'attempts' => $result['attempts'],
        ]);

        return [
            'status' => 'accepted',
            'dispatch_reference' => $dispatch['dispatch_reference'],
            'protocol' => $protocol,
            'attempts' => $result['attempts'],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function syncStatus(
        string $partner,
        string $dispatchReference,
        string $status,
        array $context = []
    ): array {
        $dispatch = $this->fetchDispatch($partner, $dispatchReference);
        $adapter = $this->requireAdapter($partner);
        $protocol = $this->resolveProtocol($dispatch, $context);

        $payload = $adapter->buildStatusPayload($dispatch, $status, $context);
        try {
            $result = $this->sendToPartner($partner, $protocol, 'status', $payload);
        } catch (RuntimeException $exception) {
            $this->updateDispatchSyncState(
                $dispatch['id'],
                [
                    'sync_attempts' => $this->retryAttemptsFor($partner),
                    'sync_error' => $exception->getMessage(),
                ]
            );
            throw $exception;
        }

        $this->updateDispatchSyncState(
            $dispatch['id'],
            [
                'last_partner_status' => $status,
                'sync_attempts' => $result['attempts'],
                'sync_error' => null,
                'last_synced_at' => date('Y-m-d H:i:s'),
            ]
        );

        $this->logAudit('integration.partner_dispatch.status_synced', (int) $dispatch['id'], [
            'partner' => $partner,
            'protocol' => $protocol,
            'dispatch_reference' => $dispatch['dispatch_reference'],
            'external_reference' => $dispatch['external_reference'],
            'status' => $status,
            'attempts' => $result['attempts'],
        ]);

        return [
            'status' => 'synced',
            'dispatch_reference' => $dispatch['dispatch_reference'],
            'protocol' => $protocol,
            'attempts' => $result['attempts'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchDispatch(string $partner, string $dispatchReference): array
    {
        $partnerKey = strtolower(trim($partner));
        if ($partnerKey === '') {
            throw new InvalidArgumentException('Partner identifier is required.');
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT pdr.*
             FROM partner_dispatch_requests pdr
             INNER JOIN partner_accounts pa ON pa.id = pdr.partner_account_id
             WHERE pa.partner_key = :partner_key
               AND (pdr.dispatch_reference = :ref OR pdr.external_reference = :ref)
             ORDER BY pdr.id DESC
             LIMIT 1'
        );
        $stmt->execute([
            'partner_key' => $partnerKey,
            'ref' => $dispatchReference,
        ]);
        $dispatch = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$dispatch) {
            throw new InvalidArgumentException('Dispatch reference not found for partner.');
        }

        $payload = [];
        if (!empty($dispatch['payload'])) {
            $decoded = json_decode((string) $dispatch['payload'], true);
            if (is_array($decoded)) {
                $payload = $decoded;
            }
        }

        $dispatch['partner'] = $partnerKey;
        $dispatch['payload'] = $payload;
        $dispatch['protocol'] = $dispatch['protocol'] ?? ($payload['protocol'] ?? null);

        return $dispatch;
    }

    private function requireAdapter(string $partner): PartnerDispatchAdapterInterface
    {
        $adapter = $this->registry->adapterFor($partner);
        if ($adapter === null) {
            throw new InvalidArgumentException('Unsupported partner integration.');
        }

        return $adapter;
    }

    /**
     * @param array<string, mixed> $dispatch
     * @param array<string, mixed> $context
     */
    private function resolveProtocol(array $dispatch, array $context): string
    {
        $protocol = $dispatch['protocol'] ?? ($context['protocol'] ?? null);
        $normalized = PartnerDispatchProtocol::normalize(is_string($protocol) ? $protocol : null);
        return $normalized ?? PartnerDispatchProtocol::DIGITAL_DISPATCH;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array{attempts:int,status:string}
     */
    private function sendToPartner(string $partner, string $protocol, string $action, array $payload): array
    {
        $partnerConfig = $this->config['partners'][$partner] ?? [];
        $protocolConfig = $partnerConfig['protocols'][$protocol] ?? [];

        $endpoint = $this->endpointFor($partner, $protocol, $action);
        $attempts = $this->retryAttemptsFor($partner);
        $backoffMs = max(0, (int) ($partnerConfig['retry']['backoff_ms'] ?? 250));
        $timeout = (int) ($protocolConfig['timeout'] ?? 5);
        $authToken = (string) ($protocolConfig['auth_token'] ?? '');

        $headers = ['Content-Type: application/json', 'X-Dispatch-Protocol: ' . $protocol];
        if ($authToken !== '') {
            $headers[] = 'Authorization: Bearer ' . $authToken;
        }

        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            throw new RuntimeException('Unable to encode partner dispatch payload.');
        }

        $lastError = null;
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $this->post($endpoint, $body, $headers, $timeout);
                return ['attempts' => $attempt, 'status' => 'sent'];
            } catch (RuntimeException $exception) {
                $lastError = $exception;
                $this->logAudit('integration.partner_dispatch.retry', null, [
                    'partner' => $partner,
                    'protocol' => $protocol,
                    'action' => $action,
                    'attempt' => $attempt,
                    'error' => $exception->getMessage(),
                ]);

                if ($attempt < $attempts && $backoffMs > 0) {
                    usleep($backoffMs * 1000);
                    $backoffMs *= 2;
                }
            }
        }

        $message = $lastError?->getMessage() ?? 'Unknown partner dispatch error.';
        $this->logAudit('integration.partner_dispatch.failed', null, [
            'partner' => $partner,
            'protocol' => $protocol,
            'action' => $action,
            'error' => $message,
        ]);

        throw new RuntimeException('Partner dispatch sync failed: ' . $message);
    }

    private function retryAttemptsFor(string $partner): int
    {
        return max(1, (int) (($this->config['partners'][$partner]['retry']['attempts'] ?? 3)));
    }

    private function endpointFor(string $partner, string $protocol, string $action): string
    {
        $partnerConfig = $this->config['partners'][$partner]['protocols'][$protocol] ?? null;
        $endpoint = $partnerConfig[$action . '_endpoint'] ?? '';
        if (!is_string($endpoint) || $endpoint === '') {
            throw new InvalidArgumentException('Partner dispatch endpoint is not configured.');
        }

        return $endpoint;
    }

    /**
     * @param array<string, mixed> $updates
     */
    private function updateDispatchSyncState(int $dispatchId, array $updates): void
    {
        $fields = [];
        $params = ['id' => $dispatchId];

        foreach ($updates as $field => $value) {
            $fields[] = sprintf('%s = :%s', $field, $field);
            $params[$field] = $value;
        }

        if ($fields === []) {
            return;
        }

        $sql = 'UPDATE partner_dispatch_requests SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    private function post(string $url, string $body, array $headers, int $timeout): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize partner dispatch request.');
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            $message = $error ?: 'HTTP ' . $status;
            throw new RuntimeException('Partner dispatch sync failed: ' . $message);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAudit(string $event, ?int $dispatchId, array $context): void
    {
        if ($this->audit === null) {
            return;
        }

        $entityId = $dispatchId !== null ? (string) $dispatchId : null;
        $this->audit->log(new AuditEntry($event, 'partner_dispatch_request', $entityId, null, $context));
    }
}
