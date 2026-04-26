<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "RoutingServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\GeoFence;
use App\Models\GeoFenceEvent;
use App\Models\RoutePlan;
use App\Models\RoutePlanStop;
use App\Models\User;
use App\Services\Routing\GeoFenceEvaluator;
use App\Services\Routing\GeoFenceEventRepository;
use App\Services\Routing\GeoFenceEventService;
use App\Services\Routing\GeoFenceRepository;
use App\Services\Routing\GeoFenceService;
use App\Services\Routing\NearestNeighborRouteOptimizer;
use App\Services\Routing\RoutePlanRepository;
use App\Services\Routing\RoutePlanService;
use App\Services\Routing\RouteStop;
use App\Services\Routing\RoutingController;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 10.6 of docs/expansion-plan.md — route optimization + geo-fencing.
 *
 * Covers:
 *   - GeoFence + GeoFenceEvent + RoutePlan + RoutePlanStop model constants
 *   - GeoFenceEvaluator: haversine distance, point-in-polygon, contains()
 *     for circles + polygons + inactive fences + missing geometry
 *   - GeoFenceRepository CRUD + listActive filter
 *   - GeoFenceService: shape-validation guards, permission gates
 *   - GeoFenceEventService: explicit recording, position auto-emit with
 *     dedup against most-recent prior event, evaluatePosition pure helper
 *   - NearestNeighborRouteOptimizer: empty-stop short-circuit, ordering
 *     follows greedy nearest-neighbor, returnToOrigin distance, label
 *   - RoutePlanRepository CRUD on plans + stops, stop ordering
 *   - RoutePlanService: lifecycle (draft→active→completed/cancelled),
 *     stop lifecycle (planned→en_route→arrived→completed), auto-complete
 *     plan on last terminal stop, optimize() rewrites sequence, optimize
 *     freezes non-planned stops, edit-after-terminal blocked, transition
 *     guards, permission gates
 *   - Controller envelope shape
 */

class RtInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function rtSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $pdo->exec("CREATE TABLE geo_fences (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        name TEXT NOT NULL,
        shape_type TEXT NOT NULL DEFAULT 'circle',
        center_latitude REAL NULL,
        center_longitude REAL NULL,
        radius_meters INTEGER NULL,
        polygon_geojson TEXT NULL,
        purpose TEXT NOT NULL DEFAULT 'service_zone',
        customer_id INTEGER NULL,
        workorder_id INTEGER NULL,
        asset_id INTEGER NULL,
        active INTEGER NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE geo_fence_events (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        geo_fence_id INTEGER NOT NULL,
        user_id INTEGER NOT NULL,
        workorder_id INTEGER NULL,
        event_type TEXT NOT NULL DEFAULT 'entered',
        occurred_at TEXT NOT NULL,
        latitude REAL NULL,
        longitude REAL NULL,
        accuracy_meters INTEGER NULL,
        source TEXT NOT NULL DEFAULT 'mobile_gps',
        notes TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE route_plans (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        planned_for_user_id INTEGER NOT NULL,
        plan_date TEXT NOT NULL,
        status TEXT NOT NULL DEFAULT 'draft',
        origin_latitude REAL NULL,
        origin_longitude REAL NULL,
        origin_label TEXT NULL,
        return_to_origin INTEGER NOT NULL DEFAULT 0,
        optimization_method TEXT NOT NULL DEFAULT 'nearest_neighbor',
        total_distance_meters INTEGER NULL,
        total_duration_minutes INTEGER NULL,
        optimized_at TEXT NULL,
        activated_at TEXT NULL,
        completed_at TEXT NULL,
        cancelled_at TEXT NULL,
        created_by_user_id INTEGER NULL,
        notes TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE route_plan_stops (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        route_plan_id INTEGER NOT NULL,
        sequence_order INTEGER NOT NULL,
        workorder_id INTEGER NULL,
        appointment_id INTEGER NULL,
        stop_label TEXT NOT NULL,
        latitude REAL NOT NULL,
        longitude REAL NOT NULL,
        estimated_arrival_at TEXT NULL,
        estimated_departure_at TEXT NULL,
        service_minutes_planned INTEGER NULL,
        arrived_at TEXT NULL,
        departed_at TEXT NULL,
        status TEXT NOT NULL DEFAULT 'planned',
        notes TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    return $pdo;
}

class RtPermissiveGate extends AccessGate
{
    /** @var array<string, bool> */
    public array $denials = [];
    public function __construct()
    {
    }
    public function can(User $user, string $permission, mixed $resource = null): bool
    {
        return empty($this->denials[$permission]);
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (!empty($this->denials[$permission])) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}

function makeRtFixture(): array
{
    $pdo = rtSetUpDatabase();
    $conn = new RtInMemoryConnection($pdo);
    $gate = new RtPermissiveGate();
    $evaluator = new GeoFenceEvaluator();
    $fenceRepo = new GeoFenceRepository($conn);
    $eventRepo = new GeoFenceEventRepository($conn);
    $planRepo = new RoutePlanRepository($conn);
    $optimizer = new NearestNeighborRouteOptimizer($evaluator);
    $fenceService = new GeoFenceService($fenceRepo, $gate);
    $eventService = new GeoFenceEventService($eventRepo, $fenceRepo, $evaluator, $gate);
    $planService = new RoutePlanService($planRepo, $optimizer, $gate);
    $controller = new RoutingController($fenceService, $eventService, $planService);
    return compact(
        'pdo', 'conn', 'gate', 'evaluator',
        'fenceRepo', 'eventRepo', 'planRepo',
        'optimizer', 'fenceService', 'eventService', 'planService',
        'controller'
    );
}

function rtAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function rtAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function rtAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new RuntimeException("FAIL {$msg}: got " . get_class($e) . " expected {$expectedClass}");
        }
        return;
    }
    throw new RuntimeException("FAIL {$msg}: no exception thrown (expected {$expectedClass})");
}

function makeRtUser(int $id = 7, string $role = 'manager'): User
{
    $u = new User();
    $u->id = $id;
    $u->role = $role;
    return $u;
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Model constants ────

$tests['fence_shapes_published'] = function () {
    rtAssertSame(['circle', 'polygon'], GeoFence::SHAPES);
};
$tests['fence_purposes_published'] = function () {
    rtAssertSame(
        ['shop_zone', 'customer_site', 'service_zone', 'restricted_zone'],
        GeoFence::PURPOSES
    );
};
$tests['event_types_published'] = function () {
    rtAssertSame(['entered', 'exited', 'dwell'], GeoFenceEvent::EVENT_TYPES);
};
$tests['event_sources_published'] = function () {
    rtAssertSame(['mobile_gps', 'manual', 'background_sync'], GeoFenceEvent::SOURCES);
};
$tests['plan_statuses_published'] = function () {
    rtAssertSame(['draft', 'active', 'completed', 'cancelled'], RoutePlan::STATUSES);
};
$tests['plan_transitions_published'] = function () {
    rtAssertSame(
        ['active', 'cancelled'],
        RoutePlan::ALLOWED_TRANSITIONS['draft']
    );
    rtAssertSame(
        ['completed', 'cancelled'],
        RoutePlan::ALLOWED_TRANSITIONS['active']
    );
    rtAssertSame([], RoutePlan::ALLOWED_TRANSITIONS['completed']);
    rtAssertSame([], RoutePlan::ALLOWED_TRANSITIONS['cancelled']);
};
$tests['stop_statuses_published'] = function () {
    rtAssertSame(
        ['planned', 'en_route', 'arrived', 'completed', 'skipped'],
        RoutePlanStop::STATUSES
    );
};

// ──── GeoFenceEvaluator ────

$tests['haversine_distance_known_points'] = function () {
    $e = new GeoFenceEvaluator();
    // Roughly NYC to LA in meters — sanity check (~3,940 km).
    $d = $e->haversineDistance(40.7128, -74.0060, 34.0522, -118.2437);
    rtAssertTrue($d > 3_900_000 && $d < 4_000_000, "got {$d}");
};

$tests['haversine_zero_for_same_point'] = function () {
    $e = new GeoFenceEvaluator();
    $d = $e->haversineDistance(33.4, -112.1, 33.4, -112.1);
    rtAssertTrue($d < 1.0, "expected ~0 got {$d}");
};

$tests['contains_circle_inside'] = function () {
    $e = new GeoFenceEvaluator();
    $f = new GeoFence([
        'shape_type' => 'circle',
        'center_latitude' => 33.5,
        'center_longitude' => -112.0,
        'radius_meters' => 500,
        'active' => 1,
    ]);
    rtAssertTrue($e->contains($f, 33.5, -112.0), 'center is inside');
    // ~110m east of center
    rtAssertTrue($e->contains($f, 33.5, -111.9988), '110m east still inside');
};

$tests['contains_circle_outside'] = function () {
    $e = new GeoFenceEvaluator();
    $f = new GeoFence([
        'shape_type' => 'circle',
        'center_latitude' => 33.5,
        'center_longitude' => -112.0,
        'radius_meters' => 100,
        'active' => 1,
    ]);
    rtAssertSame(false, $e->contains($f, 34.0, -112.0), 'far north is outside');
};

$tests['contains_inactive_fence_returns_false'] = function () {
    $e = new GeoFenceEvaluator();
    $f = new GeoFence([
        'shape_type' => 'circle',
        'center_latitude' => 33.5,
        'center_longitude' => -112.0,
        'radius_meters' => 1000,
        'active' => 0,
    ]);
    rtAssertSame(false, $e->contains($f, 33.5, -112.0));
};

$tests['contains_circle_missing_geom_returns_false'] = function () {
    $e = new GeoFenceEvaluator();
    $f = new GeoFence(['shape_type' => 'circle', 'active' => 1]);
    rtAssertSame(false, $e->contains($f, 33.5, -112.0));
};

$tests['contains_polygon_simple_square'] = function () {
    $e = new GeoFenceEvaluator();
    $square = json_encode([
        [-112.0, 33.5],
        [-111.9, 33.5],
        [-111.9, 33.6],
        [-112.0, 33.6],
    ]);
    $f = new GeoFence([
        'shape_type' => 'polygon',
        'polygon_geojson' => $square,
        'active' => 1,
    ]);
    rtAssertTrue($e->contains($f, 33.55, -111.95), 'center of square inside');
    rtAssertSame(false, $e->contains($f, 33.7, -112.0), 'far north outside');
    rtAssertSame(false, $e->contains($f, 33.55, -112.5), 'far west outside');
};

$tests['contains_polygon_invalid_returns_false'] = function () {
    $e = new GeoFenceEvaluator();
    $f = new GeoFence([
        'shape_type' => 'polygon',
        'polygon_geojson' => 'not valid json',
        'active' => 1,
    ]);
    rtAssertSame(false, $e->contains($f, 33.5, -112.0));
};

$tests['matching_fences_returns_matches_only'] = function () {
    $e = new GeoFenceEvaluator();
    $f1 = new GeoFence([
        'shape_type' => 'circle',
        'center_latitude' => 33.5, 'center_longitude' => -112.0,
        'radius_meters' => 500, 'active' => 1, 'id' => 1,
    ]);
    $f2 = new GeoFence([
        'shape_type' => 'circle',
        'center_latitude' => 40.0, 'center_longitude' => -74.0,
        'radius_meters' => 500, 'active' => 1, 'id' => 2,
    ]);
    $matches = $e->matchingFences([$f1, $f2], 33.5, -112.0);
    rtAssertSame(1, count($matches));
    rtAssertSame(1, $matches[0]->id);
};

// ──── GeoFenceRepository ────

$tests['repo_create_and_find_circle'] = function () {
    $f = makeRtFixture();
    $row = $f['fenceRepo']->create([
        'name' => 'Shop',
        'shape_type' => 'circle',
        'center_latitude' => 33.5,
        'center_longitude' => -112.0,
        'radius_meters' => 200,
        'purpose' => 'shop_zone',
        'active' => true,
    ]);
    rtAssertSame('Shop', $row->name);
    rtAssertSame(true, $row->active);
    $found = $f['fenceRepo']->findById($row->id);
    rtAssertSame('shop_zone', $found->purpose);
    rtAssertSame(200, $found->radius_meters);
};

$tests['repo_list_active_excludes_inactive'] = function () {
    $f = makeRtFixture();
    $a = $f['fenceRepo']->create(['name' => 'A', 'shape_type' => 'circle',
        'center_latitude' => 0, 'center_longitude' => 0, 'radius_meters' => 100, 'active' => true]);
    $b = $f['fenceRepo']->create(['name' => 'B', 'shape_type' => 'circle',
        'center_latitude' => 0, 'center_longitude' => 0, 'radius_meters' => 100, 'active' => false]);
    $rows = $f['fenceRepo']->listActive();
    rtAssertSame(1, count($rows));
    rtAssertSame($a->id, $rows[0]->id);
};

$tests['repo_listAll_includes_inactive_when_requested'] = function () {
    $f = makeRtFixture();
    $f['fenceRepo']->create(['name' => 'A', 'shape_type' => 'circle',
        'center_latitude' => 0, 'center_longitude' => 0, 'radius_meters' => 100, 'active' => true]);
    $f['fenceRepo']->create(['name' => 'B', 'shape_type' => 'circle',
        'center_latitude' => 0, 'center_longitude' => 0, 'radius_meters' => 100, 'active' => false]);
    $rows = $f['fenceRepo']->listAll(['include_inactive' => true]);
    rtAssertSame(2, count($rows));
};

// ──── GeoFenceService validation + permission gates ────

$tests['service_create_rejects_unknown_shape'] = function () {
    $f = makeRtFixture();
    rtAssertThrows(
        fn() => $f['fenceService']->create(makeRtUser(), [
            'name' => 'X', 'shape_type' => 'blob', 'purpose' => 'shop_zone',
        ]),
        InvalidArgumentException::class
    );
};

$tests['service_create_rejects_circle_missing_radius'] = function () {
    $f = makeRtFixture();
    rtAssertThrows(
        fn() => $f['fenceService']->create(makeRtUser(), [
            'name' => 'X', 'shape_type' => 'circle', 'purpose' => 'shop_zone',
            'center_latitude' => 33.5, 'center_longitude' => -112.0,
        ]),
        InvalidArgumentException::class
    );
};

$tests['service_create_rejects_polygon_missing_geojson'] = function () {
    $f = makeRtFixture();
    rtAssertThrows(
        fn() => $f['fenceService']->create(makeRtUser(), [
            'name' => 'X', 'shape_type' => 'polygon', 'purpose' => 'shop_zone',
        ]),
        InvalidArgumentException::class
    );
};

$tests['service_create_rejects_polygon_under_three_points'] = function () {
    $f = makeRtFixture();
    rtAssertThrows(
        fn() => $f['fenceService']->create(makeRtUser(), [
            'name' => 'X', 'shape_type' => 'polygon', 'purpose' => 'shop_zone',
            'polygon_geojson' => json_encode([[-112, 33], [-112, 34]]),
        ]),
        InvalidArgumentException::class
    );
};

$tests['service_create_requires_manage'] = function () {
    $f = makeRtFixture();
    $f['gate']->denials['geofences.manage'] = true;
    rtAssertThrows(
        fn() => $f['fenceService']->create(makeRtUser(), [
            'name' => 'X', 'shape_type' => 'circle', 'purpose' => 'shop_zone',
            'center_latitude' => 33.5, 'center_longitude' => -112.0, 'radius_meters' => 100,
        ]),
        UnauthorizedException::class
    );
};

$tests['service_list_requires_view'] = function () {
    $f = makeRtFixture();
    $f['gate']->denials['geofences.view'] = true;
    rtAssertThrows(
        fn() => $f['fenceService']->listActive(makeRtUser()),
        UnauthorizedException::class
    );
};

// ──── GeoFenceEventService ────

$tests['record_explicit_persists_event'] = function () {
    $f = makeRtFixture();
    $fence = $f['fenceService']->create(makeRtUser(), [
        'name' => 'Site A', 'shape_type' => 'circle', 'purpose' => 'customer_site',
        'center_latitude' => 33.5, 'center_longitude' => -112.0, 'radius_meters' => 200,
        'active' => true,
    ]);
    $event = $f['eventService']->recordExplicit(makeRtUser(), [
        'geo_fence_id' => $fence->id,
        'user_id' => 42,
        'event_type' => 'entered',
        'latitude' => 33.5,
        'longitude' => -112.0,
        'source' => 'manual',
    ]);
    rtAssertSame('entered', $event->event_type);
    rtAssertSame(42, $event->user_id);
    rtAssertSame($fence->id, $event->geo_fence_id);
};

$tests['record_explicit_rejects_unknown_event_type'] = function () {
    $f = makeRtFixture();
    $fence = $f['fenceService']->create(makeRtUser(), [
        'name' => 'X', 'shape_type' => 'circle', 'purpose' => 'shop_zone',
        'center_latitude' => 0, 'center_longitude' => 0, 'radius_meters' => 100, 'active' => true,
    ]);
    rtAssertThrows(
        fn() => $f['eventService']->recordExplicit(makeRtUser(), [
            'geo_fence_id' => $fence->id, 'user_id' => 1, 'event_type' => 'teleported',
        ]),
        InvalidArgumentException::class
    );
};

$tests['record_explicit_rejects_unknown_fence'] = function () {
    $f = makeRtFixture();
    rtAssertThrows(
        fn() => $f['eventService']->recordExplicit(makeRtUser(), [
            'geo_fence_id' => 9999, 'user_id' => 1, 'event_type' => 'entered',
        ]),
        InvalidArgumentException::class
    );
};

$tests['record_position_emits_one_per_match'] = function () {
    $f = makeRtFixture();
    $a = $f['fenceService']->create(makeRtUser(), [
        'name' => 'Shop', 'shape_type' => 'circle', 'purpose' => 'shop_zone',
        'center_latitude' => 33.5, 'center_longitude' => -112.0, 'radius_meters' => 500, 'active' => true,
    ]);
    $b = $f['fenceService']->create(makeRtUser(), [
        'name' => 'Site', 'shape_type' => 'circle', 'purpose' => 'customer_site',
        'center_latitude' => 33.5, 'center_longitude' => -112.0, 'radius_meters' => 600, 'active' => true,
    ]);
    $events = $f['eventService']->recordPosition(makeRtUser(), [
        'user_id' => 7, 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    rtAssertSame(2, count($events), 'one entered event per matching fence');
};

$tests['record_position_dedups_against_prior_entered'] = function () {
    $f = makeRtFixture();
    $a = $f['fenceService']->create(makeRtUser(), [
        'name' => 'Shop', 'shape_type' => 'circle', 'purpose' => 'shop_zone',
        'center_latitude' => 33.5, 'center_longitude' => -112.0, 'radius_meters' => 500, 'active' => true,
    ]);
    $first = $f['eventService']->recordPosition(makeRtUser(), [
        'user_id' => 7, 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    rtAssertSame(1, count($first));
    $second = $f['eventService']->recordPosition(makeRtUser(), [
        'user_id' => 7, 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    rtAssertSame(0, count($second), 'still inside, no second entered event');
};

$tests['record_position_emits_for_different_user'] = function () {
    $f = makeRtFixture();
    $a = $f['fenceService']->create(makeRtUser(), [
        'name' => 'Shop', 'shape_type' => 'circle', 'purpose' => 'shop_zone',
        'center_latitude' => 33.5, 'center_longitude' => -112.0, 'radius_meters' => 500, 'active' => true,
    ]);
    $f['eventService']->recordPosition(makeRtUser(), [
        'user_id' => 7, 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    $other = $f['eventService']->recordPosition(makeRtUser(), [
        'user_id' => 8, 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    rtAssertSame(1, count($other), 'separate user gets its own entered event');
};

$tests['record_position_no_match_returns_empty'] = function () {
    $f = makeRtFixture();
    $f['fenceService']->create(makeRtUser(), [
        'name' => 'Shop', 'shape_type' => 'circle', 'purpose' => 'shop_zone',
        'center_latitude' => 33.5, 'center_longitude' => -112.0, 'radius_meters' => 100, 'active' => true,
    ]);
    $events = $f['eventService']->recordPosition(makeRtUser(), [
        'user_id' => 7, 'latitude' => 40.0, 'longitude' => -74.0,
    ]);
    rtAssertSame(0, count($events));
};

$tests['evaluate_position_returns_active_matches'] = function () {
    $f = makeRtFixture();
    $a = $f['fenceService']->create(makeRtUser(), [
        'name' => 'A', 'shape_type' => 'circle', 'purpose' => 'shop_zone',
        'center_latitude' => 33.5, 'center_longitude' => -112.0, 'radius_meters' => 200, 'active' => true,
    ]);
    $matches = $f['eventService']->evaluatePosition(makeRtUser(), 33.5, -112.0);
    rtAssertSame(1, count($matches));
    rtAssertSame($a->id, $matches[0]->id);
};

// ──── NearestNeighborRouteOptimizer ────

$tests['optimizer_label'] = function () {
    $opt = new NearestNeighborRouteOptimizer(new GeoFenceEvaluator());
    rtAssertSame('nearest_neighbor', $opt->label());
};

$tests['optimizer_empty_returns_zero'] = function () {
    $opt = new NearestNeighborRouteOptimizer(new GeoFenceEvaluator());
    $r = $opt->optimize(33.5, -112.0, []);
    rtAssertSame(0, count($r->orderedStops));
    rtAssertSame(0, $r->totalDistanceMeters);
    rtAssertSame(0, $r->totalDurationMinutes);
};

$tests['optimizer_picks_nearest_first_then_chains'] = function () {
    $opt = new NearestNeighborRouteOptimizer(new GeoFenceEvaluator());
    // Origin at (0,0). Far stop at (10, 0). Near stop at (0.001, 0).
    $stops = [
        new RouteStop(1, 10.0, 0.0, 'far', 0),
        new RouteStop(2, 0.001, 0.0, 'near', 0),
    ];
    $r = $opt->optimize(0.0, 0.0, $stops);
    rtAssertSame(2, count($r->orderedStops));
    rtAssertSame(2, $r->orderedStops[0]->id, 'near stop visited first');
    rtAssertSame(1, $r->orderedStops[1]->id, 'far stop visited second');
};

$tests['optimizer_return_to_origin_increases_distance'] = function () {
    $opt = new NearestNeighborRouteOptimizer(new GeoFenceEvaluator());
    $stops = [new RouteStop(1, 0.05, 0.0, 'a', 0)];
    $no = $opt->optimize(0.0, 0.0, $stops, false);
    $yes = $opt->optimize(0.0, 0.0, $stops, true);
    rtAssertTrue($yes->totalDistanceMeters > $no->totalDistanceMeters,
        'return leg adds distance');
};

$tests['optimizer_duration_includes_service_minutes'] = function () {
    $opt = new NearestNeighborRouteOptimizer(new GeoFenceEvaluator());
    $stops = [new RouteStop(1, 0.001, 0.0, 'a', 30)];
    $r = $opt->optimize(0.0, 0.0, $stops);
    rtAssertTrue($r->totalDurationMinutes >= 30, 'duration includes 30m service window');
};

// ──── RoutePlanRepository / Service basics ────

$tests['create_plan_defaults_to_draft'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7,
        'plan_date' => '2026-04-24',
        'origin_latitude' => 33.5,
        'origin_longitude' => -112.0,
    ]);
    rtAssertSame('draft', $plan->status);
    rtAssertSame(7, $plan->planned_for_user_id);
};

$tests['create_plan_requires_user'] = function () {
    $f = makeRtFixture();
    rtAssertThrows(
        fn() => $f['planService']->createPlan(makeRtUser(), ['plan_date' => '2026-04-24']),
        InvalidArgumentException::class
    );
};

$tests['create_plan_requires_manage'] = function () {
    $f = makeRtFixture();
    $f['gate']->denials['route_plans.manage'] = true;
    rtAssertThrows(
        fn() => $f['planService']->createPlan(makeRtUser(), ['planned_for_user_id' => 1]),
        UnauthorizedException::class
    );
};

// ──── Plan lifecycle ────

$tests['activate_then_complete'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $active = $f['planService']->activate(makeRtUser(), $plan->id);
    rtAssertSame('active', $active->status);
    rtAssertTrue($active->activated_at !== null, 'activated_at stamped');
    $done = $f['planService']->complete(makeRtUser(), $plan->id);
    rtAssertSame('completed', $done->status);
    rtAssertTrue($done->completed_at !== null, 'completed_at stamped');
};

$tests['cancel_from_draft'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $cancelled = $f['planService']->cancel(makeRtUser(), $plan->id);
    rtAssertSame('cancelled', $cancelled->status);
    rtAssertTrue($cancelled->cancelled_at !== null);
};

$tests['cannot_complete_draft'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    rtAssertThrows(
        fn() => $f['planService']->complete(makeRtUser(), $plan->id),
        InvalidArgumentException::class
    );
};

$tests['cannot_activate_completed_plan'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $f['planService']->activate(makeRtUser(), $plan->id);
    $f['planService']->complete(makeRtUser(), $plan->id);
    rtAssertThrows(
        fn() => $f['planService']->activate(makeRtUser(), $plan->id),
        InvalidArgumentException::class
    );
};

$tests['cannot_edit_completed_plan'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $f['planService']->activate(makeRtUser(), $plan->id);
    $f['planService']->complete(makeRtUser(), $plan->id);
    rtAssertThrows(
        fn() => $f['planService']->updatePlan(makeRtUser(), $plan->id, ['notes' => 'x']),
        InvalidArgumentException::class
    );
};

$tests['cannot_delete_active_plan'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $f['planService']->activate(makeRtUser(), $plan->id);
    rtAssertThrows(
        fn() => $f['planService']->deletePlan(makeRtUser(), $plan->id),
        InvalidArgumentException::class
    );
};

// ──── Stops ────

$tests['add_stop_auto_increments_sequence'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $a = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'A', 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    $b = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'B', 'latitude' => 33.6, 'longitude' => -112.1,
    ]);
    rtAssertSame(1, $a->sequence_order);
    rtAssertSame(2, $b->sequence_order);
};

$tests['stops_listed_in_sequence_order'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'B', 'latitude' => 33.6, 'longitude' => -112.1, 'sequence_order' => 2,
    ]);
    $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'A', 'latitude' => 33.5, 'longitude' => -112.0, 'sequence_order' => 1,
    ]);
    $rows = $f['planService']->listStops(makeRtUser(), $plan->id);
    rtAssertSame('A', $rows[0]->stop_label);
    rtAssertSame('B', $rows[1]->stop_label);
};

$tests['stop_lifecycle_planned_to_completed'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $stop = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'A', 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    $en = $f['planService']->markStopEnRoute(makeRtUser(), $stop->id);
    rtAssertSame('en_route', $en->status);
    $arr = $f['planService']->markStopArrived(makeRtUser(), $stop->id);
    rtAssertSame('arrived', $arr->status);
    rtAssertTrue($arr->arrived_at !== null);
    $done = $f['planService']->markStopCompleted(makeRtUser(), $stop->id);
    rtAssertSame('completed', $done->status);
    rtAssertTrue($done->departed_at !== null);
};

$tests['stop_can_skip_from_planned'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $stop = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'A', 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    $sk = $f['planService']->markStopSkipped(makeRtUser(), $stop->id, 'no access');
    rtAssertSame('skipped', $sk->status);
    rtAssertSame('no access', $sk->notes);
};

$tests['cannot_complete_already_completed_stop'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $stop = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'A', 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    $f['planService']->markStopArrived(makeRtUser(), $stop->id);
    $f['planService']->markStopCompleted(makeRtUser(), $stop->id);
    rtAssertThrows(
        fn() => $f['planService']->markStopCompleted(makeRtUser(), $stop->id),
        InvalidArgumentException::class
    );
};

$tests['stop_execute_requires_execute_permission'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $stop = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'A', 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    $f['gate']->denials['route_plans.execute'] = true;
    rtAssertThrows(
        fn() => $f['planService']->markStopArrived(makeRtUser(), $stop->id),
        UnauthorizedException::class
    );
};

// ──── Auto-complete plan ────

$tests['plan_auto_completes_when_last_stop_terminal'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $a = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'A', 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    $b = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'B', 'latitude' => 33.6, 'longitude' => -112.1,
    ]);
    $f['planService']->activate(makeRtUser(), $plan->id);
    $f['planService']->markStopArrived(makeRtUser(), $a->id);
    $f['planService']->markStopCompleted(makeRtUser(), $a->id);
    $beforeLast = $f['planService']->findPlan(makeRtUser(), $plan->id);
    rtAssertSame('active', $beforeLast->status, 'still active until last stop done');
    $f['planService']->markStopArrived(makeRtUser(), $b->id);
    $f['planService']->markStopCompleted(makeRtUser(), $b->id);
    $afterLast = $f['planService']->findPlan(makeRtUser(), $plan->id);
    rtAssertSame('completed', $afterLast->status);
};

// ──── Optimize ────

$tests['optimize_rewrites_sequence_by_proximity'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
        'origin_latitude' => 0.0, 'origin_longitude' => 0.0,
    ]);
    // Add far stop first, then near stop. Optimizer should swap order.
    $far = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'far', 'latitude' => 10.0, 'longitude' => 0.0,
    ]);
    $near = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'near', 'latitude' => 0.001, 'longitude' => 0.0,
    ]);
    $f['planService']->optimize(makeRtUser(), $plan->id);
    $rows = $f['planService']->listStops(makeRtUser(), $plan->id);
    rtAssertSame('near', $rows[0]->stop_label, 'near sequenced first');
    rtAssertSame('far', $rows[1]->stop_label);
    $updated = $f['planService']->findPlan(makeRtUser(), $plan->id);
    rtAssertSame('nearest_neighbor', $updated->optimization_method);
    rtAssertTrue($updated->optimized_at !== null);
    rtAssertTrue($updated->total_distance_meters > 0);
};

$tests['optimize_requires_origin'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'A', 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    rtAssertThrows(
        fn() => $f['planService']->optimize(makeRtUser(), $plan->id),
        InvalidArgumentException::class
    );
};

$tests['optimize_freezes_non_planned_stops'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
        'origin_latitude' => 0.0, 'origin_longitude' => 0.0,
    ]);
    // Frozen stop already arrived
    $frozen = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'frozen', 'latitude' => 5.0, 'longitude' => 0.0,
    ]);
    $f['planService']->markStopArrived(makeRtUser(), $frozen->id);
    // Two more planned stops in suboptimal order
    $far = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'far', 'latitude' => 10.0, 'longitude' => 0.0,
    ]);
    $near = $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'near', 'latitude' => 0.001, 'longitude' => 0.0,
    ]);
    $f['planService']->optimize(makeRtUser(), $plan->id);
    $frozenRow = $f['planService']->findStop(makeRtUser(), $frozen->id);
    rtAssertSame('arrived', $frozenRow->status, 'frozen stop unchanged status');
    rtAssertSame(1, $frozenRow->sequence_order, 'frozen kept its sequence');
    // Near should be sequenced after frozen, far after near
    $nearRow = $f['planService']->findStop(makeRtUser(), $near->id);
    $farRow = $f['planService']->findStop(makeRtUser(), $far->id);
    rtAssertTrue(
        $nearRow->sequence_order > $frozenRow->sequence_order
            && $nearRow->sequence_order < $farRow->sequence_order,
        'near sequenced between frozen and far'
    );
};

$tests['optimize_blocked_on_completed_plan'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
        'origin_latitude' => 0.0, 'origin_longitude' => 0.0,
    ]);
    $f['planService']->activate(makeRtUser(), $plan->id);
    $f['planService']->complete(makeRtUser(), $plan->id);
    rtAssertThrows(
        fn() => $f['planService']->optimize(makeRtUser(), $plan->id),
        InvalidArgumentException::class
    );
};

// ──── Controller envelopes ────

$tests['controller_get_plan_includes_stops'] = function () {
    $f = makeRtFixture();
    $plan = $f['planService']->createPlan(makeRtUser(), [
        'planned_for_user_id' => 7, 'plan_date' => '2026-04-24',
    ]);
    $f['planService']->addStop(makeRtUser(), $plan->id, [
        'stop_label' => 'A', 'latitude' => 33.5, 'longitude' => -112.0,
    ]);
    $envelope = $f['controller']->getPlan(makeRtUser(), $plan->id);
    rtAssertTrue(isset($envelope['data']['stops']), 'data.stops present');
    rtAssertSame(1, count($envelope['data']['stops']));
};

$tests['controller_list_fences_envelope_shape'] = function () {
    $f = makeRtFixture();
    $f['fenceService']->create(makeRtUser(), [
        'name' => 'X', 'shape_type' => 'circle', 'purpose' => 'shop_zone',
        'center_latitude' => 0, 'center_longitude' => 0, 'radius_meters' => 100, 'active' => true,
    ]);
    $envelope = $f['controller']->listFences(makeRtUser(), []);
    rtAssertTrue(isset($envelope['data']) && is_array($envelope['data']));
    rtAssertSame(1, count($envelope['data']));
    rtAssertSame('X', $envelope['data'][0]['name']);
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "RoutingServiceTest\n";
$pass = 0;
$fail = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        $pass++;
        echo "  ok    {$name}\n";
    } catch (Throwable $e) {
        $fail++;
        echo "  FAIL  {$name}: " . $e->getMessage() . "\n";
    }
}
echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    exit(1);
}
