<?php

namespace App\Services\Integrations;

class PartnerDispatchDTO
{
    public string $partner;
    public ?string $externalReference;
    public ?string $customerName;
    public ?string $customerPhone;
    public ?string $customerEmail;
    public ?string $location;
    public ?string $serviceType;
    public ?string $notes;
    public ?string $vehicleVin;
    public ?string $vehicleMake;
    public ?string $vehicleModel;
    public ?int $vehicleYear;
    /**
     * @var array<string, mixed>
     */
    public array $metadata;

    /**
     * @param array<string, mixed> $metadata
     */
    public function __construct(
        string $partner,
        ?string $externalReference,
        ?string $customerName,
        ?string $customerPhone,
        ?string $customerEmail,
        ?string $location,
        ?string $serviceType,
        ?string $notes,
        ?string $vehicleVin,
        ?string $vehicleMake,
        ?string $vehicleModel,
        ?int $vehicleYear,
        array $metadata = []
    ) {
        $this->partner = $partner;
        $this->externalReference = $externalReference;
        $this->customerName = $customerName;
        $this->customerPhone = $customerPhone;
        $this->customerEmail = $customerEmail;
        $this->location = $location;
        $this->serviceType = $serviceType;
        $this->notes = $notes;
        $this->vehicleVin = $vehicleVin;
        $this->vehicleMake = $vehicleMake;
        $this->vehicleModel = $vehicleModel;
        $this->vehicleYear = $vehicleYear;
        $this->metadata = $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'partner' => $this->partner,
            'external_reference' => $this->externalReference,
            'customer' => [
                'name' => $this->customerName,
                'phone' => $this->customerPhone,
                'email' => $this->customerEmail,
            ],
            'vehicle' => [
                'vin' => $this->vehicleVin,
                'make' => $this->vehicleMake,
                'model' => $this->vehicleModel,
                'year' => $this->vehicleYear,
            ],
            'location' => $this->location,
            'service_type' => $this->serviceType,
            'notes' => $this->notes,
            'metadata' => $this->metadata,
        ];
    }
}
