<?php

namespace App\Services\Integrations;

interface PartnerDispatchAdapterInterface
{
    public function supports(string $partner): bool;

    /**
     * @param array<string, mixed> $payload
     */
    public function normalize(array $payload): PartnerDispatchDTO;

    /**
     * @param array<string, mixed> $dispatch
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function buildAcceptancePayload(array $dispatch, array $context = []): array;

    /**
     * @param array<string, mixed> $dispatch
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function buildStatusPayload(array $dispatch, string $status, array $context = []): array;
}
