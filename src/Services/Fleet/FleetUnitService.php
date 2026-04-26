<?php

namespace App\Services\Fleet;

use App\Database\Connection;
use App\Models\FleetUnit;
use App\Models\FleetUnitAssignment;
use App\Models\FleetUnitReading;
use App\Models\User;
use App\Services\Crm\CompanyRepository;
use App\Services\Crm\SiteRepository;
use App\Services\Customer\CustomerRepository;
use App\Services\Pm\PmFleetInheritanceService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use PDOException;
use RuntimeException;

/**
 * Phase 7.1 of docs/expansion-plan.md — fleet maintenance core.
 *
 * Surfaces:
 *   * CRUD on fleet_units (create/get/list/update/retire), gated on
 *     fleet.manage (write) / fleet.view (read).
 *   * Append-only meter history (recordReading / listReadings) with
 *     a monotonic invariant — odometers don't go backwards, and engine
 *     hours don't either, unless caller explicitly passes
 *     allow_decrease=true (e.g., meter swap, rollover).
 *   * Append-only assignment history (assignUnit / endAssignment /
 *     listAssignments). opening a new assignment transactionally
 *     closes the prior current so there's at most one active row.
 *
 * company_id on every unit is the scope anchor — readings/assignments
 * cascade via the unit FK so they inherit the same tenancy boundary.
 * Staff with fleet.view on any company reach any unit; the product
 * scopes at the UI by company filter. (Separate per-company gates are
 * a future-phase concern once multi-company admins exist.)
 */
class FleetUnitService
{
    public const VIN_MAX_LEN = 17;
    public const UNIT_NUMBER_MAX_LEN = 40;
    public const NOTES_MAX_LEN = 500;
    public const MIN_YEAR = 1900;
    public const MAX_YEAR = 2100;

    public function __construct(
        private readonly Connection $connection,
        private readonly FleetUnitRepository $units,
        private readonly FleetUnitReadingRepository $readings,
        private readonly FleetUnitAssignmentRepository $assignments,
        private readonly CompanyRepository $companies,
        private readonly SiteRepository $sites,
        private readonly CustomerRepository $customers,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
        // Phase 7.2 hook — nullable so tests / early deploys can skip PM
        // wiring. When supplied, createUnit spawns inherited schedules
        // and recordReading advances meter-driven ones.
        private readonly ?PmFleetInheritanceService $pmInheritance = null,
    ) {
    }

    // ── Unit CRUD ────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createUnit(User $actor, int $companyId, array $input): array
    {
        $this->gate->assert($actor, 'fleet.manage');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company id is required');
        }
        if ($this->companies->findById($companyId) === null) {
            throw new InvalidArgumentException("company {$companyId} not found");
        }
        $fields = $this->validateUnitInput($input, $companyId, isCreate: true);

        try {
            $unitId = $this->units->create(array_merge(
                $fields,
                ['company_id' => $companyId, 'created_by_user_id' => $actor->id],
            ));
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new InvalidArgumentException(
                    'unit_number or vin is already in use'
                );
            }
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'fleet.unit.created',
            'fleet_unit',
            $unitId,
            $actor->id,
            [
                'company_id' => $companyId,
                'unit_number' => $fields['unit_number'],
                'unit_type' => $fields['unit_type'],
                'has_vin' => $fields['vin'] !== null,
            ],
        ));

        $unit = $this->requireUnit($unitId);
        // Phase 7.2: materialize PM schedules inherited from unit-type or
        // unit-specific bindings. Swallow hook failures so a PM config
        // glitch can't block unit creation itself.
        if ($this->pmInheritance !== null) {
            try {
                $this->pmInheritance->ensureSchedulesForUnit($actor, $unit);
            } catch (\Throwable) {
                // best-effort; audit trail for the unit has already committed
            }
        }
        return $this->serializeUnit($unit);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateUnit(User $actor, int $unitId, array $input): array
    {
        $this->gate->assert($actor, 'fleet.manage');
        $unit = $this->requireUnit($unitId);

        // Merge existing values with the patch so callers can send a
        // partial body without accidentally nulling fields they didn't
        // pass. This is the usual PHP-ish "partial PUT" shape.
        $merged = array_merge($this->unitToArray($unit), $input);
        $fields = $this->validateUnitInput($merged, $unit->company_id, isCreate: false);

        try {
            $this->units->update($unitId, $fields);
        } catch (PDOException $e) {
            if ($this->isUniqueViolation($e)) {
                throw new InvalidArgumentException(
                    'unit_number or vin collides with another fleet unit'
                );
            }
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'fleet.unit.updated',
            'fleet_unit',
            $unitId,
            $actor->id,
            ['company_id' => $unit->company_id, 'unit_number' => $fields['unit_number']],
        ));

        return $this->serializeUnit($this->requireUnit($unitId));
    }

    /**
     * Soft-retire a fleet unit. We never hard-delete because readings +
     * assignments carry business history; retiring flips status so
     * list-by-default filters it out.
     */
    public function retireUnit(User $actor, int $unitId): void
    {
        $this->gate->assert($actor, 'fleet.manage');
        $unit = $this->requireUnit($unitId);
        if ($unit->status === FleetUnit::STATUS_RETIRED) {
            return;
        }
        $this->units->update($unitId, array_merge(
            $this->unitToArray($unit),
            ['status' => FleetUnit::STATUS_RETIRED],
        ));
        // End the current assignment alongside retirement so the history
        // doesn't carry a "currently-assigned retired unit" forever.
        $this->assignments->closeCurrent($unitId, $this->nowStamp());

        $this->audit->log(new AuditEntry(
            'fleet.unit.retired',
            'fleet_unit',
            $unitId,
            $actor->id,
            ['company_id' => $unit->company_id, 'unit_number' => $unit->unit_number],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function getUnit(User $actor, int $unitId): array
    {
        $this->gate->assert($actor, 'fleet.view');
        $unit = $this->requireUnit($unitId);
        $current = $this->assignments->findCurrentForUnit($unitId);
        return [
            'unit' => $this->serializeUnit($unit),
            'current_assignment' => $current !== null
                ? $this->serializeAssignment($current)
                : null,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listForCompany(User $actor, int $companyId, array $filters): array
    {
        $this->gate->assert($actor, 'fleet.view');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company id is required');
        }
        $result = $this->units->listForCompany($companyId, $filters);
        return [
            'data' => array_map(fn(FleetUnit $u) => $this->serializeUnit($u), $result['data']),
            'total' => $result['total'],
        ];
    }

    // ── Readings ─────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function recordReading(User $actor, int $unitId, array $input): array
    {
        $this->gate->assert($actor, 'fleet.manage');
        $unit = $this->requireUnit($unitId);
        $fields = $this->validateReadingInput($input);

        $allowDecrease = !empty($input['allow_decrease']);
        $latest = $this->readings->findLatestForUnit($unitId, $fields['reading_type']);
        if ($latest !== null && $fields['value'] < $latest->value && !$allowDecrease) {
            // Meters don't go backwards under normal operation. Require
            // an explicit override so rollovers/meter-swaps are an
            // informed decision, not a fat-finger.
            throw new InvalidArgumentException(sprintf(
                '%s reading %s is lower than last recorded %s — pass allow_decrease=true to override',
                $fields['reading_type'],
                $this->formatValue($fields['reading_type'], $fields['value']),
                $this->formatValue($fields['reading_type'], $latest->value),
            ));
        }

        // Transactionally insert the reading and bump the unit's
        // denormalized current_* cache so list views stay fast.
        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $readingId = $this->readings->create(array_merge(
                $fields,
                [
                    'fleet_unit_id' => $unitId,
                    'recorded_by_user_id' => $actor->id,
                ],
            ));

            if ($fields['reading_type'] === FleetUnitReading::TYPE_ODOMETER) {
                $this->units->updateMeterCache(
                    $unitId,
                    (int) round($fields['value']),
                    null,
                    $fields['recorded_at'],
                    null,
                );
            } else {
                $this->units->updateMeterCache(
                    $unitId,
                    null,
                    (float) $fields['value'],
                    null,
                    $fields['recorded_at'],
                );
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'fleet.reading.recorded',
            'fleet_unit_reading',
            $readingId,
            $actor->id,
            [
                'company_id' => $unit->company_id,
                'fleet_unit_id' => $unitId,
                'reading_type' => $fields['reading_type'],
                'value' => $fields['value'],
                'source' => $fields['source'],
                'workorder_id' => $fields['workorder_id'],
                'allow_decrease' => $allowDecrease,
            ],
        ));

        $created = $this->readings->findById($readingId);
        if ($created === null) {
            throw new RuntimeException("reading {$readingId} vanished after insert");
        }

        // Phase 7.2: refresh the unit to pick up the bumped current_* cache
        // and hand it to the PM meter-inheritance hook. Swallow hook errors
        // so a downstream PM misconfiguration can't roll back a valid reading.
        if ($this->pmInheritance !== null) {
            try {
                $refreshed = $this->units->findById($unitId) ?? $unit;
                $this->pmInheritance->onReadingRecorded($refreshed, $created);
            } catch (\Throwable) {
                // best-effort; the reading itself has already committed
            }
        }

        return $this->serializeReading($created);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listReadings(User $actor, int $unitId, ?string $readingType, int $limit): array
    {
        $this->gate->assert($actor, 'fleet.view');
        $this->requireUnit($unitId);
        if ($readingType !== null && !in_array($readingType, FleetUnitReading::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                'reading_type must be one of ' . implode(',', FleetUnitReading::ALLOWED_TYPES)
            );
        }
        $rows = $this->readings->listForUnit($unitId, $readingType, $limit);
        return array_map(fn(FleetUnitReading $r) => $this->serializeReading($r), $rows);
    }

    // ── Assignments ──────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function assignUnit(User $actor, int $unitId, array $input): array
    {
        $this->gate->assert($actor, 'fleet.manage');
        $unit = $this->requireUnit($unitId);
        $fields = $this->validateAssignmentInput($input, $unit->company_id);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            // Close any currently-active assignment with this new
            // assignment's start time as the end stamp so history is
            // contiguous (no "everyone's driver for 12 hours" gap if
            // users don't explicitly end the prior assignment first).
            $this->assignments->closeCurrent($unitId, $fields['assigned_from']);

            $newId = $this->assignments->create(array_merge(
                $fields,
                [
                    'fleet_unit_id' => $unitId,
                    'created_by_user_id' => $actor->id,
                ],
            ));
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'fleet.assignment.opened',
            'fleet_unit_assignment',
            $newId,
            $actor->id,
            [
                'company_id' => $unit->company_id,
                'fleet_unit_id' => $unitId,
                'assignment_type' => $fields['assignment_type'],
                'assigned_user_id' => $fields['assigned_user_id'],
                'assigned_site_id' => $fields['assigned_site_id'],
                'customer_id' => $fields['customer_id'],
            ],
        ));

        $created = $this->assignments->findById($newId);
        if ($created === null) {
            throw new RuntimeException("assignment {$newId} vanished after insert");
        }
        return $this->serializeAssignment($created);
    }

    public function endAssignment(User $actor, int $unitId, ?string $endedAt): void
    {
        $this->gate->assert($actor, 'fleet.manage');
        $unit = $this->requireUnit($unitId);
        $current = $this->assignments->findCurrentForUnit($unitId);
        if ($current === null) {
            throw new InvalidArgumentException('no active assignment on this unit');
        }

        $stamp = $endedAt !== null
            ? $this->normalizeDateTime($endedAt, 'ended_at')
            : $this->nowStamp();

        $ok = $this->assignments->closeCurrent($unitId, $stamp);
        if (!$ok) {
            // Race: someone else just closed it between our read and
            // our write. Fail loud so the caller can refresh + retry.
            throw new RuntimeException(
                "concurrent assignment close detected on unit {$unitId}"
            );
        }

        $this->audit->log(new AuditEntry(
            'fleet.assignment.ended',
            'fleet_unit_assignment',
            $current->id,
            $actor->id,
            [
                'company_id' => $unit->company_id,
                'fleet_unit_id' => $unitId,
                'ended_at' => $stamp,
            ],
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAssignments(User $actor, int $unitId): array
    {
        $this->gate->assert($actor, 'fleet.view');
        $this->requireUnit($unitId);
        return array_map(
            fn(FleetUnitAssignment $a) => $this->serializeAssignment($a),
            $this->assignments->listForUnit($unitId),
        );
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateUnitInput(array $input, int $companyId, bool $isCreate): array
    {
        $unitNumber = $this->trimmedString($input, 'unit_number');
        if ($unitNumber === null || $unitNumber === '') {
            throw new InvalidArgumentException('unit_number is required');
        }
        if (strlen($unitNumber) > self::UNIT_NUMBER_MAX_LEN) {
            throw new InvalidArgumentException('unit_number exceeds ' . self::UNIT_NUMBER_MAX_LEN . ' chars');
        }

        $unitType = $this->trimmedString($input, 'unit_type') ?? FleetUnit::TYPE_TRUCK;
        if (!in_array($unitType, FleetUnit::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                'unit_type must be one of ' . implode(',', FleetUnit::ALLOWED_TYPES)
            );
        }

        $meterType = $this->trimmedString($input, 'meter_type') ?? FleetUnit::METER_ODOMETER;
        if (!in_array($meterType, FleetUnit::ALLOWED_METERS, true)) {
            throw new InvalidArgumentException(
                'meter_type must be one of ' . implode(',', FleetUnit::ALLOWED_METERS)
            );
        }

        $status = $this->trimmedString($input, 'status') ?? FleetUnit::STATUS_ACTIVE;
        if (!in_array($status, FleetUnit::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException(
                'status must be one of ' . implode(',', FleetUnit::ALLOWED_STATUSES)
            );
        }

        $vin = $this->trimmedString($input, 'vin');
        if ($vin !== null) {
            $vin = strtoupper($vin);
            if (!preg_match('/^[A-HJ-NPR-Z0-9]+$/', $vin) || strlen($vin) > self::VIN_MAX_LEN) {
                // VIN spec excludes I, O, Q. Length <=17 (modern) but
                // some pre-1981 vehicles use shorter VINs, so don't
                // insist on 17 exactly — just cap at the column max.
                throw new InvalidArgumentException(
                    'vin must be uppercase alphanumeric (no I/O/Q) and <= ' . self::VIN_MAX_LEN . ' chars'
                );
            }
        }

        $year = null;
        if (array_key_exists('year', $input) && $input['year'] !== null && $input['year'] !== '') {
            if (!is_numeric($input['year'])) {
                throw new InvalidArgumentException('year must be numeric');
            }
            $year = (int) $input['year'];
            if ($year < self::MIN_YEAR || $year > self::MAX_YEAR) {
                throw new InvalidArgumentException('year must be between ' . self::MIN_YEAR . ' and ' . self::MAX_YEAR);
            }
        }

        $homeSiteId = null;
        if (array_key_exists('home_site_id', $input) && $input['home_site_id'] !== null && $input['home_site_id'] !== '') {
            $homeSiteId = (int) $input['home_site_id'];
            if ($homeSiteId <= 0) {
                throw new InvalidArgumentException('home_site_id must be positive');
            }
            $site = $this->sites->findById($homeSiteId);
            if ($site === null) {
                throw new InvalidArgumentException("home_site_id {$homeSiteId} not found");
            }
            if ((int) ($site->company_id ?? 0) !== $companyId) {
                throw new InvalidArgumentException('home_site_id belongs to a different company');
            }
        }

        $make = $this->trimmedStringCapped($input, 'make', 120);
        $model = $this->trimmedStringCapped($input, 'model', 120);
        $trim = $this->trimmedStringCapped($input, 'trim', 120);
        $plate = $this->trimmedStringCapped($input, 'license_plate', 30);
        $notes = $this->trimmedStringCapped($input, 'notes', 2000);

        return [
            'unit_number' => $unitNumber,
            'unit_type' => $unitType,
            'vin' => $vin,
            'year' => $year,
            'make' => $make,
            'model' => $model,
            'trim' => $trim,
            'license_plate' => $plate,
            'home_site_id' => $homeSiteId,
            'meter_type' => $meterType,
            'status' => $status,
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array{reading_type: string, value: float, recorded_at: string, source: string, workorder_id: ?int, notes: ?string}
     */
    private function validateReadingInput(array $input): array
    {
        $type = $this->trimmedString($input, 'reading_type');
        if ($type === null || !in_array($type, FleetUnitReading::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                'reading_type must be one of ' . implode(',', FleetUnitReading::ALLOWED_TYPES)
            );
        }

        if (!array_key_exists('value', $input) || $input['value'] === null || $input['value'] === '') {
            throw new InvalidArgumentException('value is required');
        }
        if (!is_numeric($input['value'])) {
            throw new InvalidArgumentException('value must be numeric');
        }
        $value = (float) $input['value'];
        if ($value < 0) {
            throw new InvalidArgumentException('value must be non-negative');
        }

        $recordedAtRaw = $this->trimmedString($input, 'recorded_at');
        if ($recordedAtRaw === null) {
            throw new InvalidArgumentException('recorded_at is required');
        }
        $recordedAt = $this->normalizeDateTime($recordedAtRaw, 'recorded_at');

        $source = $this->trimmedString($input, 'source') ?? FleetUnitReading::SOURCE_MANUAL;
        if (!in_array($source, FleetUnitReading::ALLOWED_SOURCES, true)) {
            throw new InvalidArgumentException(
                'source must be one of ' . implode(',', FleetUnitReading::ALLOWED_SOURCES)
            );
        }

        $workorderId = null;
        if (array_key_exists('workorder_id', $input) && $input['workorder_id'] !== null && $input['workorder_id'] !== '') {
            $workorderId = (int) $input['workorder_id'];
            if ($workorderId <= 0) {
                throw new InvalidArgumentException('workorder_id must be positive');
            }
        }

        $notes = $this->trimmedStringCapped($input, 'notes', self::NOTES_MAX_LEN);

        return [
            'reading_type' => $type,
            'value' => $value,
            'recorded_at' => $recordedAt,
            'source' => $source,
            'workorder_id' => $workorderId,
            'notes' => $notes,
        ];
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    private function validateAssignmentInput(array $input, int $companyId): array
    {
        $type = $this->trimmedString($input, 'assignment_type') ?? FleetUnitAssignment::TYPE_DRIVER;
        if (!in_array($type, FleetUnitAssignment::ALLOWED_TYPES, true)) {
            throw new InvalidArgumentException(
                'assignment_type must be one of ' . implode(',', FleetUnitAssignment::ALLOWED_TYPES)
            );
        }

        $userId = $this->optionalPositiveInt($input, 'assigned_user_id');
        $siteId = $this->optionalPositiveInt($input, 'assigned_site_id');
        $customerId = $this->optionalPositiveInt($input, 'customer_id');

        // Cross-company references leak data — site must belong to the
        // same company as the fleet unit, and customer (used for rental
        // assignments) must also be scoped to that company.
        if ($siteId !== null) {
            $site = $this->sites->findById($siteId);
            if ($site === null) {
                throw new InvalidArgumentException("assigned_site_id {$siteId} not found");
            }
            if ((int) ($site->company_id ?? 0) !== $companyId) {
                throw new InvalidArgumentException('assigned_site_id belongs to a different company');
            }
        }
        if ($customerId !== null) {
            $customer = $this->customers->find($customerId);
            if ($customer === null) {
                throw new InvalidArgumentException("customer_id {$customerId} not found");
            }
            if ((int) ($customer->company_id ?? 0) !== $companyId) {
                throw new InvalidArgumentException('customer_id belongs to a different company');
            }
        }

        // Each assignment_type pins which of the three targets must be
        // present, so callers can't create ambiguous records like
        // "customer_rental with no customer".
        if ($type === FleetUnitAssignment::TYPE_DRIVER && $userId === null) {
            throw new InvalidArgumentException('driver assignment requires assigned_user_id');
        }
        if ($type === FleetUnitAssignment::TYPE_SITE && $siteId === null) {
            throw new InvalidArgumentException('site assignment requires assigned_site_id');
        }
        if ($type === FleetUnitAssignment::TYPE_CUSTOMER_RENTAL && $customerId === null) {
            throw new InvalidArgumentException('customer_rental assignment requires customer_id');
        }

        $fromRaw = $this->trimmedString($input, 'assigned_from');
        $from = $fromRaw !== null
            ? $this->normalizeDateTime($fromRaw, 'assigned_from')
            : $this->nowStamp();

        $until = null;
        $untilRaw = $this->trimmedString($input, 'assigned_until');
        if ($untilRaw !== null) {
            $until = $this->normalizeDateTime($untilRaw, 'assigned_until');
            if (strcmp($until, $from) < 0) {
                throw new InvalidArgumentException('assigned_until must be >= assigned_from');
            }
        }

        $notes = $this->trimmedStringCapped($input, 'notes', self::NOTES_MAX_LEN);

        return [
            'assignment_type' => $type,
            'assigned_user_id' => $userId,
            'assigned_site_id' => $siteId,
            'customer_id' => $customerId,
            'assigned_from' => $from,
            'assigned_until' => $until,
            'notes' => $notes,
        ];
    }

    // ── Helpers ──────────────────────────────────────────────────────────

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
            $dt = new DateTimeImmutable($raw);
        } catch (Exception $e) {
            throw new InvalidArgumentException("{$field} is not a valid datetime: " . $e->getMessage());
        }
        return $dt->format('Y-m-d H:i:s');
    }

    private function nowStamp(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }

    /**
     * @param array<string, mixed> $input
     */
    private function trimmedString(array $input, string $key): ?string
    {
        if (!array_key_exists($key, $input) || $input[$key] === null) {
            return null;
        }
        if (!is_string($input[$key])) {
            if (is_numeric($input[$key])) {
                return (string) $input[$key];
            }
            throw new InvalidArgumentException("{$key} must be a string");
        }
        $v = trim($input[$key]);
        return $v === '' ? null : $v;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function trimmedStringCapped(array $input, string $key, int $cap): ?string
    {
        $v = $this->trimmedString($input, $key);
        if ($v === null) {
            return null;
        }
        if (strlen($v) > $cap) {
            throw new InvalidArgumentException("{$key} exceeds {$cap} chars");
        }
        return $v;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function optionalPositiveInt(array $input, string $key): ?int
    {
        if (!array_key_exists($key, $input) || $input[$key] === null || $input[$key] === '') {
            return null;
        }
        if (!is_numeric($input[$key])) {
            throw new InvalidArgumentException("{$key} must be numeric");
        }
        $v = (int) $input[$key];
        if ($v <= 0) {
            throw new InvalidArgumentException("{$key} must be positive");
        }
        return $v;
    }

    /**
     * @return array<string, mixed>
     */
    private function unitToArray(FleetUnit $u): array
    {
        return [
            'unit_number' => $u->unit_number,
            'unit_type' => $u->unit_type,
            'vin' => $u->vin,
            'year' => $u->year,
            'make' => $u->make,
            'model' => $u->model,
            'trim' => $u->trim,
            'license_plate' => $u->license_plate,
            'home_site_id' => $u->home_site_id,
            'meter_type' => $u->meter_type,
            'status' => $u->status,
            'notes' => $u->notes,
        ];
    }

    private function isUniqueViolation(PDOException $e): bool
    {
        if (($e->getCode() ?? '') === '23000') {
            return true;
        }
        $msg = $e->getMessage();
        return stripos($msg, 'duplicate') !== false
            || stripos($msg, 'UNIQUE constraint') !== false;
    }

    private function formatValue(string $type, float $value): string
    {
        if ($type === FleetUnitReading::TYPE_ODOMETER) {
            return number_format($value, 0);
        }
        return number_format($value, 2);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeUnit(FleetUnit $u): array
    {
        return [
            'id' => $u->id,
            'company_id' => $u->company_id,
            'unit_number' => $u->unit_number,
            'unit_type' => $u->unit_type,
            'vin' => $u->vin,
            'year' => $u->year,
            'make' => $u->make,
            'model' => $u->model,
            'trim' => $u->trim,
            'license_plate' => $u->license_plate,
            'home_site_id' => $u->home_site_id,
            'meter_type' => $u->meter_type,
            'current_odometer' => $u->current_odometer,
            'current_engine_hours' => $u->current_engine_hours,
            'odometer_last_read_at' => $u->odometer_last_read_at,
            'engine_hours_last_read_at' => $u->engine_hours_last_read_at,
            'status' => $u->status,
            'notes' => $u->notes,
            'created_by_user_id' => $u->created_by_user_id,
            'created_at' => $u->created_at,
            'updated_at' => $u->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeReading(FleetUnitReading $r): array
    {
        return [
            'id' => $r->id,
            'fleet_unit_id' => $r->fleet_unit_id,
            'reading_type' => $r->reading_type,
            'value' => $r->value,
            'recorded_at' => $r->recorded_at,
            'source' => $r->source,
            'workorder_id' => $r->workorder_id,
            'notes' => $r->notes,
            'recorded_by_user_id' => $r->recorded_by_user_id,
            'created_at' => $r->created_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAssignment(FleetUnitAssignment $a): array
    {
        return [
            'id' => $a->id,
            'fleet_unit_id' => $a->fleet_unit_id,
            'assignment_type' => $a->assignment_type,
            'assigned_user_id' => $a->assigned_user_id,
            'assigned_site_id' => $a->assigned_site_id,
            'customer_id' => $a->customer_id,
            'assigned_from' => $a->assigned_from,
            'assigned_until' => $a->assigned_until,
            'is_current' => $a->assigned_until === null,
            'notes' => $a->notes,
            'created_by_user_id' => $a->created_by_user_id,
            'created_at' => $a->created_at,
        ];
    }
}
