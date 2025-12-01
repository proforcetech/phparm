<?php

namespace App\Services\Warranty;

use App\Database\Connection;
use App\Models\WarrantyClaim;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Notifications\NotificationDispatcher;
use InvalidArgumentException;
use PDO;

class WarrantyManagementService
{
    private Connection $connection;
    private ?AuditLogger $audit;
    private ?NotificationDispatcher $notifications;

    public function __construct(Connection $connection, ?AuditLogger $audit = null, ?NotificationDispatcher $notifications = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
        $this->notifications = $notifications;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, WarrantyClaim>
     */
    public function listClaims(array $filters = []): array
    {
        $sql = 'SELECT * FROM warranty_claims WHERE 1=1';
        $params = [];

        if (!empty($filters['status'])) {
            $sql .= ' AND status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['customer_id'])) {
            $sql .= ' AND customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }

        if (!empty($filters['invoice_id'])) {
            $sql .= ' AND invoice_id = :invoice_id';
            $params['invoice_id'] = (int) $filters['invoice_id'];
        }

        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(static fn (array $row) => new WarrantyClaim($row), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getClaim(int $claimId): ?WarrantyClaim
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM warranty_claims WHERE id = :id');
        $stmt->execute(['id' => $claimId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : new WarrantyClaim($row);
    }

    public function transitionStatus(int $claimId, string $status, int $actorId, ?string $message = null): bool
    {
        $allowed = ['open', 'reviewing', 'awaiting_customer', 'resolved', 'rejected'];
        if (!in_array($status, $allowed, true)) {
            throw new InvalidArgumentException('Invalid warranty claim status.');
        }

        $stmt = $this->connection->pdo()->prepare('UPDATE warranty_claims SET status = :status, updated_at = NOW() WHERE id = :id');
        $stmt->execute(['status' => $status, 'id' => $claimId]);

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $context = ['status' => $status];
        if ($message !== null) {
            $context['message'] = $message;
        }

        $this->log($actorId, 'warranty_claim.status_changed', $claimId, $context);

        return true;
    }

    public function addInternalNote(int $claimId, string $note, int $actorId): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO warranty_claim_notes (claim_id, note, created_by, created_at) VALUES (:claim_id, :note, :actor, NOW())'
        );
        $stmt->execute([
            'claim_id' => $claimId,
            'note' => $note,
            'actor' => $actorId,
        ]);

        $added = $stmt->rowCount() > 0;
        if ($added) {
            $this->log($actorId, 'warranty_claim.note_added', $claimId, ['note' => $note]);
        }

        return $added;
    }

    public function messageCustomer(int $claimId, string $channel, string $templateKey, string $recipient, array $data, int $actorId): void
    {
        if ($this->notifications === null) {
            throw new InvalidArgumentException('Notifications are not configured.');
        }

        match ($channel) {
            'mail' => $this->notifications->sendMail($templateKey, $recipient, $data),
            'sms' => $this->notifications->sendSms($templateKey, $recipient, $data),
            default => throw new InvalidArgumentException('Unsupported message channel.'),
        };

        $this->log($actorId, 'warranty_claim.customer_message', $claimId, [
            'channel' => $channel,
            'template' => $templateKey,
            'recipient' => $recipient,
            'data' => $data,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function timeline(int $claimId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM audit_logs WHERE entity_type = :entity AND entity_id = :id ORDER BY created_at DESC');
        $stmt->execute([
            'entity' => 'warranty_claim',
            'id' => $claimId,
        ]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    private function log(int $actorId, string $event, int $claimId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'warranty_claim', (string) $claimId, $actorId, $context));
    }
}
