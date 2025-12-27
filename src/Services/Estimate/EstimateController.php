<?php

namespace App\Services\Estimate;

use App\Models\User;
use App\Services\Invoice\InvoiceService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class EstimateController
{
    private EstimateRepository $repository;
    private AccessGate $gate;
    private EstimateEditorService $editor;
    private InvoiceService $invoices;

    public function __construct(
        EstimateRepository $repository,
        AccessGate $gate,
        EstimateEditorService $editor,
        InvoiceService $invoices
    )
    {
        $this->repository = $repository;
        $this->gate = $gate;
        $this->editor = $editor;
        $this->invoices = $invoices;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $params = []): array
    {
        $this->assertViewAccess($user);
        $filters = $this->extractFilters($params, $user);
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;

        return array_map(static fn ($estimate) => $estimate->toArray(), $this->repository->list($filters, $limit, $offset));
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $estimateId): array
    {
        $this->assertViewAccess($user);

        $estimate = $this->repository->find($estimateId);
        if ($estimate === null) {
            throw new InvalidArgumentException('Estimate not found');
        }

        if ($user->role === 'customer' && $user->customer_id !== null && $estimate->customer_id !== $user->customer_id) {
            throw new UnauthorizedException('Cannot view another customer\'s estimate.');
        }

        return $estimate->toArray();
    }

    public function approve(User $user, int $estimateId, ?string $reason = null): ?array
    {
        $this->assertManageAccess($user);

        $estimate = $this->repository->updateStatus($estimateId, 'approved', $user->id, $reason);

        return $estimate?->toArray();
    }

    public function reject(User $user, int $estimateId, ?string $reason = null): ?array
    {
        $this->assertManageAccess($user);

        $estimate = $this->editor->reject($estimateId, $reason ?? '', $user->id);

        return $estimate?->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function updateItemStatuses(User $user, int $estimateId, array $payload): array
    {
        $this->assertManageAccess($user);

        if (!isset($payload['items']) || !is_array($payload['items'])) {
            throw new InvalidArgumentException('items are required for status updates');
        }

        $estimate = $this->editor->updateItemStatuses($estimateId, $payload['items'], $user->id);
        if ($estimate === null) {
            throw new InvalidArgumentException('Estimate not found');
        }

        return $estimate->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function mergeIntoInvoice(User $user, int $estimateId, array $payload): array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'invoices.update');

        if (!isset($payload['invoice_id'])) {
            throw new InvalidArgumentException('invoice_id is required');
        }

        $invoice = $this->invoices->mergeEstimateIntoInvoice((int) $payload['invoice_id'], $estimateId, $user->id);
        if ($invoice === null) {
            throw new InvalidArgumentException('Invoice not found');
        }

        return $invoice->toArray();
    }

    public function requestReapproval(User $user, int $estimateId, ?string $reason = null): ?array
    {
        $this->assertManageAccess($user);

        $estimate = $this->repository->updateStatus($estimateId, 'needs_reapproval', $user->id, $reason);

        return $estimate?->toArray();
    }

    public function expire(User $user, int $estimateId, ?string $reason = null): ?array
    {
        $this->assertManageAccess($user);

        $estimate = $this->repository->updateStatus($estimateId, 'expired', $user->id, $reason);

        return $estimate?->toArray();
    }

    public function convertToInvoice(User $user, int $estimateId, string $issueDate, ?string $dueDate = null): ?array
    {
        $this->assertManageAccess($user);
        $this->gate->assert($user, 'invoices.create');

        $invoice = $this->repository->convertToInvoice($estimateId, $issueDate, $dueDate, $user->id);

        return $invoice?->toArray();
    }

    public function sendLink(User $user, int $estimateId, string $channel, string $recipient, string $link): array
    {
        $this->assertViewAccess($user);

        return $this->repository->logLinkDispatch($estimateId, $channel, $recipient, $link, $user->id);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function store(User $user, array $payload): array
    {
        $this->assertCreateAccess($user);

        $estimate = $this->editor->create($payload, $user->id);

        return $estimate->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function update(User $user, int $estimateId, array $payload): array
    {
        $this->assertManageAccess($user);

        $updated = $this->editor->update($estimateId, $payload, $user->id);
        if ($updated === null) {
            throw new InvalidArgumentException('Estimate not found');
        }

        return $updated->toArray();
    }

    public function delete(User $user, int $estimateId): bool
    {
        $this->assertManageAccess($user);

        return $this->repository->delete($estimateId, $user->id);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function extractFilters(array $params, ?User $user = null): array
    {
        $filters = [];
        foreach (['status', 'customer_id', 'vehicle_id', 'service_type_id', 'term', 'created_from', 'created_to'] as $key) {
            if (isset($params[$key]) && $params[$key] !== '') {
                $filters[$key] = $params[$key];
            }
        }

        if ($user !== null && $user->role === 'customer' && $user->customer_id !== null) {
            $filters['customer_id'] = $user->customer_id;
        }

        return $filters;
    }

    private function assertManageAccess(User $user): void
    {
        $this->gate->assert($user, 'estimates.update');
    }

    private function assertCreateAccess(User $user): void
    {
        if ($this->gate->can($user, 'estimates.create') || $this->gate->can($user, 'estimates.update')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to create estimates.');
    }

    private function assertViewAccess(User $user): void
    {
        if ($this->gate->can($user, 'estimates.view') || $this->gate->can($user, 'estimates.update')) {
            return;
        }

        if ($this->gate->can($user, 'portal.estimates')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view estimates.');
    }
}
