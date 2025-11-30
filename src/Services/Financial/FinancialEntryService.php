<?php

namespace App\Services\Financial;

use App\Database\Connection;
use App\Models\FinancialEntry;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;

class FinancialEntryService
{
    private Connection $connection;
    private ?AuditLogger $audit;

    public function __construct(Connection $connection, ?AuditLogger $audit = null)
    {
        $this->connection = $connection;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function record(string $type, array $payload, int $actorId): FinancialEntry
    {
        if (!in_array($type, ['income', 'expense', 'purchase'], true)) {
            throw new InvalidArgumentException('Invalid entry type');
        }

        $required = ['amount', 'date'];
        foreach ($required as $field) {
            if (!isset($payload[$field])) {
                throw new InvalidArgumentException("Missing {$field}");
            }
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO financial_entries (type, description, amount, occurred_on, attachment_path, metadata) ' .
            'VALUES (:type, :description, :amount, :occurred_on, :attachment_path, :metadata)'
        );
        $stmt->execute([
            'type' => $type,
            'description' => $payload['description'] ?? null,
            'amount' => $payload['amount'],
            'occurred_on' => $payload['date'],
            'attachment_path' => $payload['attachment_path'] ?? null,
            'metadata' => json_encode($payload['metadata'] ?? []),
        ]);

        $entryId = (int) $this->connection->pdo()->lastInsertId();
        $entry = $this->fetch($entryId);
        $this->log('financial.entry_recorded', $entryId, $actorId, ['type' => $type, 'payload' => $payload]);

        return $entry ?? new FinancialEntry(['id' => $entryId]);
    }

    public function attachReceipt(int $entryId, string $path, int $actorId): bool
    {
        $stmt = $this->connection->pdo()->prepare('UPDATE financial_entries SET attachment_path = :path WHERE id = :id');
        $stmt->execute(['path' => $path, 'id' => $entryId]);
        $updated = $stmt->rowCount() > 0;
        if ($updated) {
            $this->log('financial.attachment_added', $entryId, $actorId, ['path' => $path]);
        }

        return $updated;
    }

    private function fetch(int $entryId): ?FinancialEntry
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM financial_entries WHERE id = :id');
        $stmt->execute(['id' => $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? new FinancialEntry($row) : null;
    }

    private function log(string $action, int $entityId, int $actorId, array $payload = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($action, 'financial', $entityId, $actorId, $payload));
    }
}
