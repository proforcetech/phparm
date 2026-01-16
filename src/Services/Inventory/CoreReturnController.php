<?php

namespace App\Services\Inventory;

use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Controller for Core Return API endpoints.
 */
class CoreReturnController
{
    private CoreReturnService $service;
    private AccessGate $gate;

    public function __construct(CoreReturnService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * GET /api/core-returns
     * List core returns with filters
     *
     * @param User $user
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function index(User $user, array $params = []): array
    {
        $this->gate->assert($user, 'inventory.view');

        $filters = [];
        foreach (['status', 'customer_id', 'workorder_id', 'invoice_id', 'vendor'] as $field) {
            if (!empty($params[$field])) {
                $filters[$field] = $params[$field];
            }
        }

        foreach (['pending_customer', 'pending_vendor', 'overdue_customer', 'overdue_vendor'] as $flag) {
            if (!empty($params[$flag])) {
                $filters[$flag] = true;
            }
        }

        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return $this->service->list($filters, $limit, $offset);
    }

    /**
     * GET /api/core-returns/summary
     * Get dashboard summary
     *
     * @param User $user
     * @return array<string, mixed>
     */
    public function summary(User $user): array
    {
        $this->gate->assert($user, 'inventory.view');

        return $this->service->getSummary();
    }

    /**
     * GET /api/core-returns/{id}
     * Get single core return
     *
     * @param User $user
     * @param int $id
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->gate->assert($user, 'inventory.view');

        $record = $this->service->get($id);
        if ($record === null) {
            throw new InvalidArgumentException('Core return not found.');
        }

        return $record;
    }

    /**
     * POST /api/core-returns
     * Create a new core return record
     *
     * @param User $user
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->gate->assert($user, 'inventory.*');

        if (empty($data['part_description'])) {
            throw new InvalidArgumentException('Part description is required.');
        }

        return $this->service->create($data, $user->id);
    }

    /**
     * POST /api/core-returns/{id}/receive-from-customer
     * Mark core as received from customer
     *
     * @param User $user
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function receiveFromCustomer(User $user, int $id, array $data = []): array
    {
        $this->gate->assert($user, 'inventory.*');

        return $this->service->receiveFromCustomer($id, $user->id, $data);
    }

    /**
     * POST /api/core-returns/{id}/credit-customer
     * Credit customer for returned core
     *
     * @param User $user
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function creditCustomer(User $user, int $id, array $data = []): array
    {
        $this->gate->assert($user, 'inventory.*');

        return $this->service->creditCustomer($id, $user->id, $data['notes'] ?? null);
    }

    /**
     * POST /api/core-returns/{id}/return-to-vendor
     * Mark core as returned to vendor
     *
     * @param User $user
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function returnToVendor(User $user, int $id, array $data = []): array
    {
        $this->gate->assert($user, 'inventory.*');

        return $this->service->returnToVendor($id, $user->id, $data['notes'] ?? null);
    }

    /**
     * POST /api/core-returns/{id}/vendor-credit
     * Record vendor credit received
     *
     * @param User $user
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function vendorCredit(User $user, int $id, array $data): array
    {
        $this->gate->assert($user, 'inventory.*');

        if (!isset($data['credit_amount'])) {
            throw new InvalidArgumentException('Credit amount is required.');
        }

        return $this->service->recordVendorCredit(
            $id,
            (float) $data['credit_amount'],
            $user->id,
            $data['notes'] ?? null
        );
    }

    /**
     * POST /api/core-returns/{id}/waive
     * Waive core (customer keeps old part)
     *
     * @param User $user
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function waive(User $user, int $id, array $data = []): array
    {
        $this->gate->assert($user, 'inventory.*');

        return $this->service->waive($id, $user->id, $data['notes'] ?? null);
    }

    /**
     * POST /api/core-returns/{id}/expire
     * Mark core as expired
     *
     * @param User $user
     * @param int $id
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function expire(User $user, int $id, array $data = []): array
    {
        $this->gate->assert($user, 'inventory.*');

        return $this->service->expire($id, $user->id, $data['notes'] ?? null);
    }

    /**
     * GET /api/core-returns/status
     * Check if core tracking is enabled
     *
     * @param User $user
     * @return array<string, bool>
     */
    public function status(User $user): array
    {
        $this->gate->assert($user, 'inventory.view');

        return [
            'enabled' => $this->service->isEnabled(),
        ];
    }
}
