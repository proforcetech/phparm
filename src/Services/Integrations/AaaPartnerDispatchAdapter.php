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
        $metadata = $this->metadata($payload, ['membershipId', 'callType', 'coverage', 'priority']);

        return new PartnerDispatchDTO(
            'aaa',
            $this->value($payload, ['callId', 'dispatchId', 'reference', 'caseId']),
            $this->value($payload, ['memberName', 'customerName', 'name']),
            $this->value($payload, ['memberPhone', 'phone', 'contactPhone']),
            $this->value($payload, ['memberEmail', 'email']),
            $this->value($payload, ['breakdownLocation', 'location', 'address']),
            $this->value($payload, ['serviceType', 'service', 'requestType']),
            $this->value($payload, ['notes', 'comments', 'description']),
            $this->value($payload, ['vehicle.vin', 'vin']),
            $this->value($payload, ['vehicle.make', 'vehicleMake', 'make']),
            $this->value($payload, ['vehicle.model', 'vehicleModel', 'model']),
            $this->intValue($payload, ['vehicle.year', 'vehicleYear', 'year']),
            $metadata
        );
    }
}
