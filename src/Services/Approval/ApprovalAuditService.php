<?php

namespace App\Services\Approval;

use App\Database\Connection;
use App\Models\ApprovalAuditLog;
use PDO;

/**
 * Service for logging all approval-related actions for legal compliance and audit trail.
 * Captures IP addresses, user agents, device fingerprints, and document hashes.
 */
class ApprovalAuditService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * Log a document view event.
     */
    public function logView(
        string $entityType,
        int $entityId,
        string $ipAddress,
        ?string $userAgent = null,
        ?string $deviceFingerprint = null
    ): int {
        return $this->log([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => ApprovalAuditLog::ACTION_VIEWED,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_fingerprint' => $deviceFingerprint,
        ]);
    }

    /**
     * Log a job approval event.
     */
    public function logJobApproval(
        string $entityType,
        int $entityId,
        int $jobId,
        string $ipAddress,
        ?string $signerName = null,
        ?string $signerEmail = null,
        ?string $userAgent = null,
        ?string $comment = null
    ): int {
        return $this->log([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => ApprovalAuditLog::ACTION_JOB_APPROVED,
            'job_id' => $jobId,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'comment' => $comment,
        ]);
    }

    /**
     * Log a job rejection event.
     */
    public function logJobRejection(
        string $entityType,
        int $entityId,
        int $jobId,
        string $ipAddress,
        ?string $signerName = null,
        ?string $signerEmail = null,
        ?string $userAgent = null,
        ?string $comment = null
    ): int {
        return $this->log([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => ApprovalAuditLog::ACTION_JOB_REJECTED,
            'job_id' => $jobId,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'comment' => $comment,
        ]);
    }

    /**
     * Log a full approval event (all jobs approved).
     */
    public function logFullApproval(
        string $entityType,
        int $entityId,
        string $ipAddress,
        ?string $signerName = null,
        ?string $signerEmail = null,
        ?string $userAgent = null,
        ?string $documentHash = null
    ): int {
        return $this->log([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => ApprovalAuditLog::ACTION_FULLY_APPROVED,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'document_hash' => $documentHash,
        ]);
    }

    /**
     * Log a full rejection event (entire estimate rejected).
     */
    public function logFullRejection(
        string $entityType,
        int $entityId,
        string $ipAddress,
        ?string $signerName = null,
        ?string $signerEmail = null,
        ?string $userAgent = null,
        ?string $comment = null
    ): int {
        return $this->log([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => ApprovalAuditLog::ACTION_FULLY_REJECTED,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'comment' => $comment,
        ]);
    }

    /**
     * Log a signature capture event.
     */
    public function logSignatureCapture(
        string $entityType,
        int $entityId,
        string $signerName,
        string $ipAddress,
        string $signatureHash,
        string $documentHash,
        ?string $signerEmail = null,
        ?string $userAgent = null,
        ?string $deviceFingerprint = null,
        ?string $geoLocation = null,
        ?array $metadata = null
    ): int {
        return $this->log([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => ApprovalAuditLog::ACTION_SIGNATURE_CAPTURED,
            'signer_name' => $signerName,
            'signer_email' => $signerEmail,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'device_fingerprint' => $deviceFingerprint,
            'geo_location' => $geoLocation,
            'signature_hash' => $signatureHash,
            'document_hash' => $documentHash,
            'metadata' => $metadata,
        ]);
    }

    /**
     * Log when a public link is generated.
     */
    public function logLinkGenerated(
        string $entityType,
        int $entityId,
        string $ipAddress,
        ?int $actorId = null,
        ?array $metadata = null
    ): int {
        return $this->log([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => ApprovalAuditLog::ACTION_LINK_GENERATED,
            'ip_address' => $ipAddress,
            'metadata' => array_merge($metadata ?? [], ['actor_id' => $actorId]),
        ]);
    }

    /**
     * Log when a document is sent to customer.
     */
    public function logDocumentSent(
        string $entityType,
        int $entityId,
        string $recipientEmail,
        string $channel,
        string $ipAddress,
        ?int $actorId = null
    ): int {
        return $this->log([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => ApprovalAuditLog::ACTION_DOCUMENT_SENT,
            'signer_email' => $recipientEmail,
            'ip_address' => $ipAddress,
            'metadata' => [
                'channel' => $channel,
                'actor_id' => $actorId,
            ],
        ]);
    }

    /**
     * Get the complete audit trail for an entity.
     *
     * @return array<int, ApprovalAuditLog>
     */
    public function getAuditTrail(string $entityType, int $entityId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT * FROM approval_audit_log
            WHERE entity_type = :entity_type AND entity_id = :entity_id
            ORDER BY created_at ASC
        SQL);

        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
        ]);

        return array_map(function ($row) {
            if (isset($row['metadata']) && is_string($row['metadata'])) {
                $row['metadata'] = json_decode($row['metadata'], true);
            }
            return new ApprovalAuditLog($row);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Get audit entries by action type.
     *
     * @return array<int, ApprovalAuditLog>
     */
    public function getByAction(string $entityType, int $entityId, string $action): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT * FROM approval_audit_log
            WHERE entity_type = :entity_type AND entity_id = :entity_id AND action = :action
            ORDER BY created_at ASC
        SQL);

        $stmt->execute([
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'action' => $action,
        ]);

        return array_map(function ($row) {
            if (isset($row['metadata']) && is_string($row['metadata'])) {
                $row['metadata'] = json_decode($row['metadata'], true);
            }
            return new ApprovalAuditLog($row);
        }, $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Generate a document hash for signature verification.
     * This creates a hash of the document content at the time of signing.
     */
    public function generateDocumentHash(array $documentData): string
    {
        // Remove volatile fields that shouldn't affect the hash
        unset($documentData['updated_at'], $documentData['created_at']);

        // Sort keys for consistent hashing
        ksort($documentData);

        return hash('sha256', json_encode($documentData));
    }

    /**
     * Generate a signature hash for verification.
     */
    public function generateSignatureHash(string $signatureData, string $signerName, string $timestamp): string
    {
        return hash('sha256', $signatureData . $signerName . $timestamp);
    }

    /**
     * Verify that a document hasn't been modified since signing.
     */
    public function verifyDocumentIntegrity(array $currentDocumentData, string $originalHash): bool
    {
        $currentHash = $this->generateDocumentHash($currentDocumentData);
        return hash_equals($originalHash, $currentHash);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function log(array $data): int
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO approval_audit_log (
                entity_type, entity_id, action, job_id, signer_name, signer_email,
                ip_address, user_agent, device_fingerprint, geo_location,
                signature_hash, document_hash, comment, metadata, created_at
            ) VALUES (
                :entity_type, :entity_id, :action, :job_id, :signer_name, :signer_email,
                :ip_address, :user_agent, :device_fingerprint, :geo_location,
                :signature_hash, :document_hash, :comment, :metadata, NOW()
            )
        SQL);

        $stmt->execute([
            'entity_type' => $data['entity_type'],
            'entity_id' => $data['entity_id'],
            'action' => $data['action'],
            'job_id' => $data['job_id'] ?? null,
            'signer_name' => $data['signer_name'] ?? null,
            'signer_email' => $data['signer_email'] ?? null,
            'ip_address' => $data['ip_address'],
            'user_agent' => $data['user_agent'] ?? null,
            'device_fingerprint' => $data['device_fingerprint'] ?? null,
            'geo_location' => $data['geo_location'] ?? null,
            'signature_hash' => $data['signature_hash'] ?? null,
            'document_hash' => $data['document_hash'] ?? null,
            'comment' => $data['comment'] ?? null,
            'metadata' => isset($data['metadata']) ? json_encode($data['metadata']) : null,
        ]);

        return (int) $this->connection->pdo()->lastInsertId();
    }
}
