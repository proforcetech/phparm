<?php

namespace App\Services\User;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class UserController
{
    private UserRepository $repository;
    private AccessGate $gate;

    public function __construct(UserRepository $repository, AccessGate $gate)
    {
        $this->repository = $repository;
        $this->gate = $gate;
    }

    /**
     * List technicians
     *
     * @param array<string, mixed> $params
     * @return array<int, array<string, mixed>>
     */
    public function listTechnicians(User $user, array $params = []): array
    {
        if (!$this->gate->can($user, 'users.view') && !$this->gate->can($user, 'appointments.*')) {
            throw new UnauthorizedException('Cannot view technicians');
        }

        $query = $params['query'] ?? '';

        if ($query !== '') {
            $technicians = $this->repository->searchByRole('technician', $query, 20);
        } else {
            $technicians = $this->repository->listByRole('technician');
        }

        return array_map(static fn ($tech) => [
            'id' => $tech->id,
            'name' => $tech->name,
            'email' => $tech->email,
            'role' => $tech->role
        ], $technicians);
    }
}
