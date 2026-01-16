<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use App\Support\Audit\AuditLogger;
use PDO;

/**
 * Service for managing core return tracking.
 * Handles core charges for parts like alternators, batteries, starters, etc.
 */
class CoreReturnService
{
    private Connection $connection;
    private AuditLogger $auditLogger;

    // Core return statuses
    public const STATUS_PENDING_FROM_CUSTOMER = 'pending_from_customer';
    public const STATUS_RECEIVED_FROM_CUSTOMER = 'received_from_customer';
    public const STATUS_PENDING_TO_VENDOR = 'pending_to_vendor';
    public const STATUS_RETURNED_TO_VENDOR = 'returned_to_vendor';
    public const STATUS_CREDIT_RECEIVED = 'credit_received';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_WAIVED = 'waived';

    public function __construct(Connection $connection, AuditLogger $auditLogger)
    {
        $this->connection = $connection;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Create a core return record when a part with core is sold
     *
     * @param array<string, mixed> $data
     * @param int|null $userId
     * @return array<string, mixed>
     */
    public function create(array $data, ?int $userId): array
    {
        $customerReturnDays = $this->getSettingValue('inventory.core_tracking.customer_return_days', 30);
        $vendorReturnDays = $this->getSettingValue('inventory.core_tracking.vendor_return_days', 45);

        $customerDueDate = date('Y-m-d', strtotime("+{$customerReturnDays} days"));
        $vendorDueDate = date('Y-m-d', strtotime("+{$vendorReturnDays} days"));

        $sql = 'INSERT INTO core_returns (
                    workorder_id, workorder_item_id, invoice_id, invoice_item_id,
                    inventory_item_id, part_description, sku,
                    core_cost, core_price, status,
                    customer_id, customer_core_due_date, vendor, vendor_core_due_date,
                    notes, created_by
                ) VALUES (
                    :workorder_id, :workorder_item_id, :invoice_id, :invoice_item_id,
                    :inventory_item_id, :part_description, :sku,
                    :core_cost, :core_price, :status,
                    :customer_id, :customer_core_due_date, :vendor, :vendor_core_due_date,
                    :notes, :created_by
                )';

        $params = [
            'workorder_id' => $data['workorder_id'] ?? null,
            'workorder_item_id' => $data['workorder_item_id'] ?? null,
            'invoice_id' => $data['invoice_id'] ?? null,
            'invoice_item_id' => $data['invoice_item_id'] ?? null,
            'inventory_item_id' => $data['inventory_item_id'] ?? null,
            'part_description' => $data['part_description'],
            'sku' => $data['sku'] ?? null,
            'core_cost' => $data['core_cost'] ?? 0,
            'core_price' => $data['core_price'] ?? 0,
            'status' => self::STATUS_PENDING_FROM_CUSTOMER,
            'customer_id' => $data['customer_id'] ?? null,
            'customer_core_due_date' => $data['customer_core_due_date'] ?? $customerDueDate,
            'vendor' => $data['vendor'] ?? null,
            'vendor_core_due_date' => $data['vendor_core_due_date'] ?? $vendorDueDate,
            'notes' => $data['notes'] ?? null,
            'created_by' => $userId,
        ];

        $this->connection->pdo()->prepare($sql)->execute($params);
        $id = (int) $this->connection->pdo()->lastInsertId();

        // Log history
        $this->logHistory($id, null, self::STATUS_PENDING_FROM_CUSTOMER, 'Core return created', $userId);

        $this->auditLogger->log(
            $userId,
            'core_return_created',
            'core_returns',
            $id,
            [],
            $params
        );

        return $this->get($id);
    }

    /**
     * Get a core return by ID
     *
     * @param int $id
     * @return array<string, mixed>|null
     */
    public function get(int $id): ?array
    {
        $sql = 'SELECT cr.*,
                    c.name as customer_name,
                    wo.number as workorder_number,
                    inv.number as invoice_number
                FROM core_returns cr
                LEFT JOIN customers c ON cr.customer_id = c.id
                LEFT JOIN workorders wo ON cr.workorder_id = wo.id
                LEFT JOIN invoices inv ON cr.invoice_id = inv.id
                WHERE cr.id = :id';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        $row['history'] = $this->getHistory($id);

        return $row;
    }

    /**
     * List core returns with filters
     *
     * @param array<string, mixed> $filters
     * @param int $limit
     * @param int $offset
     * @return array<string, mixed>
     */
    public function list(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $clauses = [];
        $bindings = [];

        if (!empty($filters['status'])) {
            $clauses[] = 'cr.status = :status';
            $bindings['status'] = $filters['status'];
        }

        if (!empty($filters['customer_id'])) {
            $clauses[] = 'cr.customer_id = :customer_id';
            $bindings['customer_id'] = (int) $filters['customer_id'];
        }

        if (!empty($filters['workorder_id'])) {
            $clauses[] = 'cr.workorder_id = :workorder_id';
            $bindings['workorder_id'] = (int) $filters['workorder_id'];
        }

        if (!empty($filters['invoice_id'])) {
            $clauses[] = 'cr.invoice_id = :invoice_id';
            $bindings['invoice_id'] = (int) $filters['invoice_id'];
        }

        if (!empty($filters['vendor'])) {
            $clauses[] = 'cr.vendor LIKE :vendor';
            $bindings['vendor'] = '%' . $filters['vendor'] . '%';
        }

        if (!empty($filters['pending_customer'])) {
            $clauses[] = 'cr.status = :pending_status';
            $bindings['pending_status'] = self::STATUS_PENDING_FROM_CUSTOMER;
        }

        if (!empty($filters['pending_vendor'])) {
            $clauses[] = 'cr.status = :pending_vendor_status';
            $bindings['pending_vendor_status'] = self::STATUS_PENDING_TO_VENDOR;
        }

        if (!empty($filters['overdue_customer'])) {
            $clauses[] = 'cr.status = :overdue_status AND cr.customer_core_due_date < CURDATE()';
            $bindings['overdue_status'] = self::STATUS_PENDING_FROM_CUSTOMER;
        }

        if (!empty($filters['overdue_vendor'])) {
            $clauses[] = 'cr.status = :overdue_vendor_status AND cr.vendor_core_due_date < CURDATE()';
            $bindings['overdue_vendor_status'] = self::STATUS_PENDING_TO_VENDOR;
        }

        $where = $clauses ? 'WHERE ' . implode(' AND ', $clauses) : '';

        // Get total count
        $countSql = "SELECT COUNT(*) FROM core_returns cr $where";
        $stmt = $this->connection->pdo()->prepare($countSql);
        $stmt->execute($bindings);
        $total = (int) $stmt->fetchColumn();

        // Get items
        $sql = "SELECT cr.*,
                    c.name as customer_name,
                    wo.number as workorder_number,
                    inv.number as invoice_number,
                    CASE
                        WHEN cr.status = 'pending_from_customer' AND cr.customer_core_due_date < CURDATE() THEN 1
                        WHEN cr.status = 'pending_to_vendor' AND cr.vendor_core_due_date < CURDATE() THEN 1
                        ELSE 0
                    END as is_overdue
                FROM core_returns cr
                LEFT JOIN customers c ON cr.customer_id = c.id
                LEFT JOIN workorders wo ON cr.workorder_id = wo.id
                LEFT JOIN invoices inv ON cr.invoice_id = inv.id
                $where
                ORDER BY
                    is_overdue DESC,
                    CASE cr.status
                        WHEN 'pending_from_customer' THEN 1
                        WHEN 'pending_to_vendor' THEN 2
                        ELSE 3
                    END,
                    cr.customer_core_due_date ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($bindings as $key => $value) {
            $stmt->bindValue(':' . $key, $value);
        }
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'items' => $stmt->fetchAll(PDO::FETCH_ASSOC),
            'total' => $total,
            'limit' => $limit,
            'offset' => $offset,
        ];
    }

    /**
     * Get dashboard summary of core returns
     *
     * @return array<string, mixed>
     */
    public function getSummary(): array
    {
        $alertDays = $this->getSettingValue('inventory.core_tracking.alert_days_before_due', 7);
        $alertDate = date('Y-m-d', strtotime("+{$alertDays} days"));

        $sql = "SELECT
                    SUM(CASE WHEN status = 'pending_from_customer' THEN 1 ELSE 0 END) as pending_from_customer,
                    SUM(CASE WHEN status = 'pending_from_customer' AND customer_core_due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_customer,
                    SUM(CASE WHEN status = 'pending_from_customer' AND customer_core_due_date BETWEEN CURDATE() AND :alert_date THEN 1 ELSE 0 END) as due_soon_customer,
                    SUM(CASE WHEN status = 'pending_from_customer' THEN core_price ELSE 0 END) as pending_customer_value,

                    SUM(CASE WHEN status = 'pending_to_vendor' THEN 1 ELSE 0 END) as pending_to_vendor,
                    SUM(CASE WHEN status = 'pending_to_vendor' AND vendor_core_due_date < CURDATE() THEN 1 ELSE 0 END) as overdue_vendor,
                    SUM(CASE WHEN status = 'pending_to_vendor' AND vendor_core_due_date BETWEEN CURDATE() AND :alert_date2 THEN 1 ELSE 0 END) as due_soon_vendor,
                    SUM(CASE WHEN status = 'pending_to_vendor' THEN core_cost ELSE 0 END) as pending_vendor_value,

                    SUM(CASE WHEN status = 'received_from_customer' THEN 1 ELSE 0 END) as received_not_returned,
                    SUM(CASE WHEN status = 'credit_received' THEN vendor_credit_amount ELSE 0 END) as total_credits_received
                FROM core_returns";

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['alert_date' => $alertDate, 'alert_date2' => $alertDate]);

        return $stmt->fetch(PDO::FETCH_ASSOC);
    }

    /**
     * Mark core as received from customer
     *
     * @param int $id
     * @param int $userId
     * @param string|null $notes
     * @return array<string, mixed>
     */
    public function receiveFromCustomer(int $id, int $userId, ?string $notes = null): array
    {
        return $this->updateStatus($id, self::STATUS_RECEIVED_FROM_CUSTOMER, $userId, $notes, [
            'customer_core_received_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Credit customer for returned core
     *
     * @param int $id
     * @param int $userId
     * @param string|null $notes
     * @return array<string, mixed>
     */
    public function creditCustomer(int $id, int $userId, ?string $notes = null): array
    {
        // Transition to pending vendor return after crediting customer
        return $this->updateStatus($id, self::STATUS_PENDING_TO_VENDOR, $userId, $notes, [
            'customer_credited_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Mark core as returned to vendor
     *
     * @param int $id
     * @param int $userId
     * @param string|null $notes
     * @return array<string, mixed>
     */
    public function returnToVendor(int $id, int $userId, ?string $notes = null): array
    {
        return $this->updateStatus($id, self::STATUS_RETURNED_TO_VENDOR, $userId, $notes, [
            'vendor_return_sent_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Record vendor credit received
     *
     * @param int $id
     * @param float $creditAmount
     * @param int $userId
     * @param string|null $notes
     * @return array<string, mixed>
     */
    public function recordVendorCredit(int $id, float $creditAmount, int $userId, ?string $notes = null): array
    {
        return $this->updateStatus($id, self::STATUS_CREDIT_RECEIVED, $userId, $notes, [
            'vendor_credit_received_at' => date('Y-m-d H:i:s'),
            'vendor_credit_amount' => $creditAmount,
        ]);
    }

    /**
     * Waive core (customer keeps old part)
     *
     * @param int $id
     * @param int $userId
     * @param string|null $notes
     * @return array<string, mixed>
     */
    public function waive(int $id, int $userId, ?string $notes = null): array
    {
        return $this->updateStatus($id, self::STATUS_WAIVED, $userId, $notes);
    }

    /**
     * Mark core as expired (customer didn't return in time)
     *
     * @param int $id
     * @param int $userId
     * @param string|null $notes
     * @return array<string, mixed>
     */
    public function expire(int $id, int $userId, ?string $notes = null): array
    {
        return $this->updateStatus($id, self::STATUS_EXPIRED, $userId, $notes);
    }

    /**
     * Update core return status
     *
     * @param int $id
     * @param string $newStatus
     * @param int $userId
     * @param string|null $notes
     * @param array<string, mixed> $additionalFields
     * @return array<string, mixed>
     */
    private function updateStatus(int $id, string $newStatus, int $userId, ?string $notes = null, array $additionalFields = []): array
    {
        $current = $this->get($id);
        if ($current === null) {
            throw new \InvalidArgumentException('Core return not found');
        }

        $oldStatus = $current['status'];

        $setClauses = ['status = :status', 'updated_by = :updated_by'];
        $params = [
            'id' => $id,
            'status' => $newStatus,
            'updated_by' => $userId,
        ];

        foreach ($additionalFields as $field => $value) {
            $setClauses[] = "$field = :$field";
            $params[$field] = $value;
        }

        $sql = 'UPDATE core_returns SET ' . implode(', ', $setClauses) . ' WHERE id = :id';
        $this->connection->pdo()->prepare($sql)->execute($params);

        // Log history
        $this->logHistory($id, $oldStatus, $newStatus, $notes, $userId);

        $this->auditLogger->log(
            $userId,
            'core_return_status_changed',
            'core_returns',
            $id,
            ['status' => $oldStatus],
            ['status' => $newStatus, 'notes' => $notes]
        );

        return $this->get($id);
    }

    /**
     * Log status change history
     *
     * @param int $coreReturnId
     * @param string|null $oldStatus
     * @param string $newStatus
     * @param string|null $notes
     * @param int $userId
     */
    private function logHistory(int $coreReturnId, ?string $oldStatus, string $newStatus, ?string $notes, ?int $userId): void
    {
        $sql = 'INSERT INTO core_return_history (core_return_id, old_status, new_status, notes, created_by)
                VALUES (:core_return_id, :old_status, :new_status, :notes, :created_by)';

        $this->connection->pdo()->prepare($sql)->execute([
            'core_return_id' => $coreReturnId,
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'notes' => $notes,
            'created_by' => $userId,
        ]);
    }

    /**
     * Get history for a core return
     *
     * @param int $coreReturnId
     * @return array<int, array<string, mixed>>
     */
    private function getHistory(int $coreReturnId): array
    {
        $sql = 'SELECT crh.*, u.name as created_by_name
                FROM core_return_history crh
                LEFT JOIN users u ON crh.created_by = u.id
                WHERE crh.core_return_id = :core_return_id
                ORDER BY crh.created_at DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['core_return_id' => $coreReturnId]);

        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Get setting value with fallback
     *
     * @param string $key
     * @param mixed $default
     * @return mixed
     */
    private function getSettingValue(string $key, $default)
    {
        try {
            $stmt = $this->connection->pdo()->prepare('SELECT value FROM settings WHERE key_name = :key');
            $stmt->execute(['key' => $key]);
            $value = $stmt->fetchColumn();
            return $value !== false ? $value : $default;
        } catch (\Exception $e) {
            return $default;
        }
    }

    /**
     * Check if core tracking is enabled
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return filter_var($this->getSettingValue('inventory.core_tracking.enabled', true), FILTER_VALIDATE_BOOLEAN);
    }
}
