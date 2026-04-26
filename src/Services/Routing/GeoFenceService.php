<?php

namespace App\Services\Routing;

use App\Models\GeoFence;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * Phase 10.6 — orchestrates fence CRUD with permission enforcement.
 *
 * Permission gates:
 *   geofences.view   read fence list/find
 *   geofences.manage create/update/delete fences (dispatcher/manager only)
 *
 * Validation guards: a circle without center+radius is rejected up-front
 * (rather than letting the database accept a half-formed row that the
 * evaluator can never match).
 */
class GeoFenceService
{
    public function __construct(
        private readonly GeoFenceRepository $repo,
        private readonly AccessGate $gate,
    ) {
    }

    public function find(User $actor, int $id): GeoFence
    {
        $this->gate->assert($actor, 'geofences.view');
        $row = $this->repo->findById($id);
        if ($row === null) {
            throw new InvalidArgumentException("Fence {$id} not found");
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, GeoFence>
     */
    public function listActive(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'geofences.view');
        return $this->repo->listActive($filters);
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, GeoFence>
     */
    public function listAll(User $actor, array $filters = []): array
    {
        $this->gate->assert($actor, 'geofences.view');
        return $this->repo->listAll($filters);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function create(User $actor, array $data): GeoFence
    {
        $this->gate->assert($actor, 'geofences.manage');
        $this->validateShape($data);
        return $this->repo->create($data);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(User $actor, int $id, array $data): GeoFence
    {
        $this->gate->assert($actor, 'geofences.manage');
        $existing = $this->repo->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Fence {$id} not found");
        }
        // Merge into existing for shape validation so a partial update that
        // sets only radius is checked against the persisted shape_type.
        $merged = array_merge($existing->toArray(), $data);
        $this->validateShape($merged);
        return $this->repo->update($id, $data);
    }

    public function delete(User $actor, int $id): void
    {
        $this->gate->assert($actor, 'geofences.manage');
        $this->repo->delete($id);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function validateShape(array $data): void
    {
        $shape = (string) ($data['shape_type'] ?? '');
        if (!in_array($shape, GeoFence::SHAPES, true)) {
            throw new InvalidArgumentException(
                "Unknown shape_type '{$shape}' (allowed: " . implode(', ', GeoFence::SHAPES) . ')'
            );
        }
        $purpose = (string) ($data['purpose'] ?? '');
        if (!in_array($purpose, GeoFence::PURPOSES, true)) {
            throw new InvalidArgumentException(
                "Unknown purpose '{$purpose}' (allowed: " . implode(', ', GeoFence::PURPOSES) . ')'
            );
        }
        if ($shape === GeoFence::SHAPE_CIRCLE) {
            $lat = $data['center_latitude'] ?? null;
            $lng = $data['center_longitude'] ?? null;
            $radius = $data['radius_meters'] ?? null;
            if ($lat === null || $lat === '' || $lng === null || $lng === '' || $radius === null || $radius === '') {
                throw new InvalidArgumentException(
                    'Circle fence requires center_latitude, center_longitude, and radius_meters'
                );
            }
            if ((int) $radius <= 0) {
                throw new InvalidArgumentException('radius_meters must be > 0');
            }
        }
        if ($shape === GeoFence::SHAPE_POLYGON) {
            $geo = $data['polygon_geojson'] ?? null;
            if (!is_string($geo) || trim($geo) === '') {
                throw new InvalidArgumentException(
                    'Polygon fence requires polygon_geojson (JSON array of [lng, lat] pairs)'
                );
            }
            $decoded = json_decode($geo, true);
            if (!is_array($decoded) || count($decoded) < 3) {
                throw new InvalidArgumentException(
                    'polygon_geojson must decode to an array of >= 3 [lng, lat] pairs'
                );
            }
        }
    }
}
