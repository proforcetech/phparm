<?php

namespace App\Services\Security;

use App\Models\AccessSchedule;
use App\Models\CredentialDoor;
use App\Models\CredentialRegister;
use App\Models\ProgrammingLog;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/security/credentials, /api/security/access-schedules,
 * /api/security/programming-logs — Phase 16 / S1 of docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   security_credentials.view   — read credentials, doors, schedules, audit
 *   security_credentials.manage — create/edit credentials, grant/revoke
 *                                  doors, manage schedules
 *
 * All write paths route through CredentialRegisterService so the
 * programming_logs ledger stays in sync with the row state.
 */
class CredentialRegisterController
{
    public function __construct(
        private readonly CredentialRegisterRepository $credentials,
        private readonly CredentialDoorRepository $doors,
        private readonly AccessScheduleRepository $schedules,
        private readonly ProgrammingLogRepository $logs,
        private readonly CredentialRegisterService $service,
        private readonly AccessGate $gate,
    ) {
    }

    // ---- credentials ------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{credentials: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'security_credentials.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->credentials->list($filters, $perPage, $offset);
        $total = $this->credentials->count($filters);

        return [
            'credentials' => array_map(
                fn (CredentialRegister $row) => self::credentialToArray($this->redact($row)),
                $rows
            ),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->gate->assert($user, 'security_credentials.view');

        $credential = $this->credentials->findById($id);
        if ($credential === null) {
            throw new InvalidArgumentException("credential {$id} not found");
        }
        $payload = self::credentialToArray($this->redact($credential));
        $payload['doors'] = array_map(
            [self::class, 'doorToArray'],
            $this->doors->listForCredential($id)
        );
        $payload['programming_logs'] = array_map(
            [self::class, 'logToArray'],
            $this->logs->listForTarget(ProgrammingLog::TARGET_CREDENTIAL, $id, 100)
        );
        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body, ?string $ip = null): array
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $credential = $this->service->createCredential($body, (int) $user->id, $ip);
        return self::credentialToArray($credential);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body, ?string $ip = null): array
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $credential = $this->service->updateCredential($id, $body, (int) $user->id, $ip);
        return self::credentialToArray($credential);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function changeStatus(User $user, int $id, array $body, ?string $ip = null): array
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $status = trim((string) ($body['status'] ?? ''));
        if ($status === '') {
            throw new InvalidArgumentException('status is required');
        }
        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;
        if ($reason === '') {
            $reason = null;
        }
        $credential = $this->service->markStatus($id, $status, $reason, (int) $user->id, $ip);
        return self::credentialToArray($credential);
    }

    public function destroy(User $user, int $id, ?string $ip = null): void
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $this->service->deleteCredential($id, (int) $user->id, $ip);
    }

    // ---- doors ------------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDoors(User $user, int $credentialId): array
    {
        $this->gate->assert($user, 'security_credentials.view');

        if ($this->credentials->findById($credentialId) === null) {
            throw new InvalidArgumentException("credential {$credentialId} not found");
        }
        return array_map(
            [self::class, 'doorToArray'],
            $this->doors->listForCredential($credentialId)
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function grantDoor(User $user, int $credentialId, array $body, ?string $ip = null): array
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $grant = $this->service->grantDoor($credentialId, $body, (int) $user->id, $ip);
        return self::doorToArray($grant);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateDoor(User $user, int $doorId, array $body, ?string $ip = null): array
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $door = $this->service->updateDoor($doorId, $body, (int) $user->id, $ip);
        return self::doorToArray($door);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function revokeDoor(User $user, int $doorId, array $body, ?string $ip = null): array
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $reason = isset($body['reason']) ? trim((string) $body['reason']) : null;
        if ($reason === '') {
            $reason = null;
        }
        $door = $this->service->revokeDoor($doorId, $reason, (int) $user->id, $ip);
        return self::doorToArray($door);
    }

    // ---- schedules --------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{access_schedules: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listSchedules(User $user, array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $this->gate->assert($user, 'security_credentials.view');

        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->schedules->list($filters, $perPage, $offset);
        $total = $this->schedules->count($filters);

        return [
            'access_schedules' => array_map([self::class, 'scheduleToArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function showSchedule(User $user, int $id): array
    {
        $this->gate->assert($user, 'security_credentials.view');

        $schedule = $this->schedules->findById($id);
        if ($schedule === null) {
            throw new InvalidArgumentException("access_schedule {$id} not found");
        }
        return self::scheduleToArray($schedule);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function storeSchedule(User $user, array $body, ?string $ip = null): array
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $schedule = $this->service->createSchedule($body, (int) $user->id, $ip);
        return self::scheduleToArray($schedule);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateSchedule(User $user, int $id, array $body, ?string $ip = null): array
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $schedule = $this->service->updateSchedule($id, $body, (int) $user->id, $ip);
        return self::scheduleToArray($schedule);
    }

    public function destroySchedule(User $user, int $id, ?string $ip = null): void
    {
        $this->gate->assert($user, 'security_credentials.manage');

        $this->service->deleteSchedule($id, (int) $user->id, $ip);
    }

    // ---- programming-log audit feed --------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{programming_logs: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listLogs(User $user, array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $this->gate->assert($user, 'security_credentials.view');

        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->logs->list($filters, $perPage, $offset);
        $total = $this->logs->count($filters);

        return [
            'programming_logs' => array_map([self::class, 'logToArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    // ---- helpers ----------------------------------------------------------

    private function redact(CredentialRegister $row): CredentialRegister
    {
        $row->redactCodeIfSensitive();
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    public static function credentialToArray(CredentialRegister $row): array
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
            'credential_code' => $row->credential_code,
            'credential_format' => $row->credential_format,
            'status' => $row->status,
            'is_terminal' => $row->isTerminal(),
            'is_expired' => $row->isExpired(),
            'issued_at' => $row->issued_at,
            'expires_at' => $row->expires_at,
            'suspended_at' => $row->suspended_at,
            'revoked_at' => $row->revoked_at,
            'revoke_reason' => $row->revoke_reason,
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
            'created_by_user_id' => $row->created_by_user_id,
            'updated_by_user_id' => $row->updated_by_user_id,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function doorToArray(CredentialDoor $row): array
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
            'is_active' => $row->isActive(),
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function scheduleToArray(AccessSchedule $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'name' => $row->name,
            'description' => $row->description,
            'days_of_week' => $row->days_of_week,
            'days' => $row->days(),
            'start_time' => $row->start_time,
            'end_time' => $row->end_time,
            'timezone' => $row->timezone,
            'is_active' => (bool) $row->is_active,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function logToArray(ProgrammingLog $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'site_id' => $row->site_id,
            'target_type' => $row->target_type,
            'target_id' => $row->target_id,
            'action' => $row->action,
            'summary' => $row->summary,
            'before_snapshot' => $row->before_snapshot,
            'after_snapshot' => $row->after_snapshot,
            'programmed_at' => $row->programmed_at,
            'programmed_by_user_id' => $row->programmed_by_user_id,
            'programmed_by_external' => $row->programmed_by_external,
            'ip_address' => $row->ip_address,
            'created_at' => $row->created_at,
        ];
    }
}
