<?php

namespace App\Services\ImportExport;

use App\Database\Connection;
use PDO;

class AuditExportService
{
    private Connection $connection;

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function toCsv(array $filters = []): string
    {
        $sql = 'SELECT event, entity_type, entity_id, actor_id, context, created_at FROM audit_logs WHERE 1=1';
        $params = [];

        if (!empty($filters['entity_type'])) {
            $sql .= ' AND entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }

        if (!empty($filters['actor_id'])) {
            $sql .= ' AND actor_id = :actor_id';
            $params['actor_id'] = (int) $filters['actor_id'];
        }

        $sql .= ' ORDER BY created_at DESC LIMIT 1000';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $buffer = fopen('php://temp', 'rb+');
        fputcsv($buffer, ['Event', 'Entity Type', 'Entity ID', 'Actor ID', 'Context', 'Created At']);
        foreach ($rows as $row) {
            fputcsv($buffer, [
                $row['event'],
                $row['entity_type'],
                $row['entity_id'],
                $row['actor_id'],
                $row['context'],
                $row['created_at'],
            ]);
        }

        rewind($buffer);
        $csv = stream_get_contents($buffer) ?: '';
        fclose($buffer);

        return $csv;
    }
}
