<?php

namespace App\Services\Procurement;

use App\Models\User;
use App\Models\Vendor;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Phase 18 / S5 — vendor master service.
 *
 * Wraps VendorRepository with the permission gate (procurement.view /
 * procurement.manage) and audit logging.
 */
class VendorService
{
    public function __construct(
        private readonly VendorRepository $repo,
        private readonly AccessGate $gate,
        private readonly ?AuditLogger $audit = null,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{data: array<int, Vendor>, total: int}
     */
    public function search(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'procurement.view');
        return $this->repo->search($filters);
    }

    public function get(User $actor, int $id): Vendor
    {
        $this->gate->assert($actor, 'procurement.view');
        $vendor = $this->repo->findById($id);
        if ($vendor === null) {
            throw new InvalidArgumentException("vendor {$id} not found");
        }
        return $vendor;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(User $actor, array $data): Vendor
    {
        $this->gate->assert($actor, 'procurement.manage');
        $name = isset($data['name']) ? trim((string) $data['name']) : '';
        if ($name === '') {
            throw new InvalidArgumentException('name is required');
        }
        $status = $data['status'] ?? Vendor::STATUS_ACTIVE;
        if (!in_array($status, Vendor::STATUSES, true)) {
            throw new InvalidArgumentException("invalid status '{$status}'");
        }
        $data['name'] = $name;
        $vendor = $this->repo->create($data);
        $this->logAudit('vendor.create', $vendor->id, $actor->id, ['name' => $vendor->name]);
        return $vendor;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(User $actor, int $id, array $data): Vendor
    {
        $this->gate->assert($actor, 'procurement.manage');
        $existing = $this->get($actor, $id);
        if (array_key_exists('name', $data)) {
            $name = trim((string) $data['name']);
            if ($name === '') {
                throw new InvalidArgumentException('name cannot be empty');
            }
            $data['name'] = $name;
        }
        if (array_key_exists('status', $data) && !in_array($data['status'], Vendor::STATUSES, true)) {
            throw new InvalidArgumentException("invalid status '{$data['status']}'");
        }
        $updated = $this->repo->update($id, $data);
        $this->logAudit('vendor.update', $id, $actor->id, ['changed' => array_keys($data), 'name' => $existing->name]);
        return $updated;
    }

    public function delete(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'procurement.manage');
        $existing = $this->get($actor, $id);
        $this->repo->delete($id);
        $this->logAudit('vendor.delete', $id, $actor->id, ['name' => $existing->name]);
    }

    private function logAudit(string $event, int $entityId, int $actorId, array $context): void
    {
        if ($this->audit === null) {
            return;
        }
        $this->audit->log(new AuditEntry($event, 'vendor', $entityId, $actorId, $context));
    }
}
