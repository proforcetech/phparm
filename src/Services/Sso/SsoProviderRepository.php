<?php

namespace App\Services\Sso;

use App\Database\Connection;
use App\Models\SsoProvider;
use PDO;

class SsoProviderRepository
{
    private const COLUMNS = [
        'id', 'slug', 'company_id', 'name', 'type', 'issuer_url', 'client_id', 'client_secret',
        'redirect_uri', 'authorize_endpoint', 'token_endpoint', 'userinfo_endpoint',
        'jwks_uri', 'scopes', 'is_active', 'portal_enabled', 'staff_enabled',
        'auto_provision', 'default_role', 'sync_profile_on_login', 'metadata',
        'created_at', 'updated_at',
    ];

    private const WRITABLE = [
        'slug', 'company_id', 'name', 'type', 'issuer_url', 'client_id', 'client_secret',
        'redirect_uri', 'authorize_endpoint', 'token_endpoint', 'userinfo_endpoint',
        'jwks_uri', 'scopes', 'is_active', 'portal_enabled', 'staff_enabled',
        'auto_provision', 'default_role', 'sync_profile_on_login', 'metadata',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?SsoProvider
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_providers WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findBySlug(string $slug): ?SsoProvider
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_providers WHERE slug = :s'
        );
        $stmt->execute(['s' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, SsoProvider>
     */
    public function listAll(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_providers ORDER BY name ASC'
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<int, SsoProvider>
     */
    public function listActive(): array
    {
        $stmt = $this->connection->pdo()->query(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_providers WHERE is_active = 1 ORDER BY name ASC'
        );
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Active staff-side providers visible to the staff login picker.
     * Includes global (company_id IS NULL) and any provider scoped to the
     * passed company. Backward-compat: when company filter is not used,
     * returns staff-enabled providers across all companies.
     *
     * @return array<int, SsoProvider>
     */
    public function listForStaff(?int $companyId = null): array
    {
        $sql = 'SELECT ' . implode(', ', self::COLUMNS)
            . ' FROM sso_providers WHERE is_active = 1 AND staff_enabled = 1';
        $params = [];
        if ($companyId !== null) {
            $sql .= ' AND (company_id IS NULL OR company_id = :cid)';
            $params['cid'] = $companyId;
        }
        $sql .= ' ORDER BY name ASC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Active portal-side providers a portal account in $companyId can use.
     * Includes global (company_id IS NULL) and providers scoped to that company.
     *
     * @return array<int, SsoProvider>
     */
    public function listForPortal(int $companyId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_providers
             WHERE is_active = 1 AND portal_enabled = 1
               AND (company_id IS NULL OR company_id = :cid)
             ORDER BY name ASC'
        );
        $stmt->execute(['cid' => $companyId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Lookup for the portal callback: provider must be active, portal-enabled,
     * and either global or matching the portal account's company.
     */
    public function findActiveBySlugForPortal(string $slug, int $companyId): ?SsoProvider
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_providers
             WHERE slug = :s AND is_active = 1 AND portal_enabled = 1
               AND (company_id IS NULL OR company_id = :cid)
             LIMIT 1'
        );
        $stmt->execute(['s' => $slug, 'cid' => $companyId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Lookup for the staff login flow.
     */
    public function findActiveBySlugForStaff(string $slug, ?int $companyId = null): ?SsoProvider
    {
        $sql = 'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_providers
             WHERE slug = :s AND is_active = 1 AND staff_enabled = 1';
        $params = ['s' => $slug];
        if ($companyId !== null) {
            $sql .= ' AND (company_id IS NULL OR company_id = :cid)';
            $params['cid'] = $companyId;
        }
        $sql .= ' LIMIT 1';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function create(array $payload): SsoProvider
    {
        $columns = [];
        $placeholders = [];
        $params = [];
        foreach (self::WRITABLE as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $columns[] = $col;
            $placeholders[] = ':' . $col;
            $params[$col] = $this->encode($col, $payload[$col]);
        }

        $sql = 'INSERT INTO sso_providers (' . implode(', ', $columns) . ') VALUES ('
            . implode(', ', $placeholders) . ')';
        $this->connection->pdo()->prepare($sql)->execute($params);

        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new SsoProvider(['id' => $id]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $id, array $payload): ?SsoProvider
    {
        $sets = [];
        $params = ['id' => $id];
        foreach (self::WRITABLE as $col) {
            if (!array_key_exists($col, $payload)) {
                continue;
            }
            $sets[] = $col . ' = :' . $col;
            $params[$col] = $this->encode($col, $payload[$col]);
        }
        if ($sets === []) {
            return $this->find($id);
        }
        $this->connection->pdo()->prepare(
            'UPDATE sso_providers SET ' . implode(', ', $sets) . ' WHERE id = :id'
        )->execute($params);
        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM sso_providers WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    private function encode(string $col, mixed $value): mixed
    {
        if (in_array($col, [
            'is_active', 'portal_enabled', 'staff_enabled',
            'auto_provision', 'sync_profile_on_login',
        ], true)) {
            return $value ? 1 : 0;
        }
        if ($col === 'company_id') {
            return $value === null || $value === '' ? null : (int) $value;
        }
        if ($col === 'metadata' && $value !== null && !is_string($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        }
        return $value;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SsoProvider
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new SsoProvider($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'company_id' => (int) $value,
            'is_active', 'portal_enabled', 'staff_enabled',
            'auto_provision', 'sync_profile_on_login' => (bool) $value,
            'metadata' => is_string($value) ? (json_decode($value, true) ?: null) : $value,
            default => $value,
        };
    }
}
