<?php

namespace App\Services\Security;

use App\Database\Connection;
use App\Models\CredentialRegister;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `credential_registers` — Phase 16 / S1 of
 * docs/woms-expansion-plan.md.
 *
 * Stores issued security credentials (cards, fobs, PINs, mobile tokens,
 * biometric IDs, plates). PIN credentials MUST be hashed before they reach
 * here — the service layer (CredentialRegisterService) is responsible for
 * the hash; this repository is a dumb writer.
 *
 * Status writes (suspend/revoke/lost) and door assignments are coordinated
 * through CredentialRegisterService so the programming_logs ledger captures
 * the move.
 */
class CredentialRegisterRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{customer_id?: int, site_id?: int, status?: string,
     *              credential_type?: string, search?: string,
     *              expires_before?: string} $filters
     * @return array<int, CredentialRegister>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$where, $params] = $this->buildFilters($filters);
        $sql = 'SELECT * FROM credential_registers ' . $where
            . ' ORDER BY holder_name ASC, id ASC LIMIT :limit OFFSET :offset';
        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue(':' . $key, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $stmt->bindValue(':limit', max(1, min(500, $limit)), PDO::PARAM_INT);
        $stmt->bindValue(':offset', max(0, $offset), PDO::PARAM_INT);
        $stmt->execute();

        return array_map(
            static fn (array $row) => CredentialRegister::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @param array<string, mixed> $filters
     */
    public function count(array $filters = []): int
    {
        [$where, $params] = $this->buildFilters($filters);
        $stmt = $this->connection->pdo()->prepare(
            'SELECT COUNT(*) FROM credential_registers ' . $where
        );
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function findById(int $id): ?CredentialRegister
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM credential_registers WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? CredentialRegister::fromRow($row) : null;
    }

    public function findByCode(int $customerId, string $type, string $code): ?CredentialRegister
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM credential_registers
              WHERE customer_id = :c AND credential_type = :t AND credential_code = :code
              LIMIT 1'
        );
        $stmt->execute(['c' => $customerId, 't' => $type, 'code' => $code]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? CredentialRegister::fromRow($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): CredentialRegister
    {
        $customerId = (int) ($data['customer_id'] ?? 0);
        $holderName = trim((string) ($data['holder_name'] ?? ''));
        $type = (string) ($data['credential_type'] ?? '');
        $code = (string) ($data['credential_code'] ?? '');
        if ($customerId <= 0 || $holderName === '' || $type === '' || $code === '') {
            throw new InvalidArgumentException(
                'customer_id, holder_name, credential_type, credential_code are required'
            );
        }
        if (!in_array($type, CredentialRegister::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException("Invalid credential_type '{$type}'");
        }
        $status = (string) ($data['status'] ?? CredentialRegister::STATUS_ACTIVE);
        if (!in_array($status, CredentialRegister::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid status '{$status}'");
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO credential_registers
                (customer_id, site_id, holder_name, holder_email, holder_phone,
                 holder_employee_id, credential_type, credential_code,
                 credential_format, status, issued_at, expires_at, notes,
                 created_by_user_id, updated_by_user_id)
             VALUES
                (:customer_id, :site_id, :holder_name, :holder_email, :holder_phone,
                 :holder_employee_id, :credential_type, :credential_code,
                 :credential_format, :status, :issued_at, :expires_at, :notes,
                 :created_by_user_id, :updated_by_user_id)'
        );
        $stmt->execute([
            'customer_id' => $customerId,
            'site_id' => $this->nullableInt($data['site_id'] ?? null),
            'holder_name' => $holderName,
            'holder_email' => $this->nullableString($data['holder_email'] ?? null),
            'holder_phone' => $this->nullableString($data['holder_phone'] ?? null),
            'holder_employee_id' => $this->nullableString($data['holder_employee_id'] ?? null),
            'credential_type' => $type,
            'credential_code' => $code,
            'credential_format' => $this->nullableString($data['credential_format'] ?? null),
            'status' => $status,
            'issued_at' => $data['issued_at'] ?? date('Y-m-d H:i:s'),
            'expires_at' => $this->nullableString($data['expires_at'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
            'created_by_user_id' => $this->nullableInt($data['created_by_user_id'] ?? null),
            'updated_by_user_id' => $this->nullableInt($data['updated_by_user_id'] ?? null),
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created credential_register');
        }
        return $row;
    }

    /**
     * Partial update — does NOT change status (use markStatus()).
     *
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): CredentialRegister
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("credential_register {$id} not found");
        }
        $fields = [];
        $params = ['id' => $id];

        $stringCols = ['holder_name', 'holder_email', 'holder_phone',
                       'holder_employee_id', 'credential_format', 'expires_at',
                       'notes'];
        foreach ($stringCols as $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$key} = :{$key}";
                $params[$key] = $data[$key] === null ? null : (string) $data[$key];
            }
        }
        if (array_key_exists('site_id', $data)) {
            $fields[] = 'site_id = :site_id';
            $params['site_id'] = $this->nullableInt($data['site_id']);
        }
        if (array_key_exists('updated_by_user_id', $data)) {
            $fields[] = 'updated_by_user_id = :updated_by_user_id';
            $params['updated_by_user_id'] = $this->nullableInt($data['updated_by_user_id']);
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE credential_registers SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("credential_register {$id} not found after update");
        }
        return $row;
    }

    /**
     * Status writer used by CredentialRegisterService. Stamps the matching
     * timestamp column atomically with the status change.
     */
    public function markStatus(int $id, string $status, ?string $reason = null, ?int $actorUserId = null): CredentialRegister
    {
        if (!in_array($status, CredentialRegister::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid status '{$status}'");
        }

        $now = date('Y-m-d H:i:s');
        $fields = ['status = :status', 'updated_by_user_id = :updated_by_user_id'];
        $params = [
            'id' => $id,
            'status' => $status,
            'updated_by_user_id' => $actorUserId,
        ];

        switch ($status) {
            case CredentialRegister::STATUS_SUSPENDED:
                $fields[] = 'suspended_at = :suspended_at';
                $params['suspended_at'] = $now;
                break;
            case CredentialRegister::STATUS_REVOKED:
            case CredentialRegister::STATUS_LOST:
                $fields[] = 'revoked_at = :revoked_at';
                $fields[] = 'revoke_reason = :revoke_reason';
                $params['revoked_at'] = $now;
                $params['revoke_reason'] = $reason;
                break;
            case CredentialRegister::STATUS_ACTIVE:
                // Reactivation clears the suspension/revocation marks so
                // the row reads cleanly going forward.
                $fields[] = 'suspended_at = NULL';
                $fields[] = 'revoked_at = NULL';
                $fields[] = 'revoke_reason = NULL';
                break;
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE credential_registers SET ' . implode(', ', $fields) . ' WHERE id = :id'
        );
        $stmt->execute($params);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("credential_register {$id} not found after status change");
        }
        return $row;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM credential_registers WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function nullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        return (int) $value;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function buildFilters(array $filters): array
    {
        $where = 'WHERE 1=1';
        $params = [];

        if (isset($filters['customer_id'])) {
            $where .= ' AND customer_id = :customer_id';
            $params['customer_id'] = (int) $filters['customer_id'];
        }
        if (isset($filters['site_id'])) {
            $where .= ' AND site_id = :site_id';
            $params['site_id'] = (int) $filters['site_id'];
        }
        if (!empty($filters['status'])) {
            $where .= ' AND status = :status';
            $params['status'] = (string) $filters['status'];
        }
        if (!empty($filters['credential_type'])) {
            $where .= ' AND credential_type = :credential_type';
            $params['credential_type'] = (string) $filters['credential_type'];
        }
        if (!empty($filters['search'])) {
            $where .= ' AND (holder_name LIKE :search'
                . ' OR holder_email LIKE :search'
                . ' OR holder_employee_id LIKE :search'
                . ' OR credential_code LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }
        if (!empty($filters['expires_before'])) {
            $where .= ' AND expires_at IS NOT NULL AND expires_at < :expires_before';
            $params['expires_before'] = (string) $filters['expires_before'];
        }
        return [$where, $params];
    }
}
