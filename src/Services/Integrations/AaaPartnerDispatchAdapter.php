<?php

namespace App\Services\Integrations;

class AaaPartnerDispatchAdapter extends AbstractPartnerDispatchAdapter
{
    public function supports(string $partner): bool
    {
        return strtolower($partner) === 'aaa';
    }

    public function normalize(array $payload): PartnerDispatchDTO
    {
        $protocol = $this->detectProtocol($payload, ['protocol', 'dispatchProtocol', 'dispatch_protocol']);
        $metadata = $this->metadata($payload, [
            'membershipId',
            'callType',
            'coverage',
            'priority',
            'protocol',
        ]);
        if ($protocol !== null) {
            $metadata['protocol'] = $protocol;
        }

        return new PartnerDispatchDTO(
            'aaa',
            $protocol,
            $this->value($payload, [
                'callId',
                'dispatchId',
                'reference',
                'caseId',
                'swift.callId',
                'swift.caseId',
                'digitalDispatch.callId',
                'digital_dispatch.callId',
            ]),
            $this->value($payload, [
                'memberName',
                'customerName',
                'name',
                'swift.memberName',
                'digitalDispatch.customer.name',
            ]),
            $this->value($payload, [
                'memberPhone',
                'phone',
                'contactPhone',
                'swift.memberPhone',
                'digitalDispatch.customer.phone',
            ]),
            $this->value($payload, [
                'memberEmail',
                'email',
                'swift.memberEmail',
                'digitalDispatch.customer.email',
            ]),
            $this->value($payload, [
                'breakdownLocation',
                'location',
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
        $context['status'] = 'accepted';
        return $this->buildStatusPayload($dispatch, 'accepted', $context);
    }

    public function buildStatusPayload(array $dispatch, string $status, array $context = []): array
    {
        $protocol = $dispatch['protocol'] ?? PartnerDispatchProtocol::DIGITAL_DISPATCH;
        $normalizedStatus = $this->normalizeStatus($status);
        $context['status'] = $normalizedStatus;
        $acceptanceFlag = match ($normalizedStatus) {
            'accepted' => true,
            'declined' => false,
            default => null,
        };

        $base = $this->baseStatusPayload($dispatch, $context);
        $payload = match ($protocol) {
            PartnerDispatchProtocol::SWIFT => [
                'protocol' => PartnerDispatchProtocol::SWIFT,
                'call_id' => $dispatch['external_reference'] ?? $dispatch['dispatch_reference'] ?? null,
                'dispatch_reference' => $dispatch['dispatch_reference'] ?? null,
                'status' => $normalizedStatus,
                'accepted' => $acceptanceFlag,
                'timestamp' => $base['occurred_at'],
                'provider' => $base['provider'],
                'notes' => $base['notes'],
            ],
            default => [
                'protocol' => PartnerDispatchProtocol::DIGITAL_DISPATCH,
                'dispatch_id' => $dispatch['external_reference'] ?? $dispatch['dispatch_reference'] ?? null,
                'status' => $normalizedStatus,
                'accepted' => $acceptanceFlag,
                'updated_at' => $base['occurred_at'],
                'provider' => $base['provider'],
                'notes' => $base['notes'],
            ],
        };

        return $this->pruneNulls($payload);
    }
}
