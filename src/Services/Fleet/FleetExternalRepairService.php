<?php

namespace App\Services\Fleet;

use App\Database\Connection;
use App\Models\FleetExternalRepair;
use App\Models\FleetUnit;
use App\Models\FleetUnitReading;
use App\Models\User;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use Exception;
use InvalidArgumentException;
use RuntimeException;

/**
 * Phase 7.5 of docs/expansion-plan.md — external vendor repair tracking.
 *
 * Public surface mirrors the rest of Phase 7 — fleet.manage gates
 * writes, fleet.view gates reads, audit entries carry company_id +
 * fleet_unit_id + vendor + cost (never the free-form notes / attachment
 * paths which may carry PII).
 *
 * Optional reading auto-record: when odometer_at_service or
 * engine_hours_at_service is provided AND the value is higher than the
 * latest recorded reading for that meter, a companion fleet_unit_reading
 * is inserted (source='import', notes referencing the external repair)
 * so the unit's meter cache advances without a second API call. Values
 * that tie or move backwards are silently skipped — external repair
 * invoices often arrive out of order, and we don't want a late invoice
 * with an old odometer reading to break the external_repair insert.
 * The reading insert is best-effort (try/catch Throwable swallow) so
 * a PM hook or meter-cache glitch can't roll back the external_repair
 * write.
 */
class FleetExternalRepairService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly FleetUnitRepository $units,
        private readonly FleetExternalRepairRepository $repairs,
        private readonly FleetUnitReadingRepository $readings,
        private readonly AccessGate $gate,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function createRepair(User $actor, int $unitId, array $input): array
    {
        $this->gate->assert($actor, 'fleet.manage');
        $unit = $this->requireUnit($unitId);
        $fields = $this->validateInput($input);

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();
        try {
            $newId = $this->repairs->create([
                'fleet_unit_id' => $unitId,
                'vendor_name' => $fields['vendor_name'],
                'vendor_invoice_number' => $fields['vendor_invoice_number'],
                'category' => $fields['category'],
                'service_date' => $fields['service_date'],
                'description' => $fields['description'],
                'labor_cost' => $fields['labor_cost'],
                'parts_cost' => $fields['parts_cost'],
                'other_cost' => $fields['other_cost'],
                'total_cost' => $fields['total_cost'],
                'odometer_at_service' => $fields['odometer_at_service'],
                'engine_hours_at_service' => $fields['engine_hours_at_service'],
                'notes' => $fields['notes'],
                'attachment_path' => $fields['attachment_path'],
                'created_by_user_id' => $actor->id,
            ]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        $this->recordReadingsFromRepair($unit, $fields, $actor, $newId);

        $this->audit->log(new AuditEntry(
            'fleet.external_repair.created',
            'fleet_external_repairs',
            $newId,
            $actor->id,
            [
                'company_id' => $unit->company_id,
                'fleet_unit_id' => $unitId,
                'vendor' => $fields['vendor_name'],
                'category' => $fields['category'],
                'total_cost' => $fields['total_cost'],
                'service_date' => $fields['service_date'],
            ],
        ));

        $created = $this->repairs->findById($newId);
        if ($created === null) {
            throw new RuntimeException("external_repair {$newId} vanished after insert");
        }
        return $this->serialize($created);
    }

    /**
     * @param array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function updateRepair(User $actor, int $id, array $input): array
    {
        $this->gate->assert($actor, 'fleet.manage');
        $existing = $this->repairs->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("external_repair {$id} not found");
        }
        $unit = $this->requireUnit($existing->fleet_unit_id);

        // Merge existing values with the patch so callers can send a
        // partial body without nulling fields they didn't pass.
        $merged = [
            'vendor_name' => $input['vendor_name'] ?? $existing->vendor_name,
            'vendor_invoice_number' => array_key_exists('vendor_invoice_number', $input)
                ? $input['vendor_invoice_number'] : $existing->vendor_invoice_number,
            'category' => $input['category'] ?? $existing->category,
            'service_date' => $input['service_date'] ?? $existing->service_date,
            'description' => $input['description'] ?? $existing->description,
            'labor_cost' => $input['labor_cost'] ?? $existing->labor_cost,
            'parts_cost' => $input['parts_cost'] ?? $existing->parts_cost,
            'other_cost' => $input['other_cost'] ?? $existing->other_cost,
            'total_cost' => $input['total_cost'] ?? $existing->total_cost,
            'odometer_at_service' => array_key_exists('odometer_at_service', $input)
                ? $input['odometer_at_service'] : $existing->odometer_at_service,
            'engine_hours_at_service' => array_key_exists('engine_hours_at_service', $input)
                ? $input['engine_hours_at_service'] : $existing->engine_hours_at_service,
            'notes' => array_key_exists('notes', $input) ? $input['notes'] : $existing->notes,
            'attachment_path' => array_key_exists('attachment_path', $input)
                ? $input['attachment_path'] : $existing->attachment_path,
        ];
        $fields = $this->validateInput($merged);

        $this->repairs->update($id, $fields);

        $this->audit->log(new AuditEntry(
            'fleet.external_repair.updated',
            'fleet_external_repairs',
            $id,
            $actor->id,
            [
                'company_id' => $unit->company_id,
                'fleet_unit_id' => $existing->fleet_unit_id,
                'vendor' => $fields['vendor_name'],
                'total_cost' => $fields['total_cost'],
            ],
        ));

        $fresh = $this->repairs->findById($id);
        if ($fresh === null) {
            throw new RuntimeException("external_repair {$id} vanished after update");
        }
        return $this->serialize($fresh);
    }

    public function deleteRepair(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'fleet.manage');
        $existing = $this->repairs->findById($id);
        if ($existing === null) {
            // Idempotent delete — already-absent is a no-op rather than
            // a 404, matching how other Phase 7 deletes handle repeat
            // calls.
            return;
        }
        $unit = $this->units->findById($existing->fleet_unit_id);

        $this->repairs->delete($id);

        $this->audit->log(new AuditEntry(
            'fleet.external_repair.deleted',
            'fleet_external_repairs',
            $id,
            $actor->id,
            [
                'company_id' => $unit?->company_id,
                'fleet_unit_id' => $existing->fleet_unit_id,
                'vendor' => $existing->vendor_name,
                'total_cost' => $existing->total_cost,
            ],
        ));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForUnit(User $actor, int $unitId, int $limit = 100): array
    {
        $this->gate->assert($actor, 'fleet.view');
        $this->requireUnit($unitId);
        return array_map(
            fn(FleetExternalRepair $r) => $this->serialize($r),
            $this->repairs->listForUnit($unitId, $limit),
        );
    }

    /**
     * @param array{vendor?: ?string, category?: ?string, from?: ?string, to?: ?string, limit?: ?int} $filters
     * @return array<int, array<string, mixed>>
     */
    public function listForCompany(User $actor, int $companyId, array $filters = []): array
    {
        $this->gate->assert($actor, 'fleet.view');
        if ($companyId <= 0) {
            throw new InvalidArgumentException('company_id is required');
        }
        return array_map(
            fn(FleetExternalRepair $r) => $this->serialize($r),
            $this->repairs->listForCompany($companyId, $filters),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getRepair(User $actor, int $id): array
    {
        $this->gate->assert($actor, 'fleet.view');
        $repair = $this->repairs->findById($id);
        if ($repair === null) {
            throw new InvalidArgumentException("external_repair {$id} not found");
        }
        $this->requireUnit($repair->fleet_unit_id);
        return $this->serialize($repair);
    }

    // ── Validation ───────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $input
     * @return array{
     *   vendor_name: string,
     *   vendor_invoice_number: ?string,
     *   category: string,
     *   service_date: string,
     *   description: string,
     *   labor_cost: float,
     *   parts_cost: float,
     *   other_cost: float,
     *   total_cost: float,
     *   odometer_at_service: ?int,
     *   engine_hours_at_service: ?float,
     *   notes: ?string,
     *   attachment_path: ?string
     * }
     */
    private function validateInput(array $input): array
    {
        $vendor = $this->trimmed($input, 'vendor_name');
        if ($vendor === null || $vendor === '') {
            throw new InvalidArgumentException('vendor_name is required');
        }
        if (strlen($vendor) > FleetExternalRepair::VENDOR_NAME_MAX_LEN) {
            throw new InvalidArgumentException(
                'vendor_name exceeds ' . FleetExternalRepair::VENDOR_NAME_MAX_LEN . ' chars'
            );
        }

        $invoice = $this->trimmed($input, 'vendor_invoice_number');
        if ($invoice !== null && strlen($invoice) > FleetExternalRepair::VENDOR_INVOICE_MAX_LEN) {
            throw new InvalidArgumentException(
                'vendor_invoice_number exceeds ' . FleetExternalRepair::VENDOR_INVOICE_MAX_LEN . ' chars'
            );
        }

        $category = $this->trimmed($input, 'category') ?? FleetExternalRepair::CATEGORY_REPAIR;
        if (!in_array($category, FleetExternalRepair::ALLOWED_CATEGORIES, true)) {
            throw new InvalidArgumentException(
                'category must be one of ' . implode(',', FleetExternalRepair::ALLOWED_CATEGORIES)
            );
        }

        $serviceDate = $this->trimmed($input, 'service_date');
        if ($serviceDate === null || $serviceDate === '') {
            throw new InvalidArgumentException('service_date is required (YYYY-MM-DD)');
        }
        $serviceDate = $this->normalizeDate($serviceDate);

        $description = $this->trimmed($input, 'description');
        if ($description === null || $description === '') {
            throw new InvalidArgumentException('description is required');
        }
        if (strlen($description) > FleetExternalRepair::DESCRIPTION_MAX_LEN) {
            throw new InvalidArgumentException(
                'description exceeds ' . FleetExternalRepair::DESCRIPTION_MAX_LEN . ' chars'
            );
        }

        $labor = $this->nonNegativeFloat($input, 'labor_cost');
        $parts = $this->nonNegativeFloat($input, 'parts_cost');
        $other = $this->nonNegativeFloat($input, 'other_cost');
        $total = isset($input['total_cost']) && $input['total_cost'] !== ''
            ? $this->nonNegativeFloat($input, 'total_cost')
            : round($labor + $parts + $other, 2);

        // Guard against drift between the explicit total and the split —
        // an out-of-sync total usually means a client-side rounding bug
        // that would silently skew cost reports if we let it through.
        if (abs($total - ($labor + $parts + $other)) > 0.01) {
            throw new InvalidArgumentException(
                'total_cost must equal labor_cost + parts_cost + other_cost (within $0.01)'
            );
        }

        $odo = null;
        if (isset($input['odometer_at_service']) && $input['odometer_at_service'] !== '') {
            $odo = (int) $input['odometer_at_service'];
            if ($odo < 0) {
                throw new InvalidArgumentException('odometer_at_service must be >= 0');
            }
        }

        $hours = null;
        if (isset($input['engine_hours_at_service']) && $input['engine_hours_at_service'] !== '') {
            $hours = (float) $input['engine_hours_at_service'];
            if ($hours < 0) {
                throw new InvalidArgumentException('engine_hours_at_service must be >= 0');
            }
        }

        $notes = $this->trimmed($input, 'notes');
        if ($notes !== null && strlen($notes) > FleetExternalRepair::NOTES_MAX_LEN) {
            throw new InvalidArgumentException(
                'notes exceeds ' . FleetExternalRepair::NOTES_MAX_LEN . ' chars'
            );
        }

        $attachment = $this->trimmed($input, 'attachment_path');
        if ($attachment !== null && strlen($attachment) > FleetExternalRepair::ATTACHMENT_PATH_MAX_LEN) {
            throw new InvalidArgumentException(
                'attachment_path exceeds ' . FleetExternalRepair::ATTACHMENT_PATH_MAX_LEN . ' chars'
            );
        }

        return [
            'vendor_name' => $vendor,
            'vendor_invoice_number' => $invoice,
            'category' => $category,
            'service_date' => $serviceDate,
            'description' => $description,
            'labor_cost' => round($labor, 2),
            'parts_cost' => round($parts, 2),
            'other_cost' => round($other, 2),
            'total_cost' => round($total, 2),
            'odometer_at_service' => $odo,
            'engine_hours_at_service' => $hours,
            'notes' => $notes,
            'attachment_path' => $attachment,
        ];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function trimmed(array $input, string $key): ?string
    {
        if (!isset($input[$key])) {
            return null;
        }
        if (!is_string($input[$key])) {
            return (string) $input[$key];
        }
        $v = trim($input[$key]);
        return $v === '' ? null : $v;
    }

    /**
     * @param array<string, mixed> $input
     */
    private function nonNegativeFloat(array $input, string $key): float
    {
        $raw = $input[$key] ?? 0;
        $v = is_numeric($raw) ? (float) $raw : 0.0;
        if ($v < 0) {
            throw new InvalidArgumentException("{$key} must be >= 0");
        }
        return $v;
    }

    private function normalizeDate(string $raw): string
    {
        try {
            return (new DateTimeImmutable($raw))->format('Y-m-d');
        } catch (Exception $e) {
            throw new InvalidArgumentException('service_date is not a valid date: ' . $e->getMessage());
        }
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

    /**
     * @param array{
     *   odometer_at_service: ?int,
     *   engine_hours_at_service: ?float,
     *   service_date: string,
     *   vendor_name: string
     * } $fields
     */
    private function recordReadingsFromRepair(
        FleetUnit $unit,
        array $fields,
        User $actor,
        int $repairId,
    ): void {
        // Best-effort companion reading for each meter value provided.
        // Swallowed on failure because the repair write already
        // committed — reading cache drift is fixable via a manual
        // reading record, but losing the repair write would be worse.
        if ($fields['odometer_at_service'] !== null) {
            $this->tryRecordReading(
                $unit,
                FleetUnitReading::TYPE_ODOMETER,
                (float) $fields['odometer_at_service'],
                $fields['service_date'],
                $actor,
                $repairId,
                $fields['vendor_name'],
            );
        }
        if ($fields['engine_hours_at_service'] !== null) {
            $this->tryRecordReading(
                $unit,
                FleetUnitReading::TYPE_ENGINE_HOURS,
                $fields['engine_hours_at_service'],
                $fields['service_date'],
                $actor,
                $repairId,
                $fields['vendor_name'],
            );
        }
    }

    private function tryRecordReading(
        FleetUnit $unit,
        string $readingType,
        float $value,
        string $serviceDate,
        User $actor,
        int $repairId,
        string $vendor,
    ): void {
        try {
            $latest = $this->readings->findLatestForUnit($unit->id, $readingType);
            if ($latest !== null && $value <= $latest->value) {
                // Skip backwards / tie readings — external invoices
                // often arrive out of order, and reapplying an older
                // meter would make the history non-monotonic.
                return;
            }
            $this->readings->create([
                'fleet_unit_id' => $unit->id,
                'reading_type' => $readingType,
                'value' => $value,
                'recorded_at' => $serviceDate . ' 00:00:00',
                'source' => FleetUnitReading::SOURCE_IMPORT,
                'workorder_id' => null,
                'notes' => "external_repair #{$repairId} ({$vendor})",
                'recorded_by_user_id' => $actor->id,
            ]);
            // Bump the unit's denormalized meter cache so list views
            // reflect the new value without waiting for the next manual
            // reading. COALESCE-based partial update leaves the other
            // meter's cache untouched.
            $stamp = $serviceDate . ' 00:00:00';
            if ($readingType === FleetUnitReading::TYPE_ODOMETER) {
                $this->units->updateMeterCache($unit->id, (int) $value, null, $stamp, null);
            } else {
                $this->units->updateMeterCache($unit->id, null, $value, null, $stamp);
            }
        } catch (\Throwable) {
            // Silent — reading sync is best-effort.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FleetExternalRepair $r): array
    {
        return [
            'id' => $r->id,
            'fleet_unit_id' => $r->fleet_unit_id,
            'vendor_name' => $r->vendor_name,
            'vendor_invoice_number' => $r->vendor_invoice_number,
            'category' => $r->category,
            'service_date' => $r->service_date,
            'description' => $r->description,
            'labor_cost' => $r->labor_cost,
            'parts_cost' => $r->parts_cost,
            'other_cost' => $r->other_cost,
            'total_cost' => $r->total_cost,
            'odometer_at_service' => $r->odometer_at_service,
            'engine_hours_at_service' => $r->engine_hours_at_service,
            'notes' => $r->notes,
            'attachment_path' => $r->attachment_path,
            'created_by_user_id' => $r->created_by_user_id,
            'created_at' => $r->created_at,
            'updated_at' => $r->updated_at,
        ];
    }
}
