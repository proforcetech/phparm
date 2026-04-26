<?php

namespace App\Services\ServiceLine;

use App\Models\ServiceLine;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP-facing controller for /api/service-lines and /api/me/service-lines.
 * Mirrors {@see DivisionController}: read endpoints are open to any
 * authenticated user (lists feed UI pickers); mutating endpoints require
 * settings.service_lines.manage.
 *
 * NOTE: not-found is signalled with InvalidArgumentException to match the
 * existing pattern (Router maps it to a 400). The codebase has no
 * HttpException type yet — see DivisionController for the same pattern.
 */
class ServiceLineController
{
    public function __construct(
        private readonly ServiceLineService $service,
        private readonly ServiceLineRepository $repository,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @return array{service_lines: array<int, array<string, mixed>>}
     */
    public function index(User $user, bool $includeInactive = false): array
    {
        $lines = $includeInactive
            ? $this->repository->listAll()
            : $this->repository->listActive();

        return [
            'service_lines' => array_map([self::class, 'toArray'], $lines),
        ];
    }

    /**
     * @return array{service_line: array<string, mixed>}
     */
    public function show(User $user, int $id): array
    {
        $line = $this->repository->findById($id);
        if ($line === null) {
            throw new InvalidArgumentException("Service line {$id} not found");
        }

        return ['service_line' => self::toArray($line)];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body): array
    {
        $this->gate->assert($user, 'settings.service_lines.manage');

        $slug = trim((string) ($body['slug'] ?? ''));
        $name = trim((string) ($body['name'] ?? ''));
        if ($slug === '' || $name === '') {
            throw new InvalidArgumentException('slug and name are required');
        }
        if (!preg_match('/^[a-z0-9_]+$/', $slug)) {
            throw new InvalidArgumentException('slug must be lowercase alphanumeric with underscores');
        }

        $this->service->assertSlugAvailable($slug);

        $line = $this->repository->create([
            'slug' => $slug,
            'name' => $name,
            'description' => isset($body['description']) ? (string) $body['description'] : null,
            'icon' => isset($body['icon']) ? (string) $body['icon'] : null,
            'sort_order' => (int) ($body['sort_order'] ?? 0),
            'is_active' => (bool) ($body['is_active'] ?? true),
        ]);

        return self::toArray($line);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'settings.service_lines.manage');

        $existing = $this->repository->findById($id);
        if ($existing === null) {
            throw new InvalidArgumentException("Service line {$id} not found");
        }

        $updates = [];
        if (isset($body['name'])) {
            $updates['name'] = trim((string) $body['name']);
        }
        if (array_key_exists('description', $body)) {
            $updates['description'] = $body['description'] === null ? null : (string) $body['description'];
        }
        if (array_key_exists('icon', $body)) {
            $updates['icon'] = $body['icon'] === null ? null : (string) $body['icon'];
        }
        if (isset($body['sort_order'])) {
            $updates['sort_order'] = (int) $body['sort_order'];
        }
        if (isset($body['is_active'])) {
            $updates['is_active'] = (bool) $body['is_active'];
        }

        // slug is intentionally NOT updatable — it's a stable external key,
        // matching the divisions.code convention.

        $updated = $this->repository->update($id, $updates);

        return self::toArray($updated);
    }

    /**
     * Lines visible to the calling user, plus their primary line id.
     *
     * @return array{service_lines: array<int, array<string, mixed>>, primary_id: ?int}
     */
    public function mine(User $user): array
    {
        $effective = $this->service->getEffectiveLinesForUser($user->id);

        return [
            'service_lines' => array_map([self::class, 'toArray'], $effective['lines']),
            'primary_id' => $effective['primary_id'],
        ];
    }

    /**
     * Set the calling user's primary service line. Body shape: { service_line_id: int }.
     *
     * @param array<string, mixed> $body
     * @return array{service_lines: array<int, array<string, mixed>>, primary_id: ?int}
     */
    public function setPrimary(User $user, array $body): array
    {
        $serviceLineId = (int) ($body['service_line_id'] ?? 0);
        if ($serviceLineId <= 0) {
            throw new InvalidArgumentException('service_line_id is required');
        }

        $this->service->setPrimary($user->id, $serviceLineId);

        return $this->mine($user);
    }

    /**
     * @return array<string, mixed>
     */
    private static function toArray(ServiceLine $line): array
    {
        return [
            'id' => $line->id,
            'slug' => $line->slug,
            'name' => $line->name,
            'description' => $line->description,
            'icon' => $line->icon,
            'sort_order' => $line->sort_order,
            'is_active' => $line->is_active,
            'created_at' => $line->created_at,
            'updated_at' => $line->updated_at,
        ];
    }
}
