<?php

namespace App\Services\Integrations;

class AgeroPartnerDispatchAdapter extends AbstractPartnerDispatchAdapter
{
    public function supports(string $partner): bool
    {
        return strtolower($partner) === 'agero';
    }

    public function normalize(array $payload): PartnerDispatchDTO
    {
        $protocol = $this->detectProtocol($payload, ['protocol', 'dispatchProtocol', 'dispatch_protocol']);
        $metadata = $this->metadata($payload, [
            'caseNumber',
            'program',
            'serviceLevel',
            'priority',
            'protocol',
        ]);
        if ($protocol !== null) {
            $metadata['protocol'] = $protocol;
        }

        return new PartnerDispatchDTO(
            'agero',
            $protocol,
            $this->value($payload, [
                'caseNumber',
                'dispatchId',
                'reference',
                'callId',
                'swift.caseNumber',
                'swift.dispatchId',
                'digitalDispatch.caseNumber',
                'digital_dispatch.caseNumber',
            ]),
            $this->value($payload, [
                'callerName',
                'customerName',
                'name',
                'swift.callerName',
                'digitalDispatch.customer.name',
            ]),
            $this->value($payload, [
                'callerPhone',
                'phone',
                'contactPhone',
                'swift.callerPhone',
                'digitalDispatch.customer.phone',
            ]),
            $this->value($payload, [
                'callerEmail',
                'email',
                'swift.callerEmail',
                'digitalDispatch.customer.email',
            ]),
            $this->value($payload, [
                'location',
                'breakdownLocation',
                'address',
                'swift.location',
                'digitalDispatch.location',
            ]),
            $this->value($payload, [
                'serviceType',
                'service',
                'requestType',
                'swift.serviceType',
                'digitalDispatch.serviceType',
            ]),
            $this->value($payload, [
                'notes',
                'comments',
                'description',
                'swift.notes',
                'digitalDispatch.notes',
            ]),
            $this->value($payload, ['vehicle.vin', 'vin', 'swift.vehicle.vin', 'digitalDispatch.vehicle.vin']),
            $this->value($payload, ['vehicle.make', 'vehicleMake', 'make', 'swift.vehicle.make', 'digitalDispatch.vehicle.make']),
            $this->value($payload, ['vehicle.model', 'vehicleModel', 'model', 'swift.vehicle.model', 'digitalDispatch.vehicle.model']),
            $this->intValue($payload, ['vehicle.year', 'vehicleYear', 'year', 'swift.vehicle.year', 'digitalDispatch.vehicle.year']),
            $metadata
        );
    }

    public function buildAcceptancePayload(array $dispatch, array $context = []): array
    {
        $protocol = $dispatch['protocol'] ?? PartnerDispatchProtocol::DIGITAL_DISPATCH;
        $context['status'] = 'accepted';

        return $this->buildStatusPayload($dispatch, 'accepted', $context);
    }

    public function buildStatusPayload(array $dispatch, string $status, array $context = []): array
    {
        $protocol = $dispatch['protocol'] ?? PartnerDispatchProtocol::DIGITAL_DISPATCH;
        $normalizedStatus = $this->normalizeStatus($status);
        $context['status'] = $normalizedStatus;

        $base = $this->baseStatusPayload($dispatch, $context);
        $payload = match ($protocol) {
            PartnerDispatchProtocol::SWIFT => [
                'protocol' => PartnerDispatchProtocol::SWIFT,
                'case_number' => $dispatch['external_reference'] ?? $dispatch['dispatch_reference'] ?? null,
                'dispatch_reference' => $dispatch['dispatch_reference'] ?? null,
                'status' => $normalizedStatus,
                'accepted' => $normalizedStatus === 'accepted',
                'timestamp' => $base['occurred_at'],
                'provider' => $base['provider'],
                'notes' => $base['notes'],
            ],
            default => [
                'protocol' => PartnerDispatchProtocol::DIGITAL_DISPATCH,
                'dispatch_id' => $dispatch['external_reference'] ?? $dispatch['dispatch_reference'] ?? null,
                'status' => $normalizedStatus,
                'updated_at' => $base['occurred_at'],
                'provider' => $base['provider'],
                'notes' => $base['notes'],
            ],
        };

        return $this->pruneNulls($payload);
    }
}
