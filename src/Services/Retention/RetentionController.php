<?php

namespace App\Services\Retention;

use App\Models\DataRetentionPolicy;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

class RetentionController
{
    public function __construct(
        private RetentionPolicyRepository $policies,
        private RetentionRunRepository $runs,
        private RetentionRunner $runner,
        private AccessGate $gate
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function listPolicies(User $actor): array
    {
        $this->gate->assert($actor, 'retention.view');

        $items = array_map(static fn (DataRetentionPolicy $p): array => $p->toArray(), $this->policies->listAll());

        return ['data' => $items];
    }

    /**
     * @return array<string, mixed>
     */
    public function getPolicy(User $actor, int $id): array
    {
        $this->gate->assert($actor, 'retention.view');

        $policy = $this->policies->find($id);
        if ($policy === null) {
            throw new InvalidArgumentException('Retention policy not found.');
        }

        $recent = array_map(static fn ($r) => $r->toArray(), $this->runs->listForPolicy($id, 25));

        return ['data' => ['policy' => $policy->toArray(), 'recent_runs' => $recent]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createPolicy(User $actor, array $payload): array
    {
        $this->gate->assert($actor, 'retention.manage');
        $this->validatePayload($payload, true);

        $policy = $this->policies->create($payload);

        return ['data' => $policy->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updatePolicy(User $actor, int $id, array $payload): array
    {
        $this->gate->assert($actor, 'retention.manage');

        if ($this->policies->find($id) === null) {
            throw new InvalidArgumentException('Retention policy not found.');
        }
        $this->validatePayload($payload, false);

        $policy = $this->policies->update($id, $payload);
        if ($policy === null) {
            throw new InvalidArgumentException('Retention policy not found.');
        }

        return ['data' => $policy->toArray()];
    }

    /**
     * @return array<string, mixed>
     */
    public function deletePolicy(User $actor, int $id): array
    {
        $this->gate->assert($actor, 'retention.manage');
        $deleted = $this->policies->delete($id);

        return ['data' => ['deleted' => $deleted]];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function runPolicy(User $actor, int $id, array $payload): array
    {
        $dryRun = !empty($payload['dry_run']);
        $run = $this->runner->runById($actor, $id, $dryRun);

        return ['data' => $run->toArray()];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function runAll(User $actor, array $payload): array
    {
        $dryRun = !empty($payload['dry_run']);
        $runs = $this->runner->runAllActive($actor, $dryRun);

        return ['data' => array_map(static fn ($r) => $r->toArray(), $runs)];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function listRuns(User $actor, array $query): array
    {
        $this->gate->assert($actor, 'retention.view');
        $limit = isset($query['limit']) ? max(1, min(200, (int) $query['limit'])) : 100;

        $runs = $this->runs->listRecent($limit);

        return ['data' => array_map(static fn ($r) => $r->toArray(), $runs)];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function validatePayload(array $payload, bool $isCreate): void
    {
        if ($isCreate) {
            if (empty($payload['entity_type'])) {
                throw new InvalidArgumentException('entity_type is required.');
            }
            if (empty($payload['table_name'])) {
                throw new InvalidArgumentException('table_name is required.');
            }
        }
        if (isset($payload['action']) && !in_array($payload['action'], DataRetentionPolicy::ACTIONS, true)) {
            throw new InvalidArgumentException('Invalid action: ' . $payload['action']);
        }
        if (isset($payload['retention_days']) && (int) $payload['retention_days'] < 0) {
            throw new InvalidArgumentException('retention_days must be >= 0.');
        }
        if (
            isset($payload['action'])
            && $payload['action'] === DataRetentionPolicy::ACTION_ARCHIVE
            && empty($payload['archive_table_name'])
        ) {
            throw new InvalidArgumentException('archive_table_name is required for archive action.');
        }
    }
}
