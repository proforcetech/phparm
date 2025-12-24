<?php

namespace App\Services\Customer;

use InvalidArgumentException;

class CustomerValidator
{
    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function validate(array $data): array
    {
        $payload = [
            'first_name' => trim((string) ($data['first_name'] ?? '')),
            'last_name' => trim((string) ($data['last_name'] ?? '')),
            'business_name' => isset($data['business_name']) && $data['business_name'] !== '' ? trim((string) $data['business_name']) : null,
            'email' => isset($data['email']) && $data['email'] !== '' ? trim((string) $data['email']) : null,
            'phone' => isset($data['phone']) && $data['phone'] !== '' ? trim((string) $data['phone']) : null,
            'street' => isset($data['street']) && $data['street'] !== '' ? trim((string) $data['street']) : null,
            'city' => isset($data['city']) && $data['city'] !== '' ? trim((string) $data['city']) : null,
            'state' => isset($data['state']) && $data['state'] !== '' ? trim((string) $data['state']) : null,
            'postal_code' => isset($data['postal_code']) && $data['postal_code'] !== '' ? trim((string) $data['postal_code']) : null,
            'country' => isset($data['country']) && $data['country'] !== '' ? trim((string) $data['country']) : null,
            'billing_street' => isset($data['billing_street']) && $data['billing_street'] !== '' ? trim((string) $data['billing_street']) : null,
            'billing_city' => isset($data['billing_city']) && $data['billing_city'] !== '' ? trim((string) $data['billing_city']) : null,
            'billing_state' => isset($data['billing_state']) && $data['billing_state'] !== '' ? trim((string) $data['billing_state']) : null,
            'billing_postal_code' => isset($data['billing_postal_code']) && $data['billing_postal_code'] !== '' ? trim((string) $data['billing_postal_code']) : null,
            'billing_country' => isset($data['billing_country']) && $data['billing_country'] !== '' ? trim((string) $data['billing_country']) : null,
            'is_commercial' => (bool) ($data['is_commercial'] ?? false),
            'tax_exempt' => (bool) ($data['tax_exempt'] ?? false),
            'notes' => isset($data['notes']) && $data['notes'] !== '' ? trim((string) $data['notes']) : null,
            'external_reference' => isset($data['external_reference']) && $data['external_reference'] !== '' ? trim((string) $data['external_reference']) : null,
        ];

        if ($payload['first_name'] === '') {
            throw new InvalidArgumentException('Customer first name is required.');
        }

        if ($payload['last_name'] === '') {
            throw new InvalidArgumentException('Customer last name is required.');
        }

        if ($payload['email'] === null && $payload['phone'] === null) {
            throw new InvalidArgumentException('Provide at least one contact method (email or phone).');
        }

        // Validate email format if provided
        if ($payload['email'] !== null && !filter_var($payload['email'], FILTER_VALIDATE_EMAIL)) {
            throw new InvalidArgumentException('Invalid email format.');
        }

        return $payload;
    }
}
