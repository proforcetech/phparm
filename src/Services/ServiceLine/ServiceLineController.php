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
     * Admin view of another user's effective service lines + primary. Visibility
     * applies the same role-based fallback as {@see mine()} so admins see what
     * the target user would actually see in their sidebar — not the raw
     * membership rows. Use the explicit memberships endpoint
     * ({@see listMemberships()}) when you need the raw join-table view.
     *
     * @return array{service_lines: array<int, array<string, mixed>>, primary_id: ?int}
     */
    public function showForUser(User $admin, int $targetUserId): array
    {
        $this->gate->assert($admin, 'settings.service_lines.manage');

        $effective = $this->service->getEffectiveLinesForUser($targetUserId);

        return [
            'service_lines' => array_map([self::class, 'toArray'], $effective['lines']),
            'primary_id' => $effective['primary_id'],
        ];
    }

    /**
     * Add a single service-line membership for the given user. Idempotent:
     * a re-add is a no-op (driven by INSERT IGNORE in the repository).
     *
     * @param array<string, mixed> $body
     * @return array{service_lines: array<int, array<string, mixed>>, primary_id: ?int}
     */
    public function addMembership(User $admin, int $targetUserId, array $body): array
    {
        $this->gate->assert($admin, 'settings.service_lines.manage');

        $serviceLineId = (int) ($body['service_line_id'] ?? 0);
        if ($serviceLineId <= 0) {
            throw new InvalidArgumentException('service_line_id is required');
        }

        $line = $this->repository->findById($serviceLineId);
        if ($line === null) {
            throw new InvalidArgumentException("Service line {$serviceLineId} not found");
        }

        $this->repository->assignUser($targetUserId, $serviceLineId);

        return $this->showForUser($admin, $targetUserId);
    }

    /**
     * Remove a single service-line membership. If the removed line was the
     * user's primary, the repository clears it (see ServiceLineRepository::unassignUser).
     *
     * @return array{service_lines: array<int, array<string, mixed>>, primary_id: ?int}
     */
    public function removeMembership(User $admin, int $targetUserId, int $serviceLineId): array
    {
        $this->gate->assert($admin, 'settings.service_lines.manage');

        $this->repository->unassignUser($targetUserId, $serviceLineId);

        return $this->showForUser($admin, $targetUserId);
    }

    /**
     * Admin override for setting another user's primary service line. Falls
     * through to the same service-layer assignment used by self-service so
     * the primary-implies-membership invariant is preserved.
     *
     * @param array<string, mixed> $body
     * @return array{service_lines: array<int, array<string, mixed>>, primary_id: ?int}
     */
    public function setPrimaryForUser(User $admin, int $targetUserId, array $body): array
    {
        $this->gate->assert($admin, 'settings.service_lines.manage');

        $serviceLineId = (int) ($body['service_line_id'] ?? 0);
        if ($serviceLineId <= 0) {
            throw new InvalidArgumentException('service_line_id is required');
        }

        // Backfill membership so admins can always pick a line for any user
        // without manual two-step (assign then promote).
        $this->repository->assignUser($targetUserId, $serviceLineId);
        $this->service->setPrimary($targetUserId, $serviceLineId);

        return $this->showForUser($admin, $targetUserId);
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
