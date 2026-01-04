<?php

namespace App\Services\Messaging;

use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

class MaskedSmsController
{
    private MaskedSmsService $service;
    private AccessGate $gate;

    public function __construct(MaskedSmsService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function send(User $user, string $jobReference, array $payload): array
    {
        if (!$this->gate->can($user, 'messages.send')) {
            throw new UnauthorizedException('Cannot send masked SMS messages');
        }

        $message = (string) ($payload['message'] ?? '');
        if (trim($message) === '') {
            throw new InvalidArgumentException('message is required');
        }

        $driverUserId = (int) ($payload['driver_user_id'] ?? $user->id);
        $customerId = (int) ($payload['customer_id'] ?? 0);
        if ($customerId <= 0) {
            throw new InvalidArgumentException('customer_id is required');
        }

        $session = $this->service->createOrFetchSession([
            'job_reference' => $jobReference,
            'job_type' => $payload['job_type'] ?? 'workorder',
            'driver_user_id' => $driverUserId,
            'customer_id' => $customerId,
            'driver_phone' => $payload['driver_phone'] ?? '',
            'customer_phone' => $payload['customer_phone'] ?? '',
            'masked_number' => $payload['masked_number'] ?? null,
        ]);

        $senderRole = (string) ($payload['sender_role'] ?? $this->resolveDefaultRole($user));
        $messageRow = $this->service->sendMessage((int) $session['id'], $senderRole, $message);

        return [
            'session' => $session,
            'message' => $messageRow,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function receive(array $payload): array
    {
        $message = $this->service->receiveInbound($payload);

        return [
            'status' => 'received',
            'message' => $message,
        ];
    }

    private function resolveDefaultRole(User $user): string
    {
        return match ($user->role) {
            'customer' => 'customer',
            'technician', 'roadside' => 'driver',
            default => 'dispatcher',
        };
    }
}
