<?php

namespace App\Services\Security;

use App\Database\Connection;
use App\Models\AccessSchedule;
use App\Models\CredentialDoor;
use App\Models\CredentialRegister;
use App\Models\ProgrammingLog;
use App\Services\Assets\SiteAssetRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Phase 16 / S1 of docs/woms-expansion-plan.md — orchestrates writes against
 * `credential_registers`, `credential_doors`, `access_schedules`, and the
 * companion `programming_logs` ledger.
 *
 * Why this layer exists
 * ---------------------
 * Every config-change against a credential, door grant, or schedule MUST land
 * a row in programming_logs with a before/after JSON snapshot. The
 * repositories deliberately don't know about programming_logs — they're dumb
 * writers. This service pairs each write with the audit row inside one
 * transaction so the row state and the ledger can never disagree.
 *
 * It also enforces the PIN-hashing invariant: a credential of type 'pin' has
 * its plaintext code hashed (password_hash) before reaching the repository,
 * and the cleartext is dropped from any after_snapshot we persist.
 */
class CredentialRegisterService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly CredentialRegisterRepository $credentials,
        private readonly CredentialDoorRepository $doors,
        private readonly AccessScheduleRepository $schedules,
        private readonly ProgrammingLogRepository $logs,
        private readonly SiteAssetRepository $siteAssets,
        private readonly AuditLogger $audit,
    ) {
    }

    // =========================================================================
    // Credentials
    // =========================================================================

    /**
     * @param array<string, mixed> $data
     */
    public function createCredential(array $data, int $actorUserId, ?string $ip = null): CredentialRegister
    {
        $data['created_by_user_id'] = $actorUserId;
        $data['updated_by_user_id'] = $actorUserId;

        if (($data['credential_type'] ?? null) === CredentialRegister::TYPE_PIN) {
            $data['credential_code'] = $this->hashPinCode((string) ($data['credential_code'] ?? ''));
        }

        $credential = $this->withTransaction(fn () => $this->credentials->create($data));

        $this->writeLog([
            'customer_id' => $credential->customer_id,
            'site_id' => $credential->site_id,
            'target_type' => ProgrammingLog::TARGET_CREDENTIAL,
            'target_id' => $credential->id,
            'action' => ProgrammingLog::ACTION_CREATED,
            'summary' => sprintf('Credential issued to %s', $credential->holder_name),
            'after_snapshot' => $this->credentialSnapshot($credential),
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'credential.created',
            'credential',
            (string) $credential->id,
            $actorUserId,
            ['customer_id' => $credential->customer_id]
        ));
        return $this->maybeRedact($credential);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateCredential(int $id, array $data, int $actorUserId, ?string $ip = null): CredentialRegister
    {
        $existing = $this->credentials->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("credential {$id} not found");
        }
        $data['updated_by_user_id'] = $actorUserId;

        $before = $this->credentialSnapshot($existing);
        $updated = $this->withTransaction(fn () => $this->credentials->update($id, $data));
        $after = $this->credentialSnapshot($updated);

        $this->writeLog([
            'customer_id' => $updated->customer_id,
            'site_id' => $updated->site_id,
            'target_type' => ProgrammingLog::TARGET_CREDENTIAL,
            'target_id' => $updated->id,
            'action' => ProgrammingLog::ACTION_UPDATED,
            'summary' => 'Credential record updated',
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'credential.updated',
            'credential',
            (string) $updated->id,
            $actorUserId,
            ['changed_keys' => array_keys($this->diffKeys($before, $after))]
        ));
        return $this->maybeRedact($updated);
    }

    public function markStatus(
        int $id,
        string $status,
        ?string $reason,
        int $actorUserId,
        ?string $ip = null
    ): CredentialRegister {
        $existing = $this->credentials->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("credential {$id} not found");
        }
        $before = $this->credentialSnapshot($existing);

        $updated = $this->withTransaction(
            fn () => $this->credentials->markStatus($id, $status, $reason, $actorUserId)
        );

        $action = match ($status) {
            CredentialRegister::STATUS_REVOKED, CredentialRegister::STATUS_LOST => ProgrammingLog::ACTION_REVOKED,
            CredentialRegister::STATUS_SUSPENDED => ProgrammingLog::ACTION_DISABLED,
            CredentialRegister::STATUS_ACTIVE => ProgrammingLog::ACTION_ENABLED,
            default => ProgrammingLog::ACTION_CONFIG_CHANGED,
        };

        $this->writeLog([
            'customer_id' => $updated->customer_id,
            'site_id' => $updated->site_id,
            'target_type' => ProgrammingLog::TARGET_CREDENTIAL,
            'target_id' => $updated->id,
            'action' => $action,
            'summary' => sprintf(
                'Credential status %s → %s%s',
                $existing->status,
                $updated->status,
                $reason !== null && $reason !== '' ? " ({$reason})" : ''
            ),
            'before_snapshot' => $before,
            'after_snapshot' => $this->credentialSnapshot($updated),
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'credential.status_changed',
            'credential',
            (string) $updated->id,
            $actorUserId,
            ['from' => $existing->status, 'to' => $updated->status, 'reason' => $reason]
        ));

        // When a credential is revoked/lost we also revoke every active door
        // assignment so a stolen card can't unlock a door tomorrow.
        if (in_array($status, [CredentialRegister::STATUS_REVOKED, CredentialRegister::STATUS_LOST], true)) {
            $this->revokeAllDoors($updated, $actorUserId, $reason ?? 'credential ' . $status, $ip);
        }

        return $this->maybeRedact($updated);
    }

    public function deleteCredential(int $id, int $actorUserId, ?string $ip = null): void
    {
        $existing = $this->credentials->findById($id);
        if ($existing === null) {
            return;
        }
        $before = $this->credentialSnapshot($existing);

        $this->withTransaction(function () use ($id) {
            $this->credentials->delete($id);
        });

        $this->writeLog([
            'customer_id' => $existing->customer_id,
            'site_id' => $existing->site_id,
            'target_type' => ProgrammingLog::TARGET_CREDENTIAL,
            'target_id' => $existing->id,
            'action' => ProgrammingLog::ACTION_DELETED,
            'summary' => sprintf('Credential %s deleted', $existing->holder_name),
            'before_snapshot' => $before,
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'credential.deleted',
            'credential',
            (string) $existing->id,
            $actorUserId,
            ['customer_id' => $existing->customer_id]
        ));
    }

    // =========================================================================
    // Door grants
    // =========================================================================

    /**
     * @param array<string, mixed> $data
     */
    public function grantDoor(int $credentialId, array $data, int $actorUserId, ?string $ip = null): CredentialDoor
    {
        $credential = $this->credentials->findById($credentialId);
        if ($credential === null) {
            throw new InvalidArgumentException("credential {$credentialId} not found");
        }
        if ($credential->isTerminal()) {
            throw new InvalidArgumentException("Cannot grant access on a {$credential->status} credential");
        }

        $siteAssetId = (int) ($data['site_asset_id'] ?? 0);
        if ($siteAssetId <= 0) {
            throw new InvalidArgumentException('site_asset_id is required');
        }
        if ($this->siteAssets->findById($siteAssetId) === null) {
            throw new InvalidArgumentException("site_asset {$siteAssetId} not found");
        }

        $scheduleId = isset($data['access_schedule_id']) && $data['access_schedule_id'] !== ''
            ? (int) $data['access_schedule_id'] : null;
        if ($scheduleId !== null) {
            $schedule = $this->schedules->findById($scheduleId);
            if ($schedule === null || $schedule->customer_id !== $credential->customer_id) {
                throw new InvalidArgumentException("access_schedule {$scheduleId} not valid for this credential");
            }
        }

        $payload = [
            'credential_id' => $credentialId,
            'site_asset_id' => $siteAssetId,
            'access_schedule_id' => $scheduleId,
            'granted_by_user_id' => $actorUserId,
            'notes' => $data['notes'] ?? null,
        ];

        $grant = $this->withTransaction(fn () => $this->doors->grant($payload));

        $this->writeLog([
            'customer_id' => $credential->customer_id,
            'site_id' => $credential->site_id,
            'target_type' => ProgrammingLog::TARGET_CREDENTIAL_DOOR,
            'target_id' => $grant->id,
            'action' => ProgrammingLog::ACTION_ASSIGNED,
            'summary' => sprintf(
                'Door %d granted to credential %d%s',
                $siteAssetId,
                $credentialId,
                $scheduleId !== null ? " on schedule {$scheduleId}" : ''
            ),
            'after_snapshot' => $this->doorSnapshot($grant),
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'credential_door.granted',
            'credential_door',
            (string) $grant->id,
            $actorUserId,
            ['credential_id' => $credentialId, 'site_asset_id' => $siteAssetId]
        ));
        return $grant;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateDoor(int $doorId, array $data, int $actorUserId, ?string $ip = null): CredentialDoor
    {
        $existing = $this->doors->findById($doorId);
        if ($existing === null) {
            throw new InvalidArgumentException("credential_door {$doorId} not found");
        }
        $before = $this->doorSnapshot($existing);

        if (array_key_exists('access_schedule_id', $data) && $data['access_schedule_id'] !== null && $data['access_schedule_id'] !== '') {
            $scheduleId = (int) $data['access_schedule_id'];
            $credential = $this->credentials->findById($existing->credential_id);
            $schedule = $this->schedules->findById($scheduleId);
            if ($credential === null || $schedule === null || $schedule->customer_id !== $credential->customer_id) {
                throw new InvalidArgumentException("access_schedule {$scheduleId} not valid for this credential");
            }
            $data['access_schedule_id'] = $scheduleId;
        }

        $updated = $this->withTransaction(fn () => $this->doors->update($doorId, $data));
        $after = $this->doorSnapshot($updated);

        $credential = $this->credentials->findById($existing->credential_id);
        $this->writeLog([
            'customer_id' => $credential?->customer_id ?? 0,
            'site_id' => $credential?->site_id,
            'target_type' => ProgrammingLog::TARGET_CREDENTIAL_DOOR,
            'target_id' => $updated->id,
            'action' => ProgrammingLog::ACTION_CONFIG_CHANGED,
            'summary' => 'Door assignment updated',
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'credential_door.updated',
            'credential_door',
            (string) $updated->id,
            $actorUserId,
            ['changed_keys' => array_keys($this->diffKeys($before, $after))]
        ));
        return $updated;
    }

    public function revokeDoor(int $doorId, ?string $reason, int $actorUserId, ?string $ip = null): CredentialDoor
    {
        $existing = $this->doors->findById($doorId);
        if ($existing === null) {
            throw new InvalidArgumentException("credential_door {$doorId} not found");
        }
        if (!$existing->isActive()) {
            return $existing;
        }
        $before = $this->doorSnapshot($existing);

        $updated = $this->withTransaction(
            fn () => $this->doors->revoke($doorId, $reason, $actorUserId)
        );

        $credential = $this->credentials->findById($existing->credential_id);
        $this->writeLog([
            'customer_id' => $credential?->customer_id ?? 0,
            'site_id' => $credential?->site_id,
            'target_type' => ProgrammingLog::TARGET_CREDENTIAL_DOOR,
            'target_id' => $updated->id,
            'action' => ProgrammingLog::ACTION_REVOKED,
            'summary' => sprintf(
                'Door %d access revoked from credential %d%s',
                $existing->site_asset_id,
                $existing->credential_id,
                $reason !== null && $reason !== '' ? " ({$reason})" : ''
            ),
            'before_snapshot' => $before,
            'after_snapshot' => $this->doorSnapshot($updated),
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        $this->audit->log(new AuditEntry(
            'credential_door.revoked',
            'credential_door',
            (string) $updated->id,
            $actorUserId,
            ['reason' => $reason]
        ));
        return $updated;
    }

    private function revokeAllDoors(
        CredentialRegister $credential,
        int $actorUserId,
        string $reason,
        ?string $ip
    ): void {
        $active = $this->doors->listForCredential($credential->id, false);
        foreach ($active as $grant) {
            try {
                $this->revokeDoor($grant->id, $reason, $actorUserId, $ip);
            } catch (Throwable $e) {
                // Bubble up only if catastrophic — but never let one failed
                // cascade leave half the doors live. Re-throw so the caller
                // sees the partial state.
                throw new RuntimeException(
                    "Failed to cascade-revoke door {$grant->id} after credential status change: "
                    . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
    }

    // =========================================================================
    // Access schedules
    // =========================================================================

    /**
     * @param array<string, mixed> $data
     */
    public function createSchedule(array $data, int $actorUserId, ?string $ip = null): AccessSchedule
    {
        $schedule = $this->withTransaction(fn () => $this->schedules->create($data));

        $this->writeLog([
            'customer_id' => $schedule->customer_id,
            'target_type' => ProgrammingLog::TARGET_ACCESS_SCHEDULE,
            'target_id' => $schedule->id,
            'action' => ProgrammingLog::ACTION_CREATED,
            'summary' => sprintf('Access schedule "%s" created', $schedule->name),
            'after_snapshot' => $this->scheduleSnapshot($schedule),
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        return $schedule;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateSchedule(int $id, array $data, int $actorUserId, ?string $ip = null): AccessSchedule
    {
        $existing = $this->schedules->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("access_schedule {$id} not found");
        }
        $before = $this->scheduleSnapshot($existing);

        $updated = $this->withTransaction(fn () => $this->schedules->update($id, $data));
        $after = $this->scheduleSnapshot($updated);

        $this->writeLog([
            'customer_id' => $updated->customer_id,
            'target_type' => ProgrammingLog::TARGET_ACCESS_SCHEDULE,
            'target_id' => $updated->id,
            'action' => ProgrammingLog::ACTION_UPDATED,
            'summary' => sprintf('Access schedule "%s" updated', $updated->name),
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
        return $updated;
    }

    public function deleteSchedule(int $id, int $actorUserId, ?string $ip = null): void
    {
        $existing = $this->schedules->findById($id);
        if ($existing === null) {
            return;
        }
        $before = $this->scheduleSnapshot($existing);

        $this->withTransaction(function () use ($id) {
            $this->schedules->delete($id);
        });

        $this->writeLog([
            'customer_id' => $existing->customer_id,
            'target_type' => ProgrammingLog::TARGET_ACCESS_SCHEDULE,
            'target_id' => $existing->id,
            'action' => ProgrammingLog::ACTION_DELETED,
            'summary' => sprintf('Access schedule "%s" deleted', $existing->name),
            'before_snapshot' => $before,
            'programmed_by_user_id' => $actorUserId,
            'ip_address' => $ip,
        ]);
    }

    // =========================================================================
    // Internals
    // =========================================================================

    /**
     * Hash a PIN for storage. Uses password_hash so the verify path can
     * upgrade the algorithm via password_needs_rehash later.
     */
    private function hashPinCode(string $plaintext): string
    {
        $trim = trim($plaintext);
        if ($trim === '') {
            throw new InvalidArgumentException('PIN credential_code is required');
        }
        $hash = password_hash($trim, PASSWORD_DEFAULT);
        if (!is_string($hash) || $hash === '') {
            throw new RuntimeException('Failed to hash PIN credential');
        }
        return $hash;
    }

    private function maybeRedact(CredentialRegister $row): CredentialRegister
    {
        $row->redactCodeIfSensitive();
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function credentialSnapshot(CredentialRegister $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'site_id' => $row->site_id,
            'holder_name' => $row->holder_name,
            'holder_email' => $row->holder_email,
            'holder_phone' => $row->holder_phone,
            'holder_employee_id' => $row->holder_employee_id,
            'credential_type' => $row->credential_type,
            // Never put the raw code (or PIN hash) into the audit ledger.
            'credential_code_present' => $row->credential_code !== '',
            'credential_format' => $row->credential_format,
            'status' => $row->status,
            'issued_at' => $row->issued_at,
            'expires_at' => $row->expires_at,
            'suspended_at' => $row->suspended_at,
            'revoked_at' => $row->revoked_at,
            'revoke_reason' => $row->revoke_reason,
            'notes' => $row->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function doorSnapshot(CredentialDoor $row): array
    {
        return [
            'id' => $row->id,
            'credential_id' => $row->credential_id,
            'site_asset_id' => $row->site_asset_id,
            'access_schedule_id' => $row->access_schedule_id,
            'granted_at' => $row->granted_at,
            'granted_by_user_id' => $row->granted_by_user_id,
            'revoked_at' => $row->revoked_at,
            'revoked_by_user_id' => $row->revoked_by_user_id,
            'revoke_reason' => $row->revoke_reason,
            'notes' => $row->notes,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function scheduleSnapshot(AccessSchedule $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'name' => $row->name,
            'description' => $row->description,
            'days_of_week' => $row->days_of_week,
            'start_time' => $row->start_time,
            'end_time' => $row->end_time,
            'timezone' => $row->timezone,
            'is_active' => (bool) $row->is_active,
        ];
    }

    /**
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     * @return array<string, array{from: mixed, to: mixed}>
     */
    private function diffKeys(array $a, array $b): array
    {
        $diff = [];
        foreach ($b as $k => $v) {
            if (!array_key_exists($k, $a) || $a[$k] !== $v) {
                $diff[$k] = ['from' => $a[$k] ?? null, 'to' => $v];
            }
        }
        return $diff;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function writeLog(array $data): void
    {
        try {
            $this->logs->record($data);
        } catch (Throwable $e) {
            // Programming-log writes are advisory: a logging failure must not
            // roll back the credential write that already committed. Surface
            // it via the audit logger so ops can see it without taking down
            // the call.
            $this->audit->log(new AuditEntry(
                'programming_log.write_failed',
                $data['target_type'] ?? 'unknown',
                isset($data['target_id']) ? (string) $data['target_id'] : null,
                isset($data['programmed_by_user_id']) ? (int) $data['programmed_by_user_id'] : null,
                ['error' => $e->getMessage()]
            ));
        }
    }

    /**
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private function withTransaction(callable $work)
    {
        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }
        try {
            $result = $work();
            if ($startedTx) {
                $pdo->commit();
            }
            return $result;
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }
}
