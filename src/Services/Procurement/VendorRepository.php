<?php

namespace App\Services\Procurement;

use App\Database\Connection;
use App\Models\Vendor;
use PDO;
use RuntimeException;

/**
 * Phase 18 / S5 — repository for first-class procurement vendors.
 *
 * Independent of the legacy `inventory_lookups` (type='vendors') rows —
 * those remain in place for the parts-supplier dropdown but procurement
 * code reads/writes only this table.
 */
class VendorRepository
{
    private const COLUMNS = 'id, name, code, status, primary_contact_name,
        email, phone, website, street, city, state, postal_code, country,
        payment_terms, currency, tax_id, requires_1099, is_consignment_partner,
        notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @param array{
     *     status?: string,
     *     query?: string,
     *     consignment_only?: bool,
     *     limit?: int,
     *     offset?: int
     * } $filters
     * @return array{data: array<int, Vendor>, total: int}
     */
    public function search(array $filters = []): array
    {
        $where = ['1=1'];
        $params = [];
        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['query'])) {
            $where[] = '(name LIKE :q OR code LIKE :q OR email LIKE :q)';
            $params['q'] = '%' . $filters['query'] . '%';
        }
        if (!empty($filters['consignment_only'])) {
            $where[] = 'is_consignment_partner = 1';
        }
        $whereSql = implode(' AND ', $where);
        $limit = max(1, min(500, (int) ($filters['limit'] ?? 100)));
        $offset = max(0, (int) ($filters['offset'] ?? 0));

        $pdo = $this->connection->pdo();
        $count = $pdo->prepare("SELECT COUNT(*) FROM vendors WHERE {$whereSql}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();

        $list = $pdo->prepare(
            'SELECT ' . self::COLUMNS . " FROM vendors WHERE {$whereSql}
             ORDER BY name ASC LIMIT {$limit} OFFSET {$offset}"
        );
        $list->execute($params);
        return [
            'data' => array_map(static fn(array $r) => new Vendor($r), $list->fetchAll(PDO::FETCH_ASSOC) ?: []),
            'total' => $total,
        ];
    }

    public function findById(int $id): ?Vendor
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM vendors WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Vendor($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Vendor
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO vendors (name, code, status, primary_contact_name,
                email, phone, website, street, city, state, postal_code, country,
                payment_terms, currency, tax_id, requires_1099, is_consignment_partner, notes)
             VALUES (:name, :code, :status, :primary_contact_name,
                :email, :phone, :website, :street, :city, :state, :postal_code, :country,
                :payment_terms, :currency, :tax_id, :requires_1099, :is_consignment_partner, :notes)'
        );
        $stmt->execute([
            'name' => $data['name'],
            'code' => $this->emptyToNull($data['code'] ?? null),
            'status' => $data['status'] ?? Vendor::STATUS_ACTIVE,
            'primary_contact_name' => $data['primary_contact_name'] ?? null,
            'email' => $this->emptyToNull($data['email'] ?? null),
            'phone' => $data['phone'] ?? null,
            'website' => $data['website'] ?? null,
            'street' => $data['street'] ?? null,
            'city' => $data['city'] ?? null,
            'state' => $data['state'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'country' => $data['country'] ?? null,
            'payment_terms' => $data['payment_terms'] ?? null,
            'currency' => $data['currency'] ?? 'USD',
            'tax_id' => $data['tax_id'] ?? null,
            'requires_1099' => !empty($data['requires_1099']) ? 1 : 0,
            'is_consignment_partner' => !empty($data['is_consignment_partner']) ? 1 : 0,
            'notes' => $data['notes'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException('vendors insert did not return a row');
        }
        return $found;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Vendor
    {
        $writable = [
            'name', 'code', 'status', 'primary_contact_name',
            'email', 'phone', 'website',
            'street', 'city', 'state', 'postal_code', 'country',
            'payment_terms', 'currency', 'tax_id', 'notes',
        ];
        $fields = [];
        $params = ['id' => $id];
        foreach ($writable as $col) {
            if (array_key_exists($col, $data)) {
                $fields[] = "{$col} = :{$col}";
                $params[$col] = in_array($col, ['code', 'email'], true)
                    ? $this->emptyToNull($data[$col])
                    : $data[$col];
            }
        }
        foreach (['requires_1099', 'is_consignment_partner'] as $boolCol) {
            if (array_key_exists($boolCol, $data)) {
                $fields[] = "{$boolCol} = :{$boolCol}";
                $params[$boolCol] = !empty($data[$boolCol]) ? 1 : 0;
            }
        }
        if ($fields === []) {
            $found = $this->findById($id);
            if ($found === null) {
                throw new RuntimeException("vendor {$id} not found");
            }
            return $found;
        }
        $sql = 'UPDATE vendors SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $found = $this->findById($id);
        if ($found === null) {
            throw new RuntimeException("vendor {$id} not found");
        }
        return $found;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM vendors WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }

    private function emptyToNull(mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        if (is_string($value) && trim($value) === '') {
            return null;
        }
        return $value;
    }
}
