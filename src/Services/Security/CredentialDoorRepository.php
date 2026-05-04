<?php

namespace App\Services\Security;

use App\Database\Connection;
use App\Models\CredentialDoor;
use InvalidArgumentException;
use PDO;
use RuntimeException;

/**
 * Data access for `credential_doors` — Phase 16 / S1 of
 * docs/woms-expansion-plan.md.
 *
 * Many-to-many writer for credential ↔ door (site_asset) grants. Revocation
 * flips revoked_at + reason rather than DELETE so the per-credential history
 * view + programming_logs trail remain populated. The service layer
 * (CredentialRegisterService) is responsible for writing the companion
 * programming_logs row on every grant/revoke/update.
 */
class CredentialDoorRepository
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function findById(int $id): ?CredentialDoor
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM credential_doors WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? CredentialDoor::fromRow($row) : null;
    }

    public function findActivePair(int $credentialId, int $siteAssetId): ?CredentialDoor
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM credential_doors
              WHERE credential_id = :c AND site_asset_id = :a AND revoked_at IS NULL
              LIMIT 1'
        );
        $stmt->execute(['c' => $credentialId, 'a' => $siteAssetId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? CredentialDoor::fromRow($row) : null;
    }

    /**
     * @return array<int, CredentialDoor>
     */
    public function listForCredential(int $credentialId, bool $includeRevoked = true): array
    {
        $sql = 'SELECT * FROM credential_doors WHERE credential_id = :c';
        if (!$includeRevoked) {
            $sql .= ' AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY revoked_at IS NULL DESC, granted_at DESC, id DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['c' => $credentialId]);
        return array_map(
            static fn (array $row) => CredentialDoor::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * @return array<int, CredentialDoor>
     */
    public function listForDoor(int $siteAssetId, bool $includeRevoked = false): array
    {
        $sql = 'SELECT * FROM credential_doors WHERE site_asset_id = :a';
        if (!$includeRevoked) {
            $sql .= ' AND revoked_at IS NULL';
        }
        $sql .= ' ORDER BY granted_at DESC, id DESC';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['a' => $siteAssetId]);
        return array_map(
            static fn (array $row) => CredentialDoor::fromRow($row),
            $stmt->fetchAll(PDO::FETCH_ASSOC) ?: []
        );
    }

    /**
     * Grant a credential access to a door. If an active grant already exists
     * for the pair, returns it instead of inserting a duplicate (the unique
     * key on (credential_id, site_asset_id) would otherwise reject — but the
     * unique key includes revoked rows too, so we look up by pair + status).
     *
     * @param array{credential_id: int, site_asset_id: int,
     *              access_schedule_id?: ?int, granted_by_user_id?: ?int,
     *              notes?: ?string} $data
     */
    public function grant(array $data): CredentialDoor
    {
        $credentialId = (int) ($data['credential_id'] ?? 0);
        $siteAssetId = (int) ($data['site_asset_id'] ?? 0);
        if ($credentialId <= 0 || $siteAssetId <= 0) {
            throw new InvalidArgumentException(
                'credential_id and site_asset_id are required'
            );
        }

        $existing = $this->findActivePair($credentialId, $siteAssetId);
        if ($existing !== null) {
            return $existing;
        }

        // The (credential_id, site_asset_id) UNIQUE applies to ALL rows, so
        // any prior revoked row blocks a fresh insert. Reactivate it instead.
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM credential_doors
              WHERE credential_id = :c AND site_asset_id = :a
              ORDER BY id DESC LIMIT 1'
        );
        $stmt->execute(['c' => $credentialId, 'a' => $siteAssetId]);
        $priorId = $stmt->fetchColumn();
        if ($priorId !== false) {
            return $this->reactivate(
                (int) $priorId,
                $this->nullableInt($data['access_schedule_id'] ?? null),
                $this->nullableInt($data['granted_by_user_id'] ?? null),
                $this->nullableString($data['notes'] ?? null)
            );
        }

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO credential_doors
                (credential_id, site_asset_id, access_schedule_id, granted_at,
                 granted_by_user_id, notes)
             VALUES
                (:credential_id, :site_asset_id, :access_schedule_id, :granted_at,
                 :granted_by_user_id, :notes)'
        );
        $stmt->execute([
            'credential_id' => $credentialId,
            'site_asset_id' => $siteAssetId,
            'access_schedule_id' => $this->nullableInt($data['access_schedule_id'] ?? null),
            'granted_at' => $data['granted_at'] ?? date('Y-m-d H:i:s'),
            'granted_by_user_id' => $this->nullableInt($data['granted_by_user_id'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException('Failed to load newly created credential_door');
        }
        return $row;
    }

    /**
     * Adjust the schedule or notes on an existing assignment without
     * grant/revoke semantics.
     *
     * @param array{access_schedule_id?: ?int, notes?: ?string} $data
     */
    public function update(int $id, array $data): CredentialDoor
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("credential_door {$id} not found");
        }
        $fields = [];
        $params = ['id' => $id];

        if (array_key_exists('access_schedule_id', $data)) {
            $fields[] = 'access_schedule_id = :access_schedule_id';
            $params['access_schedule_id'] = $this->nullableInt($data['access_schedule_id']);
        }
        if (array_key_exists('notes', $data)) {
            $fields[] = 'notes = :notes';
            $params['notes'] = $this->nullableString($data['notes']);
        }

        if ($fields !== []) {
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE credential_doors SET ' . implode(', ', $fields) . ' WHERE id = :id'
            );
            $stmt->execute($params);
        }

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("credential_door {$id} not found after update");
        }
        return $row;
    }

    public function revoke(int $id, ?string $reason = null, ?int $actorUserId = null): CredentialDoor
    {
        if ($this->findById($id) === null) {
            throw new RuntimeException("credential_door {$id} not found");
        }
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE credential_doors
                SET revoked_at = :revoked_at,
                    revoked_by_user_id = :actor,
                    revoke_reason = :reason
              WHERE id = :id AND revoked_at IS NULL'
        );
        $stmt->execute([
            'revoked_at' => date('Y-m-d H:i:s'),
            'actor' => $actorUserId,
            'reason' => $reason,
            'id' => $id,
        ]);

        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("credential_door {$id} not found after revoke");
        }
        return $row;
    }

    /**
     * Hard delete — only used by the test suite or by a customer cascade.
     * Real code should call revoke() to keep the audit trail intact.
     */
    public function delete(int $id): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'DELETE FROM credential_doors WHERE id = :id'
        );
        $stmt->execute(['id' => $id]);
    }

    private function reactivate(int $id, ?int $scheduleId, ?int $actorUserId, ?string $notes): CredentialDoor
    {
        $stmt = $this->connection->pdo()->prepare(
            'UPDATE credential_doors
                SET revoked_at = NULL,
                    revoked_by_user_id = NULL,
                    revoke_reason = NULL,
                    access_schedule_id = :access_schedule_id,
                    granted_at = :granted_at,
                    granted_by_user_id = :granted_by_user_id,
                    notes = :notes
              WHERE id = :id'
        );
        $stmt->execute([
            'access_schedule_id' => $scheduleId,
            'granted_at' => date('Y-m-d H:i:s'),
            'granted_by_user_id' => $actorUserId,
            'notes' => $notes,
            'id' => $id,
        ]);
        $row = $this->findById($id);
        if ($row === null) {
            throw new RuntimeException("credential_door {$id} not found after reactivate");
        }
        return $row;
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
}
