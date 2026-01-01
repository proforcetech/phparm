<?php

namespace App\Services\Roadside;

use App\Services\Messaging\MessagingNotificationService;

class RoadsideService
{
    private ?MessagingNotificationService $messagingNotifications;

    public function __construct(?MessagingNotificationService $messagingNotifications = null)
    {
        $this->messagingNotifications = $messagingNotifications;
    }

    /**
     * @return array<string, mixed>
     */
    public function dashboardSummary(): array
    {
        return [
            'open_requests' => 0,
            'in_progress' => 0,
            'completed_today' => 0,
            'last_updated' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function listRequests(array $filters = []): array
    {
        return [
            'filters' => $filters,
            'items' => [
                [
                    'id' => 1001,
                    'status' => 'new',
                    'priority' => 'normal',
                    'customer' => 'Sample Customer',
                    'location' => 'Awaiting dispatch details',
                    'requested_at' => (new \DateTimeImmutable('-10 minutes'))->format(DATE_ATOM),
                ],
            ],
            'total' => 1,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createRequest(array $payload, ?int $actorId = null): array
    {
        $requestId = (int) ($payload['request_id'] ?? 1001);
        $customerName = (string) ($payload['customer_name'] ?? 'Pending Customer');
        $request = [
            'id' => $requestId,
            'status' => 'new',
            'priority' => $payload['priority'] ?? 'normal',
            'customer' => $customerName,
            'notes' => $payload['notes'] ?? 'Request intake captured (placeholder).',
            'requested_at' => (new \DateTimeImmutable())->format(DATE_ATOM),
        ];

        $this->messagingNotifications?->dispatch('roadside.assistance.requested', [
            'request_id' => $requestId,
            'customer' => $customerName,
            'actor_id' => $actorId,
        ]);

        return $request;
    }
}
