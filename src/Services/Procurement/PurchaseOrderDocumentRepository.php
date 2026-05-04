<?php

namespace App\Services\Procurement;

use App\Database\Connection;
use App\Models\PurchaseOrderDocument;
use PDO;
use RuntimeException;

/**
 * Phase 18 / C1 — uploads attached to a purchase order (tracking labels,
 * packing slips, vendor invoices). Soft-deletable so the audit trail keeps
 * the references even after files are pruned from disk by a cleanup job.
 */
class PurchaseOrderDocumentRepository
{
    private const COLUMNS = 'id, purchase_order_id, purchase_order_line_id, kind,
        original_name, stored_path, mime_type, size_bytes, sha256,
        tracking_number, carrier, notes,
        uploaded_via_token_id, uploaded_by_user_id, uploaded_at, deleted_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): PurchaseOrderDocument
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO purchase_order_documents
             (purchase_order_id, purchase_order_line_id, kind, original_name, stored_path,
              mime_type, size_bytes, sha256, tracking_number, carrier, notes,
              uploaded_via_token_id, uploaded_by_user_id)
             VALUES
             (:purchase_order_id, :purchase_order_line_id, :kind, :original_name, :stored_path,
              :mime_type, :size_bytes, :sha256, :tracking_number, :carrier, :notes,
              :uploaded_via_token_id, :uploaded_by_user_id)'
        );
        $stmt->execute([
            'purchase_order_id' => (int) ($data['purchase_order_id'] ?? 0),
            'purchase_order_line_id' => isset($data['purchase_order_line_id'])
                ? (int) $data['purchase_order_line_id']
                : null,
            'kind' => (string) ($data['kind'] ?? PurchaseOrderDocument::KIND_TRACKING),
            'original_name' => $data['original_name'] ?? null,
            'stored_path' => $data['stored_path'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'size_bytes' => isset($data['size_bytes']) ? (int) $data['size_bytes'] : null,
            'sha256' => $data['sha256'] ?? null,
            'tracking_number' => $data['tracking_number'] ?? null,
            'carrier' => $data['carrier'] ?? null,
            'notes' => $data['notes'] ?? null,
            'uploaded_via_token_id' => isset($data['uploaded_via_token_id'])
                ? (int) $data['uploaded_via_token_id']
                : null,
            'uploaded_by_user_id' => isset($data['uploaded_by_user_id'])
                ? (int) $data['uploaded_by_user_id']
                : null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->find($id);
        if ($found === null) {
            throw new RuntimeException('purchase_order_documents insert did not return a row');
        }
        return $found;
    }

    public function find(int $id): ?PurchaseOrderDocument
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM purchase_order_documents
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new PurchaseOrderDocument($row) : null;
    }

    /**
     * @return array<int, PurchaseOrderDocument>
     */
    public function listForPurchaseOrder(int $poId, bool $includeDeleted = false): array
    {
        $where = ['purchase_order_id = :p'];
        if (!$includeDeleted) {
            $where[] = 'deleted_at IS NULL';
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM purchase_order_documents
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY uploaded_at DESC, id DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['p' => $poId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new PurchaseOrderDocument($r), $rows);
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE purchase_order_documents
             SET deleted_at = NOW()
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }
}
