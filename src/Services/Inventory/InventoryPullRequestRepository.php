<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use App\Models\InventoryPullRequest;
use App\Support\Audit\AuditLogger;
use App\Support\Audit\AuditEntry;
use App\Services\Messaging\MessagingNotificationService;
use App\Services\Inventory\InventoryTransactionRepository;
use InvalidArgumentException;
use PDO;

class InventoryPullRequestRepository
{
    private Connection $connection;
    private AuditLogger $auditLogger;
    private ?MessagingNotificationService $messagingNotifications;
    private InventoryTransactionRepository $transactionRepository;

    public function __construct(
        Connection $connection,
        AuditLogger $auditLogger,
        ?MessagingNotificationService $messagingNotifications = null
    ) {
        $this->connection = $connection;
        $this->auditLogger = $auditLogger;
        $this->messagingNotifications = $messagingNotifications;
        $this->transactionRepository = new InventoryTransactionRepository($connection);
    }

    public function find(int $id): ?InventoryPullRequest
    {
        $sql = "SELECT pr.*,
                w.number as workorder_number,
                wj.title as job_description,
                ii.name as inventory_item_name,
                u1.name as requested_by_name,
                u2.name as fulfilled_by_name
            FROM inventory_pull_requests pr
            LEFT JOIN workorders w ON pr.workorder_id = w.id
            LEFT JOIN workorder_jobs wj ON pr.workorder_job_id = wj.id
            LEFT JOIN inventory_items ii ON pr.inventory_item_id = ii.id
            LEFT JOIN users u1 ON pr.requested_by = u1.id
            LEFT JOIN users u2 ON pr.fulfilled_by = u2.id
            WHERE pr.id = :id";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        return $this->mapRow($row);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: InventoryPullRequest[], total: int}
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['workorder_id'])) {
            $clauses[] = 'pr.workorder_id = :workorder_id';
            $bindings['workorder_id'] = (int) $filters['workorder_id'];
        }

        if (array_key_exists('branch_id', $filters) && $filters['branch_id'] !== '' && $filters['branch_id'] !== null) {
            $clauses[] = 'pr.branch_id = :branch_id';
            $bindings['branch_id'] = (int) $filters['branch_id'];
        }

        if (!empty($filters['status'])) {
            $clauses[] = 'pr.status = :status';
            $bindings['status'] = $filters['status'];
        }

        if (!empty($filters['request_type'])) {
            $clauses[] = 'pr.request_type = :request_type';
            $bindings['request_type'] = $filters['request_type'];
        }

        if (!empty($filters['pending_only'])) {
            $clauses[] = "pr.status IN ('pending', 'ordered')";
        }

        $whereClause = count($clauses) > 0 ? 'WHERE ' . implode(' AND ', $clauses) : '';

        // Count total
        $countSql = "SELECT COUNT(*) FROM inventory_pull_requests pr $whereClause";
        $stmt = $this->connection->pdo()->prepare($countSql);
        $stmt->execute($bindings);
        $total = (int) $stmt->fetchColumn();

        // Fetch items
        $sql = "SELECT pr.*,
                w.number as workorder_number,
                wj.title as job_description,
                ii.name as inventory_item_name,
                u1.name as requested_by_name,
                u2.name as fulfilled_by_name
            FROM inventory_pull_requests pr
            LEFT JOIN workorders w ON pr.workorder_id = w.id
            LEFT JOIN workorder_jobs wj ON pr.workorder_job_id = wj.id
            LEFT JOIN inventory_items ii ON pr.inventory_item_id = ii.id
            LEFT JOIN users u1 ON pr.requested_by = u1.id
            LEFT JOIN users u2 ON pr.fulfilled_by = u2.id
            $whereClause
            ORDER BY pr.created_at DESC
            LIMIT :limit OFFSET :offset";

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = $this->mapRow($row);
        }

        return ['items' => $items, 'total' => $total];
    }

    /**
     * @param string[] $statuses
     * @return array{counts: array<string, int>, items: InventoryPullRequest[]}
     */
    public function getDashboardNotifications(array $statuses, int $limit = 5, ?int $branchId = null): array
    {
        $filteredStatuses = array_values(array_filter(
            $statuses,
            fn (string $status) => in_array($status, InventoryPullRequest::ALLOWED_STATUSES, true)
        ));

        if ($filteredStatuses === []) {
            $filteredStatuses = [
                InventoryPullRequest::STATUS_PENDING,
                InventoryPullRequest::STATUS_ORDERED,
                InventoryPullRequest::STATUS_RECEIVED,
            ];
        }

        $placeholders = [];
        foreach ($filteredStatuses as $index => $status) {
            $placeholders[] = ':status_' . $index;
        }
        $placeholdersSql = implode(', ', $placeholders);

        $counts = array_fill_keys($filteredStatuses, 0);
        $countSql = "SELECT pr.status, COUNT(*) as count
            FROM inventory_pull_requests pr
            WHERE pr.status IN ($placeholdersSql)";
        if ($branchId !== null) {
            $countSql .= ' AND pr.branch_id = :branch_id';
        }
        $countSql .= ' GROUP BY pr.status';

        $stmt = $this->connection->pdo()->prepare($countSql);
        foreach ($filteredStatuses as $index => $status) {
            $stmt->bindValue(':status_' . $index, $status);
        }
        if ($branchId !== null) {
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        }
        $stmt->execute();

        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $counts[$row['status']] = (int) $row['count'];
        }

        $sql = "SELECT pr.*,
                w.number as workorder_number,
                wj.title as job_description,
                ii.name as inventory_item_name,
                u1.name as requested_by_name,
                u2.name as fulfilled_by_name
            FROM inventory_pull_requests pr
            LEFT JOIN workorders w ON pr.workorder_id = w.id
            LEFT JOIN workorder_jobs wj ON pr.workorder_job_id = wj.id
            LEFT JOIN inventory_items ii ON pr.inventory_item_id = ii.id
            LEFT JOIN users u1 ON pr.requested_by = u1.id
            LEFT JOIN users u2 ON pr.fulfilled_by = u2.id
            WHERE pr.status IN ($placeholdersSql)";
        if ($branchId !== null) {
            $sql .= ' AND pr.branch_id = :branch_id';
        }
        $sql .= '
            ORDER BY pr.created_at DESC
            LIMIT :limit';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($filteredStatuses as $index => $status) {
            $stmt->bindValue(':status_' . $index, $status);
        }
        if ($branchId !== null) {
            $stmt->bindValue(':branch_id', $branchId, PDO::PARAM_INT);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = $this->mapRow($row);
        }

        return ['counts' => $counts, 'items' => $items];
    }

    /**
     * Get all pull requests for a workorder
     * @return InventoryPullRequest[]
     */
    public function getByWorkorder(int $workorderId): array
    {
        $sql = "SELECT pr.*,
                w.number as workorder_number,
                wj.title as job_description,
                ii.name as inventory_item_name,
                u1.name as requested_by_name,
                u2.name as fulfilled_by_name
            FROM inventory_pull_requests pr
            LEFT JOIN workorders w ON pr.workorder_id = w.id
            LEFT JOIN workorder_jobs wj ON pr.workorder_job_id = wj.id
            LEFT JOIN inventory_items ii ON pr.inventory_item_id = ii.id
            LEFT JOIN users u1 ON pr.requested_by = u1.id
            LEFT JOIN users u2 ON pr.fulfilled_by = u2.id
            WHERE pr.workorder_id = :workorder_id
            ORDER BY pr.created_at DESC";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['workorder_id' => $workorderId]);

        $items = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $items[] = $this->mapRow($row);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(array $data, ?int $actorId = null): InventoryPullRequest
    {
        $this->validateData($data);

        // Determine request type based on inventory availability
        $requestType = $this->determineRequestType($data);

        $sql = "INSERT INTO inventory_pull_requests (
                branch_id, workorder_id, workorder_job_id, inventory_item_id, sku, description,
                quantity_requested, quantity_fulfilled, unit_cost, unit_price,
                status, request_type, notes, vendor, order_reference,
                requested_by, requested_at
            ) VALUES (
                :branch_id, :workorder_id, :workorder_job_id, :inventory_item_id, :sku, :description,
                :quantity_requested, :quantity_fulfilled, :unit_cost, :unit_price,
                :status, :request_type, :notes, :vendor, :order_reference,
                :requested_by, NOW()
            )";

        $this->connection->pdo()->prepare($sql)->execute([
            'branch_id' => array_key_exists('branch_id', $data) ? $data['branch_id'] : null,
            'workorder_id' => (int) $data['workorder_id'],
            'workorder_job_id' => isset($data['workorder_job_id']) ? (int) $data['workorder_job_id'] : null,
            'inventory_item_id' => isset($data['inventory_item_id']) ? (int) $data['inventory_item_id'] : null,
            'sku' => $data['sku'] ?? null,
            'description' => $data['description'],
            'quantity_requested' => (int) ($data['quantity_requested'] ?? 1),
            'quantity_fulfilled' => 0,
            'unit_cost' => (float) ($data['unit_cost'] ?? 0),
            'unit_price' => (float) ($data['unit_price'] ?? 0),
            'status' => InventoryPullRequest::STATUS_PENDING,
            'request_type' => $requestType,
            'notes' => $data['notes'] ?? null,
            'vendor' => $data['vendor'] ?? null,
            'order_reference' => null,
            'requested_by' => $actorId,
        ]);

        $id = (int) $this->connection->pdo()->lastInsertId();

        $this->log('pull_request.created', $id, $actorId, [
            'workorder_id' => $data['workorder_id'],
            'request_type' => $requestType,
        ]);

        $this->messagingNotifications?->dispatch('inventory.pull_request.created', [
            'pull_request_id' => $id,
            'workorder_id' => $data['workorder_id'],
            'request_type' => $requestType,
            'description' => $data['description'],
            'quantity_requested' => (int) ($data['quantity_requested'] ?? 1),
            'actor_id' => $actorId,
        ]);

        return $this->find($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $id, array $data, ?int $actorId = null): ?InventoryPullRequest
    {
        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $sql = "UPDATE inventory_pull_requests SET
                sku = :sku,
                description = :description,
                quantity_requested = :quantity_requested,
                unit_cost = :unit_cost,
                unit_price = :unit_price,
                notes = :notes,
                vendor = :vendor,
                updated_at = NOW()
            WHERE id = :id";

        $this->connection->pdo()->prepare($sql)->execute([
            'sku' => $data['sku'] ?? $existing->sku,
            'description' => $data['description'] ?? $existing->description,
            'quantity_requested' => (int) ($data['quantity_requested'] ?? $existing->quantity_requested),
            'unit_cost' => (float) ($data['unit_cost'] ?? $existing->unit_cost),
            'unit_price' => (float) ($data['unit_price'] ?? $existing->unit_price),
            'notes' => $data['notes'] ?? $existing->notes,
            'vendor' => $data['vendor'] ?? $existing->vendor,
            'id' => $id,
        ]);

        $this->log('pull_request.updated', $id, $actorId);

        return $this->find($id);
    }

    /**
     * Mark request as pulled from inventory
     */
    public function markAsPulled(int $id, int $quantityFulfilled, ?int $actorId = null): ?InventoryPullRequest
    {
        $request = $this->find($id);
        if ($request === null) {
            return null;
        }

        $totalFulfilled = $request->quantity_fulfilled + $quantityFulfilled;
        $status = $totalFulfilled >= $request->quantity_requested
            ? InventoryPullRequest::STATUS_PULLED
            : InventoryPullRequest::STATUS_PENDING;

        $sql = "UPDATE inventory_pull_requests SET
                quantity_fulfilled = :quantity_fulfilled,
                status = :status,
                fulfilled_by = :fulfilled_by,
                fulfilled_at = NOW(),
                updated_at = NOW()
            WHERE id = :id";

        $this->connection->pdo()->prepare($sql)->execute([
            'quantity_fulfilled' => $totalFulfilled,
            'status' => $status,
            'fulfilled_by' => $actorId,
            'id' => $id,
        ]);

        // Deduct from inventory if linked to inventory item
        if ($request->inventory_item_id) {
            $this->deductFromInventory($request->inventory_item_id, $quantityFulfilled, $id, $actorId);
        }

        $this->log('pull_request.pulled', $id, $actorId, [
            'quantity_pulled' => $quantityFulfilled,
            'total_fulfilled' => $totalFulfilled,
        ]);

        $this->messagingNotifications?->dispatch('inventory.pull_request.pulled', [
            'pull_request_id' => $id,
            'quantity_pulled' => $quantityFulfilled,
            'total_fulfilled' => $totalFulfilled,
            'actor_id' => $actorId,
        ]);

        return $this->find($id);
    }

    /**
     * Mark request as ordered
     */
    public function markAsOrdered(int $id, ?string $orderReference = null, ?int $actorId = null): ?InventoryPullRequest
    {
        $sql = "UPDATE inventory_pull_requests SET
                status = :status,
                order_reference = :order_reference,
                updated_at = NOW()
            WHERE id = :id";

        $this->connection->pdo()->prepare($sql)->execute([
            'status' => InventoryPullRequest::STATUS_ORDERED,
            'order_reference' => $orderReference,
            'id' => $id,
        ]);

        $this->log('pull_request.ordered', $id, $actorId, [
            'order_reference' => $orderReference,
        ]);

        $this->messagingNotifications?->dispatch('inventory.pull_request.ordered', [
            'pull_request_id' => $id,
            'order_reference' => $orderReference,
            'actor_id' => $actorId,
        ]);

        return $this->find($id);
    }

    /**
     * Mark ordered request as received
     */
    public function markAsReceived(int $id, int $quantityReceived, ?int $actorId = null): ?InventoryPullRequest
    {
        $request = $this->find($id);
        if ($request === null) {
            return null;
        }

        $totalFulfilled = $request->quantity_fulfilled + $quantityReceived;
        $status = $totalFulfilled >= $request->quantity_requested
            ? InventoryPullRequest::STATUS_RECEIVED
            : InventoryPullRequest::STATUS_ORDERED;

        $sql = "UPDATE inventory_pull_requests SET
                quantity_fulfilled = :quantity_fulfilled,
                status = :status,
                fulfilled_by = :fulfilled_by,
                fulfilled_at = NOW(),
                updated_at = NOW()
            WHERE id = :id";

        $this->connection->pdo()->prepare($sql)->execute([
            'quantity_fulfilled' => $totalFulfilled,
            'status' => $status,
            'fulfilled_by' => $actorId,
            'id' => $id,
        ]);

        $this->log('pull_request.received', $id, $actorId, [
            'quantity_received' => $quantityReceived,
            'total_fulfilled' => $totalFulfilled,
        ]);

        $this->messagingNotifications?->dispatch('inventory.pull_request.received', [
            'pull_request_id' => $id,
            'quantity_received' => $quantityReceived,
            'total_fulfilled' => $totalFulfilled,
            'actor_id' => $actorId,
        ]);

        return $this->find($id);
    }

    /**
     * Cancel a pull request
     */
    public function cancel(int $id, ?int $actorId = null): ?InventoryPullRequest
    {
        $sql = "UPDATE inventory_pull_requests SET
                status = :status,
                updated_at = NOW()
            WHERE id = :id";

        $this->connection->pdo()->prepare($sql)->execute([
            'status' => InventoryPullRequest::STATUS_CANCELLED,
            'id' => $id,
        ]);

        $this->log('pull_request.cancelled', $id, $actorId);

        $this->messagingNotifications?->dispatch('inventory.pull_request.cancelled', [
            'pull_request_id' => $id,
            'actor_id' => $actorId,
        ]);

        return $this->find($id);
    }

    public function delete(int $id): bool
    {
        $stmt = $this->connection->pdo()->prepare('DELETE FROM inventory_pull_requests WHERE id = :id');
        $stmt->execute(['id' => $id]);

        return $stmt->rowCount() > 0;
    }

    /**
     * Get summary counts for dashboard/widgets
     * @return array<string, int>
     */
    public function getSummary(?int $branchId = null): array
    {
        $sql = "SELECT
                SUM(CASE WHEN status = 'pending' AND request_type = 'pull' THEN 1 ELSE 0 END) as pending_pulls,
                SUM(CASE WHEN status = 'pending' AND request_type = 'order' THEN 1 ELSE 0 END) as pending_orders,
                SUM(CASE WHEN status = 'ordered' THEN 1 ELSE 0 END) as on_order,
                COUNT(*) as total
            FROM inventory_pull_requests
            WHERE status NOT IN ('pulled', 'received', 'cancelled')";
        $params = [];
        if ($branchId !== null) {
            $sql .= ' AND branch_id = :branch_id';
            $params['branch_id'] = $branchId;
        }

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'pending_pulls' => (int) ($row['pending_pulls'] ?? 0),
            'pending_orders' => (int) ($row['pending_orders'] ?? 0),
            'on_order' => (int) ($row['on_order'] ?? 0),
            'total_open' => (int) ($row['total'] ?? 0),
        ];
    }

    /**
     * Determine if request should be "pull" or "order" based on inventory
     */
    private function determineRequestType(array $data): string
    {
        // If no inventory item linked, it's an order
        if (empty($data['inventory_item_id'])) {
            return InventoryPullRequest::TYPE_ORDER;
        }

        // Check inventory item - handle case where is_tracked column may not exist
        try {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT stock_quantity FROM inventory_items WHERE id = :id'
            );
            $stmt->execute(['id' => (int) $data['inventory_item_id']]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$item) {
                return InventoryPullRequest::TYPE_ORDER;
            }

            // Check if out of stock
            if ((int) $item['stock_quantity'] < (int) ($data['quantity_requested'] ?? 1)) {
                return InventoryPullRequest::TYPE_ORDER;
            }

            return InventoryPullRequest::TYPE_PULL;
        } catch (\Exception $e) {
            // Fallback to order type if query fails
            return InventoryPullRequest::TYPE_ORDER;
        }
    }

    /**
     * Deduct quantity from inventory
     */
    private function deductFromInventory(int $itemId, int $quantity, int $pullRequestId, ?int $actorId): void
    {
        $beforeStmt = $this->connection->pdo()->prepare(
            'SELECT stock_quantity, branch_id FROM inventory_items WHERE id = :id'
        );
        $beforeStmt->execute(['id' => $itemId]);
        $beforeRow = $beforeStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $quantityBefore = (int) ($beforeRow['stock_quantity'] ?? 0);
        $branchId = isset($beforeRow['branch_id']) ? (int) $beforeRow['branch_id'] : null;

        $sql = "UPDATE inventory_items SET
                stock_quantity = GREATEST(0, stock_quantity - :quantity),
                updated_at = NOW()
            WHERE id = :id";

        $this->connection->pdo()->prepare($sql)->execute([
            'quantity' => $quantity,
            'id' => $itemId,
        ]);

        $afterStmt = $this->connection->pdo()->prepare(
            'SELECT stock_quantity FROM inventory_items WHERE id = :id'
        );
        $afterStmt->execute(['id' => $itemId]);
        $quantityAfter = (int) ($afterStmt->fetchColumn() ?? $quantityBefore);

        $this->transactionRepository->record(
            $itemId,
            $quantityBefore,
            $quantityAfter,
            'pull_request',
            sprintf('pull_request:%d', $pullRequestId),
            'Inventory pull request fulfillment',
            $actorId,
            $branchId
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateData(array $data): void
    {
        if (empty($data['workorder_id'])) {
            throw new InvalidArgumentException('Workorder ID is required');
        }

        if (empty($data['description'])) {
            throw new InvalidArgumentException('Description is required');
        }
    }

    private function mapRow(array $row): InventoryPullRequest
    {
        $row['quantity_requested'] = (int) $row['quantity_requested'];
        $row['quantity_fulfilled'] = (int) $row['quantity_fulfilled'];
        $row['unit_cost'] = (float) $row['unit_cost'];
        $row['unit_price'] = (float) $row['unit_price'];

        return new InventoryPullRequest($row);
    }

    private function log(string $action, int $subjectId, ?int $actorId, array $context = []): void
    {
        $entry = new AuditEntry($action, 'inventory_pull_request', $subjectId, $actorId, $context);
        $this->auditLogger->log($entry);
    }
}
