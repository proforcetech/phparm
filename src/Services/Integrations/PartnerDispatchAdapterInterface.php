<?php

namespace App\Services\Integrations;

interface PartnerDispatchAdapterInterface
{
    public function supports(string $partner): bool;

    /**
     * @param array<string, mixed> $payload
     */
    public function normalize(array $payload): PartnerDispatchDTO;
}
