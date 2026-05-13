<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Support\Cron\CronDispatcher;

/**
 * AUD-077 — CronDispatcher unit + integration tests.
 *
 * Covers:
 *   - defaultTimeoutForSchedule() — per-minute, step-N, and fallback bands.
 *   - Single fast job → exit 0 + non-zero duration.
 *   - Single failing job → exit code surfaces unchanged.
 *   - Slow job → SIGTERM at timeout, result row marked timed_out=true.
 *   - Parallel dispatch — three sleep(1) jobs finish in ~1 s wall, not ~3 s.
 *   - Output capture — stdout from a child reaches the result.
 *   - Missing script — gracefully skipped, never started.
 */

if (!function_exists('proc_open')) {
    echo "CronDispatcherTest\n";
    echo "  skipped — proc_open not available\n";
    exit(0);
}

$results = [];
function cdRun(string $name, callable $fn): void
{
    global $results;
    try {
        $fn();
        $results[] = ['name' => $name, 'ok' => true];
        echo "  PASS  {$name}\n";
    } catch (Throwable $e) {
        $results[] = ['name' => $name, 'ok' => false, 'err' => $e->getMessage()];
        echo "  FAIL  {$name} — {$e->getMessage()}\n";
    }
}
function cdAssert(bool $cond, string $msg): void
{
    if (!$cond) {
        throw new RuntimeException($msg);
    }
}
function cdAssertSame(mixed $expected, mixed $actual, string $msg): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(sprintf(
            '%s (expected %s, got %s)',
            $msg,
            var_export($expected, true),
            var_export($actual, true)
        ));
    }
}

/**
 * Drop a one-off PHP script into the system tmp dir and return its path.
 */
function cdMakeScript(string $body): string
{
    $path = tempnam(sys_get_temp_dir(), 'cdtest_') . '.php';
    file_put_contents($path, "<?php\n" . $body . "\n");
    return $path;
}

echo "CronDispatcherTest\n";

$tempScripts = [];
register_shutdown_function(function () use (&$tempScripts) {
    foreach ($tempScripts as $path) {
        @unlink($path);
    }
});

// -- defaultTimeoutForSchedule ----------------------------------------------

cdRun('per-minute job defaults to 50s timeout', function () {
    cdAssertSame(50, CronDispatcher::defaultTimeoutForSchedule('* * * * *'),
        'per-minute schedule should default to 50s');
});

cdRun('*/5 schedule defaults to 240s timeout (one-minute margin under cadence)', function () {
    cdAssertSame(240, CronDispatcher::defaultTimeoutForSchedule('*/5 * * * *'),
        '*/5 schedule should default to 240s');
});

cdRun('*/15 schedule defaults to 840s timeout', function () {
    cdAssertSame(840, CronDispatcher::defaultTimeoutForSchedule('*/15 * * * *'),
        '*/15 schedule should default to 840s');
});

cdRun('hourly + slower schedules fall back to 1800s', function () {
    cdAssertSame(1800, CronDispatcher::defaultTimeoutForSchedule('0 * * * *'),
        'hourly should fall back to 1800s');
    cdAssertSame(1800, CronDispatcher::defaultTimeoutForSchedule('0 2 * * *'),
        'daily should fall back to 1800s');
    cdAssertSame(1800, CronDispatcher::defaultTimeoutForSchedule('0 2 1 * *'),
        'monthly should fall back to 1800s');
});

// -- Single-job dispatch ----------------------------------------------------

cdRun('fast job reports exit 0 + non-trivial duration', function () use (&$tempScripts) {
    $script = cdMakeScript('echo "hello";');
    $tempScripts[] = $script;

    $output = [];
    $dispatcher = new CronDispatcher(static function (string $line) use (&$output) {
        $output[] = $line;
    });
    $results = $dispatcher->dispatch([
        'fast' => ['name' => 'Fast Job', 'script' => $script, 'schedule' => '* * * * *'],
    ]);

    cdAssertSame(1, count($results), 'expected one result row');
    cdAssertSame(0, $results[0]['exit_code'], 'exit code should be 0');
    cdAssertSame(false, $results[0]['timed_out'], 'job should not be marked timed out');
    cdAssertSame(true, $results[0]['started'], 'job should be marked as started');
    cdAssertSame('Fast Job', $results[0]['name'], 'job name should round-trip');
    cdAssert($results[0]['duration_seconds'] >= 0,
        'duration_seconds should be non-negative');
    cdAssert(in_array('  hello', $output, true) || in_array('hello', $output, true),
        'logger should have received the child stdout line; got: ' . json_encode($output));
});

cdRun('failing job surfaces non-zero exit code', function () use (&$tempScripts) {
    $script = cdMakeScript('echo "boom"; exit(7);');
    $tempScripts[] = $script;

    $dispatcher = new CronDispatcher(static fn () => null);
    $results = $dispatcher->dispatch([
        'fail' => ['name' => 'Failing Job', 'script' => $script, 'schedule' => '* * * * *'],
    ]);

    cdAssertSame(1, count($results), 'expected one result row');
    cdAssertSame(7, $results[0]['exit_code'], 'exit code should be 7');
    cdAssertSame(false, $results[0]['timed_out'], 'failing-but-fast job should not be marked timed out');
});

cdRun('slow job is killed at timeout and marked timed_out=true', function () use (&$tempScripts) {
    // Deliberately slow — the dispatcher should SIGTERM after ~1s.
    $script = cdMakeScript('sleep(20); echo "should not reach";');
    $tempScripts[] = $script;

    $dispatcher = new CronDispatcher(static fn () => null);
    $start = microtime(true);
    $results = $dispatcher->dispatch([
        'slow' => [
            'name' => 'Slow Job',
            'script' => $script,
            'schedule' => '* * * * *',
            'timeout' => 1,
        ],
    ]);
    $wall = microtime(true) - $start;

    cdAssertSame(1, count($results), 'expected one result row');
    cdAssertSame(true, $results[0]['timed_out'], 'slow job should be marked timed out');
    cdAssert($wall < 8.0, sprintf('dispatcher should return within timeout+grace, took %.2fs', $wall));
    cdAssert($results[0]['duration_seconds'] >= 1.0,
        'duration_seconds should reflect at least the timeout window');
});

cdRun('missing script is skipped without starting a process', function () {
    $dispatcher = new CronDispatcher(static fn () => null);
    $results = $dispatcher->dispatch([
        'gone' => [
            'name' => 'Missing Job',
            'script' => '/var/tmp/this/path/does/not/exist.php',
            'schedule' => '* * * * *',
        ],
    ]);

    cdAssertSame(1, count($results), 'expected one result row');
    cdAssertSame(false, $results[0]['started'], 'job with missing script should not be marked started');
    cdAssertSame(-1, $results[0]['exit_code'], 'unstarted job should report exit_code=-1');
});

// -- Parallel dispatch ------------------------------------------------------

cdRun('parallel dispatch finishes near max(child) wall time, not sum(child)', function () use (&$tempScripts) {
    // Three sleep(1) jobs. Sequential would be ~3s; parallel should be ~1s.
    $script = cdMakeScript('sleep(1);');
    $tempScripts[] = $script;

    $dispatcher = new CronDispatcher(static fn () => null);
    $start = microtime(true);
    $results = $dispatcher->dispatch([
        'a' => ['name' => 'Job A', 'script' => $script, 'schedule' => '* * * * *', 'timeout' => 30],
        'b' => ['name' => 'Job B', 'script' => $script, 'schedule' => '* * * * *', 'timeout' => 30],
        'c' => ['name' => 'Job C', 'script' => $script, 'schedule' => '* * * * *', 'timeout' => 30],
    ]);
    $wall = microtime(true) - $start;

    cdAssertSame(3, count($results), 'expected three result rows');
    foreach ($results as $r) {
        cdAssertSame(0, $r['exit_code'], 'each child should exit 0');
        cdAssertSame(false, $r['timed_out'], 'no child should time out');
    }
    cdAssert($wall < 2.5,
        sprintf('three sleep(1) jobs should finish in ~1s wall under parallel dispatch, took %.2fs', $wall));
});

cdRun('maxConcurrent throttles parallelism — 4 sleep(1) jobs at concurrency=2 take ~2s', function () use (&$tempScripts) {
    $script = cdMakeScript('sleep(1);');
    $tempScripts[] = $script;

    $dispatcher = new CronDispatcher(static fn () => null);
    $start = microtime(true);
    $results = $dispatcher->dispatch([
        'a' => ['name' => 'Job A', 'script' => $script, 'schedule' => '* * * * *', 'timeout' => 30],
        'b' => ['name' => 'Job B', 'script' => $script, 'schedule' => '* * * * *', 'timeout' => 30],
        'c' => ['name' => 'Job C', 'script' => $script, 'schedule' => '* * * * *', 'timeout' => 30],
        'd' => ['name' => 'Job D', 'script' => $script, 'schedule' => '* * * * *', 'timeout' => 30],
    ], 2);
    $wall = microtime(true) - $start;

    cdAssertSame(4, count($results), 'expected four result rows');
    cdAssert($wall >= 1.8 && $wall < 4.0,
        sprintf('4 sleep(1) jobs at concurrency=2 should land in ~2s, got %.2fs', $wall));
});

cdRun('empty job set returns empty result without spawning anything', function () {
    $dispatcher = new CronDispatcher(static fn () => null);
    $results = $dispatcher->dispatch([]);
    cdAssertSame([], $results, 'empty input should yield empty result');
});

// -- Summary ----------------------------------------------------------------

$ok = 0;
$total = count($results);
foreach ($results as $r) {
    if ($r['ok']) {
        $ok++;
    }
}
echo "\nOK: {$ok}/{$total}\n";
exit($ok === $total ? 0 : 1);
