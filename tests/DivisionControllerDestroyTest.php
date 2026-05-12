<?php

require __DIR__ . '/test_bootstrap.php';

use App\Models\Division;
use App\Models\User;
use App\Services\Division\DivisionController;
use App\Services\Division\DivisionRepository;
use App\Support\Auth\AccessGate;

/**
 * UIG-9 — DELETE /api/divisions/{id} closeout.
 *
 * Covers DivisionController::destroy() + DivisionRepository::delete():
 *   - happy-path delete: gate passes, repo->delete() invoked
 *   - missing division → InvalidArgumentException (router translates to 4xx)
 *   - gate denial → assert throws before findById/delete are reached
 */

class DivisionDestroyFakeGate extends AccessGate
{
    /** @var array<int, string> */
    public array $denied = [];
    public function __construct()
    {
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (in_array($permission, $this->denied, true)) {
            throw new RuntimeException("denied: {$permission}");
        }
    }
}

class DivisionDestroyFakeRepo extends DivisionRepository
{
    /** @var array<int, Division> */
    public array $store = [];
    /** @var array<int, int> */
    public array $deleted = [];

    public function __construct()
    {
    }

    public function add(int $id, string $code = 'NR', string $name = 'North Region'): Division
    {
        $d = new Division();
        $d->id = $id;
        $d->code = $code;
        $d->name = $name;
        $this->store[$id] = $d;
        return $d;
    }

    public function findById(int $id): ?Division
    {
        return $this->store[$id] ?? null;
    }

    public function delete(int $id): void
    {
        $this->deleted[] = $id;
        unset($this->store[$id]);
    }
}

function divEnv(): array
{
    $gate = new DivisionDestroyFakeGate();
    $repo = new DivisionDestroyFakeRepo();
    $controller = new DivisionController($repo, $gate);
    return compact('gate', 'repo', 'controller');
}

function divUser(): User
{
    $u = new User();
    $u->id = 7;
    $u->role = 'admin';
    return $u;
}

function divCheck(callable $fn, string $label): void
{
    try {
        $fn();
        echo "  PASS {$label}\n";
    } catch (Throwable $ex) {
        echo "  FAIL {$label}: " . $ex->getMessage() . "\n";
        exit(1);
    }
}

function divExpectThrow(callable $fn, string $needle, string $label): void
{
    try {
        $fn();
        echo "  FAIL {$label}: expected throw containing '{$needle}'\n";
        exit(1);
    } catch (Throwable $ex) {
        if (!str_contains($ex->getMessage(), $needle)) {
            echo "  FAIL {$label}: wrong throw — got '" . $ex->getMessage() . "', wanted '{$needle}'\n";
            exit(1);
        }
        echo "  PASS {$label}\n";
    }
}

echo "UIG-9 — DivisionController::destroy()\n";

// 1. destroy() — happy path: gate passes, repo->delete() called with id.
$env = divEnv();
$env['repo']->add(11);
divCheck(function () use ($env) {
    $env['controller']->destroy(divUser(), 11);
    if ($env['repo']->deleted !== [11]) {
        throw new RuntimeException(
            'delete() not invoked with expected id, got: ' . json_encode($env['repo']->deleted)
        );
    }
    if (isset($env['repo']->store[11])) {
        throw new RuntimeException('division row not removed from store');
    }
}, 'destroy deletes existing division');

// 2. destroy() — missing id raises InvalidArgumentException with "not found".
$env = divEnv();
divExpectThrow(
    fn() => $env['controller']->destroy(divUser(), 999),
    'Division 999 not found',
    'destroy throws on unknown id'
);

// 3. destroy() — unknown id does NOT call repo->delete().
$env = divEnv();
try {
    $env['controller']->destroy(divUser(), 999);
} catch (\InvalidArgumentException $e) {
    // expected
}
divCheck(function () use ($env) {
    if ($env['repo']->deleted !== []) {
        throw new RuntimeException(
            'delete() must not be called when row missing; got: ' . json_encode($env['repo']->deleted)
        );
    }
}, 'destroy does not call repo->delete when row missing');

// 4. destroy() — gate denial blocks before any lookup/delete.
$env = divEnv();
$env['gate']->denied = ['settings.divisions.manage'];
$env['repo']->add(22);
divExpectThrow(
    fn() => $env['controller']->destroy(divUser(), 22),
    'denied: settings.divisions.manage',
    'destroy requires settings.divisions.manage'
);
divCheck(function () use ($env) {
    if ($env['repo']->deleted !== []) {
        throw new RuntimeException('delete() must not be called when gate denies');
    }
    if (!isset($env['repo']->store[22])) {
        throw new RuntimeException('row should still be present after denied destroy');
    }
}, 'destroy does not delete when gate denies');

echo "\nALL 5 PASS\n";
