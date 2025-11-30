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
            'name' => trim((string) ($data['name'] ?? '')),
            'email' => isset($data['email']) ? trim((string) $data['email']) : null,
            'phone' => isset($data['phone']) ? trim((string) $data['phone']) : null,
            'commercial' => (bool) ($data['commercial'] ?? false),
            'tax_exempt' => (bool) ($data['tax_exempt'] ?? false),
            'notes' => isset($data['notes']) ? trim((string) $data['notes']) : null,
        ];

        if ($payload['name'] === '') {
            throw new InvalidArgumentException('Customer name is required.');
        }

        if ($payload['email'] === '' && $payload['phone'] === '') {
            throw new InvalidArgumentException('Provide at least one contact method (email or phone).');
        }

        return $payload;
    }
}
