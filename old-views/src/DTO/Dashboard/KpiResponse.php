<?php

namespace App\DTO\Dashboard;

class KpiResponse
{
    /**
     * @var array<string, int>
     */
    public array $estimateStatusCounts = [];

    /**
     * @var array<string, float>
     */
    public array $invoiceTotals = [];

    /**
     * @var array<string, float>
     */
    public array $taxTotals = [];

    /**
     * @var array<string, int>
     */
    public array $warrantyCounts = [];

    /**
     * @var array<string, int>
     */
    public array $appointmentCounts = [];

    /**
     * @var array<string, int>
     */
    public array $inventoryAlerts = [];

    /**
     * @var array<string, float|int>
     */
    public array $summary = [];

    public function toArray(): array
    {
        return [
            'estimates' => $this->estimateStatusCounts,
            'invoices' => $this->invoiceTotals,
            'tax' => $this->taxTotals,
            'warranty' => $this->warrantyCounts,
            'appointments' => $this->appointmentCounts,
            'inventory' => $this->inventoryAlerts,
            'summary' => $this->summary,
        ];
    }
}
