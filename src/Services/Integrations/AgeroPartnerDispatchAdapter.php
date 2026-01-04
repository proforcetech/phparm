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
        $metadata = $this->metadata($payload, ['caseNumber', 'program', 'serviceLevel', 'priority']);

        return new PartnerDispatchDTO(
            'agero',
            $this->value($payload, ['caseNumber', 'dispatchId', 'reference', 'callId']),
            $this->value($payload, ['callerName', 'customerName', 'name']),
            $this->value($payload, ['callerPhone', 'phone', 'contactPhone']),
            $this->value($payload, ['callerEmail', 'email']),
            $this->value($payload, ['location', 'breakdownLocation', 'address']),
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
