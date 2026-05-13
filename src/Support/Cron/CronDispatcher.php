<?php

namespace App\Support\Cron;

use RuntimeException;

/**
 * Parallel cron-job dispatcher with per-job timeout enforcement.
 *
 * AUD-077 background: the previous unified runner walked due jobs in a
 * single sequential `exec()` loop. With four `* * * * *` jobs scheduled
 * every minute (waterfall-dispatch, geofence-processor, pos-stale-sweeper,
 * ticket-sla-breach), one slow run could push later jobs past their next
 * tick — and the global file lock had no PID check and a 5-minute stale
 * window, so a crashed runner would block the next legitimate tick for
 * up to five minutes.
 *
 * This dispatcher fixes the second half of that finding (the lock half is
 * handled in bin/cron/run.php with flock()). It runs due jobs through
 * `proc_open()` with non-blocking pipes, caps concurrency, enforces a
 * per-job timeout (SIGTERM, then SIGKILL after a short grace), and
 * returns a structured per-job result array the caller can log or feed
 * to observability.
 *
 * The per-job timeout default is derived from the cron schedule: a
 * `* * * * *` job gets 50 s (must finish before the next tick), a
 * `*\/N` job gets `N*60-60` s (one-minute margin), and slower
 * cadences fall back to 30 minutes. Jobs can opt into an explicit
 * `timeout` field in the job spec to override the default.
 */
class CronDispatcher
{
    public const DEFAULT_MAX_CONCURRENT = 4;
    private const POLL_INTERVAL_USEC = 100_000;
    private const TERM_GRACE_SECONDS = 5.0;

    /** @var callable */
    private $logger;

    /**
     * @param callable|null $logger fn(string $line): void; defaults to echo for
     *                              CLI use. Tests pass a buffered logger.
     */
    public function __construct(?callable $logger = null)
    {
        $this->logger = $logger ?? static function (string $line): void {
            echo $line . "\n";
        };
    }

    /**
     * Default per-job timeout in seconds derived from a 5-field cron
     * expression. The intent is to never let a job spill into its own
     * next tick — a `* * * * *` job that hangs for 60 s would otherwise
     * still be running when the next minute's spawn arrives.
     */
    public static function defaultTimeoutForSchedule(string $schedule): int
    {
        $schedule = trim($schedule);
        if ($schedule === '* * * * *') {
            return 50;
        }
        if (preg_match('/^\*\/(\d+)\s/', $schedule, $m)) {
            $stepSeconds = ((int) $m[1]) * 60;
            return max(60, $stepSeconds - 60);
        }
        return 1800;
    }

    /**
     * Dispatch a set of due jobs in parallel, respecting `$maxConcurrent`
     * and each job's per-job timeout.
     *
     * @param array<string, array{name: string, script: string, schedule: string, timeout?: int}> $jobs
     *        Map of job key → spec. `script` must be an absolute path to a PHP file.
     * @param int  $maxConcurrent Cap on parallel children. Defaults to
     *                            DEFAULT_MAX_CONCURRENT to keep midnight-tick
     *                            convergence from overwhelming the DB.
     * @return array<int, array{key: string, name: string, exit_code: int, duration_seconds: float, timed_out: bool, started: bool}>
     *         One entry per job in the order of completion (or in
     *         dispatch order for jobs that never started).
     */
    public function dispatch(array $jobs, int $maxConcurrent = self::DEFAULT_MAX_CONCURRENT): array
    {
        if ($maxConcurrent < 1) {
            throw new RuntimeException('maxConcurrent must be >= 1');
        }
        if (empty($jobs)) {
            return [];
        }

        $queue = [];
        foreach ($jobs as $key => $job) {
            $queue[] = [(string) $key, $job];
        }

        $active = [];
        $results = [];

        while (!empty($queue) || !empty($active)) {
            while (!empty($queue) && count($active) < $maxConcurrent) {
                [$key, $job] = array_shift($queue);
                $entry = $this->startJob($key, $job);
                if ($entry === null) {
                    $results[] = [
                        'key' => $key,
                        'name' => $job['name'] ?? $key,
                        'exit_code' => -1,
                        'duration_seconds' => 0.0,
                        'timed_out' => false,
                        'started' => false,
                    ];
                    continue;
                }
                $active[$key] = $entry;
            }

            foreach ($active as $key => $entry) {
                $this->drainPipes($active[$key]);
                $status = proc_get_status($entry['proc']);
                $elapsed = microtime(true) - $entry['started_at'];

                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    $results[] = $this->finishJob($key, $active[$key], $exitCode, $elapsed, false);
                    unset($active[$key]);
                    continue;
                }

                if ($elapsed >= $entry['timeout']) {
                    $exitCode = $this->killJob($active[$key]);
                    $results[] = $this->finishJob($key, $active[$key], $exitCode, $elapsed, true);
                    unset($active[$key]);
                }
            }

            if (!empty($active)) {
                usleep(self::POLL_INTERVAL_USEC);
            }
        }

        return $results;
    }

    /**
     * @param array{name: string, script: string, schedule: string, timeout?: int} $job
     * @return array{proc: resource, pipes: array<int, resource>, started_at: float, timeout: int, job: array<string, mixed>, stdout: string, stderr: string}|null
     */
    private function startJob(string $key, array $job): ?array
    {
        $script = $job['script'] ?? '';
        $name = $job['name'] ?? $key;

        if (!is_string($script) || $script === '' || !file_exists($script)) {
            $this->log(sprintf('[%s] SKIP: %s — script not found (%s)',
                date('Y-m-d H:i:s'), $name, $script));
            return null;
        }

        $timeout = isset($job['timeout']) && (int) $job['timeout'] > 0
            ? (int) $job['timeout']
            : self::defaultTimeoutForSchedule((string) ($job['schedule'] ?? ''));

        $this->log(sprintf('[%s] Starting: %s (timeout=%ds)',
            date('Y-m-d H:i:s'), $name, $timeout));

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $proc = proc_open(['php', $script], $descriptors, $pipes);
        if (!is_resource($proc)) {
            $this->log(sprintf('[%s] FAIL: %s — proc_open returned false',
                date('Y-m-d H:i:s'), $name));
            return null;
        }

        // Stdin gets nothing.
        fclose($pipes[0]);
        unset($pipes[0]);
        // Non-blocking reads so a chatty child can't deadlock the dispatcher
        // by filling its pipe buffer while we sit in usleep().
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return [
            'proc' => $proc,
            'pipes' => $pipes,
            'started_at' => microtime(true),
            'timeout' => $timeout,
            'job' => $job,
            'stdout' => '',
            'stderr' => '',
        ];
    }

    /**
     * Drain whatever the child has written to stdout/stderr without blocking.
     */
    private function drainPipes(array &$entry): void
    {
        foreach ([1 => 'stdout', 2 => 'stderr'] as $fd => $bucket) {
            if (!isset($entry['pipes'][$fd]) || !is_resource($entry['pipes'][$fd])) {
                continue;
            }
            while (($chunk = @fread($entry['pipes'][$fd], 8192)) !== false && $chunk !== '') {
                $entry[$bucket] .= $chunk;
            }
        }
    }

    /**
     * Send SIGTERM, give the child up to TERM_GRACE_SECONDS to exit
     * cleanly, then escalate to SIGKILL. Returns the resulting exit
     * code (or -1 if the platform did not surface one).
     */
    private function killJob(array &$entry): int
    {
        $name = $entry['job']['name'] ?? '?';
        $this->log(sprintf('[%s] TIMEOUT: %s exceeded %ds — sending SIGTERM',
            date('Y-m-d H:i:s'), $name, $entry['timeout']));
        proc_terminate($entry['proc'], 15); // SIGTERM

        $deadline = microtime(true) + self::TERM_GRACE_SECONDS;
        while (microtime(true) < $deadline) {
            $status = proc_get_status($entry['proc']);
            if (!$status['running']) {
                return (int) $status['exitcode'];
            }
            usleep(self::POLL_INTERVAL_USEC);
        }

        $this->log(sprintf('[%s] SIGKILL: %s did not exit within %ds of SIGTERM',
            date('Y-m-d H:i:s'), $name, (int) self::TERM_GRACE_SECONDS));
        proc_terminate($entry['proc'], 9); // SIGKILL

        // Reap. proc_close blocks until the child is collected.
        return -1;
    }

    /**
     * Close pipes, reap the child, emit per-job summary lines, and
     * return the structured result row.
     */
    private function finishJob(string $key, array $entry, int $exitCode, float $duration, bool $timedOut): array
    {
        $this->drainPipes($entry);
        foreach ($entry['pipes'] as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $closed = proc_close($entry['proc']);
        // proc_close returns the exit code if proc_get_status had not
        // already consumed it; prefer the value we captured first.
        if ($exitCode === -1 && $closed !== -1) {
            $exitCode = $closed;
        }

        $name = $entry['job']['name'] ?? $key;

        if ($entry['stdout'] !== '') {
            foreach (preg_split("/\r\n|\n|\r/", rtrim($entry['stdout'])) ?: [] as $line) {
                if ($line !== '') {
                    $this->log("  {$line}");
                }
            }
        }
        if ($entry['stderr'] !== '') {
            foreach (preg_split("/\r\n|\n|\r/", rtrim($entry['stderr'])) ?: [] as $line) {
                if ($line !== '') {
                    $this->log("  [stderr] {$line}");
                }
            }
        }

        if ($timedOut) {
            $this->log(sprintf('  [TIMEOUT] %s killed after %.2fs (exit=%d)',
                $name, $duration, $exitCode));
        } elseif ($exitCode !== 0) {
            $this->log(sprintf('  [ERROR] %s failed with code %d (%.2fs)',
                $name, $exitCode, $duration));
        } else {
            $this->log(sprintf('  [OK] %s completed in %.2fs',
                $name, $duration));
        }

        return [
            'key' => $key,
            'name' => $name,
            'exit_code' => $exitCode,
            'duration_seconds' => round($duration, 3),
            'timed_out' => $timedOut,
            'started' => true,
        ];
    }

    private function log(string $line): void
    {
        ($this->logger)($line);
    }
}
