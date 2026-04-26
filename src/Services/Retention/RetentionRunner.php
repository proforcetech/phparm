<?php

namespace App\Services\Retention;

use App\Database\Connection;
use App\Models\DataRetentionPolicy;
use App\Models\DataRetentionRun;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Executes a retention policy: counts the rows older than the cutoff,
 * then either deletes them, copies-and-deletes them into an archive
 * table, or — when dry_run — only counts.
 *
 * Driver-tolerant: queries information_schema on MySQL, sqlite_master
 * on SQLite, so the same runner works in production and in the
 * SQLite-gated test harness.
 */
class RetentionRunner
{
    public function __construct(
        private Connection $connection,
        private RetentionPolicyRepository $policies,
        private RetentionRunRepository $runs,
        private AccessGate $gate
    ) {
    }

    public function runById(?User $actor, int $policyId, bool $dryRun = false): DataRetentionRun
    {
        if ($actor !== null) {
            $this->gate->assert($actor, 'retention.run');
        }
        $policy = $this->policies->find($policyId);
        if ($policy === null) {
            throw new InvalidArgumentException('Retention policy not found.');
        }

        return $this->runPolicy($policy, $dryRun, $actor?->id);
    }

    /**
     * @return array<int, DataRetentionRun>
     */
    public function runAllActive(?User $actor, bool $dryRun = false): array
    {
        if ($actor !== null) {
            $this->gate->assert($actor, 'retention.run');
        }
        $results = [];
        foreach ($this->policies->listActive() as $policy) {
            try {
                $results[] = $this->runPolicy($policy, $dryRun, $actor?->id);
            } catch (Throwable $e) {
                // Per-policy failure isolation — one broken policy must
                // not stop the rest of the cron run. The failure is
                // already recorded on the run row inside runPolicy().
                continue;
            }
        }

        return $results;
    }

    public function runPolicy(DataRetentionPolicy $policy, bool $dryRun, ?int $userId): DataRetentionRun
    {
        if ($policy->id === null) {
            throw new InvalidArgumentException('Cannot run unsaved policy.');
        }

        $run = $this->runs->start($policy->id, $dryRun, $userId);
        $runId = (int) $run->id;

        try {
            if (!$this->tableExists($policy->table_name)) {
                $this->finalize($policy, $runId, DataRetentionRun::STATUS_SKIPPED, 0, 0, 'Table not present.');
                return $this->runs->find($runId) ?? $run;
            }

            $cutoff = $this->cutoffTimestamp($policy);
            $examined = $this->countCandidates($policy, $cutoff);

            if ($dryRun) {
                $this->finalize($policy, $runId, DataRetentionRun::STATUS_DRY_RUN, $examined, 0, null);
                return $this->runs->find($runId) ?? $run;
            }

            if ($examined === 0) {
                $this->finalize($policy, $runId, DataRetentionRun::STATUS_SUCCESS, 0, 0, null);
                return $this->runs->find($runId) ?? $run;
            }

            $affected = match ($policy->action) {
                DataRetentionPolicy::ACTION_DELETE => $this->deleteCandidates($policy, $cutoff),
                DataRetentionPolicy::ACTION_ARCHIVE => $this->archiveCandidates($policy, $cutoff),
                default => throw new RuntimeException('Unknown retention action: ' . $policy->action),
            };

            $this->finalize($policy, $runId, DataRetentionRun::STATUS_SUCCESS, $examined, $affected, null);

            return $this->runs->find($runId) ?? $run;
        } catch (Throwable $e) {
            $this->finalize($policy, $runId, DataRetentionRun::STATUS_FAILED, null, null, $e->getMessage());
            throw $e;
        }
    }

    private function finalize(
        DataRetentionPolicy $policy,
        int $runId,
        string $status,
        ?int $examined,
        ?int $affected,
        ?string $error
    ): void {
        $this->runs->complete($runId, $status, $examined, $affected, $error);
        if ($policy->id !== null) {
            $this->policies->recordRunSummary($policy->id, $status, $affected);
        }
    }

    private function cutoffTimestamp(DataRetentionPolicy $policy): string
    {
        $now = new DateTimeImmutable();
        $cutoff = $now->modify('-' . max(0, $policy->retention_days) . ' days');
        return $cutoff->format('Y-m-d H:i:s');
    }

    private function countCandidates(DataRetentionPolicy $policy, string $cutoff): int
    {
        $sql = sprintf(
            'SELECT COUNT(*) FROM %s WHERE %s < :cutoff',
            $this->quoteIdent($policy->table_name),
            $this->quoteIdent($policy->timestamp_column)
        );
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['cutoff' => $cutoff]);

        return (int) $stmt->fetchColumn();
    }

    private function deleteCandidates(DataRetentionPolicy $policy, string $cutoff): int
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s < :cutoff',
            $this->quoteIdent($policy->table_name),
            $this->quoteIdent($policy->timestamp_column)
        );
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['cutoff' => $cutoff]);

        return $stmt->rowCount();
    }

    private function archiveCandidates(DataRetentionPolicy $policy, string $cutoff): int
    {
        $archive = $policy->archive_table_name;
        if ($archive === null || $archive === '') {
            throw new RuntimeException('Archive policy missing archive_table_name.');
        }
        if (!$this->tableExists($archive)) {
            throw new RuntimeException('Archive table does not exist: ' . $archive);
        }

        $pdo = $this->connection->pdo();
        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        try {
            $copySql = sprintf(
                'INSERT INTO %s SELECT * FROM %s WHERE %s < :cutoff',
                $this->quoteIdent($archive),
                $this->quoteIdent($policy->table_name),
                $this->quoteIdent($policy->timestamp_column)
            );
            $pdo->prepare($copySql)->execute(['cutoff' => $cutoff]);

            $deleteSql = sprintf(
                'DELETE FROM %s WHERE %s < :cutoff',
                $this->quoteIdent($policy->table_name),
                $this->quoteIdent($policy->timestamp_column)
            );
            $stmt = $pdo->prepare($deleteSql);
            $stmt->execute(['cutoff' => $cutoff]);
            $affected = $stmt->rowCount();

            if ($startedTransaction) {
                $pdo->commit();
            }

            return $affected;
        } catch (Throwable $e) {
            if ($startedTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    private function tableExists(string $table): bool
    {
        $pdo = $this->connection->pdo();
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :n"
            );
            $stmt->execute(['n' => $table]);
            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :n'
        );
        $stmt->execute(['n' => $table]);
        return (int) $stmt->fetchColumn() > 0;
    }

    private function quoteIdent(string $name): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) !== 1) {
            throw new InvalidArgumentException('Invalid identifier: ' . $name);
        }

        $driver = $this->connection->pdo()->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            return '`' . $name . '`';
        }

        return '"' . $name . '"';
    }
}
