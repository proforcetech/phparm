<?php

namespace App\Services\Credit;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class CreditAccountController
{
    private CreditAccountService $service;
    private CreditAccountStatementService $statements;
    private AccessGate $gate;

    public function __construct(
        CreditAccountService $service,
        CreditAccountStatementService $statements,
        AccessGate $gate
    ) {
        $this->service = $service;
        $this->statements = $statements;
        $this->gate = $gate;
    }

    /**
     * List credit accounts
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'credit.view')) {
            throw new UnauthorizedException('Cannot view credit accounts');
        }

        $accounts = $this->service->list($filters);
        return array_map(static fn ($a) => $a->toArray(), $accounts);
    }

    /**
     * Get credit account
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        if (!$this->gate->can($user, 'credit.view')) {
            throw new UnauthorizedException('Cannot view credit accounts');
        }

        $account = $this->service->findById($id);

        if ($account === null) {
            throw new InvalidArgumentException('Credit account not found');
        }

        return $account->toArray();
    }

    /**
     * Create credit account
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'credit.create')) {
            throw new UnauthorizedException('Cannot create credit accounts');
        }

        $account = $this->service->create($data, $user->id);
        return $account->toArray();
    }

    /**
     * Record payment to credit account
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function recordPayment(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'credit.update')) {
            throw new UnauthorizedException('Cannot update credit accounts');
        }

        $result = $this->service->recordPayment($id, $data, $user->id);
        return $result;
    }

    /**
     * Generate statement
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function statement(User $user, int $id, array $params): array
    {
        if (!$this->gate->can($user, 'credit.view')) {
            throw new UnauthorizedException('Cannot view credit account statements');
        }

        $startDate = $params['start_date'] ?? null;
        $endDate = $params['end_date'] ?? null;

        $statement = $this->statements->generate($id, $startDate, $endDate);
        return $statement;
    }

    /**
     * Customer portal - view own credit account
     *
     * @return array<string, mixed>
     */
    public function customerView(User $user): array
    {
        if ($user->role !== 'customer' || $user->customer_id === null) {
            throw new UnauthorizedException('Only customers can access this endpoint');
        }

        $account = $this->service->findByCustomerId($user->customer_id);

        if ($account === null) {
            throw new InvalidArgumentException('No credit account found');
        }

        return [
            'account' => $account->toArray(),
            'balance' => $this->service->getBalance($account->id),
            'available_credit' => $this->service->getAvailableCredit($account->id),
        ];
    }

    /**
     * Customer portal - transaction history & reminders
     *
     * @return array<string, mixed>
     */
    public function customerHistory(User $user): array
    {
        if ($user->role !== 'customer' || $user->customer_id === null) {
            throw new UnauthorizedException('Only customers can access this endpoint');
        }

        return $this->service->customerLedger($user->customer_id);
    }

    /**
     * Customer portal - submit payment for review
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function submitCustomerPayment(User $user, array $data): array
    {
        if ($user->role !== 'customer' || $user->customer_id === null) {
            throw new UnauthorizedException('Only customers can access this endpoint');
        }

        return $this->service->submitCustomerPayment($user->customer_id, $data, $user->id);
    }
}
