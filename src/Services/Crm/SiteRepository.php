<?php

namespace App\Services\Crm;

use App\Database\Connection;
use App\Models\Site;
use PDO;
use RuntimeException;

class SiteRepository
{
    private const COLUMNS = 'id, company_id, legacy_customer_id, division_id, name, code, is_primary, status,
        street, city, state, postal_code, country, latitude, longitude, timezone, phone,
        access_instructions, hours_json, alarm_code_encrypted, gate_code_encrypted,
        notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, Site>
     */
    public function listForCompany(int $companyId, bool $activeOnly = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM sites WHERE company_id = :cid';
        $params = ['cid' => $companyId];
        if ($activeOnly) {
            $sql .= " AND status = 'active'";
        }
        $sql .= ' ORDER BY is_primary DESC, name ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(
            static fn(array $r) => new Site($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?Site
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM sites WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Site($row) : null;
    }

    public function findPrimaryForCompany(int $companyId): ?Site
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM sites WHERE company_id = :cid AND is_primary = 1 LIMIT 1'
        );
        $stmt->execute(['cid' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Site($row) : null;
    }

    public function findByLegacyCustomer(int $customerId): ?Site
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM sites WHERE legacy_customer_id = :cid
             ORDER BY is_primary DESC, id ASC LIMIT 1'
        );
        $stmt->execute(['cid' => $customerId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new Site($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): Site
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            // A company must have exactly one primary site. If the new row is
            // flagged primary, demote the existing one first.
            if (!empty($data['is_primary'])) {
                $demote = $pdo->prepare('UPDATE sites SET is_primary = 0 WHERE company_id = :cid');
                $demote->execute(['cid' => $data['company_id']]);
            }

            $stmt = $pdo->prepare(
                'INSERT INTO sites (company_id, legacy_customer_id, division_id, name, code, is_primary, status,
                    street, city, state, postal_code, country, latitude, longitude, timezone, phone,
                    access_instructions, hours_json, alarm_code_encrypted, gate_code_encrypted, notes)
                 VALUES (:company_id, :legacy_customer_id, :division_id, :name, :code, :is_primary, :status,
                    :street, :city, :state, :postal_code, :country, :latitude, :longitude, :timezone, :phone,
                    :access_instructions, :hours_json, :alarm_code_encrypted, :gate_code_encrypted, :notes)'
            );
            $stmt->execute([
                'company_id' => (int) $data['company_id'],
                'legacy_customer_id' => isset($data['legacy_customer_id']) ? (int) $data['legacy_customer_id'] : null,
                'division_id' => isset($data['division_id']) ? (int) $data['division_id'] : null,
                'name' => $data['name'],
                'code' => $data['code'] ?? null,
                'is_primary' => (int) ($data['is_primary'] ?? 0),
                'status' => $data['status'] ?? 'active',
                'street' => $data['street'] ?? null,
                'city' => $data['city'] ?? null,
                'state' => $data['state'] ?? null,
                'postal_code' => $data['postal_code'] ?? null,
                'country' => $data['country'] ?? null,
                'latitude' => $data['latitude'] ?? null,
                'longitude' => $data['longitude'] ?? null,
                'timezone' => $data['timezone'] ?? null,
                'phone' => $data['phone'] ?? null,
                'access_instructions' => $data['access_instructions'] ?? null,
                'hours_json' => isset($data['hours_json']) && $data['hours_json'] !== null
                    ? json_encode($data['hours_json'])
                    : null,
                'alarm_code_encrypted' => $data['alarm_code_encrypted'] ?? null,
                'gate_code_encrypted' => $data['gate_code_encrypted'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $site = $this->findById($id);
        if ($site === null) {
            throw new RuntimeException('Failed to load newly created site');
        }
        return $site;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): Site
    {
        $pdo = $this->connection->pdo();
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new RuntimeException("Site {$id} not found");
        }

        $pdo->beginTransaction();
        try {
            if (!empty($data['is_primary'])) {
                $demote = $pdo->prepare('UPDATE sites SET is_primary = 0 WHERE company_id = :cid AND id != :id');
                $demote->execute(['cid' => $existing->company_id, 'id' => $id]);
            }

            $fields = [];
            $params = ['id' => $id];
            $simple = ['legacy_customer_id','division_id','name','code','status','street','city','state',
                       'postal_code','country','latitude','longitude','timezone','phone',
                       'access_instructions','alarm_code_encrypted','gate_code_encrypted','notes'];
            foreach ($simple as $k) {
                if (array_key_exists($k, $data)) {
                    $fields[] = "{$k} = :{$k}";
                    $params[$k] = $data[$k];
                }
            }
            if (array_key_exists('hours_json', $data)) {
                $fields[] = 'hours_json = :hours_json';
                $params['hours_json'] = $data['hours_json'] === null
                    ? null
                    : json_encode($data['hours_json']);
            }
            if (array_key_exists('is_primary', $data)) {
                $fields[] = 'is_primary = :is_primary';
                $params['is_primary'] = (int) $data['is_primary'];
            }

            if ($fields !== []) {
                $stmt = $pdo->prepare('UPDATE sites SET ' . implode(', ', $fields) . ' WHERE id = :id');
                $stmt->execute($params);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $site = $this->findById($id);
        if ($site === null) {
            throw new RuntimeException("Site {$id} not found after update");
        }
        return $site;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM sites WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
