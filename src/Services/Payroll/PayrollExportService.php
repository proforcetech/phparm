<?php

namespace App\Services\Payroll;

use App\Models\PayrollExport;

class PayrollExportService
{
    private PayrollRunRepository $repository;
    private PayrollExportFormatter $formatter;

    public function __construct(PayrollRunRepository $repository, ?PayrollExportFormatter $formatter = null)
    {
        $this->repository = $repository;
        $this->formatter = $formatter ?? new PayrollExportFormatter();
    }

    public function exportRunToCsv(int $runId, string $provider, ?int $actorId = null): PayrollExport
    {
        $run = $this->repository->findRun($runId);
        if (!$run) {
            throw new \RuntimeException('Payroll run not found for export.');
        }

        $entries = $this->repository->fetchEntriesForExport($runId);
        $payload = $this->formatter->toCsv($run, $entries);

        return $this->repository->createExport($runId, $provider, 'csv', $payload, $actorId);
    }
}
