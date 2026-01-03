<?php

namespace App\Services\Health;

class HealthStatusController
{
    private HealthStatusService $service;

    public function __construct(HealthStatusService $service)
    {
        $this->service = $service;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        $status = $this->service->status();
        $ok = $status['database']['ok']
            && $status['queues']['ok']
            && $status['schedulers']['ok']
            && $status['integrations']['ok'];

        return [
            'ok' => $ok,
            'checks' => $status,
        ];
    }
}
