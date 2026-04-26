<?php

namespace App\Services\Workorder\Kit;

use App\Database\Connection;
use App\Models\User;
use App\Models\WorkorderKitInstall;
use App\Services\Estimate\BundleService;
use App\Services\Inventory\InventoryTransactionRepository;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Phase 10.8 — Kit/bundle install support on workorders.
 *
 * The estimate side already had BundleService::applyToEstimate() which
 * resolves bundle items into estimate line items. This service is the
 * symmetric workorder-side flow: it lets a tech (or dispatcher prepping a
 * job) apply a bundle directly to a workorder, with a planned/installed
 * lifecycle and proper inventory consumption when the install actually
 * happens. Cancelling an installed kit reverses both the WO line items it
 * added and the inventory it consumed.
 *
 * Permissions:
 *   workorder_kits.view     read installs + items
 *   workorder_kits.install  plan + transition planned→installed
 *   workorder_kits.cancel   cancel a planned/installed install
 *   workorder_kits.manage   delete a planned install entirely (purges
 *                           snapshot rows from history, distinct from
 *                           cancel which keeps the audit trail)
 */
class WorkorderKitInstallService
{
    public function __construct(
        private Connection $connection,
        private WorkorderKitInstallRepository $repo,
        private BundleService $bundleService,
        private InventoryTransactionRepository $txRepo,
        private AccessGate $gate,
        private ?DateTimeImmutable $now = null,
    ) {
    }

    private function now(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable();
    }

    private function nowString(): string
    {
        return $this->now()->format('Y-m-d H:i:s');
    }

    /**
     * @return array<string, mixed>
     */
    public function get(User $actor, int $installId): array
    {
        $this->gate->assert($actor, 'workorder_kits.view');

        $install = $this->repo->find($installId);
        if ($install === null) {
            throw new InvalidArgumentException('Kit install not found.');
        }

        return $this->bundle($install);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForWorkorder(User $actor, int $workorderId): array
    {
        $this->gate->assert($actor, 'workorder_kits.view');

        return array_map(
            fn (WorkorderKitInstall $install): array => $this->bundle($install),
            $this->repo->listForWorkorder($workorderId)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listForJob(User $actor, int $jobId): array
    {
        $this->gate->assert($actor, 'workorder_kits.view');

        return array_map(
            fn (WorkorderKitInstall $install): array => $this->bundle($install),
            $this->repo->listForJob($jobId)
        );
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPlanned(User $actor, int $limit = 50, int $offset = 0): array
    {
        $this->gate->assert($actor, 'workorder_kits.view');

        return array_map(
            fn (WorkorderKitInstall $install): array => $this->bundle($install),
            $this->repo->listByStatus(WorkorderKitInstall::STATUS_PLANNED, $limit, $offset)
        );
    }

    /**
     * Plan an install — snapshots bundle items into the install, but does
     * not touch inventory or the workorder's line items yet. The install
     * transitions to "installed" via install() once the tech is ready to
     * actually apply the kit.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function plan(User $actor, array $payload): array
    {
        $this->gate->assert($actor, 'workorder_kits.install');

        $workorderId = (int) ($payload['workorder_id'] ?? 0);
        if ($workorderId <= 0) {
            throw new InvalidArgumentException('workorder_id is required.');
        }

        $bundleId = (int) ($payload['bundle_id'] ?? 0);
        if ($bundleId <= 0) {
            throw new InvalidArgumentException('bundle_id is required.');
        }

        $bundle = $this->bundleService->find($bundleId);
        if ($bundle === null) {
            throw new InvalidArgumentException('Bundle not found.');
        }

        $jobId = isset($payload['workorder_job_id']) ? (int) $payload['workorder_job_id'] : null;
        $items = $this->bundleService->fetchBundleItems($bundleId);
        if ($items === []) {
            throw new InvalidArgumentException('Bundle has no items to install.');
        }

        $pdo = $this->connection->pdo();
        $usingTransaction = !$pdo->inTransaction();
        if ($usingTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $install = $this->repo->create([
                'workorder_id' => $workorderId,
                'workorder_job_id' => $jobId,
                'bundle_id' => $bundleId,
                'bundle_name_snapshot' => $bundle->name,
                'installed_by_user_id' => $actor->id,
                'status' => WorkorderKitInstall::STATUS_PLANNED,
                'planned_at' => $this->nowString(),
                'notes' => $payload['notes'] ?? null,
                'total_parts_consumed' => 0,
            ]);

            foreach ($items as $bundleItem) {
                $quantity = (float) ($bundleItem['quantity'] ?? 1);
                $unitPrice = (float) ($bundleItem['unit_price'] ?? 0);
                $type = strtoupper((string) $bundleItem['type']);

                $this->repo->addItem((int) $install->id, [
                    'workorder_item_id' => null,
                    'bundle_item_id' => null,
                    'inventory_item_id' => null,
                    'type' => $type,
                    'description' => $bundleItem['description'],
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'line_total' => $unitPrice * $quantity,
                    'stock_consumed' => 0,
                    'stock_consumed_at' => null,
                ]);
            }

            if ($usingTransaction) {
                $pdo->commit();
            }

            return $this->bundle($this->repo->find((int) $install->id));
        } catch (Throwable $exception) {
            if ($usingTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Transition planned → installed: materialise workorder_items rows on
     * the WO (one per snapshot item) and decrement inventory for PART
     * items where we can resolve an inventory_item_id.
     *
     * @return array<string, mixed>
     */
    public function install(User $actor, int $installId): array
    {
        $this->gate->assert($actor, 'workorder_kits.install');

        $install = $this->mustFind($installId);
        $this->assertTransition($install, WorkorderKitInstall::STATUS_INSTALLED);

        $jobId = $install->workorder_job_id ?? $this->resolveDefaultJob($install->workorder_id);
        if ($jobId === null) {
            throw new RuntimeException('No workorder_jobs row available for install (planned install needs a job target).');
        }

        $items = $this->repo->itemsForInstall($installId);
        if ($items === []) {
            throw new RuntimeException('Install has no snapshot items.');
        }

        $pdo = $this->connection->pdo();
        $usingTransaction = !$pdo->inTransaction();
        if ($usingTransaction) {
            $pdo->beginTransaction();
        }

        try {
            $maxPositionStmt = $pdo->prepare(
                'SELECT COALESCE(MAX(position), -1) AS max_pos FROM workorder_items WHERE workorder_job_id = :job_id'
            );
            $maxPositionStmt->execute(['job_id' => $jobId]);
            $position = ((int) $maxPositionStmt->fetchColumn()) + 1;

            $totalConsumed = 0;
            foreach ($items as $item) {
                $insertWi = $pdo->prepare(
                    'INSERT INTO workorder_items '
                    . '(workorder_job_id, type, description, quantity, unit_price, taxable, line_total, position) '
                    . 'VALUES (:job_id, :type, :description, :quantity, :unit_price, 1, :line_total, :position)'
                );
                $insertWi->execute([
                    'job_id' => $jobId,
                    'type' => $item->type,
                    'description' => $item->description,
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'line_total' => $item->line_total,
                    'position' => $position++,
                ]);
                $workorderItemId = (int) $pdo->lastInsertId();

                $stockConsumed = 0;
                $stockConsumedAt = null;
                $inventoryItemId = $item->inventory_item_id;

                if ($item->type === 'PART') {
                    if ($inventoryItemId === null) {
                        $inventoryItemId = $this->resolveInventoryItemId($item->description);
                    }

                    if ($inventoryItemId !== null) {
                        $consumed = $this->consumeStock($inventoryItemId, $item->quantity, $actor->id, $installId);
                        if ($consumed > 0) {
                            $stockConsumed = $consumed;
                            $stockConsumedAt = $this->nowString();
                            $totalConsumed += $consumed;
                        }
                    }
                }

                $this->repo->updateItem((int) $item->id, [
                    'workorder_item_id' => $workorderItemId,
                    'inventory_item_id' => $inventoryItemId,
                    'stock_consumed' => $stockConsumed,
                    'stock_consumed_at' => $stockConsumedAt,
                ]);
            }

            $this->repo->update($installId, [
                'status' => WorkorderKitInstall::STATUS_INSTALLED,
                'installed_at' => $this->nowString(),
                'installed_by_user_id' => $actor->id,
                'total_parts_consumed' => $totalConsumed,
            ]);

            if ($usingTransaction) {
                $pdo->commit();
            }

            return $this->bundle($this->repo->find($installId));
        } catch (Throwable $exception) {
            if ($usingTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Cancel an install. If it was already in "installed" state the
     * workorder_items rows it created are removed and the inventory it
     * consumed is returned to stock; if it was still planned only the
     * status flip happens (and the snapshot remains for audit).
     *
     * @return array<string, mixed>
     */
    public function cancel(User $actor, int $installId, ?string $reason = null): array
    {
        $this->gate->assert($actor, 'workorder_kits.cancel');

        $install = $this->mustFind($installId);
        $this->assertTransition($install, WorkorderKitInstall::STATUS_CANCELLED);

        $pdo = $this->connection->pdo();
        $usingTransaction = !$pdo->inTransaction();
        if ($usingTransaction) {
            $pdo->beginTransaction();
        }

        try {
            if ($install->status === WorkorderKitInstall::STATUS_INSTALLED) {
                foreach ($this->repo->itemsForInstall($installId) as $item) {
                    if ($item->workorder_item_id !== null) {
                        $del = $pdo->prepare('DELETE FROM workorder_items WHERE id = :id');
                        $del->execute(['id' => $item->workorder_item_id]);
                    }

                    if ($item->stock_consumed > 0 && $item->inventory_item_id !== null) {
                        $this->returnStock(
                            $item->inventory_item_id,
                            $item->stock_consumed,
                            $actor->id,
                            $installId
                        );
                    }

                    $this->repo->updateItem((int) $item->id, [
                        'workorder_item_id' => null,
                        'stock_consumed' => 0,
                        'stock_consumed_at' => null,
                    ]);
                }
            }

            $this->repo->update($installId, [
                'status' => WorkorderKitInstall::STATUS_CANCELLED,
                'cancelled_at' => $this->nowString(),
                'cancellation_reason' => $reason,
                'total_parts_consumed' => 0,
            ]);

            if ($usingTransaction) {
                $pdo->commit();
            }

            return $this->bundle($this->repo->find($installId));
        } catch (Throwable $exception) {
            if ($usingTransaction && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    /**
     * Hard-delete a planned install. Disallowed for installed/cancelled
     * installs because those carry historical state worth preserving —
     * cancel them instead.
     */
    public function delete(User $actor, int $installId): bool
    {
        $this->gate->assert($actor, 'workorder_kits.manage');

        $install = $this->mustFind($installId);
        if ($install->status !== WorkorderKitInstall::STATUS_PLANNED) {
            throw new InvalidArgumentException('Only planned installs may be deleted; cancel installed kits instead.');
        }

        return $this->repo->delete($installId);
    }

    private function mustFind(int $installId): WorkorderKitInstall
    {
        $install = $this->repo->find($installId);
        if ($install === null) {
            throw new InvalidArgumentException('Kit install not found.');
        }
        return $install;
    }

    private function assertTransition(WorkorderKitInstall $install, string $to): void
    {
        $allowed = WorkorderKitInstall::ALLOWED_TRANSITIONS[$install->status] ?? [];
        if (!in_array($to, $allowed, true)) {
            throw new InvalidArgumentException(
                'Cannot transition install from ' . $install->status . ' to ' . $to . '.'
            );
        }
    }

    /**
     * Read currently-tracked stock and decrement by ceil(quantity), capped
     * at the available level so we never go negative. Returns the
     * integer units consumed (0 means no consumption — caller leaves
     * stock_consumed at 0 and the parts pull system handles it).
     */
    private function consumeStock(int $inventoryItemId, float $quantity, ?int $actorId, int $installId): int
    {
        $needed = (int) ceil($quantity);
        if ($needed <= 0) {
            return 0;
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare('SELECT stock_quantity FROM inventory_items WHERE id = :id');
        $stmt->execute(['id' => $inventoryItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return 0;
        }

        $before = (int) ($row['stock_quantity'] ?? 0);
        if ($before <= 0) {
            return 0;
        }

        $consumed = min($before, $needed);
        $after = $before - $consumed;

        $update = $pdo->prepare('UPDATE inventory_items SET stock_quantity = :after WHERE id = :id');
        $update->execute(['after' => $after, 'id' => $inventoryItemId]);

        $this->txRepo->record(
            $inventoryItemId,
            $before,
            $after,
            'workorder_kit_install',
            'install_id:' . $installId,
            'Kit install on workorder',
            $actorId,
            null
        );

        return $consumed;
    }

    private function returnStock(int $inventoryItemId, int $units, ?int $actorId, int $installId): void
    {
        if ($units <= 0) {
            return;
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare('SELECT stock_quantity FROM inventory_items WHERE id = :id');
        $stmt->execute(['id' => $inventoryItemId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return;
        }

        $before = (int) ($row['stock_quantity'] ?? 0);
        $after = $before + $units;

        $update = $pdo->prepare('UPDATE inventory_items SET stock_quantity = :after WHERE id = :id');
        $update->execute(['after' => $after, 'id' => $inventoryItemId]);

        $this->txRepo->record(
            $inventoryItemId,
            $before,
            $after,
            'workorder_kit_install_cancel',
            'install_id:' . $installId,
            'Kit install cancelled — stock returned',
            $actorId,
            null
        );
    }

    /**
     * SKU/description match against inventory_items. Matches the brittle
     * description-pivot used by BundleService::applyToEstimate(); the
     * estimate side TODO-ed a part-type table — when that lands, both
     * sides should switch to it.
     */
    private function resolveInventoryItemId(string $description): ?int
    {
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare(
            'SELECT id FROM inventory_items '
            . 'WHERE LOWER(name) = LOWER(:desc) OR LOWER(description) = LOWER(:desc) LIMIT 1'
        );
        $stmt->execute(['desc' => $description]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['id'];
    }

    /**
     * Pick a default workorder_jobs row when the install header didn't
     * pin one — first job by position. Used so a "kit install on the WO"
     * UX can skip the job picker and still produce valid line items.
     */
    private function resolveDefaultJob(int $workorderId): ?int
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT id FROM workorder_jobs WHERE workorder_id = :wo ORDER BY position ASC, id ASC LIMIT 1'
        );
        $stmt->execute(['wo' => $workorderId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : (int) $row['id'];
    }

    /**
     * @return array<string, mixed>
     */
    private function bundle(?WorkorderKitInstall $install): array
    {
        if ($install === null) {
            throw new RuntimeException('Install vanished during read-back.');
        }

        $items = array_map(
            fn ($item) => $item->toArray(),
            $this->repo->itemsForInstall((int) $install->id)
        );

        return [
            'install' => $install->toArray(),
            'items' => $items,
        ];
    }
}
