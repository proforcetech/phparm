<?php

namespace App\Services\Estimate;

use App\Database\Connection;
use App\Models\ApprovalAuditLog;
use App\Models\EstimateJob;
use App\Models\EstimatePublicLink;
use App\Models\EstimateSignature;
use App\Services\Approval\ApprovalAuditService;
use App\Services\Messaging\MessagingNotificationService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use RuntimeException;

class EstimatePublicLinkService
{
    private Connection $connection;
    private EstimateRepository $estimates;
    private EstimateEditorService $editor;
    private ?AuditLogger $audit;
    private ?ApprovalAuditService $approvalAudit;
    private ?MessagingNotificationService $messagingNotifications;

    public function __construct(
        Connection $connection,
        EstimateRepository $estimates,
        EstimateEditorService $editor,
        ?AuditLogger $audit = null,
        ?ApprovalAuditService $approvalAudit = null,
        ?MessagingNotificationService $messagingNotifications = null
    ) {
        $this->connection = $connection;
        $this->estimates = $estimates;
        $this->editor = $editor;
        $this->audit = $audit;
        $this->approvalAudit = $approvalAudit;
        $this->messagingNotifications = $messagingNotifications;
    }

    public function issueLink(
        int $estimateId,
        string $baseUrl,
        ?string $expiresAt = null,
        ?int $actorId = null,
        ?string $signerEmail = null,
        ?int $signerInvitationId = null
    ): array {
        $token = $this->generateToken();
        $hash = hash('sha256', $token);
        $shortCode = substr($hash, 0, 10);
        $normalizedEmail = self::normalizeEmail($signerEmail);

        // R-04 — snapshot the document hash + JSON now. Without an
        // estimate row at issue we can't verify drift later; if the
        // estimate is missing here we fail loudly rather than issuing
        // a link that would be unusable on capture.
        $estimate = $this->estimates->find($estimateId);
        if ($estimate === null) {
            throw new RuntimeException('Estimate not found; cannot issue link.');
        }
        $snapshotJson = self::canonicalizeEstimateSnapshot($estimate->toArray());
        $documentHashAtIssue = hash('sha256', $snapshotJson);

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_public_links (
                estimate_id, token_hash, short_code, expires_at,
                signer_email, signer_invitation_id,
                document_hash_at_issue, document_snapshot_json,
                created_at, updated_at
            ) VALUES (
                :estimate_id, :token_hash, :short_code, :expires_at,
                :signer_email, :signer_invitation_id,
                :document_hash_at_issue, :document_snapshot_json,
                NOW(), NOW()
            )
        SQL);

        $stmt->execute([
            'estimate_id' => $estimateId,
            'token_hash' => $hash,
            'short_code' => $shortCode,
            'expires_at' => $expiresAt,
            'signer_email' => $normalizedEmail,
            'signer_invitation_id' => $signerInvitationId,
            'document_hash_at_issue' => $documentHashAtIssue,
            'document_snapshot_json' => $snapshotJson,
        ]);

        $linkId = (int) $this->connection->pdo()->lastInsertId();
        $this->log('estimate.public_link_created', $estimateId, $actorId, [
            'link_id' => $linkId,
            'short_code' => $shortCode,
            'expires_at' => $expiresAt,
            'signer_email' => $normalizedEmail,
            'signer_invitation_id' => $signerInvitationId,
            'document_hash_at_issue' => $documentHashAtIssue,
        ]);

        return [
            'token' => $token,
            'short_url' => rtrim($baseUrl, '/') . '/e/' . $shortCode,
            'secure_url' => rtrim($baseUrl, '/') . '/estimate/view?token=' . $token,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchView(
        ?string $token,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $shortCode = null
    ): array {
        $link = $this->resolveLink($token, $shortCode);
        $estimate = $this->estimates->find($link->estimate_id);

        if ($estimate === null) {
            throw new RuntimeException('Estimate not found for public link.');
        }

        $jobs = $this->fetchJobsWithItems($estimate->id);
        $this->touchLastAccessed($link->id);

        // Log the view in the approval audit trail
        if ($this->approvalAudit !== null && $ipAddress !== null) {
            $this->approvalAudit->logView(
                ApprovalAuditLog::ENTITY_ESTIMATE,
                $estimate->id,
                $ipAddress,
                $userAgent
            );
        }

        return [
            'estimate' => $estimate,
            'jobs' => $jobs,
            'link' => $link,
        ];
    }

    public function approveJob(
        ?string $token,
        int $jobId,
        ?string $comment = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $signerName = null,
        ?string $signerEmail = null,
        ?string $shortCode = null
    ): bool {
        $link = $this->resolveLink($token, $shortCode);
        $estimateId = $link->estimate_id;
        $this->assertSignatureRequirementMet($estimateId);
        $updated = $this->editor->setJobCustomerStatus($estimateId, $jobId, 'approved');

        if ($updated) {
            $this->persistJobComment($estimateId, $jobId, $comment, 'approved');
            $this->propagateEstimateStatus($estimateId);
            $this->log('estimate.job_public_approved', $estimateId, null, ['job_id' => $jobId]);
            $this->messagingNotifications?->dispatch('estimate.job_public_approved', [
                'estimate_id' => $estimateId,
                'job_id' => $jobId,
                'message' => trim((string) ($comment ?? '')),
            ]);

            // Log in approval audit trail
            if ($this->approvalAudit !== null && $ipAddress !== null) {
                $this->approvalAudit->logJobApproval(
                    ApprovalAuditLog::ENTITY_ESTIMATE,
                    $estimateId,
                    $jobId,
                    $ipAddress,
                    $signerName,
                    $signerEmail,
                    $userAgent,
                    $comment
                );
            }
        }

        return $updated;
    }

    public function rejectJob(
        ?string $token,
        int $jobId,
        ?string $comment = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $signerName = null,
        ?string $signerEmail = null,
        ?string $rejectionReason = null,
        ?string $shortCode = null
    ): bool {
        $link = $this->resolveLink($token, $shortCode);
        $estimateId = $link->estimate_id;
        $this->assertSignatureRequirementMet($estimateId);
        $updated = $this->editor->setJobCustomerStatus($estimateId, $jobId, 'rejected');

        if ($updated) {
            $this->persistJobComment($estimateId, $jobId, $comment, 'rejected');
            $this->persistJobRejection($estimateId, $jobId, $rejectionReason, $comment, $signerName, $signerEmail, $ipAddress);
            $this->propagateEstimateStatus($estimateId);
            $this->log('estimate.job_public_rejected', $estimateId, null, ['job_id' => $jobId]);
            $this->messagingNotifications?->dispatch('estimate.job_public_rejected', [
                'estimate_id' => $estimateId,
                'job_id' => $jobId,
                'message' => trim((string) ($comment ?? $rejectionReason ?? '')),
            ]);

            // Log in approval audit trail
            if ($this->approvalAudit !== null && $ipAddress !== null) {
                $this->approvalAudit->logJobRejection(
                    ApprovalAuditLog::ENTITY_ESTIMATE,
                    $estimateId,
                    $jobId,
                    $ipAddress,
                    $signerName,
                    $signerEmail,
                    $userAgent,
                    $comment ?? $rejectionReason
                );
            }
        }

        return $updated;
    }

    /**
     * @param array<string, mixed> $forensics Optional browser-supplied forensic data:
     *   geo_lat, geo_lng, geo_accuracy_m, geo_captured_at,
     *   browser_name, browser_version, os_name, os_version
     */
    public function captureSignature(
        ?string $token,
        string $name,
        ?string $email,
        string $signatureData,
        ?string $comment = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?string $deviceFingerprint = null,
        bool $legalConsent = false,
        ?string $consentText = null,
        ?string $shortCode = null,
        array $forensics = [],
        bool $acceptDocumentChanges = false
    ): EstimateSignature {
        $link = $this->resolveLink($token, $shortCode);
        $estimate = $this->estimates->find($link->estimate_id);
        if ($estimate === null) {
            throw new RuntimeException('Estimate not found for signature.');
        }

        // AUD-064 — single-use enforcement. See ContractSigningService for
        // the full rationale; mirrored here for the estimate accept flow.
        if ($link->consumed_at !== null) {
            throw new RuntimeException('This estimate link has already been used to capture a signature.');
        }
        if (!$this->claimLink($link->id)) {
            throw new RuntimeException('This estimate link has already been used to capture a signature.');
        }

        // R-02b — signer email binding. When the link was issued with a
        // bound signer_email the capture must present that same address
        // (case-insensitive, trimmed). NULL preserves the legacy "open"
        // link behaviour for backwards compatibility.
        if ($link->signer_email !== null) {
            $bound = self::normalizeEmail($link->signer_email);
            $declared = self::normalizeEmail($email);
            if ($declared === null) {
                if ($this->audit !== null) {
                    $this->audit->log(new AuditEntry(
                        'estimate.signer_mismatch',
                        'estimate',
                        (string) $estimate->id,
                        null,
                        [
                            'link_id' => $link->id,
                            'reason' => 'missing_signer_email',
                            'expected_signer_email' => $bound,
                            'ip_address' => $ipAddress,
                        ]
                    ));
                }
                throw new InvalidArgumentException('signer_email is required for this signing link.');
            }
            if ($declared !== $bound) {
                if ($this->audit !== null) {
                    $this->audit->log(new AuditEntry(
                        'estimate.signer_mismatch',
                        'estimate',
                        (string) $estimate->id,
                        null,
                        [
                            'link_id' => $link->id,
                            'reason' => 'email_mismatch',
                            'expected_signer_email' => $bound,
                            'attempted_signer_email' => $declared,
                            'ip_address' => $ipAddress,
                        ]
                    ));
                }
                throw new RuntimeException('This signing link is bound to a different signer.');
            }
        }

        $signedAt = date('Y-m-d H:i:s');

        // Generate document hash for integrity verification
        $documentHash = $this->approvalAudit !== null
            ? $this->approvalAudit->generateDocumentHash($estimate->toArray())
            : null;

        // R-04 — verify the issue-time snapshot against the current
        // estimate. Mismatch defaults to refusal so a mid-flight edit
        // can't silently change what the signer "agreed to". The
        // override path requires explicit opt-in and audits the diff.
        $issueDocumentHash = $link->document_hash_at_issue;
        $documentChangedAccepted = false;
        if ($issueDocumentHash !== null
            && $documentHash !== null
            && !hash_equals($issueDocumentHash, $documentHash)
        ) {
            if ($this->audit !== null) {
                $this->audit->log(new AuditEntry(
                    $acceptDocumentChanges
                        ? 'estimate.document_changed_accepted'
                        : 'estimate.document_changed_refused',
                    'estimate',
                    (string) $estimate->id,
                    null,
                    [
                        'link_id' => $link->id,
                        'document_hash_at_issue' => $issueDocumentHash,
                        'document_hash_at_capture' => $documentHash,
                        'ip_address' => $ipAddress,
                    ]
                ));
            }
            if (!$acceptDocumentChanges) {
                throw new RuntimeException(
                    'This estimate was modified after the signing link was sent. '
                    . 'Request a fresh link from the issuer, or re-submit with '
                    . 'accept_document_changes=true to acknowledge the changes.'
                );
            }
            $documentChangedAccepted = true;
        }

        // Generate signature hash
        $signatureHash = $this->approvalAudit !== null
            ? $this->approvalAudit->generateSignatureHash($signatureData, $name, $signedAt)
            : null;

        $locationLat = $this->normalizeFloat($forensics['geo_lat'] ?? null);
        $locationLng = $this->normalizeFloat($forensics['geo_lng'] ?? null);
        $locationAccuracy = $this->normalizeFloat($forensics['geo_accuracy_m'] ?? null);
        $locationCapturedAt = $this->normalizeDateTime($forensics['geo_captured_at'] ?? null);
        $browserName = $this->normalizeString($forensics['browser_name'] ?? null, 80);
        $browserVersion = $this->normalizeString($forensics['browser_version'] ?? null, 40);
        $osName = $this->normalizeString($forensics['os_name'] ?? null, 80);
        $osVersion = $this->normalizeString($forensics['os_version'] ?? null, 40);

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_signatures (
                estimate_id, signer_name, signer_email, signature_data,
                ip_address, user_agent,
                location_lat, location_lng, location_accuracy_m, location_captured_at,
                browser_name, browser_version, os_name, os_version,
                device_fingerprint, document_hash,
                document_hash_at_issue, document_changed_accepted,
                legal_consent, consent_text, comment, signed_at, created_at
            ) VALUES (
                :estimate_id, :signer_name, :signer_email, :signature_data,
                :ip_address, :user_agent,
                :location_lat, :location_lng, :location_accuracy_m, :location_captured_at,
                :browser_name, :browser_version, :os_name, :os_version,
                :device_fingerprint, :document_hash,
                :document_hash_at_issue, :document_changed_accepted,
                :legal_consent, :consent_text, :comment, :signed_at, NOW()
            )
        SQL);

        $stmt->execute([
            'estimate_id' => $estimate->id,
            'signer_name' => $name,
            'signer_email' => $email,
            'signature_data' => $signatureData,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'location_lat' => $locationLat,
            'location_lng' => $locationLng,
            'location_accuracy_m' => $locationAccuracy,
            'location_captured_at' => $locationCapturedAt,
            'browser_name' => $browserName,
            'browser_version' => $browserVersion,
            'os_name' => $osName,
            'os_version' => $osVersion,
            'device_fingerprint' => $deviceFingerprint,
            'document_hash' => $documentHash,
            'document_hash_at_issue' => $issueDocumentHash,
            'document_changed_accepted' => $documentChangedAccepted ? 1 : 0,
            'legal_consent' => $legalConsent ? 1 : 0,
            'consent_text' => $consentText,
            'comment' => $comment,
            'signed_at' => $signedAt,
        ]);

        $signatureId = (int) $this->connection->pdo()->lastInsertId();
        $signature = new EstimateSignature([
            'id' => $signatureId,
            'estimate_id' => $estimate->id,
            'signer_name' => $name,
            'signer_email' => $email,
            'signature_data' => $signatureData,
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
            'location_lat' => $locationLat,
            'location_lng' => $locationLng,
            'location_accuracy_m' => $locationAccuracy,
            'location_captured_at' => $locationCapturedAt,
            'browser_name' => $browserName,
            'browser_version' => $browserVersion,
            'os_name' => $osName,
            'os_version' => $osVersion,
            'device_fingerprint' => $deviceFingerprint,
            'document_hash' => $documentHash,
            'document_hash_at_issue' => $issueDocumentHash,
            'document_changed_accepted' => $documentChangedAccepted,
            'legal_consent' => $legalConsent,
            'consent_text' => $consentText,
            'comment' => $comment,
            'signed_at' => $signedAt,
        ]);

        // AUD-064 — backfill the link → signature pointer for forensics.
        $this->attachSignatureToLink($link->id, $signatureId);

        $this->log('estimate.signature_captured', $estimate->id, null, ['signer' => $name]);
        $this->messagingNotifications?->dispatch('estimate.signature_captured', [
            'estimate_id' => $estimate->id,
            'signer' => $name,
        ]);

        // Log in approval audit trail
        if ($this->approvalAudit !== null && $ipAddress !== null) {
            $this->approvalAudit->logSignatureCapture(
                ApprovalAuditLog::ENTITY_ESTIMATE,
                $estimate->id,
                $name,
                $ipAddress,
                $signatureHash ?? '',
                $documentHash ?? '',
                $email,
                $userAgent,
                $deviceFingerprint
            );
        }

        return $signature;
    }

    public function addCustomerComment(?string $token, string $comment, ?string $shortCode = null): bool
    {
        $link = $this->resolveLink($token, $shortCode);
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_public_comments (estimate_id, comment, created_at)
            VALUES (:estimate_id, :comment, NOW())
        SQL);

        $stmt->execute([
            'estimate_id' => $link->estimate_id,
            'comment' => $comment,
        ]);

        $this->log('estimate.public_comment_added', $link->estimate_id, null, ['comment' => $comment]);
        $this->messagingNotifications?->dispatch('estimate.public_comment_added', [
            'estimate_id' => $link->estimate_id,
            'message' => $comment,
        ]);

        return true;
    }

    private function propagateEstimateStatus(int $estimateId): void
    {
        $stmt = $this->connection->pdo()->prepare('SELECT customer_status FROM estimate_jobs WHERE estimate_id = :estimate_id');
        $stmt->execute(['estimate_id' => $estimateId]);
        $allStatuses = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (empty($allStatuses)) {
            return;
        }

        // Count job responses
        $totalJobs = count($allStatuses);
        $approvedCount = 0;
        $rejectedCount = 0;
        $pendingCount = 0;

        foreach ($allStatuses as $status) {
            if ($status === 'approved') {
                $approvedCount++;
            } elseif ($status === 'rejected') {
                $rejectedCount++;
            } else {
                $pendingCount++;
            }
        }

        // Determine estimate status based on job responses
        if ($pendingCount > 0) {
            // Still have pending jobs - keep estimate in current state (pending/sent)
            // Don't change status until all jobs have been responded to
            return;
        }

        // All jobs have been responded to
        if ($approvedCount === $totalJobs) {
            // All approved
            $this->estimates->updateStatus($estimateId, 'approved');
        } elseif ($rejectedCount === $totalJobs) {
            // All rejected
            $this->estimates->updateStatus($estimateId, 'declined', null, 'All jobs rejected by customer');
        } else {
            // Mixed: some approved, some rejected - use "partial" status
            $this->estimates->updateStatus($estimateId, 'partial', null, "{$approvedCount}/{$totalJobs} jobs approved");
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchJobsWithItems(int $estimateId): array
    {
        $jobsStmt = $this->connection->pdo()->prepare('SELECT * FROM estimate_jobs WHERE estimate_id = :estimate_id ORDER BY display_order ASC');
        $jobsStmt->execute(['estimate_id' => $estimateId]);

        $itemStmt = $this->connection->pdo()->prepare('SELECT * FROM estimate_items WHERE estimate_job_id = :job_id ORDER BY id ASC');

        $results = [];
        foreach ($jobsStmt->fetchAll(PDO::FETCH_ASSOC) as $jobRow) {
            $itemStmt->execute(['job_id' => $jobRow['id']]);
            $items = $itemStmt->fetchAll(PDO::FETCH_ASSOC);
            $results[] = [
                'job' => new EstimateJob($jobRow),
                'items' => $items,
            ];
        }

        return $results;
    }

    private function persistJobComment(int $estimateId, int $jobId, ?string $comment, string $action): void
    {
        if ($comment === null || trim($comment) === '') {
            return;
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_job_feedback (estimate_id, job_id, action, comment, created_at)
            VALUES (:estimate_id, :job_id, :action, :comment, NOW())
        SQL);

        $stmt->execute([
            'estimate_id' => $estimateId,
            'job_id' => $jobId,
            'action' => $action,
            'comment' => $comment,
        ]);
    }

    private function persistJobRejection(
        int $estimateId,
        int $jobId,
        ?string $rejectionReason,
        ?string $rejectionDetails,
        ?string $rejectedByName,
        ?string $rejectedByEmail,
        ?string $ipAddress
    ): void {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_job_rejections (
                estimate_id, estimate_job_id, rejection_reason, rejection_details,
                rejected_by_name, rejected_by_email, ip_address, rejected_at, created_at
            ) VALUES (
                :estimate_id, :job_id, :rejection_reason, :rejection_details,
                :rejected_by_name, :rejected_by_email, :ip_address, NOW(), NOW()
            )
        SQL);

        $stmt->execute([
            'estimate_id' => $estimateId,
            'job_id' => $jobId,
            'rejection_reason' => $rejectionReason,
            'rejection_details' => $rejectionDetails,
            'rejected_by_name' => $rejectedByName,
            'rejected_by_email' => $rejectedByEmail,
            'ip_address' => $ipAddress,
        ]);
    }

    private function touchLastAccessed(int $linkId): void
    {
        $stmt = $this->connection->pdo()->prepare('UPDATE estimate_public_links SET last_accessed_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $linkId]);
    }

    /**
     * AUD-064 — atomic single-use claim. See ContractPublicLinkRepository::claim().
     */
    private function claimLink(int $linkId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE estimate_public_links
                SET consumed_at = NOW()
              WHERE id = :id AND consumed_at IS NULL'
        );
        $stmt->execute(['id' => $linkId]);
        return $stmt->rowCount() === 1;
    }

    private function attachSignatureToLink(int $linkId, int $signatureId): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE estimate_public_links
                SET consumed_by_signature_id = :sid
              WHERE id = :id'
        );
        $stmt->execute(['id' => $linkId, 'sid' => $signatureId]);
    }

    private function resolveLink(?string $token, ?string $shortCode = null): EstimatePublicLink
    {
        if ($token !== null && $token !== '') {
            $hash = hash('sha256', $token);
            $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimate_public_links WHERE token_hash = :hash LIMIT 1');
            $stmt->execute(['hash' => $hash]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                throw new RuntimeException('Invalid or unknown estimate token.');
            }

            $link = new EstimatePublicLink($row);
            $this->assertNotExpired($link);
            return $link;
        }

        if ($shortCode === null || $shortCode === '') {
            throw new InvalidArgumentException('Public token or short code is required.');
        }

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimate_public_links WHERE short_code = :short_code LIMIT 1');
        $stmt->execute(['short_code' => $shortCode]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Invalid or unknown estimate link.');
        }

        $link = new EstimatePublicLink($row);
        $this->assertNotExpired($link);

        return $link;
    }

    private function assertNotExpired(EstimatePublicLink $link): void
    {
        if ($link->expires_at !== null && strtotime($link->expires_at) < time()) {
            throw new RuntimeException('This estimate link has expired.');
        }
    }

    private function generateToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function log(string $event, int $estimateId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'estimate', (string) $estimateId, $actorId, $context));
    }

    /**
     * When require_signature is on, every per-job approve/reject must be backed
     * by a captured signature. The signature is the legal proof of acceptance;
     * approving a single job without one would leave us holding a customer
     * "yes" we cannot evidence. The accept page enforces this in the UI, but
     * the API also enforces it so an attacker can't bypass by hitting the
     * endpoint directly.
     */
    private function assertSignatureRequirementMet(int $estimateId): void
    {
        $estimate = $this->estimates->find($estimateId);
        if ($estimate === null || !$estimate->require_signature) {
            return;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT 1 FROM estimate_signatures WHERE estimate_id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $estimateId]);
        if ($stmt->fetchColumn() === false) {
            throw new RuntimeException('A signature is required before approving or rejecting jobs on this estimate.');
        }
    }

    private function normalizeFloat(mixed $value): ?float
    {
        if ($value === null || $value === '' || !is_numeric($value)) {
            return null;
        }
        return (float) $value;
    }

    private function normalizeString(mixed $value, int $maxLength): ?string
    {
        if ($value === null) {
            return null;
        }
        $string = trim((string) $value);
        if ($string === '') {
            return null;
        }
        return mb_substr($string, 0, $maxLength);
    }

    private function normalizeDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        $timestamp = is_numeric($value) ? (int) $value : strtotime((string) $value);
        if ($timestamp === false || $timestamp <= 0) {
            return null;
        }
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * R-04 — produce a stable JSON representation of the estimate row used
     * for hashing at link-issue time. Volatile fields (updated_at) would
     * otherwise cause spurious mismatches even when the customer-visible
     * content has not changed.
     *
     * @param array<string, mixed> $data
     */
    private static function canonicalizeEstimateSnapshot(array $data): string
    {
        unset($data['updated_at'], $data['created_at']);
        ksort($data);
        return json_encode($data, JSON_UNESCAPED_SLASHES) ?: '';
    }

    /**
     * R-02b — canonicalize an email for binding comparison. Trim + lowercase
     * via mb_strtolower so the same address typed in different casings on
     * the invite vs. capture sides still matches. Returns null for empty
     * or non-string input so callers can treat "no email" uniformly.
     */
    private static function normalizeEmail(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }
        return mb_strtolower($trimmed);
    }
}
