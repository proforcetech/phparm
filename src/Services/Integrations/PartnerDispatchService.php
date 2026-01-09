<?php

namespace App\Services\Integrations;

use App\Database\Connection;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use Throwable;

class PartnerDispatchService
{
    private Connection $connection;
    private ?AuditLogger $audit;
    private PartnerDispatchAdapterRegistry $registry;
    private PartnerEmailParser $emailParser;

    public function __construct(
        Connection $connection,
        ?AuditLogger $audit,
        PartnerDispatchAdapterRegistry $registry,
        PartnerEmailParser $emailParser
    ) {
        $this->connection = $connection;
        $this->audit = $audit;
        $this->registry = $registry;
        $this->emailParser = $emailParser;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function ingestApiDispatch(string $partner, array $payload): array
    {
        $rawPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $this->ingest($partner, $payload, $rawPayload !== false ? $rawPayload : '', 'api', [], []);
    }

    /**
     * @param array<int, array<string, mixed>> $attachments
     * @return array<string, mixed>
     */
    public function ingestEmailDispatch(string $partner, string $rawEmail, array $attachments = []): array
    {
        $parsed = $this->emailParser->parse($rawEmail);

        return $this->ingest(
            $partner,
            $parsed['payload'],
            $rawEmail,
            'email',
            $attachments,
            $parsed['metadata']
        );
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<int, array<string, mixed>> $attachments
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function ingest(
        string $partner,
        array $payload,
        string $rawPayload,
        string $source,
        array $attachments,
        array $metadata
    ): array {
        $partnerKey = strtolower(trim($partner));
        if ($partnerKey === '') {
            throw new InvalidArgumentException('Partner identifier is required.');
        }

        $partnerAccountId = $this->ensurePartnerAccount($partnerKey);
        $requestId = $this->createDispatchRequest($partnerAccountId, $source, $rawPayload);

        $this->logAudit('integration.partner_dispatch.received', $requestId, [
            'partner' => $partnerKey,
            'source' => $source,
        ]);

        if (!empty($attachments)) {
            $this->storeAttachments($requestId, $attachments);
        }

        try {
            $adapter = $this->registry->adapterFor($partnerKey);
            if ($adapter === null) {
                throw new InvalidArgumentException('Unsupported partner integration.');
            }

            $dto = $adapter->normalize($payload);
            $normalized = $dto->toArray();
            if (!empty($metadata)) {
                $normalized['ingestion'] = $metadata;
            }

            $dispatchReference = sprintf('PDR-%06d', $requestId);
            $this->markNormalized($requestId, $dispatchReference, $dto, $normalized);

            $this->logAudit('integration.partner_dispatch.normalized', $requestId, [
                'partner' => $partnerKey,
                'dispatch_reference' => $dispatchReference,
                'external_reference' => $dto->externalReference,
            ]);

            return [
                'id' => $requestId,
                'status' => 'normalized',
                'dispatch_reference' => $dispatchReference,
            ];
        } catch (Throwable $e) {
            $message = $e->getMessage();
            $this->markFailed($requestId, $message);

            $this->logAudit('integration.partner_dispatch.failed', $requestId, [
                'partner' => $partnerKey,
                'source' => $source,
                'error' => $message,
            ]);

            return [
                'id' => $requestId,
                'status' => 'failed',
                'error' => $message,
            ];
        }
    }

    private function ensurePartnerAccount(string $partnerKey): int
    {
        $stmt = $this->connection->pdo()->prepare('SELECT id FROM partner_accounts WHERE partner_key = :partner_key LIMIT 1');
        $stmt->execute(['partner_key' => $partnerKey]);
        $existing = $stmt->fetchColumn();
        if ($existing !== false) {
            return (int) $existing;
        }

        $name = strtoupper($partnerKey);
        $insert = $this->connection->pdo()->prepare(
            'INSERT INTO partner_accounts (partner_key, name, status, metadata, created_at, updated_at)
             VALUES (:partner_key, :name, :status, :metadata, NOW(), NOW())'
        );
        $metadata = json_encode(['auto_created' => true], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $insert->execute([
            'partner_key' => $partnerKey,
            'name' => $name,
            'status' => 'active',
            'metadata' => $metadata !== false ? $metadata : null,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    private function createDispatchRequest(int $partnerAccountId, string $source, string $rawPayload): int
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO partner_dispatch_requests (partner_account_id, source, status, raw_payload, created_at)
             VALUES (:partner_account_id, :source, :status, :raw_payload, NOW())'
        );
        $stmt->execute([
            'partner_account_id' => $partnerAccountId,
            'source' => $source,
            'status' => 'received',
            'raw_payload' => $rawPayload,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function markNormalized(int $requestId, string $dispatchReference, PartnerDispatchDTO $dto, array $payload): void
    {
        $encoded = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE partner_dispatch_requests
             SET status = :status,
                 payload = :payload,
                 external_reference = :external_reference,
                 dispatch_reference = :dispatch_reference,
                 protocol = :protocol,
                 processed_at = NOW(),
                 error_message = NULL
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'normalized',
            'payload' => $encoded !== false ? $encoded : null,
            'external_reference' => $dto->externalReference,
            'dispatch_reference' => $dispatchReference,
            'protocol' => $dto->protocol,
            'id' => $requestId,
        ]);
    }

    private function markFailed(int $requestId, string $error): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE partner_dispatch_requests
             SET status = :status,
                 error_message = :error_message,
                 processed_at = NOW()
             WHERE id = :id'
        );
        $stmt->execute([
            'status' => 'failed',
            'error_message' => $error,
            'id' => $requestId,
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $attachments
     */
    private function storeAttachments(int $requestId, array $attachments): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO partner_request_attachments (partner_dispatch_request_id, filename, mime_type, file_size, content, created_at)
             VALUES (:request_id, :filename, :mime_type, :file_size, :content, NOW())'
        );

        foreach ($attachments as $attachment) {
            if (!is_array($attachment) || empty($attachment['filename'])) {
                continue;
            }
            $content = null;
            if (!empty($attachment['content_base64'])) {
                $decoded = base64_decode((string) $attachment['content_base64'], true);
                if ($decoded !== false) {
                    $content = $decoded;
                }
            }

            $fileSize = isset($attachment['file_size']) ? (int) $attachment['file_size'] : null;
            if ($fileSize === null && $content !== null) {
                $fileSize = strlen($content);
            }

            $stmt->execute([
                'request_id' => $requestId,
                'filename' => (string) $attachment['filename'],
                'mime_type' => $attachment['mime_type'] ?? null,
                'file_size' => $fileSize,
                'content' => $content,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAudit(string $event, int $requestId, array $context): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'partner_dispatch_request', (string) $requestId, null, $context));
    }
}
