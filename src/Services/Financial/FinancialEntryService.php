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
     * List financial entries with optional filters
     *
     * @param array<string, mixed> $filters
     * @return array<int, FinancialEntry>
     */
    public function list(array $filters = []): array
    {
        $sql = 'SELECT * FROM financial_entries WHERE 1=1';
        $params = [];

        if (!empty($filters['type'])) {
            $sql .= ' AND type = :type';
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['category'])) {
            $sql .= ' AND category = :category';
            $params['category'] = $filters['category'];
        }

        if (!empty($filters['start_date'])) {
            $sql .= ' AND entry_date >= :start_date';
            $params['start_date'] = $filters['start_date'];
        }

        if (!empty($filters['end_date'])) {
            $sql .= ' AND entry_date <= :end_date';
            $params['end_date'] = $filters['end_date'];
        }

        if (!empty($filters['search'])) {
            $sql .= ' AND (vendor LIKE :search OR reference LIKE :search OR purchase_order LIKE :search OR description LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $sql .= ' ORDER BY entry_date DESC, id DESC LIMIT :limit OFFSET :offset';
        $page = max(1, (int) ($filters['page'] ?? 1));
        $perPage = max(1, min(100, (int) ($filters['per_page'] ?? 25)));

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $param = is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR;
            $stmt->bindValue(':' . $key, $value, $param);
        }
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', ($page - 1) * $perPage, PDO::PARAM_INT);
        $stmt->execute();

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $entries[] = new FinancialEntry($row);
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload, int $actorId): FinancialEntry
    {
        $payload = $this->validate($payload);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO financial_entries (type, category, reference, purchase_order, amount, entry_date, vendor, description, attachment_path) ' .
            'VALUES (:type, :category, :reference, :purchase_order, :amount, :entry_date, :vendor, :description, :attachment_path)'
        );
        $stmt->execute([
            'type' => $payload['type'],
            'category' => $payload['category'],
            'reference' => $payload['reference'],
            'purchase_order' => $payload['purchase_order'],
            'amount' => $payload['amount'],
            'entry_date' => $payload['entry_date'],
            'vendor' => $payload['vendor'],
            'description' => $payload['description'] ?? null,
            'attachment_path' => $payload['attachment_path'] ?? null,
        ]);

        $entryId = (int) $this->connection->pdo()->lastInsertId();
        $entry = $this->fetch($entryId);
        $this->log('financial.entry_created', $entryId, $actorId, $payload);

        return $entry ?? new FinancialEntry(['id' => $entryId]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $entryId, array $payload, int $actorId): ?FinancialEntry
    {
        $payload = $this->validate($payload, false);
        $existing = $this->fetch($entryId);
        if ($existing === null) {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE financial_entries SET type = :type, category = :category, reference = :reference, purchase_order = :purchase_order, ' .
            'amount = :amount, entry_date = :entry_date, vendor = :vendor, description = :description, attachment_path = :attachment_path WHERE id = :id'
        );
        $stmt->execute([
            'id' => $entryId,
            'type' => $payload['type'],
            'category' => $payload['category'],
            'reference' => $payload['reference'],
            'purchase_order' => $payload['purchase_order'],
            'amount' => $payload['amount'],
            'entry_date' => $payload['entry_date'],
            'vendor' => $payload['vendor'],
            'description' => $payload['description'] ?? null,
            'attachment_path' => $payload['attachment_path'] ?? null,
        ]);

        $this->log('financial.entry_updated', $entryId, $actorId, $payload);

        return $this->fetch($entryId);
    }

    public function delete(int $entryId, int $actorId): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM financial_entries WHERE id = :id');
        $stmt->execute(['id' => $entryId]);
        $deleted = $stmt->rowCount() > 0;
        if ($deleted) {
            $this->log('financial.entry_deleted', $entryId, $actorId);
        }

        return $deleted;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function export(array $filters = []): array
    {
        $entries = $this->list($filters);

        return array_map(static function (FinancialEntry $entry): array {
            return [
                'Type' => $entry->type,
                'Category' => $entry->category,
                'Reference' => $entry->reference,
                'Purchase Order' => $entry->purchase_order,
                'Vendor' => $entry->vendor,
                'Date' => $entry->entry_date,
                'Amount' => $entry->amount,
                'Description' => $entry->description,
            ];
        }, $entries);
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

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function validate(array $payload, bool $isCreate = true): array
    {
        $required = ['type', 'category', 'reference', 'purchase_order', 'amount', 'entry_date', 'vendor'];

        foreach ($required as $field) {
            if (!isset($payload[$field]) || $payload[$field] === '') {
                throw new InvalidArgumentException("Missing {$field}");
            }
        }

        if (!in_array($payload['type'], ['income', 'expense', 'purchase'], true)) {
            throw new InvalidArgumentException('Invalid entry type');
        }

        if (!is_numeric($payload['amount'])) {
            throw new InvalidArgumentException('Invalid amount');
        }

        $payload['amount'] = (float) $payload['amount'];

        return $payload;
    }

    private function log(string $action, int $entityId, int $actorId, array $payload = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($action, 'financial', $entityId, $actorId, $payload));
    }
}
