<?php

namespace App\Services\Crm;

use App\Database\Connection;
use App\Models\SiteContact;
use PDO;
use RuntimeException;

class SiteContactRepository
{
    private const COLUMNS = 'id, site_id, user_id, first_name, last_name, title, email, phone, mobile_phone,
        role, is_primary, is_active, permission_scope, notes, created_at, updated_at';

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array<int, SiteContact>
     */
    public function listForSite(int $siteId, bool $activeOnly = true): array
    {
        $sql = 'SELECT ' . self::COLUMNS . ' FROM site_contacts WHERE site_id = :sid';
        if ($activeOnly) {
            $sql .= ' AND is_active = 1';
        }
        $sql .= ' ORDER BY is_primary DESC, last_name ASC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['sid' => $siteId]);

        return array_map(
            static fn(array $r) => new SiteContact($r),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    public function findById(int $id): ?SiteContact
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . self::COLUMNS . ' FROM site_contacts WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? new SiteContact($row) : null;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data): SiteContact
    {
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            if (!empty($data['is_primary'])) {
                $demote = $pdo->prepare('UPDATE site_contacts SET is_primary = 0 WHERE site_id = :sid');
                $demote->execute(['sid' => $data['site_id']]);
            }
            $stmt = $pdo->prepare(
                'INSERT INTO site_contacts (site_id, user_id, first_name, last_name, title, email, phone,
                    mobile_phone, role, is_primary, is_active, permission_scope, notes)
                 VALUES (:site_id, :user_id, :first_name, :last_name, :title, :email, :phone,
                    :mobile_phone, :role, :is_primary, :is_active, :permission_scope, :notes)'
            );
            $stmt->execute([
                'site_id' => (int) $data['site_id'],
                'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : null,
                'first_name' => $data['first_name'],
                'last_name' => $data['last_name'],
                'title' => $data['title'] ?? null,
                'email' => $data['email'] ?? null,
                'phone' => $data['phone'] ?? null,
                'mobile_phone' => $data['mobile_phone'] ?? null,
                'role' => $data['role'] ?? null,
                'is_primary' => (int) ($data['is_primary'] ?? 0),
                'is_active' => (int) ($data['is_active'] ?? 1),
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
            throw new RuntimeException('Failed to load newly created site contact');
        }
        return $contact;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data): SiteContact
    {
        $pdo = $this->connection->pdo();
        $existing = $this->findById($id);
        if ($existing === null) {
            throw new RuntimeException("Site contact {$id} not found");
        }

        $pdo->beginTransaction();
        try {
            if (!empty($data['is_primary'])) {
                $demote = $pdo->prepare('UPDATE site_contacts SET is_primary = 0 WHERE site_id = :sid AND id != :id');
                $demote->execute(['sid' => $existing->site_id, 'id' => $id]);
            }

            $fields = [];
            $params = ['id' => $id];
            $simple = ['user_id','first_name','last_name','title','email','phone','mobile_phone','role','notes'];
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
                $stmt = $pdo->prepare('UPDATE site_contacts SET ' . implode(', ', $fields) . ' WHERE id = :id');
                $stmt->execute($params);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $contact = $this->findById($id);
        if ($contact === null) {
            throw new RuntimeException("Site contact {$id} not found after update");
        }
        return $contact;
    }

    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM site_contacts WHERE id = :id');
        $stmt->execute(['id' => $id]);
    }
}
