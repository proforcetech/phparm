<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Controller for Parts Cart API endpoints.
 */
class PartsCartController
{
    private PartsCartService $service;
    private AccessGate $gate;

    public function __construct(PartsCartService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    // -------------------------------------------------------------------------
    // Cart Endpoints
    // -------------------------------------------------------------------------

    /**
     * GET /api/workorders/{workorderId}/parts-cart
     * @return array<string, mixed>
     */
    public function getOrCreate(User $user, int $workorderId): array
    {
        $this->gate->assert($user, 'inventory.view');

        return $this->service->getOrCreateCart($workorderId, $user->id);
    }

    /**
     * GET /api/parts-carts/{id}
     * @return array<string, mixed>
     */
    public function show(User $user, int $cartId): array
    {
        $this->gate->assert($user, 'inventory.view');

        $cart = $this->service->getCart($cartId);
        if ($cart === null) {
            throw new InvalidArgumentException('Parts cart not found.');
        }

        return $cart;
    }

    // -------------------------------------------------------------------------
    // Item Endpoints
    // -------------------------------------------------------------------------

    /**
     * POST /api/parts-carts/{id}/items
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addItem(User $user, int $cartId, array $data): array
    {
        $this->gate->assert($user, 'inventory.*');

        if (empty($data['description'])) {
            throw new InvalidArgumentException('Item description is required.');
        }

        return $this->service->addItem($cartId, $data, $user->id);
    }

    /**
     * POST /api/parts-carts/{id}/items/from-inventory
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addFromInventory(User $user, int $cartId, array $data): array
    {
        $this->gate->assert($user, 'inventory.*');

        $inventoryItemId = $data['inventory_item_id'] ?? null;
        if (!$inventoryItemId) {
            throw new InvalidArgumentException('inventory_item_id is required.');
        }

        $quantity = (int) ($data['quantity'] ?? 1);
        $jobId = isset($data['workorder_job_id']) ? (int) $data['workorder_job_id'] : null;

        return $this->service->addFromInventory($cartId, (int) $inventoryItemId, $quantity, $jobId, $user->id);
    }

    /**
     * POST /api/parts-carts/{id}/items/from-partstech
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function addFromPartsTech(User $user, int $cartId, array $data): array
    {
        $this->gate->assert($user, 'inventory.*');

        if (empty($data['part_data']) || !is_array($data['part_data'])) {
            throw new InvalidArgumentException('part_data is required.');
        }

        $quantity = (int) ($data['quantity'] ?? 1);
        $jobId = isset($data['workorder_job_id']) ? (int) $data['workorder_job_id'] : null;

        return $this->service->addFromPartsTech($cartId, $data['part_data'], $quantity, $jobId, $user->id);
    }

    /**
     * PATCH /api/parts-carts/{cartId}/items/{itemId}
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function updateItem(User $user, int $cartId, int $itemId, array $data): array
    {
        $this->gate->assert($user, 'inventory.*');

        return $this->service->updateItem($itemId, $data, $user->id);
    }

    /**
     * DELETE /api/parts-carts/{cartId}/items/{itemId}
     */
    public function removeItem(User $user, int $cartId, int $itemId): bool
    {
        $this->gate->assert($user, 'inventory.*');

        return $this->service->removeItem($itemId, $user->id);
    }

    // -------------------------------------------------------------------------
    // Workflow Endpoints
    // -------------------------------------------------------------------------

    /**
     * POST /api/parts-carts/{id}/submit
     * @return array<string, mixed>
     */
    public function submit(User $user, int $cartId): array
    {
        $this->gate->assert($user, 'inventory.*');

        return $this->service->submitForApproval($cartId, $user->id);
    }

    /**
     * POST /api/parts-carts/{id}/approve
     * @return array<string, mixed>
     */
    public function approve(User $user, int $cartId): array
    {
        $this->gate->assert($user, 'inventory.approve');

        return $this->service->approveCart($cartId, $user->id);
    }

    /**
     * POST /api/parts-carts/{id}/reject
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function reject(User $user, int $cartId, array $data): array
    {
        $this->gate->assert($user, 'inventory.approve');

        $reason = $data['reason'] ?? null;

        return $this->service->rejectCart($cartId, $reason, $user->id);
    }

    /**
     * POST /api/parts-carts/{id}/order
     * @return array<string, mixed>
     */
    public function order(User $user, int $cartId): array
    {
        $this->gate->assert($user, 'inventory.order');

        return $this->service->placeOrder($cartId, $user->id);
    }

    /**
     * POST /api/parts-carts/{id}/receive
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function receive(User $user, int $cartId, array $data): array
    {
        $this->gate->assert($user, 'inventory.*');

        $itemQuantities = null;
        if (!empty($data['items']) && is_array($data['items'])) {
            $itemQuantities = [];
            foreach ($data['items'] as $item) {
                if (isset($item['id'], $item['quantity'])) {
                    $itemQuantities[(int) $item['id']] = (int) $item['quantity'];
                }
            }
        }

        return $this->service->markAsReceived($cartId, $itemQuantities, $user->id);
    }

    /**
     * POST /api/parts-carts/{id}/cancel
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $cartId, array $data): array
    {
        $this->gate->assert($user, 'inventory.*');

        $reason = $data['reason'] ?? null;

        return $this->service->cancelCart($cartId, $reason, $user->id);
    }

    // -------------------------------------------------------------------------
    // PartsTech Search
    // -------------------------------------------------------------------------

    /**
     * GET /api/parts-carts/partstech/search
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function searchPartsTech(User $user, array $params): array
    {
        $this->gate->assert($user, 'inventory.view');

        $query = $params['q'] ?? '';
        if (strlen($query) < 2) {
            return ['parts' => [], 'message' => 'Query must be at least 2 characters.'];
        }

        $vehicle = [];
        if (!empty($params['year'])) {
            $vehicle['year'] = (int) $params['year'];
        }
        if (!empty($params['make'])) {
            $vehicle['make'] = $params['make'];
        }
        if (!empty($params['model'])) {
            $vehicle['model'] = $params['model'];
        }

        $limit = min((int) ($params['limit'] ?? 20), 50);

        $parts = $this->service->searchPartsTech($query, $vehicle, $limit);

        return [
            'parts' => $parts,
            'count' => count($parts),
            'partstech_enabled' => $this->service->isPartsTechEnabled(),
        ];
    }

    /**
     * GET /api/parts-carts/partstech/status
     * @return array<string, mixed>
     */
    public function partsTechStatus(User $user): array
    {
        $this->gate->assert($user, 'inventory.view');

        return [
            'enabled' => $this->service->isPartsTechEnabled(),
        ];
    }
}
