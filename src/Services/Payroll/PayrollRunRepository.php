<?php

namespace App\Services\Payroll;

use App\Database\Connection;
use App\Models\PayrollExport;
use App\Models\PayrollRun;
use App\Models\PayrollRunEntry;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use PDO;

class PayrollRunRepository
{
    private Connection $connection;
    private ?AuditLogger $auditLogger;

    public function __construct(Connection $connection, ?AuditLogger $auditLogger = null)
    {
        $this->connection = $connection;
        $this->auditLogger = $auditLogger;
    }

    public function createRun(array $data, ?int $actorId = null): PayrollRun
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payroll_runs (run_label, period_start, period_end, status, notes, created_by, created_at, updated_at)
             VALUES (:run_label, :period_start, :period_end, :status, :notes, :created_by, NOW(), NOW())'
        );

        $stmt->execute([
            'run_label' => $this->nullableString($data['run_label'] ?? null),
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'status' => $this->nullableString($data['status'] ?? 'draft') ?? 'draft',
            'notes' => $this->nullableString($data['notes'] ?? null),
            'created_by' => $actorId,
        ]);

        $runId = (int) $this->connection->pdo()->lastInsertId();
        $run = $this->findRun($runId);

        if ($run) {
            $this->logAudit('payroll_run.created', 'payroll_run', $run->id, $actorId, $run->toArray());

            return $run;
        }

        throw new \RuntimeException('Failed to create payroll run.');
    }

    public function findRun(int $runId): ?PayrollRun
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM payroll_runs WHERE id = :id');
        $stmt->execute(['id' => $runId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new PayrollRun($row);
    }

    public function addEntry(int $runId, array $data, ?int $actorId = null): PayrollRunEntry
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payroll_run_entries
                (payroll_run_id, employee_id, user_id, pay_type, gross_pay, currency, calculation_details, source_snapshot, created_by, created_at, updated_at)
             VALUES
                (:payroll_run_id, :employee_id, :user_id, :pay_type, :gross_pay, :currency, :calculation_details, :source_snapshot, :created_by, NOW(), NOW())'
        );

        $stmt->execute([
            'payroll_run_id' => $runId,
            'employee_id' => $data['employee_id'],
            'user_id' => $data['user_id'] ?? null,
            'pay_type' => $data['pay_type'],
            'gross_pay' => $data['gross_pay'],
            'currency' => $this->nullableString($data['currency'] ?? 'USD') ?? 'USD',
            'calculation_details' => $this->encodeJson($data['calculation_details'] ?? null),
            'source_snapshot' => $this->encodeJson($data['source_snapshot'] ?? null),
            'created_by' => $actorId,
        ]);

        $entryId = (int) $this->connection->pdo()->lastInsertId();
        $entry = $this->findEntry($entryId);

        if ($entry) {
            $this->logAudit('payroll_run_entry.created', 'payroll_run_entry', $entry->id, $actorId, $entry->toArray());

            return $entry;
        }

        throw new \RuntimeException('Failed to create payroll run entry.');
    }

    public function findEntry(int $entryId): ?PayrollRunEntry
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM payroll_run_entries WHERE id = :id');
        $stmt->execute(['id' => $entryId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['calculation_details'] = $this->decodeJson($row['calculation_details'] ?? null);
        $row['source_snapshot'] = $this->decodeJson($row['source_snapshot'] ?? null);

        return new PayrollRunEntry($row);
    }

    /**
     * @return array<int, PayrollRunEntry>
     */
    public function findEntriesByRun(int $runId): array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM payroll_run_entries WHERE payroll_run_id = :run_id');
        $stmt->execute(['run_id' => $runId]);

        $entries = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['calculation_details'] = $this->decodeJson($row['calculation_details'] ?? null);
            $row['source_snapshot'] = $this->decodeJson($row['source_snapshot'] ?? null);
            $entries[] = new PayrollRunEntry($row);
        }

        return $entries;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function fetchEntriesForExport(int $runId): array
    {
        $sql = <<<SQL
            SELECT
                pre.id,
                pre.payroll_run_id,
                pre.employee_id,
                pre.user_id,
                pre.pay_type,
                pre.gross_pay,
                pre.currency,
                pre.calculation_details,
                pre.source_snapshot,
                u.name AS employee_name,
                u.email AS employee_email
            FROM payroll_run_entries pre
            LEFT JOIN employees e ON e.id = pre.employee_id
            LEFT JOIN users u ON u.id = COALESCE(pre.user_id, e.user_id)
            WHERE pre.payroll_run_id = :run_id
            ORDER BY u.name ASC, pre.id ASC
        SQL;

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['run_id' => $runId]);

        $rows = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $row['calculation_details'] = $this->decodeJson($row['calculation_details'] ?? null);
            $row['source_snapshot'] = $this->decodeJson($row['source_snapshot'] ?? null);
            $rows[] = $row;
        }

        return $rows;
    }

    public function createExport(int $runId, string $provider, string $format, string $payload, ?int $actorId = null): PayrollExport
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payroll_exports (payroll_run_id, provider, format, status, payload, created_by, created_at, updated_at)
             VALUES (:payroll_run_id, :provider, :format, :status, :payload, :created_by, NOW(), NOW())'
        );

        $stmt->execute([
            'payroll_run_id' => $runId,
            'provider' => $provider,
            'format' => $format,
            'status' => 'generated',
            'payload' => $payload,
            'created_by' => $actorId,
        ]);

        $exportId = (int) $this->connection->pdo()->lastInsertId();
        $export = $this->findExport($exportId);

        if ($export) {
            $this->logAudit('payroll_export.created', 'payroll_export', $export->id, $actorId, [
                'payroll_run_id' => $runId,
                'provider' => $provider,
                'format' => $format,
            ]);

            return $export;
        }

        throw new \RuntimeException('Failed to create payroll export record.');
    }

    public function findExport(int $exportId): ?PayrollExport
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM payroll_exports WHERE id = :id');
        $stmt->execute(['id' => $exportId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return new PayrollExport($row);
    }

    private function nullableString($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param array<string, mixed>|null $payload
     */
    private function encodeJson(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodeJson(?string $payload): ?array
    {
        if ($payload === null || $payload === '') {
            return null;
        }

        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAudit(string $event, string $entityType, int $entityId, ?int $actorId, array $context): void
    {
        if (!$this->auditLogger) {
            return;
        }

        $this->auditLogger->log(new AuditEntry($event, $entityType, $entityId, $actorId, $context));
    }
}
