<?php

namespace App\Services\Crm;

use App\Database\Connection;
use App\Models\BillingContact;
use PDO;
use RuntimeException;

class BillingContactRepository
{
    private const COLUMNS = 'id, company_id, user_id, first_name, last_name, title, email, phone,
        is_primary, is_active, ap_email, ap_phone, permission_scope, notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, BillingContact>
     */
    public function listForCompany(int $companyId, bool $activeOnly = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM billing_contacts WHERE company_id = :cid';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY is_primary DESC, last_name ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['cid' => $companyId]);

        return array_map(
            static fn(array $r) => new BillingContact($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?BillingContact
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM billing_contacts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new BillingContact($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): BillingContact
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            if (!empty($data['is_primary'])) {
                $demote = $pdo->prepare('UPDATE billing_contacts SET is_primary = 0 WHERE company_id = :cid');
                $demote->execute(['cid' => $data['company_id']]);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO billing_contacts (company_id, user_id, first_name, last_name, title, email, phone,
                    is_primary, is_active, ap_email, ap_phone, permission_scope, notes)
                 VALUES (:company_id, :user_id, :first_name, :last_name, :title, :email, :phone,
                    :is_primary, :is_active, :ap_email, :ap_phone, :permission_scope, :notes)'
            );
            $stmt->execute([
                'company_id' => (int) $data['company_id'],
                'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'title' => $data['title'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'is_primary' => (int) ($data['is_primary'] ?? 0),
                'is_active' => (int) ($data['is_active'] ?? 1),
                'ap_email' => $data['ap_email'] ?? null,
                'ap_phone' => $data['ap_phone'] ?? null,
                'permission_scope' => isset($data['permission_scope']) && $data['permission_scope'] !== null
                    ? json_encode($data['permission_scope'])
                    : null,
                'notes' => $data['notes'] ?? null,
            ]);
            $id = (int) $pdo->lastInsertId();
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $contact = $this->findById($id);
        if ($contact === null) {
            throw new RuntimeException('Failed to load newly created billing contact');
        }
        return $contact;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): BillingContact
    {
        $pdo = $this->connection->pdo();
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new RuntimeException("Billing contact {$id} not found");
        }

        $pdo->beginTransaction();
        try {
            if (!empty($data['is_primary'])) {
                $demote = $pdo->prepare('UPDATE billing_contacts SET is_primary = 0 WHERE company_id = :cid AND id != :id');
                $demote->execute(['cid' => $existing->company_id, 'id' => $id]);
            }

            $fields = [];
            $params = ['id' => $id];
            $simple = ['user_id','first_name','last_name','title','email','phone','ap_email','ap_phone','notes'];
            foreach ($simple as $k) {
                if (array_key_exists($k, $data)) {
                    $fields[] = "{$k} = :{$k}";
                    $params[$k] = $data[$k];
                }
            }
            foreach (['is_primary','is_active'] as $k) {
                if (array_key_exists($k, $data)) {
                    $fields[] = "{$k} = :{$k}";
                    $params[$k] = (int) $data[$k];
                }
            }
            if (array_key_exists('permission_scope', $data)) {
                $fields[] = 'permission_scope = :permission_scope';
                $params['permission_scope'] = $data['permission_scope'] === null
                    ? null
                    : json_encode($data['permission_scope']);
            }

            if ($fields !== []) {
                $stmt = $pdo->prepare('UPDATE billing_contacts SET ' . implode(', ', $fields) . ' WHERE id = :id');
                $stmt->execute($params);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $contact = $this->findById($id);
        if ($contact === null) {
            throw new RuntimeException("Billing contact {$id} not found after update");
        }
        return $contact;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM billing_contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
