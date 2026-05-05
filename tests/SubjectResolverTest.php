<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Database\Connection;
use App\Models\ServiceLine;
use App\Services\ServiceLine\ServiceLineRepository;
use App\Services\ServiceLine\SubjectResolver;

/**
 * In-memory PDO wrapper so we can exercise SubjectResolver against a real
 * service_lines table without standing up MySQL. Mirrors the pattern used by
 * EstimateRejectReasonTest.
 */
class SubjectResolverMemoryConnection extends Connection
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }
}

function bootstrapSubjectResolverSchema(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Schema mirrors service_lines after migrations 152 + 176.
    $pdo->exec('CREATE TABLE service_lines (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        slug TEXT NOT NULL UNIQUE,
        name TEXT NOT NULL,
        description TEXT NULL,
        icon TEXT NULL,
        sort_order INTEGER NOT NULL DEFAULT 0,
        is_active INTEGER NOT NULL DEFAULT 1,
        subject_column TEXT NULL,
        subject_required INTEGER NOT NULL DEFAULT 0,
        subject_label TEXT NULL,
        created_at TEXT NULL,
        updated_at TEXT NULL
    )');

    return $pdo;
}

/**
 * @return array{resolver: SubjectResolver, repo: ServiceLineRepository, pdo: PDO}
 */
function buildResolverFixture(): array
{
    $pdo = bootstrapSubjectResolverSchema();
    $connection = new SubjectResolverMemoryConnection($pdo);
    $repo = new ServiceLineRepository($connection);
    $resolver = new SubjectResolver($repo);

    // Seed three lines that exercise each rule shape:
    //   - vehicle_id required
    //   - site_asset_id NOT required
    //   - no subject_column at all (route-based)
    $insert = $pdo->prepare(
        'INSERT INTO service_lines
            (slug, name, sort_order, is_active, subject_column, subject_required, subject_label)
         VALUES (:slug, :name, :sort, 1, :col, :req, :label)'
    );
    $insert->execute([
        'slug' => 'auto_repair', 'name' => 'Auto', 'sort' => 10,
        'col' => 'vehicle_id', 'req' => 1, 'label' => 'Vehicle',
    ]);
    $insert->execute([
        'slug' => 'building_repair', 'name' => 'Building', 'sort' => 20,
        'col' => 'site_asset_id', 'req' => 0, 'label' => 'Building / Asset',
    ]);
    $insert->execute([
        'slug' => 'commercial_cleaning', 'name' => 'Cleaning', 'sort' => 30,
        'col' => null, 'req' => 0, 'label' => null,
    ]);

    return ['resolver' => $resolver, 'repo' => $repo, 'pdo' => $pdo];
}

/** @return array{passed: bool, message: string} */
function resolverAssert(string $name, callable $body): array
{
    try {
        $body();
        return ['passed' => true, 'message' => $name];
    } catch (Throwable $e) {
        return [
            'passed' => false,
            'message' => $name . ' — ' . $e::class . ': ' . $e->getMessage(),
        ];
    }
}

/** @return array{passed: bool, message: string} */
function resolverAssertThrows(string $name, callable $body, string $expectedSubstring): array
{
    try {
        $body();
        return ['passed' => false, 'message' => $name . ' — expected exception'];
    } catch (InvalidArgumentException $e) {
        if (str_contains($e->getMessage(), $expectedSubstring)) {
            return ['passed' => true, 'message' => $name];
        }
        return [
            'passed' => false,
            'message' => $name . ' — wrong message: ' . $e->getMessage(),
        ];
    } catch (Throwable $e) {
        return [
            'passed' => false,
            'message' => $name . ' — wrong exception ' . $e::class . ': ' . $e->getMessage(),
        ];
    }
}

$results = [];

// resolveLine() — null and zero short-circuit; the "missing service_line_id
// becomes a generic estimate" default depends on this contract.
$results[] = resolverAssert('resolveLine(null) returns null', function (): void {
    $f = buildResolverFixture();
    if ($f['resolver']->resolveLine(null) !== null) {
        throw new RuntimeException('expected null for null id');
    }
});

$results[] = resolverAssert('resolveLine(0) returns null', function (): void {
    $f = buildResolverFixture();
    if ($f['resolver']->resolveLine(0) !== null) {
        throw new RuntimeException('expected null for zero id');
    }
});

$results[] = resolverAssert('resolveLine(unknown) returns null', function (): void {
    $f = buildResolverFixture();
    if ($f['resolver']->resolveLine(99999) !== null) {
        throw new RuntimeException('expected null for unknown id');
    }
});

$results[] = resolverAssert('resolveLine(known) returns line', function (): void {
    $f = buildResolverFixture();
    $line = $f['resolver']->resolveLine(1);
    if (!$line instanceof ServiceLine || $line->slug !== 'auto_repair') {
        throw new RuntimeException('expected auto_repair, got ' . var_export($line?->slug, true));
    }
});

// validateSubject() — null line and lines with no required column are no-ops;
// required-but-missing throws with the configured label.
$results[] = resolverAssert('validateSubject(null) is no-op', function (): void {
    $f = buildResolverFixture();
    $f['resolver']->validateSubject(null, []);
});

$results[] = resolverAssert('validateSubject for route-based line is no-op', function (): void {
    $f = buildResolverFixture();
    $line = $f['resolver']->resolveLine(3);
    $f['resolver']->validateSubject($line, []);
});

$results[] = resolverAssert('validateSubject for non-required column is no-op', function (): void {
    $f = buildResolverFixture();
    $line = $f['resolver']->resolveLine(2); // building_repair, site_asset NOT required
    $f['resolver']->validateSubject($line, []);
});

$results[] = resolverAssert('validateSubject succeeds when required column is present', function (): void {
    $f = buildResolverFixture();
    $line = $f['resolver']->resolveLine(1);
    $f['resolver']->validateSubject($line, ['vehicle_id' => 42]);
});

$results[] = resolverAssertThrows(
    'validateSubject throws when required column is missing',
    function (): void {
        $f = buildResolverFixture();
        $line = $f['resolver']->resolveLine(1);
        $f['resolver']->validateSubject($line, []);
    },
    "Service line 'auto_repair' requires a Vehicle"
);

$results[] = resolverAssertThrows(
    'validateSubject treats 0/empty as missing',
    function (): void {
        $f = buildResolverFixture();
        $line = $f['resolver']->resolveLine(1);
        $f['resolver']->validateSubject($line, ['vehicle_id' => 0]);
    },
    'requires a Vehicle'
);

// extractSubjectColumns / subjectColumnsForLine — shape contracts that
// repositories rely on for write splices.
$results[] = resolverAssert('subjectColumnsForLine for vehicle line', function (): void {
    $f = buildResolverFixture();
    $line = $f['resolver']->resolveLine(1);
    $cols = $f['resolver']->subjectColumnsForLine($line);
    if ($cols !== ['vehicle_id']) {
        throw new RuntimeException('unexpected: ' . json_encode($cols));
    }
});

$results[] = resolverAssert('subjectColumnsForLine for null line is empty', function (): void {
    $f = buildResolverFixture();
    if ($f['resolver']->subjectColumnsForLine(null) !== []) {
        throw new RuntimeException('expected empty array');
    }
});

$results[] = resolverAssert('subjectColumnsForLine for route-based line is empty', function (): void {
    $f = buildResolverFixture();
    $line = $f['resolver']->resolveLine(3);
    if ($f['resolver']->subjectColumnsForLine($line) !== []) {
        throw new RuntimeException('expected empty array');
    }
});

$results[] = resolverAssert('extractSubjectColumns normalizes to int|null', function (): void {
    $f = buildResolverFixture();
    $line = $f['resolver']->resolveLine(1);
    $cols = $f['resolver']->extractSubjectColumns($line, ['vehicle_id' => '7']);
    if ($cols !== ['vehicle_id' => 7]) {
        throw new RuntimeException('unexpected: ' . json_encode($cols));
    }
    $cols = $f['resolver']->extractSubjectColumns($line, ['vehicle_id' => '']);
    if ($cols !== ['vehicle_id' => null]) {
        throw new RuntimeException('expected null for empty string, got: ' . json_encode($cols));
    }
});

// allSubjectColumns — DB-backed distinct query so adding a vertical via
// ServiceLineRepository::create automatically expands the set.
$results[] = resolverAssert('allSubjectColumns returns distinct non-null columns', function (): void {
    $f = buildResolverFixture();
    $cols = $f['resolver']->allSubjectColumns();
    sort($cols);
    if ($cols !== ['site_asset_id', 'vehicle_id']) {
        throw new RuntimeException('unexpected: ' . json_encode($cols));
    }
});

$failures = array_filter($results, static fn (array $r) => !$r['passed']);
if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['message'] . PHP_EOL);
    }
    exit(1);
}

echo 'All SubjectResolver tests passed (' . count($results) . ').' . PHP_EOL;
