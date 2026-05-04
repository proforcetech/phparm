<?php

namespace App\Services\Subcontractor;

use App\Database\Connection;
use App\Models\SubcontractorAssignmentPod;
use PDO;
use RuntimeException;

/**
 * Phase 18 / C2 — POD / photo / signature attachments uploaded by a sub
 * through the self-service portal. Soft-deletable so the closeout audit
 * trail keeps its references even after files are pruned from disk by a
 * separate cleanup job.
 */
class SubcontractorAssignmentPodRepository
{
    private const COLUMNS = 'id, assignment_id, subcontractor_id, kind,
        original_name, stored_path, mime_type, size_bytes, sha256, notes,
        uploaded_via_token_id, uploaded_at, deleted_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): SubcontractorAssignmentPod
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO subcontractor_assignment_pods
             (assignment_id, subcontractor_id, kind, original_name, stored_path,
              mime_type, size_bytes, sha256, notes, uploaded_via_token_id)
             VALUES
             (:assignment_id, :subcontractor_id, :kind, :original_name, :stored_path,
              :mime_type, :size_bytes, :sha256, :notes, :uploaded_via_token_id)'
        );
        $stmt->execute([
            'assignment_id' => (int) ($data['assignment_id'] ?? 0),
            'subcontractor_id' => (int) ($data['subcontractor_id'] ?? 0),
            'kind' => (string) ($data['kind'] ?? SubcontractorAssignmentPod::KIND_POD),
            'original_name' => $data['original_name'] ?? null,
            'stored_path' => $data['stored_path'] ?? null,
            'mime_type' => $data['mime_type'] ?? null,
            'size_bytes' => isset($data['size_bytes']) ? (int) $data['size_bytes'] : null,
            'sha256' => $data['sha256'] ?? null,
            'notes' => $data['notes'] ?? null,
            'uploaded_via_token_id' => isset($data['uploaded_via_token_id'])
                ? (int) $data['uploaded_via_token_id']
                : null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->find($id);
        if ($found === null) {
            throw new RuntimeException('subcontractor_assignment_pods insert did not return a row');
        }
        return $found;
    }

    public function find(int $id): ?SubcontractorAssignmentPod
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM subcontractor_assignment_pods
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new SubcontractorAssignmentPod($row) : null;
    }

    /**
     * @return array<int, SubcontractorAssignmentPod>
     */
    public function listForAssignment(int $assignmentId, bool $includeDeleted = false): array
    {
        $where = ['assignment_id = :a'];
        if (!$includeDeleted) {
            $where[] = 'deleted_at IS NULL';
        }
        $sql = 'SELECT ' . self::COLUMNS . ' FROM subcontractor_assignment_pods
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY uploaded_at DESC, id DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['a' => $assignmentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => new SubcontractorAssignmentPod($r), $rows);
    }

    public function softDelete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE subcontractor_assignment_pods
             SET deleted_at = NOW()
             WHERE id = :id AND deleted_at IS NULL'
        );
        $stmt->execute(['id' => $id]);
    }
}
