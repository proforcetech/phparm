<?php

namespace App\Services\Customer;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class CustomerRetentionReportController
{
    private CustomerRetentionReportService $reports;
    private AccessGate $gate;

    public function __construct(CustomerRetentionReportService $reports, AccessGate $gate)
    {
        $this->reports = $reports;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function index(User $user, array $params): array
    {
        $this->assertAccess($user);

        $months = isset($params['months']) ? max(1, (int) $params['months']) : 6;
        $limit = isset($params['limit']) ? max(1, (int) $params['limit']) : 50;
        $offset = isset($params['offset']) ? max(0, (int) $params['offset']) : 0;
        $query = isset($params['query']) ? trim((string) $params['query']) : null;

        return $this->reports->list($months, $limit, $offset, $query);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function export(User $user, array $params): array
    {
        $this->assertAccess($user);

        $months = isset($params['months']) ? max(1, (int) $params['months']) : 6;
        $format = (string) ($params['format'] ?? 'csv');
        $query = isset($params['query']) ? trim((string) $params['query']) : null;

        if (!in_array($format, ['csv', 'json'], true)) {
            throw new InvalidArgumentException('Unsupported export format');
        }

        $data = $this->reports->export($months, $format, $query);

        return [
            'format' => $format,
            'filename' => "customer-retention-{$months}-months.{$format}",
            'data' => $data,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatchCampaign(User $user, array $payload): array
    {
        $this->assertAccess($user);

        return $this->reports->dispatchCampaign($payload);
    }

    private function assertAccess(User $user): void
    {
        if ($this->gate->can($user, 'reports.view') || $this->gate->can($user, 'reports.*')) {
            return;
        }

        throw new UnauthorizedException('User lacks permission to view reports.');
    }
}
