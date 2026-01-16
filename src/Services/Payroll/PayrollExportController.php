<?php

namespace App\Services\Payroll;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;
use Throwable;

class PayrollExportController
{
    private PayrollExportService $service;
    private AccessGate $gate;

    public function __construct(PayrollExportService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'time_tracking.view')) {
            throw new UnauthorizedException('Cannot view payroll exports.');
        }

        $page = isset($filters['page']) ? max(1, (int) $filters['page']) : 1;
        $perPage = isset($filters['per_page']) ? max(1, (int) $filters['per_page']) : 25;
        $offset = ($page - 1) * $perPage;

        return $this->service->list($perPage, $offset);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function export(User $user, array $payload): array
    {
        if (!$this->gate->can($user, 'time_tracking.view')) {
            throw new UnauthorizedException('Cannot export payroll data.');
        }

        try {
            return $this->service->export(
                (string) ($payload['provider'] ?? ''),
                (string) ($payload['start_date'] ?? ''),
                (string) ($payload['end_date'] ?? ''),
                $user->id
            );
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            $this->service->recordFailure(
                (string) ($payload['provider'] ?? ''),
                (string) ($payload['start_date'] ?? ''),
                (string) ($payload['end_date'] ?? ''),
                $user->id,
                $exception->getMessage()
            );
            throw $exception;
        }
    }
}
