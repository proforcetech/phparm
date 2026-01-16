<?php

namespace App\Services\Financial;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

class ReconciliationController
{
    private ReconciliationService $service;
    private AccessGate $gate;

    public function __construct(ReconciliationService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listSessions(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view reconciliation sessions');
        }

        $sessions = $this->service->listSessions($filters);

        return [
            'data' => array_map(static fn ($session) => $session->toArray(), $sessions),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createSession(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'financials.create')) {
            throw new UnauthorizedException('Cannot create reconciliation sessions');
        }

        $session = $this->service->createSession($payload, $user->id);

        return $session->toArray();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function updateSession(User $user, int $sessionId, array $payload): array
    {
        if (!$this->gate->can($user, 'financials.update')) {
            throw new UnauthorizedException('Cannot update reconciliation sessions');
        }

        $session = $this->service->updateSession($sessionId, $payload);

        return $session->toArray();
    }

    public function showSession(User $user, int $sessionId): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view reconciliation sessions');
        }

        $session = $this->service->fetchSession($sessionId);
        $summary = $this->service->sessionSummary($sessionId);

        return [
            'session' => $session->toArray(),
            'summary' => $summary,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createBankTransaction(User $user, int $sessionId, array $payload): array
    {
        if (!$this->gate->can($user, 'financials.create')) {
            throw new UnauthorizedException('Cannot add bank transactions');
        }

        $transaction = $this->service->createBankTransaction($sessionId, $payload, $user->id);

        return $transaction->toArray();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listBankTransactions(User $user, int $sessionId): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view bank transactions');
        }

        return $this->service->listBankTransactions($sessionId);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function listLedgerEntries(User $user, int $sessionId, array $filters = []): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view ledger entries');
        }

        return $this->service->listLedgerEntries($sessionId, $filters);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function createMatch(User $user, int $sessionId, array $payload): array
    {
        if (!$this->gate->can($user, 'financials.update')) {
            throw new UnauthorizedException('Cannot create reconciliation matches');
        }

        $match = $this->service->createMatch($sessionId, $payload, $user->id);

        return $match->toArray();
    }

    public function deleteMatch(User $user, int $matchId): void
    {
        if (!$this->gate->can($user, 'financials.update')) {
            throw new UnauthorizedException('Cannot remove reconciliation matches');
        }

        $deleted = $this->service->deleteMatch($matchId);
        if (!$deleted) {
            throw new \InvalidArgumentException('Match not found');
        }
    }
}
