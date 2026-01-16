<?php

namespace App\Services\Integrations;

class GeicoPartnerDispatchAdapter extends AbstractPartnerDispatchAdapter
{
    public function supports(string $partner): bool
    {
        return strtolower($partner) === 'geico';
    }

    public function normalize(array $payload): PartnerDispatchDTO
    {
        $protocol = $this->detectProtocol($payload, ['protocol', 'dispatchProtocol', 'dispatch_protocol']);
        $metadata = $this->metadata($payload, ['claimNumber', 'policyNumber', 'priority', 'coverage', 'protocol']);
        if ($protocol !== null) {
            $metadata['protocol'] = $protocol;
        }

        return new PartnerDispatchDTO(
            'geico',
            $protocol,
            $this->value($payload, ['claimNumber', 'dispatchId', 'reference', 'caseId']),
            $this->value($payload, ['insuredName', 'customer.name', 'customerName', 'name']),
            $this->value($payload, ['insuredPhone', 'customer.phone', 'phone']),
            $this->value($payload, ['insuredEmail', 'customer.email', 'email']),
            $this->value($payload, ['location', 'serviceAddress', 'address']),
            $this->value($payload, ['serviceType', 'requestType', 'service']),
            $this->value($payload, ['notes', 'comments', 'description']),
            $this->value($payload, ['vehicle.vin', 'vin']),
            $this->value($payload, ['vehicle.make', 'vehicleMake', 'make']),
            $this->value($payload, ['vehicle.model', 'vehicleModel', 'model']),
            $this->intValue($payload, ['vehicle.year', 'vehicleYear', 'year']),
            $metadata
        );
    }

    public function buildAcceptancePayload(array $dispatch, array $context = []): array
    {
        $context['status'] = 'accepted';
        return $this->buildStatusPayload($dispatch, 'accepted', $context);
    }

    public function buildStatusPayload(array $dispatch, string $status, array $context = []): array
    {
        $normalizedStatus = $this->normalizeStatus($status);
        $context['status'] = $normalizedStatus;
        $base = $this->baseStatusPayload($dispatch, $context);
        $acceptanceFlag = match ($normalizedStatus) {
            'accepted' => true,
            'declined' => false,
            default => null,
        };

        return $this->pruneNulls([
            'protocol' => $dispatch['protocol'] ?? null,
            'claim_number' => $dispatch['external_reference'] ?? $dispatch['dispatch_reference'] ?? null,
            'dispatch_reference' => $dispatch['dispatch_reference'] ?? null,
            'status' => $normalizedStatus,
            'accepted' => $acceptanceFlag,
            'updated_at' => $base['occurred_at'],
            'provider' => $base['provider'],
            'notes' => $base['notes'],
        ]);
    }
}
