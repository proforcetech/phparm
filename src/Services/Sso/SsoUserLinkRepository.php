<?php

namespace App\Services\Sso;

use App\Database\Connection;
use App\Models\SsoUserLink;
use PDO;

class SsoUserLinkRepository
{
    private const COLUMNS = [
        'id', 'user_id', 'provider_id', 'subject', 'email', 'display_name',
        'last_login_at', 'created_at', 'updated_at',
    ];

    public function __construct(private Connection $connection)
    {
    }

    public function find(int $id): ?SsoUserLink
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_user_links WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    public function findByProviderSubject(int $providerId, string $subject): ?SsoUserLink
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_user_links '
            . 'WHERE provider_id = :p AND subject = :s'
        );
        $stmt->execute(['p' => $providerId, 's' => $subject]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @return array<int, SsoUserLink>
     */
    public function listForUser(int $userId): array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT ' . implode(', ', self::COLUMNS) . ' FROM sso_user_links '
            . 'WHERE user_id = :u ORDER BY id DESC'
        );
        $stmt->execute(['u' => $userId]);
        return array_map([$this, 'hydrate'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @param array<string, mixed> $payload Required: user_id, provider_id, subject.
     */
    public function create(array $payload): SsoUserLink
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO sso_user_links (user_id, provider_id, subject, email, display_name, last_login_at) '
            . 'VALUES (:u, :p, :s, :e, :n, :t)'
        );
        $stmt->execute([
            'u' => (int) $payload['user_id'],
            'p' => (int) $payload['provider_id'],
            's' => (string) $payload['subject'],
            'e' => $payload['email'] ?? null,
            'n' => $payload['display_name'] ?? null,
            't' => $payload['last_login_at'] ?? null,
        ]);
        $id = (int) $this->connection->pdo()->lastInsertId();
        return $this->find($id) ?? new SsoUserLink(['id' => $id]);
    }

    public function touchLogin(int $linkId, ?string $when = null): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE sso_user_links SET last_login_at = :t WHERE id = :id'
        )->execute(['id' => $linkId, 't' => $when ?? date('Y-m-d H:i:s')]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function syncProfile(int $linkId, array $payload): void
    {
        $this->connection->pdo()->prepare(
            'UPDATE sso_user_links SET email = :e, display_name = :n WHERE id = :id'
        )->execute([
            'id' => $linkId,
            'e' => $payload['email'] ?? null,
            'n' => $payload['display_name'] ?? null,
        ]);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM sso_user_links WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->rowCount() > 0;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): SsoUserLink
    {
        $cast = [];
        foreach ($row as $k => $v) {
            $cast[$k] = $this->castColumn($k, $v);
        }
        return new SsoUserLink($cast);
    }

    private function castColumn(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($key) {
            'id', 'user_id', 'provider_id' => (int) $value,
            default => $value,
        };
    }
}
