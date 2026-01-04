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
        $metadata = $this->metadata($payload, ['claimNumber', 'policyNumber', 'priority', 'coverage']);

        return new PartnerDispatchDTO(
            'geico',
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
}
