<?php

namespace App\Services\Estimate;

use App\Database\Connection;
use App\Models\EstimateJob;
use App\Models\EstimatePublicLink;
use App\Models\EstimateSignature;
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

    public function __construct(
        Connection $connection,
        EstimateRepository $estimates,
        EstimateEditorService $editor,
        ?AuditLogger $audit = null
    ) {
        $this->connection = $connection;
        $this->estimates = $estimates;
        $this->editor = $editor;
        $this->audit = $audit;
    }

    public function issueLink(int $estimateId, string $baseUrl, ?string $expiresAt = null, ?int $actorId = null): array
    {
        $token = $this->generateToken();
        $hash = hash('sha256', $token);
        $shortCode = substr($hash, 0, 10);

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_public_links (estimate_id, token_hash, short_code, expires_at, created_at, updated_at)
            VALUES (:estimate_id, :token_hash, :short_code, :expires_at, NOW(), NOW())
        SQL);

        $stmt->execute([
            'estimate_id' => $estimateId,
            'token_hash' => $hash,
            'short_code' => $shortCode,
            'expires_at' => $expiresAt,
        ]);

        $linkId = (int) $this->connection->pdo()->lastInsertId();
        $this->log('estimate.public_link_created', $estimateId, $actorId, [
            'link_id' => $linkId,
            'short_code' => $shortCode,
            'expires_at' => $expiresAt,
        ]);

        return [
            'token' => $token,
            'short_url' => rtrim($baseUrl, '/') . '/e/' . $shortCode,
            'secure_url' => rtrim($baseUrl, '/') . '/public/estimate?token=' . $token,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function fetchView(string $token): array
    {
        $link = $this->resolveLink($token);
        $estimate = $this->estimates->find($link->estimate_id);

        if ($estimate === null) {
            throw new RuntimeException('Estimate not found for public link.');
        }

        $jobs = $this->fetchJobsWithItems($estimate->id);
        $this->touchLastAccessed($link->id);

        return [
            'estimate' => $estimate,
            'jobs' => $jobs,
            'link' => $link,
        ];
    }

    public function approveJob(string $token, int $jobId, ?string $comment = null): bool
    {
        $link = $this->resolveLink($token);
        $estimateId = $link->estimate_id;
        $updated = $this->editor->setJobCustomerStatus($estimateId, $jobId, 'approved');

        if ($updated) {
            $this->persistJobComment($estimateId, $jobId, $comment, 'approved');
            $this->propagateEstimateStatus($estimateId);
            $this->log('estimate.job_public_approved', $estimateId, null, ['job_id' => $jobId]);
        }

        return $updated;
    }

    public function rejectJob(string $token, int $jobId, ?string $comment = null): bool
    {
        $link = $this->resolveLink($token);
        $estimateId = $link->estimate_id;
        $updated = $this->editor->setJobCustomerStatus($estimateId, $jobId, 'rejected');

        if ($updated) {
            $this->persistJobComment($estimateId, $jobId, $comment, 'rejected');
            $this->propagateEstimateStatus($estimateId);
            $this->log('estimate.job_public_rejected', $estimateId, null, ['job_id' => $jobId]);
        }

        return $updated;
    }

    public function captureSignature(string $token, string $name, ?string $email, string $signatureData, ?string $comment = null): EstimateSignature
    {
        $link = $this->resolveLink($token);
        $estimate = $this->estimates->find($link->estimate_id);
        if ($estimate === null) {
            throw new RuntimeException('Estimate not found for signature.');
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_signatures (estimate_id, signer_name, signer_email, signature_data, comment, signed_at, created_at)
            VALUES (:estimate_id, :signer_name, :signer_email, :signature_data, :comment, NOW(), NOW())
        SQL);

        $stmt->execute([
            'estimate_id' => $estimate->id,
            'signer_name' => $name,
            'signer_email' => $email,
            'signature_data' => $signatureData,
            'comment' => $comment,
        ]);

        $signatureId = (int) $this->connection->pdo()->lastInsertId();
        $signature = new EstimateSignature([
            'id' => $signatureId,
            'estimate_id' => $estimate->id,
            'signer_name' => $name,
            'signer_email' => $email,
            'signature_data' => $signatureData,
            'comment' => $comment,
            'signed_at' => date('Y-m-d H:i:s'),
        ]);

        $this->log('estimate.signature_captured', $estimate->id, null, ['signer' => $name]);

        return $signature;
    }

    public function addCustomerComment(string $token, string $comment): bool
    {
        $link = $this->resolveLink($token);
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO estimate_public_comments (estimate_id, comment, created_at)
            VALUES (:estimate_id, :comment, NOW())
        SQL);

        $stmt->execute([
            'estimate_id' => $link->estimate_id,
            'comment' => $comment,
        ]);

        $this->log('estimate.public_comment_added', $link->estimate_id, null, ['comment' => $comment]);

        return true;
    }

    private function propagateEstimateStatus(int $estimateId): void
    {
        $stmt = $this->connection->pdo()->prepare('SELECT customer_status FROM estimate_jobs WHERE estimate_id = :estimate_id');
        $stmt->execute(['estimate_id' => $estimateId]);
        $statuses = array_filter($stmt->fetchAll(PDO::FETCH_COLUMN));

        if (empty($statuses)) {
            return;
        }

        if (count(array_unique($statuses)) === 1 && reset($statuses) === 'approved') {
            $this->estimates->updateStatus($estimateId, 'approved');
            return;
        }

        if (in_array('rejected', $statuses, true)) {
            $this->estimates->updateStatus($estimateId, 'rejected', null, 'Job rejected by customer');
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function fetchJobsWithItems(int $estimateId): array
    {
        $jobsStmt = $this->connection->pdo()->prepare('SELECT * FROM estimate_jobs WHERE estimate_id = :estimate_id ORDER BY position ASC');
        $jobsStmt->execute(['estimate_id' => $estimateId]);

        $itemStmt = $this->connection->pdo()->prepare('SELECT * FROM estimate_items WHERE job_id = :job_id ORDER BY position ASC');

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

    private function touchLastAccessed(int $linkId): void
    {
        $stmt = $this->connection->pdo()->prepare('UPDATE estimate_public_links SET last_accessed_at = NOW() WHERE id = :id');
        $stmt->execute(['id' => $linkId]);
    }

    private function resolveLink(string $token): EstimatePublicLink
    {
        if ($token === '') {
            throw new InvalidArgumentException('Public token is required.');
        }

        $hash = hash('sha256', $token);
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM estimate_public_links WHERE token_hash = :hash LIMIT 1');
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            throw new RuntimeException('Invalid or unknown estimate token.');
        }

        $link = new EstimatePublicLink($row);

        if ($link->expires_at !== null && strtotime($link->expires_at) < time()) {
            throw new RuntimeException('This estimate link has expired.');
        }

        return $link;
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
}
