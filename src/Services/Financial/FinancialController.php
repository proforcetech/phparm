<?php

namespace App\Services\Financial;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class FinancialController
{
    private FinancialEntryService $entries;
    private FinancialReportService $reports;
    private AccessGate $gate;

    public function __construct(
        FinancialEntryService $entries,
        FinancialReportService $reports,
        AccessGate $gate
    ) {
        $this->entries = $entries;
        $this->reports = $reports;
        $this->gate = $gate;
    }

    /**
     * List financial entries
     *
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view financial entries');
        }

        $result = $this->entries->paginate($filters);

        return [
            'data' => array_map(static fn ($e) => $e->toArray(), $result['data']),
            'pagination' => $result['pagination'],
        ];
    }

    /**
     * Create financial entry
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        if (!$this->gate->can($user, 'financials.create')) {
            throw new UnauthorizedException('Cannot create financial entries');
        }

        $entry = $this->entries->create($data, $user->id);
        return $entry->toArray();
    }

    /**
     * Update financial entry
     *
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $data): array
    {
        if (!$this->gate->can($user, 'financials.update')) {
            throw new UnauthorizedException('Cannot update financial entries');
        }

        $entry = $this->entries->update($id, $data, $user->id);

        if ($entry === null) {
            throw new InvalidArgumentException('Financial entry not found');
        }

        return $entry->toArray();
    }

    /**
     * Delete financial entry
     */
    public function destroy(User $user, int $id): void
    {
        if (!$this->gate->can($user, 'financials.delete')) {
            throw new UnauthorizedException('Cannot delete financial entries');
        }

        $deleted = $this->entries->delete($id, $user->id);

        if (!$deleted) {
            throw new InvalidArgumentException('Financial entry not found');
        }
    }

    /**
     * Generate financial report
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function report(User $user, array $params): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot view financial reports');
        }

        $startDate = $params['start_date'] ?? null;
        $endDate = $params['end_date'] ?? null;
        $category = $params['category'] ?? null;
        $vendor = $params['vendor'] ?? null;

        if (!$startDate || !$endDate) {
            throw new InvalidArgumentException('start_date and end_date are required');
        }

        return $this->reports->generate($startDate, $endDate, $category, $vendor);
    }

    /**
     * Export financial report
     *
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function export(User $user, array $params): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot export financial reports');
        }

        $startDate = $params['start_date'] ?? null;
        $endDate = $params['end_date'] ?? null;
        $format = $params['format'] ?? 'csv';
        $category = $params['category'] ?? null;
        $vendor = $params['vendor'] ?? null;

        if (!$startDate || !$endDate) {
            throw new InvalidArgumentException('start_date and end_date are required');
        }

        $data = $this->reports->export($startDate, $endDate, $format, $category, $vendor);

        return [
            'format' => $format,
            'data' => $data,
            'filename' => "financial-report-{$startDate}-{$endDate}.{$format}",
        ];
    }

    /**
     * Export financial entries
     *
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function exportEntries(User $user, array $filters): array
    {
        if (!$this->gate->can($user, 'financials.view')) {
            throw new UnauthorizedException('Cannot export financial entries');
        }

        $data = $this->entries->exportCsv($filters);

        return [
            'format' => 'csv',
            'filename' => 'financial-entries.csv',
            'data' => $data,
        ];
    }
}
