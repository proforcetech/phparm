<?php

namespace App\Services\Routing;

use App\Models\GeoFence;
use App\Models\GeoFenceEvent;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * Phase 10.6 — orchestrates fence event recording.
 *
 * Permission gates:
 *   geofences.view          read event log
 *   geofences.record_event  record a new event (mobile clients,
 *                           background sync workers, dispatch corrections)
 *
 * recordPosition() is the auto-evaluator: pass a (user, lat, lng), and the
 * service evaluates it against the active fence set, emitting an 'entered'
 * event for every fence the user is now inside that they weren't inside on
 * the most recent prior fix. (Exit events are not auto-emitted yet — that
 * requires a per-fix "user was here" cache that lives in a follow-up.)
 *
 * recordExplicit() lets the mobile client (or dispatch) post a single
 * known event directly, e.g., "I just arrived" or "actually I left at
 * 4:15, please backfill".
 */
class GeoFenceEventService
{
    public function __construct(
        private readonly GeoFenceEventRepository $repo,
        private readonly GeoFenceRepository $fences,
        private readonly GeoFenceEvaluator $evaluator,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, GeoFenceEvent>
     */
    public function listEvents(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'geofences.view');
        return $this->repo->listEvents($filters);
    }

    public function find(User $actor, int $id): GeoFenceEvent
    {
        $this->gate->assert($actor, 'geofences.view');
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("Event {$id} not found");
        }
        return $row;
    }

    /**
     * Record a single explicit event (used by the mobile "I'm here" button
     * and dispatch backfill corrections).
     *
     * @param array{
     *   geo_fence_id: int, user_id: int, event_type: string,
     *   workorder_id?: int|null, latitude?: float|null, longitude?: float|null,
     *   accuracy_meters?: int|null, source?: string,
     *   occurred_at?: string|null, notes?: string|null
     * } $data
     */
    public function recordExplicit(User $actor, array $data, ?DateTimeImmutable $now = null): GeoFenceEvent
    {
        $this->gate->assert($actor, 'geofences.record_event');
        $eventType = (string) ($data['event_type'] ?? '');
        if (!in_array($eventType, GeoFenceEvent::EVENT_TYPES, true)) {
            throw new InvalidArgumentException(
                "Unknown event_type '{$eventType}' (allowed: "
                . implode(', ', GeoFenceEvent::EVENT_TYPES) . ')'
            );
        }
        $source = (string) ($data['source'] ?? GeoFenceEvent::SOURCE_MOBILE_GPS);
        if (!in_array($source, GeoFenceEvent::SOURCES, true)) {
            throw new InvalidArgumentException(
                "Unknown source '{$source}' (allowed: "
                . implode(', ', GeoFenceEvent::SOURCES) . ')'
            );
        }
        $fenceId = (int) ($data['geo_fence_id'] ?? 0);
        if ($fenceId <= 0 || $this->fences->findById($fenceId) === null) {
            throw new InvalidArgumentException("Fence {$fenceId} not found");
        }
        $userId = (int) ($data['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id is required');
        }
        $stamp = (string) ($data['occurred_at']
            ?? ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s'));

        return $this->repo->create([
            'geo_fence_id' => $fenceId,
            'user_id' => $userId,
            'workorder_id' => $data['workorder_id'] ?? null,
            'event_type' => $eventType,
            'occurred_at' => $stamp,
            'latitude' => $data['latitude'] ?? null,
            'longitude' => $data['longitude'] ?? null,
            'accuracy_meters' => $data['accuracy_meters'] ?? null,
            'source' => $source,
            'notes' => $data['notes'] ?? null,
        ]);
    }

    /**
     * Auto-evaluate a position against the active fence set, emitting an
     * 'entered' event for each fence the user is now inside that they
     * weren't already inside on their last fix for that fence.
     *
     * Returns the list of newly-emitted events (empty if the position
     * matched no fences or the user was already inside every match).
     *
     * @param array{
     *   user_id: int, latitude: float, longitude: float,
     *   accuracy_meters?: int|null, workorder_id?: int|null,
     *   occurred_at?: string|null, source?: string
     * } $position
     * @return array<int, GeoFenceEvent>
     */
    public function recordPosition(User $actor, array $position, ?DateTimeImmutable $now = null): array
    {
        $this->gate->assert($actor, 'geofences.record_event');
        $userId = (int) ($position['user_id'] ?? 0);
        if ($userId <= 0) {
            throw new InvalidArgumentException('user_id is required');
        }
        $lat = (float) ($position['latitude'] ?? 0.0);
        $lng = (float) ($position['longitude'] ?? 0.0);
        $stamp = (string) ($position['occurred_at']
            ?? ($now ?? new DateTimeImmutable())->format('Y-m-d H:i:s'));
        $source = (string) ($position['source'] ?? GeoFenceEvent::SOURCE_MOBILE_GPS);
        if (!in_array($source, GeoFenceEvent::SOURCES, true)) {
            $source = GeoFenceEvent::SOURCE_MOBILE_GPS;
        }
        $accuracy = $position['accuracy_meters'] ?? null;
        $workorderId = $position['workorder_id'] ?? null;

        $active = $this->fences->listActive();
        $matches = $this->evaluator->matchingFences($active, $lat, $lng);
        if ($matches === []) {
            return [];
        }

        $created = [];
        foreach ($matches as $fence) {
            // Dedup: if the most recent event for this (user, fence) was
            // 'entered' or 'dwell', the user is still inside; suppress.
            $previous = $this->repo->findMostRecentForUserAndFence($userId, $fence->id);
            if ($previous !== null
                && in_array($previous->event_type, [
                    GeoFenceEvent::EVENT_ENTERED,
                    GeoFenceEvent::EVENT_DWELL,
                ], true)) {
                continue;
            }
            $created[] = $this->repo->create([
                'geo_fence_id' => $fence->id,
                'user_id' => $userId,
                'workorder_id' => $workorderId,
                'event_type' => GeoFenceEvent::EVENT_ENTERED,
                'occurred_at' => $stamp,
                'latitude' => $lat,
                'longitude' => $lng,
                'accuracy_meters' => $accuracy,
                'source' => $source,
            ]);
        }
        return $created;
    }

    /**
     * Cheap pure helper: which active fences contain the given position.
     * Useful for the mobile UI that wants to render "you are inside
     * customer-site-42" without persisting an event.
     *
     * @return array<int, GeoFence>
     */
    public function evaluatePosition(User $actor, float $latitude, float $longitude): array
    {
        $this->gate->assert($actor, 'geofences.view');
        $active = $this->fences->listActive();
        return $this->evaluator->matchingFences($active, $latitude, $longitude);
    }
}
