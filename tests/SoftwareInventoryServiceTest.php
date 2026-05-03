<?php

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Models\InstalledSoftware;
use App\Models\LicenseAssignment;
use App\Models\LicenseSeat;
use App\Models\SoftwareAsset;
use App\Services\SoftwareInventory\InstalledSoftwareRepository;
use App\Services\SoftwareInventory\LicenseAssignmentRepository;
use App\Services\SoftwareInventory\LicenseSeatRepository;
use App\Services\SoftwareInventory\SoftwareAssetRepository;
use App\Services\SoftwareInventory\SoftwareInventoryService;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;

/**
 * Phase 14 / M9 of docs/woms-expansion-plan.md — task #129.
 *
 * Covers the SoftwareInventoryService invariants that protect license
 * compliance: capacity guard on assign, idempotent duplicate assign,
 * counter decrement on unassign, expired/non-active pool rejection,
 * seats_owned-vs-seats_assigned guard, and counter reconciliation.
 */

class SoftInvFakeAudit extends AuditLogger
{
    /** @var array<int, AuditEntry> */
    public array $entries = [];

    public function __construct()
    {
    }

    public function log(AuditEntry $entry): void
    {
        $this->entries[] = $entry;
    }

    public function logAndGetId(AuditEntry $entry): ?int
    {
        $this->entries[] = $entry;
        return count($this->entries);
    }
}

/**
 * No-op PDO double — tests don't exercise SQL, only the inTransaction /
 * begin / commit / rollBack hooks the service uses around assign/unassign.
 * Construct via reflection so we don't need a real DSN.
 */
class SoftInvFakePdo extends PDO
{
    public bool $inTx = false;
    public function __construct()
    {
        // bypass parent::__construct — no real connection needed
    }
    public function inTransaction(): bool
    {
        return $this->inTx;
    }
    public function beginTransaction(): bool
    {
        $this->inTx = true;
        return true;
    }
    public function commit(): bool
    {
        $this->inTx = false;
        return true;
    }
    public function rollBack(): bool
    {
        $this->inTx = false;
        return true;
    }
}

class SoftInvFakeConnection extends Connection
{
    public function __construct()
    {
    }

    public function pdo(): PDO
    {
        static $pdo = null;
        if ($pdo === null) {
            $pdo = new SoftInvFakePdo();
        }
        return $pdo;
    }
}

class SoftInvFakeSoftwareAssets extends SoftwareAssetRepository
{
    /** @var array<int, SoftwareAsset> */
    public array $store = [];
    public int $nextId = 1;

    public function __construct()
    {
    }

    public function findById(int $id): ?SoftwareAsset
    {
        return $this->store[$id] ?? null;
    }

    public function create(array $data): SoftwareAsset
    {
        $id = $this->nextId++;
        $row = SoftwareAsset::fromRow(array_merge([
            'id' => $id,
            'is_active' => 1,
            'created_at' => '2026-05-03 10:00:00',
            'updated_at' => '2026-05-03 10:00:00',
        ], $data));
        $this->store[$id] = $row;
        return $row;
    }

    public function update(int $id, array $data): SoftwareAsset
    {
        $existing = $this->store[$id] ?? null;
        if ($existing === null) {
            throw new RuntimeException("software_asset {$id} not found");
        }
        $merged = array_merge($existing->toArray(), $data);
        $row = SoftwareAsset::fromRow($merged);
        $this->store[$id] = $row;
        return $row;
    }
}

class SoftInvFakeSeats extends LicenseSeatRepository
{
    /** @var array<int, LicenseSeat> */
    public array $store = [];
    public int $nextId = 1;
    public int $forUpdateCalls = 0;

    public function __construct()
    {
    }

    public function findById(int $id): ?LicenseSeat
    {
        return $this->store[$id] ?? null;
    }

    public function findByIdForUpdate(int $id): ?LicenseSeat
    {
        $this->forUpdateCalls++;
        return $this->store[$id] ?? null;
    }

    public function create(array $data): LicenseSeat
    {
        $id = $this->nextId++;
        $row = LicenseSeat::fromRow(array_merge([
            'id' => $id,
            'seats_assigned' => 0,
            'status' => LicenseSeat::STATUS_ACTIVE,
            'license_type' => LicenseSeat::TYPE_SUBSCRIPTION,
            'created_at' => '2026-05-03 10:00:00',
            'updated_at' => '2026-05-03 10:00:00',
        ], $data));
        $this->store[$id] = $row;
        return $row;
    }

    public function update(int $id, array $data): LicenseSeat
    {
        $existing = $this->store[$id] ?? null;
        if ($existing === null) {
            throw new RuntimeException("license_seat {$id} not found");
        }
        if (array_key_exists('seats_assigned', $data)) {
            throw new InvalidArgumentException(
                'seats_assigned must be mutated through SoftwareInventoryService::assign / unassign'
            );
        }
        if (array_key_exists('seats_owned', $data)) {
            $newOwned = (int) $data['seats_owned'];
            if ($newOwned < $existing->seats_assigned) {
                throw new InvalidArgumentException(
                    "Cannot lower seats_owned below current seats_assigned ({$existing->seats_assigned})"
                );
            }
        }
        $merged = array_merge($existing->toArray(), $data);
        $row = LicenseSeat::fromRow($merged);
        $this->store[$id] = $row;
        return $row;
    }

    public function incrementAssigned(int $id, int $delta = 1): void
    {
        $existing = $this->store[$id];
        $new = clone $existing;
        $new->seats_assigned = $existing->seats_assigned + $delta;
        $this->store[$id] = $new;
    }

    public function decrementAssigned(int $id, int $delta = 1): void
    {
        $existing = $this->store[$id];
        $new = clone $existing;
        $new->seats_assigned = max(0, $existing->seats_assigned - $delta);
        $this->store[$id] = $new;
    }

    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        return array_values($this->store);
    }

    public function listOverAllocated(?int $customerId = null): array
    {
        return array_values(array_filter(
            $this->store,
            static fn (LicenseSeat $s) => $s->seats_assigned > $s->seats_owned
        ));
    }
}

class SoftInvFakeAssignments extends LicenseAssignmentRepository
{
    /** @var array<int, LicenseAssignment> */
    public array $store = [];
    public int $nextId = 1;

    public function __construct()
    {
    }

    public function findById(int $id): ?LicenseAssignment
    {
        return $this->store[$id] ?? null;
    }

    public function findActiveForAssignee(int $licenseSeatId, string $assigneeType, int $assigneeId): ?LicenseAssignment
    {
        foreach ($this->store as $row) {
            if ($row->license_seat_id !== $licenseSeatId) {
                continue;
            }
            if ($row->assignee_type !== $assigneeType) {
                continue;
            }
            if ($row->unassigned_at !== null) {
                continue;
            }
            $rowAssignee = $assigneeType === LicenseAssignment::ASSIGNEE_USER
                ? $row->assignee_user_id
                : $row->assignee_site_asset_id;
            if ($rowAssignee === $assigneeId) {
                return $row;
            }
        }
        return null;
    }

    public function countActiveForSeat(int $licenseSeatId): int
    {
        $n = 0;
        foreach ($this->store as $row) {
            if ($row->license_seat_id === $licenseSeatId && $row->unassigned_at === null) {
                $n++;
            }
        }
        return $n;
    }

    public function create(array $data): LicenseAssignment
    {
        $id = $this->nextId++;
        $row = LicenseAssignment::fromRow(array_merge([
            'id' => $id,
            'assigned_at' => '2026-05-03 10:00:00',
            'unassigned_at' => null,
            'created_at' => '2026-05-03 10:00:00',
            'updated_at' => '2026-05-03 10:00:00',
        ], $data));
        $this->store[$id] = $row;
        return $row;
    }

    public function markUnassigned(int $id, ?int $byUserId = null, ?string $reason = null, ?string $when = null): LicenseAssignment
    {
        $existing = $this->store[$id];
        $new = clone $existing;
        $new->unassigned_at = $when ?? '2026-05-03 11:00:00';
        $new->unassigned_by_user_id = $byUserId;
        $new->unassign_reason = $reason;
        $this->store[$id] = $new;
        return $new;
    }
}

class SoftInvFakeInstalls extends InstalledSoftwareRepository
{
    /** @var array<int, InstalledSoftware> */
    public array $store = [];
    public int $nextId = 1;
    /** @var array<int, int> */
    public array $assignmentLinksCleared = [];

    public function __construct()
    {
    }

    public function findById(int $id): ?InstalledSoftware
    {
        return $this->store[$id] ?? null;
    }

    public function findByPair(int $siteAssetId, int $softwareAssetId): ?InstalledSoftware
    {
        foreach ($this->store as $row) {
            if ($row->site_asset_id === $siteAssetId && $row->software_asset_id === $softwareAssetId) {
                return $row;
            }
        }
        return null;
    }

    public function create(array $data): InstalledSoftware
    {
        $id = $this->nextId++;
        $row = InstalledSoftware::fromRow(array_merge([
            'id' => $id,
            'source' => InstalledSoftware::SOURCE_MANUAL,
            'created_at' => '2026-05-03 10:00:00',
            'updated_at' => '2026-05-03 10:00:00',
        ], $data));
        $this->store[$id] = $row;
        return $row;
    }

    public function update(int $id, array $data): InstalledSoftware
    {
        $existing = $this->store[$id];
        $merged = array_merge($existing->toArray(), $data);
        $row = InstalledSoftware::fromRow($merged);
        $this->store[$id] = $row;
        return $row;
    }

    public function delete(int $id): void
    {
        unset($this->store[$id]);
    }

    public function clearAssignment(int $licenseAssignmentId): void
    {
        $this->assignmentLinksCleared[] = $licenseAssignmentId;
        foreach ($this->store as $id => $row) {
            if ($row->license_assignment_id === $licenseAssignmentId) {
                $new = clone $row;
                $new->license_assignment_id = null;
                $this->store[$id] = $new;
            }
        }
    }
}

// -----------------------------------------------------------------------------
// Test runner
// -----------------------------------------------------------------------------

function softInvFreshService(): array
{
    $audit = new SoftInvFakeAudit();
    $conn = new SoftInvFakeConnection();
    $softwareAssets = new SoftInvFakeSoftwareAssets();
    $seats = new SoftInvFakeSeats();
    $assignments = new SoftInvFakeAssignments();
    $installs = new SoftInvFakeInstalls();

    $service = new SoftwareInventoryService(
        $conn,
        $softwareAssets,
        $seats,
        $assignments,
        $installs,
        $audit
    );
    return [$service, $audit, $softwareAssets, $seats, $assignments, $installs];
}

$tests = [];

$tests['createSoftwareAsset writes audit entry'] = function (): void {
    [$service, $audit, $sw] = softInvFreshService();
    $row = $service->createSoftwareAsset([
        'customer_id' => 7,
        'publisher' => 'Microsoft',
        'title' => 'Office',
        'version' => '2024',
    ], actorId: 99);
    assert($row->publisher === 'Microsoft', 'publisher persisted');
    assert($row->title === 'Office', 'title persisted');
    assert(count($audit->entries) === 1, 'one audit entry');
    assert($audit->entries[0]->event === 'software_asset.created', 'correct event');
    assert($audit->entries[0]->actorId === 99, 'actor recorded');
};

$tests['createSeat rejects mismatched customer scope'] = function (): void {
    [$service] = softInvFreshService();
    // Catalog row scoped to customer 7
    $service->createSoftwareAsset(['customer_id' => 7, 'publisher' => 'Acme', 'title' => 'X']);
    $threw = false;
    try {
        $service->createSeat([
            'software_asset_id' => 1,
            'customer_id' => 8,        // wrong customer
            'seats_owned' => 5,
        ]);
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    assert($threw, 'cross-customer seat creation should be rejected');
};

$tests['createSeat allows shared catalog (NULL customer_id)'] = function (): void {
    [$service] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => null, 'publisher' => 'Adobe', 'title' => 'Acrobat']);
    $seat = $service->createSeat([
        'software_asset_id' => 1,
        'customer_id' => 42,
        'seats_owned' => 10,
    ]);
    assert($seat->seats_owned === 10, 'seats_owned persisted');
    assert($seat->seats_assigned === 0, 'seats_assigned starts at 0');
};

$tests['assign happy path increments counter and audits'] = function (): void {
    [$service, $audit, $sw, $seats] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat(['software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 3]);
    $assignment = $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 100, actorId: 1);

    assert($assignment->id > 0, 'assignment created');
    assert($assignment->assignee_user_id === 100, 'user recorded');
    assert($seats->store[1]->seats_assigned === 1, 'counter incremented');
    $events = array_map(static fn ($e) => $e->event, $audit->entries);
    assert(in_array('license_assignment.created', $events, true), 'audit event recorded');
};

$tests['assign refuses when at capacity'] = function (): void {
    [$service, $audit, $sw, $seats, $assignments] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat(['software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 1]);
    $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 100);

    $threw = false;
    try {
        $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 200);
    } catch (InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'no available seats');
    }
    assert($threw, 'over-allocation should be rejected');
    assert($seats->store[1]->seats_assigned === 1, 'counter not bumped on refusal');
    assert(count($assignments->store) === 1, 'no extra assignment row');
};

$tests['assign idempotent for duplicate (seat, assignee)'] = function (): void {
    [$service, , , $seats, $assignments] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat(['software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 5]);

    $a1 = $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 50);
    $a2 = $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 50);

    assert($a1->id === $a2->id, 'duplicate assign returns existing row');
    assert($seats->store[1]->seats_assigned === 1, 'counter only incremented once');
    assert(count($assignments->store) === 1, 'only one assignment row exists');
};

$tests['assign refuses on non-active pool'] = function (): void {
    [$service, , , $seats] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat([
        'software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 5,
        'status' => LicenseSeat::STATUS_SUSPENDED,
    ]);
    $threw = false;
    try {
        $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 50);
    } catch (InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'suspended');
    }
    assert($threw, 'suspended pool should reject assign');
};

$tests['assign refuses on expired pool'] = function (): void {
    [$service] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat([
        'software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 5,
        'expires_at' => '2020-01-01',
    ]);
    $threw = false;
    try {
        $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 50);
    } catch (InvalidArgumentException $e) {
        $threw = str_contains($e->getMessage(), 'expired');
    }
    assert($threw, 'expired pool should reject assign');
};

$tests['assign requires positive assignee id'] = function (): void {
    [$service] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat(['software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 5]);
    $threw = false;
    try {
        $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 0);
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    assert($threw, 'assignee_id <= 0 should be rejected');
};

$tests['unassign decrements counter and clears install link'] = function (): void {
    [$service, $audit, , $seats, $assignments, $installs] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat(['software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 2]);
    $assignment = $service->assign(1, LicenseAssignment::ASSIGNEE_SITE_ASSET, 555);
    $installs->create([
        'site_asset_id' => 555,
        'software_asset_id' => 1,
        'license_assignment_id' => $assignment->id,
    ]);

    $unassigned = $service->unassign($assignment->id, actorId: 9, reason: 'machine retired');
    assert($unassigned->unassigned_at !== null, 'unassigned_at populated');
    assert($seats->store[1]->seats_assigned === 0, 'counter decremented');
    assert(in_array($assignment->id, $installs->assignmentLinksCleared, true), 'install link cleared');
};

$tests['unassign idempotent when already unassigned'] = function (): void {
    [$service, , , $seats] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat(['software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 2]);
    $assignment = $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 11);
    $service->unassign($assignment->id);
    $service->unassign($assignment->id);  // second call must be a no-op
    assert($seats->store[1]->seats_assigned === 0, 'counter only decremented once');
};

$tests['updateSeat refuses to lower seats_owned below current usage'] = function (): void {
    [$service] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat(['software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 3]);
    $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 1);
    $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 2);
    $threw = false;
    try {
        $service->updateSeat(1, ['seats_owned' => 1]);
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    assert($threw, 'lowering seats_owned below seats_assigned should be rejected');
};

$tests['updateSeat allows lowering seats_owned at/above current usage'] = function (): void {
    [$service, , , $seats] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat(['software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 5]);
    $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 1);
    $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 2);
    $service->updateSeat(1, ['seats_owned' => 2]);  // exactly equal to assigned — allowed
    assert($seats->store[1]->seats_owned === 2, 'lowered to current usage');
};

$tests['recordInstall is idempotent on (site_asset, software)'] = function (): void {
    [$service, , , , , $installs] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $row1 = $service->recordInstall([
        'site_asset_id' => 99, 'software_asset_id' => 1,
        'installed_version' => '1.0',
    ]);
    $row2 = $service->recordInstall([
        'site_asset_id' => 99, 'software_asset_id' => 1,
        'installed_version' => '1.1',
        'source' => InstalledSoftware::SOURCE_AGENT,
    ]);
    assert($row1->id === $row2->id, 'second call updates the same row');
    assert(count($installs->store) === 1, 'no duplicate row');
    assert($installs->store[$row2->id]->installed_version === '1.1', 'version updated');
    assert($installs->store[$row2->id]->source === InstalledSoftware::SOURCE_AGENT, 'source updated');
};

$tests['complianceSummary surfaces over-allocation and expiry flags'] = function (): void {
    [$service, , , $seats] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $seats->create([
        'software_asset_id' => 1,
        'customer_id' => 1,
        'seats_owned' => 2,
        'seats_assigned' => 3,            // over-allocated
        'expires_at' => date('Y-m-d', strtotime('+10 days')),
    ]);
    $summary = $service->complianceSummary(1);
    assert(count($summary) === 1, 'one row');
    assert($summary[0]['over_allocated'] === true, 'over_allocated flag set');
    assert($summary[0]['expires_within_30d'] === true, 'expiring-soon flag set');
    assert($summary[0]['seats_available'] === 0, 'available is 0 when over-allocated');
};

$tests['reconcileSeatCounters fixes drift'] = function (): void {
    [$service, $audit, , $seats, $assignments] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $seat = $seats->create([
        'software_asset_id' => 1,
        'customer_id' => 1,
        'seats_owned' => 5,
        'seats_assigned' => 3,            // counter says 3...
    ]);
    // ...but only one active assignment exists in reality
    $assignments->create([
        'license_seat_id' => $seat->id,
        'assignee_type' => LicenseAssignment::ASSIGNEE_USER,
        'assignee_user_id' => 1,
    ]);
    $fixes = $service->reconcileSeatCounters();
    assert(count($fixes) === 1, 'one drift detected');
    assert($fixes[0]['before'] === 3, 'before recorded');
    assert($fixes[0]['after'] === 1, 'after recorded');
    assert($seats->store[$seat->id]->seats_assigned === 1, 'counter corrected');
    $events = array_map(static fn ($e) => $e->event, $audit->entries);
    assert(in_array('license_seat.counters_reconciled', $events, true), 'reconciliation audited');
};

$tests['linkInstallToAssignment refuses inactive assignment'] = function (): void {
    [$service, , , , , $installs] = softInvFreshService();
    $service->createSoftwareAsset(['customer_id' => 1, 'publisher' => 'A', 'title' => 'B']);
    $service->createSeat(['software_asset_id' => 1, 'customer_id' => 1, 'seats_owned' => 1]);
    $assignment = $service->assign(1, LicenseAssignment::ASSIGNEE_USER, 7);
    $service->unassign($assignment->id);
    $install = $installs->create(['site_asset_id' => 200, 'software_asset_id' => 1]);

    $threw = false;
    try {
        $service->linkInstallToAssignment($install->id, $assignment->id);
    } catch (InvalidArgumentException) {
        $threw = true;
    }
    assert($threw, 'linking to an unassigned assignment should be rejected');
};

// Run all
$pass = 0;
$fail = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "PASS  {$name}\n";
        $pass++;
    } catch (\Throwable $e) {
        echo "FAIL  {$name}\n      " . $e->getMessage() . "\n      "
            . $e->getFile() . ':' . $e->getLine() . "\n";
        $fail++;
    }
}
echo "\n{$pass} passed, {$fail} failed\n";
exit($fail === 0 ? 0 : 1);
