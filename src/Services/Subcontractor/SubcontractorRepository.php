<?php

namespace App\Services\Subcontractor;

use App\Database\Connection;
use App\Models\Subcontractor;
use App\Models\SubcontractorAssignment;
use PDO;
use RuntimeException;

/**
 * Phase 10.1 — persistence for subcontractors, the division M2M, and per-WO
 * assignments. Mirrors the COLUMNS-const + private-hydrate pattern used by
 * CapitalPlanRepository (see src/Services/CapitalPlan/CapitalPlanRepository.php).
 *
 * The service layer composes division_ids onto Subcontractor records; the
 * repo only ever reads/writes the four physical columns of subcontractor_divisions.
 */
class SubcontractorRepository
{
    private const SUBCONTRACTOR_COLUMNS = 'id, company_name, contact_name, email, phone,
        address_line1, address_line2, city, state, postal_code, country,
        tax_id, payment_terms, hourly_rate_cents, status, insurance_expires_at,
        notes, portal_login_enabled, portal_password_hash, portal_password_updated_at,
        portal_last_login_at, created_at, updated_at';

    private const ASSIGNMENT_COLUMNS = 'id, subcontractor_id, workorder_id, status,
        description, internal_notes, estimated_cost_cents, actual_cost_cents,
        assigned_at, accepted_at, declined_at, completed_at, cancelled_at,
        assigned_by_user_id, rating, rating_notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    // ──────────────────────────────────────────────────── subcontractors ────

    /**
     * @param array{status?: string, division_id?: int, search?: string, limit?: int, offset?: int} $filters
     * @return array<int, Subcontractor>
     */
    public function listSubcontractors(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 's.status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['search'])) {
            $where[] = '(s.company_name LIKE :q OR s.contact_name LIKE :q OR s.email LIKE :q)';
            $params['q'] = '%' . $filters['search'] . '%';
        }
        $join = '';
        if (!empty($filters['division_id'])) {
            $join = 'INNER JOIN subcontractor_divisions sd ON sd.subcontractor_id = s.id';
            $where[] = 'sd.division_id = :division_id';
            $params['division_id'] = (int) $filters['division_id'];
        }

        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = implode(' AND ', $where);

        $sql = 'SELECT ' . self::aliasColumns(self::SUBCONTRACTOR_COLUMNS, 's')
            . " FROM subcontractors s {$join}
                WHERE {$whereSql}
                GROUP BY s.id
                ORDER BY s.company_name ASC, s.id ASC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => self::hydrateSubcontractor($r), $rows);
    }

    public function findSubcontractor(int $id): ?Subcontractor
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::SUBCONTRACTOR_COLUMNS . ' FROM subcontractors WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateSubcontractor($row) : null;
    }

    /**
     * @return array{subcontractor: Subcontractor, password_hash: string}|null
     */
    public function findPortalLoginByEmail(string $email): ?array
    {
        $clean = trim($email);
        if ($clean === '') {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::SUBCONTRACTOR_COLUMNS . ' FROM subcontractors
             WHERE LOWER(email) = LOWER(:email)
               AND portal_login_enabled = 1
               AND portal_password_hash IS NOT NULL
             LIMIT 2'
        );
        $stmt->execute(['email' => $clean]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) !== 1) {
            return null;
        }

        $hash = (string) ($rows[0]['portal_password_hash'] ?? '');
        if ($hash === '') {
            return null;
        }

        return [
            'subcontractor' => self::hydrateSubcontractor($rows[0]),
            'password_hash' => $hash,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createSubcontractor(array $data): Subcontractor
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO subcontractors
             (company_name, contact_name, email, phone,
              address_line1, address_line2, city, state, postal_code, country,
              tax_id, payment_terms, hourly_rate_cents, status,
              insurance_expires_at, notes, portal_login_enabled, portal_password_hash,
              portal_password_updated_at)
             VALUES
             (:company_name, :contact_name, :email, :phone,
              :address_line1, :address_line2, :city, :state, :postal_code, :country,
              :tax_id, :payment_terms, :hourly_rate_cents, :status,
              :insurance_expires_at, :notes, :portal_login_enabled, :portal_password_hash,
              :portal_password_updated_at)'
        );
        $stmt->execute(self::writableSubParams($data));
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findSubcontractor($id);
        if ($found === null) {
            throw new RuntimeException('subcontractors insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSubcontractor(int $id, array $data): Subcontractor
    {
        $writable = ['company_name', 'contact_name', 'email', 'phone',
            'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
            'tax_id', 'payment_terms', 'hourly_rate_cents', 'status',
            'insurance_expires_at', 'notes', 'portal_login_enabled', 'portal_password_hash',
            'portal_password_updated_at'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = self::castSubColumn($col, $data[$col]);
            }
        }
        if ($fields !== []) {
            $sql = 'UPDATE subcontractors SET ' . implode(', ', $fields) . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findSubcontractor($id);
        if ($found === null) {
            throw new RuntimeException("subcontractors {$id} not found");
        }
        return $found;
    }

    public function deleteSubcontractor(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM subcontractors WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    public function recordPortalLogin(int $subcontractorId, ?string $ip): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE subcontractors
             SET portal_last_login_at = NOW(), portal_last_login_ip = :ip
             WHERE id = :id'
        );
        $stmt->execute([
            'id' => $subcontractorId,
            'ip' => $ip === null || $ip === '' ? null : substr($ip, 0, 45),
        ]);
    }

    // ────────────────────────────────────────────── division approvals (M2M) ─

    /**
     * @return array<int, int>
     */
    public function listDivisionsForSubcontractor(int $subcontractorId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT division_id FROM subcontractor_divisions
             WHERE subcontractor_id = :s ORDER BY division_id ASC'
        );
        $stmt->execute(['s' => $subcontractorId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
        return array_map(static fn($v) => (int) $v, $rows);
    }

    /**
     * Replaces a sub's division approvals atomically (delete-then-insert
     * inside a transaction). Inputs are de-duped before write to keep
     * (subcontractor_id, division_id) UNIQUE clean.
     *
     * @param array<int, int> $divisionIds
     */
    public function setDivisionsForSubcontractor(int $subcontractorId, array $divisionIds): void
    {
        $clean = array_values(array_unique(array_filter(
            array_map(static fn($v) => (int) $v, $divisionIds),
            static fn(int $v) => $v > 0
        )));

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $del = $pdo->prepare('DELETE FROM subcontractor_divisions WHERE subcontractor_id = :s');
            $del->execute(['s' => $subcontractorId]);

            if ($clean !== []) {
                $ins = $pdo->prepare(
                    'INSERT INTO subcontractor_divisions (subcontractor_id, division_id)
                     VALUES (:s, :d)'
                );
                foreach ($clean as $divisionId) {
                    $ins->execute(['s' => $subcontractorId, 'd' => $divisionId]);
                }
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // ──────────────────────────────────────────────────── assignments ──────

    /**
     * @return array<int, SubcontractorAssignment>
     */
    public function listAssignmentsForWorkorder(int $workorderId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ASSIGNMENT_COLUMNS . " FROM subcontractor_assignments
             WHERE workorder_id = :w
             ORDER BY id ASC"
        );
        $stmt->execute(['w' => $workorderId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => self::hydrateAssignment($r), $rows);
    }

    /**
     * @param array{status?: string, subcontractor_id?: int, limit?: int, offset?: int} $filters
     * @return array<int, SubcontractorAssignment>
     */
    public function listAssignments(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['subcontractor_id'])) {
            $where[] = 'subcontractor_id = :sub';
            $params['sub'] = (int) $filters['subcontractor_id'];
        }
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));
        $whereSql = implode(' AND ', $where);

        $sql = 'SELECT ' . self::ASSIGNMENT_COLUMNS . " FROM subcontractor_assignments
                WHERE {$whereSql}
                ORDER BY id DESC
                LIMIT {$limit} OFFSET {$offset}";
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        return array_map(static fn(array $r) => self::hydrateAssignment($r), $rows);
    }

    public function findAssignment(int $id): ?SubcontractorAssignment
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::ASSIGNMENT_COLUMNS . ' FROM subcontractor_assignments
             WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? self::hydrateAssignment($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function createAssignment(array $data): SubcontractorAssignment
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO subcontractor_assignments
             (subcontractor_id, workorder_id, status, description, internal_notes,
              estimated_cost_cents, actual_cost_cents, assigned_by_user_id)
             VALUES
             (:subcontractor_id, :workorder_id, :status, :description, :internal_notes,
              :estimated_cost_cents, :actual_cost_cents, :assigned_by_user_id)'
        );
        $stmt->execute([
            'subcontractor_id' => (int) ($data['subcontractor_id'] ?? 0),
            'workorder_id' => (int) ($data['workorder_id'] ?? 0),
            'status' => (string) ($data['status'] ?? SubcontractorAssignment::STATUS_PENDING),
            'description' => self::nullableString($data['description'] ?? null),
            'internal_notes' => self::nullableString($data['internal_notes'] ?? null),
            'estimated_cost_cents' => self::nullableInt($data['estimated_cost_cents'] ?? null),
            'actual_cost_cents' => self::nullableInt($data['actual_cost_cents'] ?? null),
            'assigned_by_user_id' => self::nullableInt($data['assigned_by_user_id'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findAssignment($id);
        if ($found === null) {
            throw new RuntimeException('subcontractor_assignments insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateAssignment(int $id, array $data): SubcontractorAssignment
    {
        $writable = ['status', 'description', 'internal_notes',
            'estimated_cost_cents', 'actual_cost_cents',
            'accepted_at', 'declined_at', 'completed_at', 'cancelled_at',
            'rating', 'rating_notes'];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (!array_key_exists($col, $data)) {
                continue;
            }
            $fields[] = "{$col} = :{$col}";
            $params[$col] = self::castAssignmentColumn($col, $data[$col]);
        }
        if ($fields !== []) {
            $sql = 'UPDATE subcontractor_assignments SET ' . implode(', ', $fields)
                . ' WHERE id = :id';
            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute($params);
        }
        $found = $this->findAssignment($id);
        if ($found === null) {
            throw new RuntimeException("subcontractor_assignments {$id} not found");
        }
        return $found;
    }

    public function deleteAssignment(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM subcontractor_assignments WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    // ─────────────────────────────────────────────────────── helpers ───────

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateSubcontractor(array $row): Subcontractor
    {
        $row['portal_password_set'] = isset($row['portal_password_hash'])
            && (string) $row['portal_password_hash'] !== '';
        unset($row['portal_password_hash'], $row['portal_last_login_ip']);
        return new Subcontractor($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function hydrateAssignment(array $row): SubcontractorAssignment
    {
        return new SubcontractorAssignment($row);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private static function writableSubParams(array $data): array
    {
        $cols = ['company_name', 'contact_name', 'email', 'phone',
            'address_line1', 'address_line2', 'city', 'state', 'postal_code', 'country',
            'tax_id', 'payment_terms', 'hourly_rate_cents', 'status',
            'insurance_expires_at', 'notes', 'portal_login_enabled', 'portal_password_hash',
            'portal_password_updated_at'];
        $out = [];
        foreach ($cols as $c) {
            $out[$c] = self::castSubColumn($c, $data[$c] ?? null);
        }
        return $out;
    }

    private static function castSubColumn(string $col, mixed $value): mixed
    {
        if ($value === '' && $col !== 'company_name' && $col !== 'status') {
            return null;
        }
        return match ($col) {
            'hourly_rate_cents' => self::nullableInt($value),
            'portal_login_enabled' => $value ? 1 : 0,
            'company_name' => (string) ($value ?? ''),
            'status' => self::normalizeStatus($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function castAssignmentColumn(string $col, mixed $value): mixed
    {
        return match ($col) {
            'status' => $value === null ? SubcontractorAssignment::STATUS_PENDING : (string) $value,
            'estimated_cost_cents', 'actual_cost_cents', 'rating' => self::nullableInt($value),
            'description', 'internal_notes', 'rating_notes' => self::nullableString($value),
            default => $value === null ? null : (string) $value,
        };
    }

    private static function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $s = (string) $value;
        return $s === '' ? null : $s;
    }

    private static function normalizeStatus(mixed $value): string
    {
        $candidate = is_string($value) ? $value : Subcontractor::STATUS_ACTIVE;
        return in_array($candidate, Subcontractor::STATUSES, true)
            ? $candidate
            : Subcontractor::STATUS_ACTIVE;
    }

    /**
     * Adds an alias prefix to a comma-separated column list so we can SELECT
     * subcontractor columns under a JOIN without ambiguity.
     */
    private static function aliasColumns(string $columns, string $alias): string
    {
        $parts = array_map('trim', explode(',', $columns));
        $aliased = array_map(static fn(string $col) => $alias . '.' . $col, $parts);
        return implode(', ', $aliased);
    }
}
