<?php

namespace App\Services\Inventory;

use App\Database\Connection;
use App\Services\Integrations\PartsTechAdapter;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use PDO;
use Throwable;

/**
 * Service for managing parts carts for workorders.
 * Integrates with PartsTech for parts lookup and ordering.
 */
class PartsCartService
{
    private Connection $connection;
    private PartsTechAdapter $partsTech;
    private ?AuditLogger $audit;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_ORDERED = 'ordered';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    public const ITEM_SOURCE_MANUAL = 'manual';
    public const ITEM_SOURCE_INVENTORY = 'inventory';
    public const ITEM_SOURCE_PARTSTECH = 'partstech';

    public function __construct(
        Connection $connection,
        PartsTechAdapter $partsTech,
        ?AuditLogger $audit = null
    ) {
        $this->connection = $connection;
        $this->partsTech = $partsTech;
        $this->audit = $audit;
    }

    // -------------------------------------------------------------------------
    // Cart Management
    // -------------------------------------------------------------------------

    /**
     * Get or create a cart for a workorder.
     *
     * @return array<string, mixed>
     */
    public function getOrCreateCart(int $workorderId, ?int $actorId = null): array
    {
        $cart = $this->findCartByWorkorder($workorderId);

        if ($cart !== null) {
            return $this->enrichCart($cart);
        }

        return $this->createCart($workorderId, $actorId);
    }

    /**
     * Create a new cart for a workorder.
     *
     * @return array<string, mixed>
     */
    public function createCart(int $workorderId, ?int $actorId = null): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            INSERT INTO parts_carts (workorder_id, status, created_by, created_at, updated_at)
            VALUES (:workorder_id, 'draft', :created_by, NOW(), NOW())
        SQL);

        $stmt->execute([
            'workorder_id' => $workorderId,
            'created_by' => $actorId,
        ]);

        $cartId = (int) $this->connection->pdo()->lastInsertId();

        $this->log('parts_cart.created', $cartId, $actorId, ['workorder_id' => $workorderId]);

        return $this->getCart($cartId);
    }

    /**
     * Get cart by ID.
     *
     * @return array<string, mixed>|null
     */
    public function getCart(int $cartId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT * FROM parts_carts WHERE id = :id
        SQL);
        $stmt->execute(['id' => $cartId]);
        $cart = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$cart) {
            return null;
        }

        return $this->enrichCart($cart);
    }

    /**
     * Find cart for a workorder.
     *
     * @return array<string, mixed>|null
     */
    public function findCartByWorkorder(int $workorderId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT * FROM parts_carts
            WHERE workorder_id = :workorder_id
            ORDER BY id DESC LIMIT 1
        SQL);
        $stmt->execute(['workorder_id' => $workorderId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get cart items.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getCartItems(int $cartId): array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT pci.*, ii.name as inventory_name, ii.stock_quantity
            FROM parts_cart_items pci
            LEFT JOIN inventory_items ii ON pci.inventory_item_id = ii.id
            WHERE pci.cart_id = :cart_id
            ORDER BY pci.id ASC
        SQL);
        $stmt->execute(['cart_id' => $cartId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    // -------------------------------------------------------------------------
    // Item Management
    // -------------------------------------------------------------------------

    /**
     * Add an item to the cart.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addItem(int $cartId, array $data, ?int $actorId = null): array
    {
        $cart = $this->getCart($cartId);
        if ($cart === null) {
            throw new InvalidArgumentException('Cart not found.');
        }

        if (!in_array($cart['status'], [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL], true)) {
            throw new InvalidArgumentException('Cannot add items to cart in current status.');
        }

        $pdo = $this->connection->pdo();

        $stmt = $pdo->prepare(<<<SQL
            INSERT INTO parts_cart_items (
                cart_id, workorder_job_id, inventory_item_id, sku, part_number,
                description, brand, quantity, unit_cost, unit_price,
                partstech_part_id, partstech_availability, partstech_supplier, partstech_eta,
                source, status, notes, created_at, updated_at
            ) VALUES (
                :cart_id, :job_id, :inventory_id, :sku, :part_number,
                :description, :brand, :quantity, :unit_cost, :unit_price,
                :partstech_part_id, :partstech_availability, :partstech_supplier, :partstech_eta,
                :source, 'pending', :notes, NOW(), NOW()
            )
        SQL);

        $stmt->execute([
            'cart_id' => $cartId,
            'job_id' => $data['workorder_job_id'] ?? null,
            'inventory_id' => $data['inventory_item_id'] ?? null,
            'sku' => $data['sku'] ?? null,
            'part_number' => $data['part_number'] ?? null,
            'description' => $data['description'],
            'brand' => $data['brand'] ?? null,
            'quantity' => $data['quantity'] ?? 1,
            'unit_cost' => $data['unit_cost'] ?? null,
            'unit_price' => $data['unit_price'] ?? null,
            'partstech_part_id' => $data['partstech_part_id'] ?? null,
            'partstech_availability' => $data['partstech_availability'] ?? null,
            'partstech_supplier' => $data['partstech_supplier'] ?? null,
            'partstech_eta' => $data['partstech_eta'] ?? null,
            'source' => $data['source'] ?? self::ITEM_SOURCE_MANUAL,
            'notes' => $data['notes'] ?? null,
        ]);

        $itemId = (int) $pdo->lastInsertId();

        $this->recalculateCartTotals($cartId);
        $this->log('parts_cart.item_added', $cartId, $actorId, ['item_id' => $itemId]);

        return $this->getItem($itemId);
    }

    /**
     * Add item from local inventory.
     *
     * @return array<string, mixed>
     */
    public function addFromInventory(
        int $cartId,
        int $inventoryItemId,
        int $quantity = 1,
        ?int $jobId = null,
        ?int $actorId = null
    ): array {
        $item = $this->fetchInventoryItem($inventoryItemId);
        if ($item === null) {
            throw new InvalidArgumentException('Inventory item not found.');
        }

        $markup = $this->getDefaultMarkup();
        $unitPrice = $item['cost'] * (1 + $markup / 100);

        return $this->addItem($cartId, [
            'workorder_job_id' => $jobId,
            'inventory_item_id' => $inventoryItemId,
            'sku' => $item['sku'],
            'part_number' => $item['manufacturer_part_number'],
            'description' => $item['name'],
            'quantity' => $quantity,
            'unit_cost' => $item['cost'],
            'unit_price' => round($unitPrice, 2),
            'source' => self::ITEM_SOURCE_INVENTORY,
        ], $actorId);
    }

    /**
     * Add item from PartsTech search result.
     *
     * @param array<string, mixed> $partData
     * @return array<string, mixed>
     */
    public function addFromPartsTech(
        int $cartId,
        array $partData,
        int $quantity = 1,
        ?int $jobId = null,
        ?int $actorId = null
    ): array {
        $markup = $this->getDefaultMarkup();
        $cost = $partData['cost'] ?? 0;
        $unitPrice = $cost * (1 + $markup / 100);

        return $this->addItem($cartId, [
            'workorder_job_id' => $jobId,
            'part_number' => $partData['part_number'] ?? null,
            'description' => $partData['description'],
            'brand' => $partData['brand'] ?? null,
            'quantity' => $quantity,
            'unit_cost' => $cost,
            'unit_price' => round($unitPrice, 2),
            'partstech_part_id' => $partData['part_id'] ?? null,
            'partstech_availability' => $partData['availability'] ?? null,
            'partstech_supplier' => $partData['supplier'] ?? null,
            'partstech_eta' => $partData['eta'] ?? null,
            'source' => self::ITEM_SOURCE_PARTSTECH,
        ], $actorId);
    }

    /**
     * Update a cart item.
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateItem(int $itemId, array $data, ?int $actorId = null): array
    {
        $item = $this->getItem($itemId);
        if ($item === null) {
            throw new InvalidArgumentException('Cart item not found.');
        }

        $cart = $this->getCart((int) $item['cart_id']);
        if (!in_array($cart['status'], [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL], true)) {
            throw new InvalidArgumentException('Cannot update items in cart with current status.');
        }

        $updates = [];
        $params = ['id' => $itemId];

        $allowedFields = ['quantity', 'unit_cost', 'unit_price', 'notes', 'workorder_job_id'];
        foreach ($allowedFields as $field) {
            if (array_key_exists($field, $data)) {
                $updates[] = "{$field} = :{$field}";
                $params[$field] = $data[$field];
            }
        }

        if (empty($updates)) {
            return $item;
        }

        $updates[] = 'updated_at = NOW()';
        $sql = 'UPDATE parts_cart_items SET ' . implode(', ', $updates) . ' WHERE id = :id';

        $this->connection->pdo()->prepare($sql)->execute($params);

        $this->recalculateCartTotals((int) $item['cart_id']);

        return $this->getItem($itemId);
    }

    /**
     * Remove an item from the cart.
     */
    public function removeItem(int $itemId, ?int $actorId = null): bool
    {
        $item = $this->getItem($itemId);
        if ($item === null) {
            return false;
        }

        $cart = $this->getCart((int) $item['cart_id']);
        if (!in_array($cart['status'], [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL], true)) {
            throw new InvalidArgumentException('Cannot remove items from cart with current status.');
        }

        $stmt = $this->connection->pdo()->prepare('DELETE FROM parts_cart_items WHERE id = :id');
        $stmt->execute(['id' => $itemId]);

        $this->recalculateCartTotals((int) $item['cart_id']);
        $this->log('parts_cart.item_removed', (int) $item['cart_id'], $actorId, ['item_id' => $itemId]);

        return true;
    }

    /**
     * Get a single cart item.
     *
     * @return array<string, mixed>|null
     */
    public function getItem(int $itemId): ?array
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT * FROM parts_cart_items WHERE id = :id
        SQL);
        $stmt->execute(['id' => $itemId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    // -------------------------------------------------------------------------
    // Cart Workflow
    // -------------------------------------------------------------------------

    /**
     * Submit cart for approval.
     *
     * @return array<string, mixed>
     */
    public function submitForApproval(int $cartId, ?int $actorId = null): array
    {
        $cart = $this->getCart($cartId);
        if ($cart === null) {
            throw new InvalidArgumentException('Cart not found.');
        }

        if ($cart['status'] !== self::STATUS_DRAFT) {
            throw new InvalidArgumentException('Only draft carts can be submitted for approval.');
        }

        if (empty($cart['items'])) {
            throw new InvalidArgumentException('Cannot submit empty cart.');
        }

        // Check if approval is required
        $requiresApproval = $this->isApprovalRequired();

        $newStatus = $requiresApproval ? self::STATUS_PENDING_APPROVAL : self::STATUS_APPROVED;

        $this->updateCartStatus($cartId, $newStatus);
        $this->log('parts_cart.submitted', $cartId, $actorId, ['status' => $newStatus]);

        return $this->getCart($cartId);
    }

    /**
     * Approve cart for ordering.
     *
     * @return array<string, mixed>
     */
    public function approveCart(int $cartId, ?int $actorId = null): array
    {
        $cart = $this->getCart($cartId);
        if ($cart === null) {
            throw new InvalidArgumentException('Cart not found.');
        }

        if ($cart['status'] !== self::STATUS_PENDING_APPROVAL) {
            throw new InvalidArgumentException('Only pending carts can be approved.');
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            UPDATE parts_carts
            SET status = 'approved', approved_by = :approved_by, approved_at = NOW(), updated_at = NOW()
            WHERE id = :id
        SQL);

        $stmt->execute(['approved_by' => $actorId, 'id' => $cartId]);

        $this->log('parts_cart.approved', $cartId, $actorId);

        return $this->getCart($cartId);
    }

    /**
     * Reject cart back to draft.
     *
     * @return array<string, mixed>
     */
    public function rejectCart(int $cartId, ?string $reason = null, ?int $actorId = null): array
    {
        $cart = $this->getCart($cartId);
        if ($cart === null) {
            throw new InvalidArgumentException('Cart not found.');
        }

        if ($cart['status'] !== self::STATUS_PENDING_APPROVAL) {
            throw new InvalidArgumentException('Only pending carts can be rejected.');
        }

        $notes = $cart['notes'] ?? '';
        if ($reason) {
            $notes .= ($notes ? "\n" : '') . "Rejected: {$reason}";
        }

        $stmt = $this->connection->pdo()->prepare(<<<SQL
            UPDATE parts_carts SET status = 'draft', notes = :notes, updated_at = NOW() WHERE id = :id
        SQL);

        $stmt->execute(['notes' => $notes, 'id' => $cartId]);

        $this->log('parts_cart.rejected', $cartId, $actorId, ['reason' => $reason]);

        return $this->getCart($cartId);
    }

    /**
     * Place order with PartsTech (or mark as ordered for manual ordering).
     *
     * @return array<string, mixed>
     */
    public function placeOrder(int $cartId, ?int $actorId = null): array
    {
        $cart = $this->getCart($cartId);
        if ($cart === null) {
            throw new InvalidArgumentException('Cart not found.');
        }

        if ($cart['status'] !== self::STATUS_APPROVED) {
            throw new InvalidArgumentException('Only approved carts can be ordered.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            $partsTechOrderId = null;
            $partsTechQuoteId = null;

            // Try to order through PartsTech if enabled and items have PartsTech IDs
            if ($this->partsTech->isEnabled()) {
                $partsTechItems = array_filter($cart['items'], fn($item) => !empty($item['partstech_part_id']));

                if (!empty($partsTechItems)) {
                    // Create quote
                    $quoteItems = array_map(fn($item) => [
                        'part_id' => $item['partstech_part_id'],
                        'quantity' => (int) $item['quantity'],
                    ], $partsTechItems);

                    $quote = $this->partsTech->createQuote($quoteItems, 'WO-' . $cart['workorder_id']);
                    $partsTechQuoteId = $quote['quote_id'];

                    // Place order
                    if ($partsTechQuoteId) {
                        $order = $this->partsTech->placeOrder($partsTechQuoteId);
                        $partsTechOrderId = $order['order_id'];
                    }
                }
            }

            // Update cart status
            $stmt = $pdo->prepare(<<<SQL
                UPDATE parts_carts
                SET status = 'ordered',
                    partstech_quote_id = :quote_id,
                    partstech_order_id = :order_id,
                    ordered_at = NOW(),
                    updated_at = NOW()
                WHERE id = :id
            SQL);

            $stmt->execute([
                'quote_id' => $partsTechQuoteId,
                'order_id' => $partsTechOrderId,
                'id' => $cartId,
            ]);

            // Update item statuses
            $pdo->prepare(<<<SQL
                UPDATE parts_cart_items SET status = 'ordered', updated_at = NOW() WHERE cart_id = :cart_id
            SQL)->execute(['cart_id' => $cartId]);

            $pdo->commit();

            $this->log('parts_cart.ordered', $cartId, $actorId, [
                'partstech_quote_id' => $partsTechQuoteId,
                'partstech_order_id' => $partsTechOrderId,
            ]);

            return $this->getCart($cartId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Mark cart/items as received.
     *
     * @param array<int, int>|null $itemQuantities Map of item_id => received_quantity
     * @return array<string, mixed>
     */
    public function markAsReceived(int $cartId, ?array $itemQuantities = null, ?int $actorId = null): array
    {
        $cart = $this->getCart($cartId);
        if ($cart === null) {
            throw new InvalidArgumentException('Cart not found.');
        }

        if ($cart['status'] !== self::STATUS_ORDERED) {
            throw new InvalidArgumentException('Only ordered carts can be marked as received.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            if ($itemQuantities === null) {
                // Mark all items as fully received
                $pdo->prepare(<<<SQL
                    UPDATE parts_cart_items
                    SET status = 'received', received_quantity = quantity, updated_at = NOW()
                    WHERE cart_id = :cart_id
                SQL)->execute(['cart_id' => $cartId]);
            } else {
                // Update specific items
                $stmt = $pdo->prepare(<<<SQL
                    UPDATE parts_cart_items
                    SET received_quantity = received_quantity + :qty,
                        status = CASE WHEN received_quantity + :qty >= quantity THEN 'received' ELSE status END,
                        updated_at = NOW()
                    WHERE id = :id AND cart_id = :cart_id
                SQL);

                foreach ($itemQuantities as $itemId => $qty) {
                    $stmt->execute([
                        'qty' => $qty,
                        'id' => $itemId,
                        'cart_id' => $cartId,
                    ]);
                }
            }

            // Check if all items are received
            $stmt = $pdo->prepare(<<<SQL
                SELECT COUNT(*) as pending FROM parts_cart_items
                WHERE cart_id = :cart_id AND status != 'received' AND status != 'cancelled'
            SQL);
            $stmt->execute(['cart_id' => $cartId]);
            $pending = (int) $stmt->fetchColumn();

            if ($pending === 0) {
                $pdo->prepare(<<<SQL
                    UPDATE parts_carts SET status = 'received', received_at = NOW(), updated_at = NOW()
                    WHERE id = :id
                SQL)->execute(['id' => $cartId]);
            }

            $pdo->commit();

            $this->log('parts_cart.received', $cartId, $actorId, ['item_quantities' => $itemQuantities]);

            return $this->getCart($cartId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Cancel cart.
     *
     * @return array<string, mixed>
     */
    public function cancelCart(int $cartId, ?string $reason = null, ?int $actorId = null): array
    {
        $cart = $this->getCart($cartId);
        if ($cart === null) {
            throw new InvalidArgumentException('Cart not found.');
        }

        $terminalStatuses = [self::STATUS_RECEIVED, self::STATUS_CANCELLED];
        if (in_array($cart['status'], $terminalStatuses, true)) {
            throw new InvalidArgumentException('Cart cannot be cancelled.');
        }

        $pdo = $this->connection->pdo();
        $pdo->beginTransaction();

        try {
            // Try to cancel PartsTech order if exists
            if (!empty($cart['partstech_order_id']) && $this->partsTech->isEnabled()) {
                $this->partsTech->cancelOrder($cart['partstech_order_id']);
            }

            $notes = $cart['notes'] ?? '';
            if ($reason) {
                $notes .= ($notes ? "\n" : '') . "Cancelled: {$reason}";
            }

            $pdo->prepare(<<<SQL
                UPDATE parts_carts SET status = 'cancelled', notes = :notes, updated_at = NOW() WHERE id = :id
            SQL)->execute(['notes' => $notes, 'id' => $cartId]);

            $pdo->prepare(<<<SQL
                UPDATE parts_cart_items SET status = 'cancelled', updated_at = NOW() WHERE cart_id = :cart_id
            SQL)->execute(['cart_id' => $cartId]);

            $pdo->commit();

            $this->log('parts_cart.cancelled', $cartId, $actorId, ['reason' => $reason]);

            return $this->getCart($cartId);
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // PartsTech Search
    // -------------------------------------------------------------------------

    /**
     * Search PartsTech for parts.
     *
     * @param array{year?: int, make?: string, model?: string} $vehicle
     * @return array<int, array<string, mixed>>
     */
    public function searchPartsTech(string $query, array $vehicle = [], int $limit = 20): array
    {
        if (!$this->partsTech->isEnabled()) {
            return [];
        }

        return $this->partsTech->searchParts($query, $vehicle, $limit);
    }

    /**
     * Check if PartsTech is enabled.
     */
    public function isPartsTechEnabled(): bool
    {
        return $this->partsTech->isEnabled();
    }

    // -------------------------------------------------------------------------
    // Private Helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $cart
     * @return array<string, mixed>
     */
    private function enrichCart(array $cart): array
    {
        $cart['items'] = $this->getCartItems((int) $cart['id']);
        $cart['item_count'] = count($cart['items']);
        $cart['can_edit'] = in_array($cart['status'], [self::STATUS_DRAFT, self::STATUS_PENDING_APPROVAL], true);
        $cart['can_submit'] = $cart['status'] === self::STATUS_DRAFT;
        $cart['can_approve'] = $cart['status'] === self::STATUS_PENDING_APPROVAL;
        $cart['can_order'] = $cart['status'] === self::STATUS_APPROVED;
        $cart['can_receive'] = $cart['status'] === self::STATUS_ORDERED;
        $cart['partstech_enabled'] = $this->partsTech->isEnabled();

        return $cart;
    }

    private function updateCartStatus(int $cartId, string $status): void
    {
        $this->connection->pdo()->prepare(<<<SQL
            UPDATE parts_carts SET status = :status, updated_at = NOW() WHERE id = :id
        SQL)->execute(['status' => $status, 'id' => $cartId]);
    }

    private function recalculateCartTotals(int $cartId): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT
                COALESCE(SUM(quantity * COALESCE(unit_cost, 0)), 0) as total_cost,
                COALESCE(SUM(quantity * COALESCE(unit_price, unit_cost, 0)), 0) as total_price
            FROM parts_cart_items
            WHERE cart_id = :cart_id AND status != 'cancelled'
        SQL);
        $stmt->execute(['cart_id' => $cartId]);
        $totals = $stmt->fetch(PDO::FETCH_ASSOC);

        $this->connection->pdo()->prepare(<<<SQL
            UPDATE parts_carts
            SET total_estimated = :cost, total_actual = :price, updated_at = NOW()
            WHERE id = :id
        SQL)->execute([
            'cost' => $totals['total_cost'],
            'price' => $totals['total_price'],
            'id' => $cartId,
        ]);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchInventoryItem(int $id): ?array
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM inventory_items WHERE id = :id');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function getDefaultMarkup(): float
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT `value` FROM settings WHERE `key` = 'partstech_default_markup'"
        );
        $stmt->execute();
        return (float) ($stmt->fetchColumn() ?: 30);
    }

    private function isApprovalRequired(): bool
    {
        $stmt = $this->connection->pdo()->prepare(
            "SELECT `value` FROM settings WHERE `key` = 'parts_cart_requires_approval'"
        );
        $stmt->execute();
        return $stmt->fetchColumn() === 'true';
    }

    private function log(string $event, int $cartId, ?int $actorId, array $context = []): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'parts_cart', (string) $cartId, $actorId, $context));
    }
}
