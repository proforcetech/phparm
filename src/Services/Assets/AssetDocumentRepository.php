<?php

namespace App\Services\Assets;

use App\Database\Connection;
use App\Models\AssetDocument;
use PDO;
use RuntimeException;

/**
 * Documents attached to an installed asset (Phase 2.2 of
 * docs/expansion-plan.md). Stores metadata; the actual bytes live on disk
 * under `storage/private/asset-documents/{asset_id}/…`.
 */
class AssetDocumentRepository
{
    private const COLUMNS = 'id, asset_id, doc_type, filename, original_filename, mime_type,
        size_bytes, storage_path, storage_disk, notes, uploaded_by, uploaded_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, AssetDocument>
     */
    public function listForAsset(int $assetId, ?string $docType = null): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM asset_documents WHERE asset_id = :aid';
        $params = ['aid' => $assetId];
        if ($docType !== null) {
            $sql .= ' AND doc_type = :dt';
            $params['dt'] = $docType;
        }
        $sql .= ' ORDER BY uploaded_at DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map(
            static fn(array $r) => new AssetDocument($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?AssetDocument
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM asset_documents WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new AssetDocument($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): AssetDocument
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO asset_documents (asset_id, doc_type, filename, original_filename,
                mime_type, size_bytes, storage_path, storage_disk, notes, uploaded_by)
             VALUES (:asset_id, :doc_type, :filename, :original_filename,
                :mime_type, :size_bytes, :storage_path, :storage_disk, :notes, :uploaded_by)'
        );
        $stmt->execute([
            'asset_id' => (int) $data['asset_id'],
            'doc_type' => $data['doc_type'] ?? 'other',
            'filename' => $data['filename'],
            'original_filename' => $data['original_filename'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'size_bytes' => isset($data['size_bytes']) ? (int) $data['size_bytes'] : null,
            'storage_path' => $data['storage_path'],
            'storage_disk' => $data['storage_disk'] ?? null,
            'notes' => $data['notes'] ?? null,
            'uploaded_by' => $data['uploaded_by'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('asset_documents insert did not return a row');
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM asset_documents WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
