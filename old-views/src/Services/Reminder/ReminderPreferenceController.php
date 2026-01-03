<?php

namespace App\Services\Reminder;

use App\Models\User;
use App\Services\Customer\CustomerRepository;
use InvalidArgumentException;

class ReminderPreferenceController
{
    private ReminderPreferenceService $preferences;
    private CustomerRepository $customers;

    public function __construct(ReminderPreferenceService $preferences, CustomerRepository $customers)
    {
        $this->preferences = $preferences;
        $this->customers = $customers;
    }

    public function showForCustomer(User $user): array
    {
        $this->assertCustomer($user);

        $customer = $this->customers->find((int) $user->customer_id);
        $preference = $this->preferences->findByCustomer((int) $user->customer_id);

        return [
            'customer' => $customer?->toArray(),
            'preference' => $preference?->toArray(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function upsertForCustomer(User $user, array $payload): array
    {
        $this->assertCustomer($user);

        $channel = $payload['preferred_channel'] ?? 'none';
        if (!in_array($channel, ['mail', 'sms', 'both', 'none', 'email'], true)) {
            throw new InvalidArgumentException('Invalid preferred channel.');
        }

        $firstName = trim((string) ($payload['first_name'] ?? ''));
        $lastName = trim((string) ($payload['last_name'] ?? ''));
        $email = isset($payload['email']) ? trim((string) $payload['email']) : null;
        $phone = isset($payload['phone']) ? trim((string) $payload['phone']) : null;
        if ($firstName === '' || $lastName === '') {
            throw new InvalidArgumentException('First name and last name are required.');
        }
        if ($email === '' && $phone === '') {
            throw new InvalidArgumentException('Provide at least one contact method.');
        }

        $timezone = $payload['timezone'] ?? 'UTC';
        $leadDays = isset($payload['lead_days']) ? max(0, (int) $payload['lead_days']) : 3;
        $preferredHour = isset($payload['preferred_hour']) ? max(0, min(23, (int) $payload['preferred_hour'])) : 9;
        $isActive = isset($payload['is_active']) ? (bool) $payload['is_active'] : false;

        $this->syncCustomer((int) $user->customer_id, [
            'first_name' => $firstName,
            'last_name' => $lastName,
            'email' => $email,
            'phone' => $phone,
        ]);

        $preference = $this->preferences->upsertForCustomer((int) $user->customer_id, [
            'email' => $email,
            'phone' => $phone,
            'timezone' => $timezone,
            'preferred_channel' => $channel,
            'lead_days' => $leadDays,
            'preferred_hour' => $preferredHour,
            'is_active' => $isActive,
            'source' => $payload['source'] ?? 'customer_portal',
        ]);

        $customer = $this->customers->find((int) $user->customer_id);

        return [
            'customer' => $customer?->toArray(),
            'preference' => $preference->toArray(),
        ];
    }

    private function assertCustomer(User $user): void
    {
        if ($user->role !== 'customer' || $user->customer_id === null) {
            throw new InvalidArgumentException('Customer access required.');
        }
    }

    /**
     * @param array{first_name:string,last_name:string,email:?string,phone:?string} $data
     */
    private function syncCustomer(int $customerId, array $data): void
    {
        $stmt = $this->customers->connection()->pdo()->prepare(
            'UPDATE customers SET first_name = :first_name, last_name = :last_name, email = :email, phone = :phone, updated_at = NOW() WHERE id = :id'
        );

        $stmt->execute([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'email' => $data['email'],
            'phone' => $data['phone'],
            'id' => $customerId,
        ]);
    }
}
