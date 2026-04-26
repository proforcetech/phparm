<?php

namespace App\Services\Crm;

use App\Database\Connection;
use App\Models\Company;
use PDO;
use RuntimeException;

class CompanyRepository
{
    private const COLUMNS = 'id, division_id, name, legal_name, external_reference, company_type,
        tax_id, tax_exempt, status, primary_phone, primary_email, website, notes,
        created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{status?: string, division_id?: int, query?: string, limit?: int, offset?: int} $filters
     * @return array{data: array<int, Company>, total: int}
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['division_id'])) {
            $where[] = 'division_id = :division_id';
            $params['division_id'] = (int) $filters['division_id'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(name LIKE :q OR legal_name LIKE :q OR external_reference LIKE :q OR primary_email LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }

        $whereSql = implode(' AND ', $where);
        $limit = max(1, min(200, (int) ($filters['limit'] ?? 50)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $pdo = $this->connection->pdo();
        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM companies WHERE {$whereSql}");
        $countStmt->execute($params);
        $total = (int) $countStmt->fetchColumn();

        $listSql = 'SELECT ' . self::COLUMNS . " FROM companies WHERE {$whereSql}
                    ORDER BY name ASC LIMIT {$limit} OFFSET {$offset}";
        $listStmt = $pdo->prepare($listSql);
        $listStmt->execute($params);
        $rows = $listStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

        return [
            'data' => array_map(static fn(array $r) => new Company($r), $rows),
            'total' => $total,
        ];
    }

    public function findById(int $id): ?Company
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM companies WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Company($row) : null;
    }

    public function findByExternalReference(string $ref): ?Company
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM companies WHERE external_reference = :ref LIMIT 1'
        );
        $stmt->execute(['ref' => $ref]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Company($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Company
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO companies (division_id, name, legal_name, external_reference, company_type,
                tax_id, tax_exempt, status, primary_phone, primary_email, website, notes)
             VALUES (:division_id, :name, :legal_name, :external_reference, :company_type,
                :tax_id, :tax_exempt, :status, :primary_phone, :primary_email, :website, :notes)'
        );
        $stmt->execute([
            'division_id' => $data['division_id'] ?? null,
            'name' => $data['name'],
            'legal_name' => $data['legal_name'] ?? null,
            'external_reference' => $data['external_reference'] ?? null,
            'company_type' => $data['company_type'] ?? 'commercial',
            'tax_id' => $data['tax_id'] ?? null,
            'tax_exempt' => (int) ($data['tax_exempt'] ?? 0),
            'status' => $data['status'] ?? 'active',
            'primary_phone' => $data['primary_phone'] ?? null,
            'primary_email' => $data['primary_email'] ?? null,
            'website' => $data['website'] ?? null,
            'notes' => $data['notes'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $c = $this->findById($id);
        if ($c === null) {
            throw new RuntimeException('Failed to load newly created company');
        }
        return $c;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Company
    {
        $fields = [];
        $params = ['id' => $id];
        $simple = ['division_id','name','legal_name','external_reference','company_type',
                   'tax_id','status','primary_phone','primary_email','website','notes'];
        foreach ($simple as $k) {
            if (array_key_exists($k, $data)) {
                $fields[] = "{$k} = :{$k}";
                $params[$k] = $data[$k];
            }
        }
        if (array_key_exists('tax_exempt', $data)) {
            $fields[] = 'tax_exempt = :tax_exempt';
            $params['tax_exempt'] = (int) $data['tax_exempt'];
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE companies SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $c = $this->findById($id);
        if ($c === null) {
            throw new RuntimeException("Company {$id} not found after update");
        }
        return $c;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM companies WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
