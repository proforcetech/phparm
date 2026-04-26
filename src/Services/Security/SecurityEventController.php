<?php

namespace App\Services\Security;

use App\Models\SecurityEvent;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

class SecurityEventController
{
    public function __construct(
        private SecurityEventRepository $repo,
        private SecurityEventLogger $logger,
        private AccessGate $gate
    ) {
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function index(User $actor, array $query): array
    {
        $this->gate->assert($actor, 'security_events.view');

        $filters = $this->parseFilters($query);
        $limit = isset($query['limit']) ? max(1, min(500, (int) $query['limit'])) : 100;
        $offset = isset($query['offset']) ? max(0, (int) $query['offset']) : 0;

        $events = $this->repo->listFiltered($filters, $limit, $offset);
        $total = $this->repo->countFiltered($filters);

        return [
            'data' => [
                'events' => array_map(static fn (SecurityEvent $e) => $e->toArray(), $events),
                'total' => $total,
                'limit' => $limit,
                'offset' => $offset,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $actor, int $id): array
    {
        $this->gate->assert($actor, 'security_events.view');

        $event = $this->repo->find($id);
        if ($event === null) {
            throw new InvalidArgumentException('Security event not found.');
        }

        return ['data' => $event->toArray()];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    public function summary(User $actor, array $query): array
    {
        $this->gate->assert($actor, 'security_events.view');
        $since = isset($query['since']) ? (string) $query['since'] : null;

        return ['data' => ['by_severity' => $this->repo->aggregateBySeverity($since)]];
    }

    /**
     * Manual SOC entry from an admin tool — `security_events.manage`
     * gate covers explicit creates so we can distinguish "admin
     * recorded a triage note" from automated logger writes.
     *
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function record(User $actor, array $payload): array
    {
        $this->gate->assert($actor, 'security_events.manage');

        if (empty($payload['event_type'])) {
            throw new InvalidArgumentException('event_type is required.');
        }
        $severity = $payload['severity'] ?? SecurityEvent::SEVERITY_INFO;
        if (!in_array($severity, SecurityEvent::SEVERITIES, true)) {
            throw new InvalidArgumentException('Invalid severity: ' . $severity);
        }

        $event = $this->logger->log((string) $payload['event_type'], (string) $severity, [
            'actor' => $actor,
            'target_user_id' => $payload['target_user_id'] ?? null,
            'context' => is_array($payload['context'] ?? null) ? $payload['context'] : null,
        ]);

        return ['data' => $event->toArray()];
    }

    /**
     * @param array<string, mixed> $query
     * @return array<string, mixed>
     */
    private function parseFilters(array $query): array
    {
        $filters = [];
        foreach (['event_type', 'severity', 'since'] as $k) {
            if (isset($query[$k]) && $query[$k] !== '') {
                $filters[$k] = (string) $query[$k];
            }
        }
        foreach (['actor_user_id', 'target_user_id'] as $k) {
            if (isset($query[$k]) && $query[$k] !== '') {
                $filters[$k] = (int) $query[$k];
            }
        }
        return $filters;
    }
}
