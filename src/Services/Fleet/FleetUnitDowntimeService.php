<?php

namespace App\Services\Fleet;

use App\Database\Connection;
use App\Models\FleetUnit;
use App\Models\FleetUnitDowntime;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7.3 of docs/expansion-plan.md — downtime tracking.
 *
 * Runs alongside (not inside) FleetUnitService so the unit CRUD path
 * stays tight. Public surface:
 *
 *   * startDowntime  — open a window + flip fleet_units.status to
 *     out_of_service (unless retired; retired units can't go back
 *     out-of-service). Transactionally closes any prior open window
 *     first so a second start with no explicit end doesn't leave two
 *     concurrent open rows.
 *
 *   * endDowntime    — stamp ended_at on the current open window. Flips
 *     status back to active when no other windows remain open and the
 *     unit is still in operational state. Manually-retired units stay
 *     retired; manual out_of_service flips (set via updateUnit) that
 *     weren't opened via startDowntime are left alone because there's
 *     no window to close against them.
 *
 *   * listDowntime   — full history for a unit, newest first.
 *
 *   * currentDowntime — the unit's open window if any, for detail views.
 *
 * Gates: fleet.view for reads, fleet.manage for writes (same gates as
 * the rest of Phase 7; a manager who can edit the unit can take it out
 * of service).
 */
class FleetUnitDowntimeService
{
    public const NOTES_MAX_LEN = 1000;

    public function __construct(
        private readonly Connection $connection,
        private readonly FleetUnitRepository $units,
        private readonly FleetUnitDowntimeRepository $downtime,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function startDowntime(User $actor, int $unitId, array $input): array
    {
        $this->gate->assert($actor, 'fleet.manage');
        $unit = $this->requireUnit($unitId);
        if ($unit->status === FleetUnit::STATUS_RETIRED) {
            // Retired units have no operational window to take offline.
            // Force the caller to un-retire first rather than silently
            // bouncing the unit back into out_of_service.
            throw new InvalidArgumentException(
                'cannot start downtime on a retired unit — un-retire first'
            );
        }
        $fields = $this->validateStartInput($input);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            // Close any prior open window at the new start time so the
            // history is contiguous (no "was broken down for 12 hours"
            // phantom overlap when a user forgets to end the prior one).
            $this->downtime->closeOpenForUnit($unitId, $fields['started_at'], $actor->id);

            $newId = $this->downtime->create([
                'fleet_unit_id' => $unitId,
                'reason' => $fields['reason'],
                'started_at' => $fields['started_at'],
                'ended_at' => null,
                'notes' => $fields['notes'],
                'started_by_user_id' => $actor->id,
                'ended_by_user_id' => null,
            ]);

            // Flip the unit status only when it isn't already
            // out_of_service — avoids an unnecessary UPDATE + audit flap
            // on the common "start a new window while prior was still
            // open" path.
            if ($unit->status !== FleetUnit::STATUS_OUT_OF_SERVICE) {
                $this->units->setStatus($unitId, FleetUnit::STATUS_OUT_OF_SERVICE);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'fleet.downtime.started',
            'fleet_unit_downtime',
            $newId,
            $actor->id,
            [
                'company_id' => $unit->company_id,
                'fleet_unit_id' => $unitId,
                'reason' => $fields['reason'],
                'started_at' => $fields['started_at'],
            ],
        ));

        $created = $this->downtime->findById($newId);
        if ($created === null) {
            throw new RuntimeException("downtime {$newId} vanished after insert");
        }
        return $this->serialize($created);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function endDowntime(User $actor, int $unitId, array $input): array
    {
        $this->gate->assert($actor, 'fleet.manage');
        $unit = $this->requireUnit($unitId);

        $open = $this->downtime->findOpenForUnit($unitId);
        if ($open === null) {
            throw new InvalidArgumentException('no open downtime on this unit');
        }

        $endedAt = $this->resolveEndedAt($input, $open->started_at);
        $notes = $this->mergeNotes($open->notes, $input);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $closed = $this->downtime->closeOpenForUnit($unitId, $endedAt, $actor->id);
            if ($closed === 0) {
                // Race: someone else just closed it between our read
                // and our write. Fail loud so the caller can refresh.
                throw new RuntimeException(
                    "concurrent downtime close detected on unit {$unitId}"
                );
            }

            if ($notes !== null && $notes !== $open->notes) {
                $this->downtime->appendNotes($open->id, $notes);
            }

            // Flip back to active only when no other open windows
            // remain AND the unit hasn't been manually retired in the
            // meantime. Retired wins — a retired unit stays retired
            // even if its downtime windows happen to close.
            $remaining = $this->downtime->countOpenForUnit($unitId);
            if (
                $remaining === 0
                && $unit->status === FleetUnit::STATUS_OUT_OF_SERVICE
            ) {
                $this->units->setStatus($unitId, FleetUnit::STATUS_ACTIVE);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'fleet.downtime.ended',
            'fleet_unit_downtime',
            $open->id,
            $actor->id,
            [
                'company_id' => $unit->company_id,
                'fleet_unit_id' => $unitId,
                'reason' => $open->reason,
                'started_at' => $open->started_at,
                'ended_at' => $endedAt,
            ],
        ));

        $fresh = $this->downtime->findById($open->id);
        if ($fresh === null) {
            throw new RuntimeException("downtime {$open->id} vanished after close");
        }
        return $this->serialize($fresh);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listDowntime(User $actor, int $unitId, int $limit = 100): array
    {
        $this->gate->assert($actor, 'fleet.view');
        $this->requireUnit($unitId);
        return array_map(
            fn(FleetUnitDowntime $d) => $this->serialize($d),
            $this->downtime->listForUnit($unitId, $limit),
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    public function currentDowntime(User $actor, int $unitId): ?array
    {
        $this->gate->assert($actor, 'fleet.view');
        $this->requireUnit($unitId);
        $open = $this->downtime->findOpenForUnit($unitId);
        return $open !== null ? $this->serialize($open) : null;
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array{reason: string, started_at: string, notes: ?string}
     */
    private function validateStartInput(array $input): array
    {
        $reason = isset($input['reason']) && is_string($input['reason']) && $input['reason'] !== ''
            ? $input['reason']
            : FleetUnitDowntime::REASON_BREAKDOWN;
        if (!in_array($reason, FleetUnitDowntime::ALLOWED_REASONS, true)) {
            throw new InvalidArgumentException(
                'reason must be one of ' . implode(',', FleetUnitDowntime::ALLOWED_REASONS)
            );
        }

        $startedRaw = isset($input['started_at']) && is_string($input['started_at'])
            ? trim($input['started_at'])
            : '';
        $started = $startedRaw !== ''
            ? $this->normalizeDateTime($startedRaw, 'started_at')
            : $this->nowStamp();

        $notes = null;
        if (isset($input['notes']) && is_string($input['notes'])) {
            $notes = trim($input['notes']);
            if ($notes === '') {
                $notes = null;
            } elseif (strlen($notes) > self::NOTES_MAX_LEN) {
                throw new InvalidArgumentException(
                    'notes exceeds ' . self::NOTES_MAX_LEN . ' chars'
                );
            }
        }

        return ['reason' => $reason, 'started_at' => $started, 'notes' => $notes];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function resolveEndedAt(array $input, string $startedAt): string
    {
        if (isset($input['ended_at']) && is_string($input['ended_at']) && trim($input['ended_at']) !== '') {
            $ended = $this->normalizeDateTime($input['ended_at'], 'ended_at');
        } else {
            $ended = $this->nowStamp();
        }
        if (strcmp($ended, $startedAt) < 0) {
            throw new InvalidArgumentException('ended_at must be >= started_at');
        }
        return $ended;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function mergeNotes(?string $existing, array $input): ?string
    {
        if (!isset($input['notes']) || !is_string($input['notes'])) {
            return $existing;
        }
        $appended = trim($input['notes']);
        if ($appended === '') {
            return $existing;
        }
        if (strlen($appended) > self::NOTES_MAX_LEN) {
            throw new InvalidArgumentException(
                'notes exceeds ' . self::NOTES_MAX_LEN . ' chars'
            );
        }
        if ($existing === null || $existing === '') {
            return $appended;
        }
        $combined = $existing . "\n" . $appended;
        if (strlen($combined) > self::NOTES_MAX_LEN) {
            // Prefer keeping the freshest note rather than the oldest —
            // the close-out note usually supersedes the start-time
            // note anyway.
            return substr($combined, -self::NOTES_MAX_LEN);
        }
        return $combined;
    }

    private function requireUnit(int $unitId): FleetUnit
    {
        if ($unitId <= 0) {
            throw new InvalidArgumentException('fleet unit id is required');
        }
        $unit = $this->units->findById($unitId);
        if ($unit === null) {
            throw new InvalidArgumentException("fleet unit {$unitId} not found");
        }
        return $unit;
    }

    private function normalizeDateTime(string $raw, string $field): string
    {
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d H:i:s');
        } catch (Exception $e) {
            throw new InvalidArgumentException("{$field} is not a valid datetime: " . $e->getMessage());
        }
    }

    private function nowStamp(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FleetUnitDowntime $d): array
    {
        return [
            'id' => $d->id,
            'fleet_unit_id' => $d->fleet_unit_id,
            'reason' => $d->reason,
            'started_at' => $d->started_at,
            'ended_at' => $d->ended_at,
            'is_open' => $d->ended_at === null,
            'duration_minutes' => $this->durationMinutes($d),
            'notes' => $d->notes,
            'started_by_user_id' => $d->started_by_user_id,
            'ended_by_user_id' => $d->ended_by_user_id,
            'created_at' => $d->created_at,
            'updated_at' => $d->updated_at,
        ];
    }

    private function durationMinutes(FleetUnitDowntime $d): ?int
    {
        if ($d->started_at === '') {
            return null;
        }
        try {
            $start = new DateTimeImmutable($d->started_at);
            $end = $d->ended_at !== null
                ? new DateTimeImmutable($d->ended_at)
                : new DateTimeImmutable();
        } catch (Exception) {
            return null;
        }
        $delta = $end->getTimestamp() - $start->getTimestamp();
        if ($delta < 0) {
            return 0;
        }
        return (int) floor($delta / 60);
    }
}
