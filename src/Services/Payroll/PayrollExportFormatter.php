<?php

namespace App\Services\Payroll;

use App\Models\PayrollRun;

class PayrollExportFormatter
{
    /**
     * @param array<int, array<string, mixed>> $entries
     */
    public function toCsv(PayrollRun $run, array $entries): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open memory stream for payroll export.');
        }

        $headers = [
            'run_id',
            'run_label',
            'period_start',
            'period_end',
            'entry_id',
            'employee_id',
            'employee_name',
            'employee_email',
            'pay_type',
            'gross_pay',
            'currency',
        ];

        fputcsv($handle, $headers);

        foreach ($entries as $entry) {
            fputcsv($handle, [
                $run->id,
                $run->run_label,
                $run->period_start,
                $run->period_end,
                $entry['id'] ?? null,
                $entry['employee_id'] ?? null,
                $entry['employee_name'] ?? null,
                $entry['employee_email'] ?? null,
                $entry['pay_type'] ?? null,
                $entry['gross_pay'] ?? null,
                $entry['currency'] ?? null,
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return $csv === false ? '' : $csv;
    }
}
