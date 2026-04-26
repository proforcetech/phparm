<?php

namespace App\Services\ServiceLine;

use App\Database\Connection;
use App\Models\ServiceLine;
use InvalidArgumentException;
use PDO;

/**
 * Thin business layer over {@see ServiceLineRepository}. Houses logic that
 * spans repository calls or that benefits from being unit-tested independently
 * of the controller (auth/role lookups, slug uniqueness checks).
 */
class ServiceLineService
{
    /**
     * Roles that always see every active service line regardless of explicit
     * user_service_lines membership. Admins must be able to switch into any
     * line without first being assigned, so the membership table is treated
     * as advisory for them (a hint about their primary, not a gate).
     */
    private const UNCONDITIONAL_ACCESS_ROLES = ['admin'];

    /**
     * Roles that fall back to "all active lines" only when they have zero
     * explicit memberships. Once any membership row exists, that becomes
     * the authoritative scope. Phase 12+ will widen managers via division
     * coverage; for now the union is just their explicit memberships.
     */
    private const FALLBACK_ACCESS_ROLES = ['manager'];

    public function __construct(
        private readonly ServiceLineRepository $repository,
        private readonly Connection $connection,
    ) {
    }

    /**
     * Resolve the service lines a given user can see in the UI.
     *
     * Visibility rules (per docs/woms-service-lines.md cross-cutting concerns
     * and config/auth.php role definitions):
     *
     *   - admin: always sees all active lines, regardless of membership rows.
     *   - manager: union of explicit memberships; if none, falls back to all
     *     active lines. (Division-derived coverage is Phase 12+ work.)
     *   - all other roles: only their explicit memberships, no fallback. A
     *     technician assigned to IT support shouldn't see Cleaning routes.
     *
     * @return array{lines: array<int, ServiceLine>, primary_id: ?int}
     */
    public function getEffectiveLinesForUser(int $userId): array
    {
        $role = $this->lookupRole($userId);
        $primary = $this->repository->getPrimaryForUser($userId);

        if ($role !== null && in_array($role, self::UNCONDITIONAL_ACCESS_ROLES, true)) {
            $lines = $this->repository->listActive();
        } else {
            $lines = $this->repository->listForUser($userId);

            if ($lines === []
                && $role !== null
                && in_array($role, self::FALLBACK_ACCESS_ROLES, true)
            ) {
                $lines = $this->repository->listActive();
            }
        }

        return [
            'lines' => $lines,
            'primary_id' => $primary?->id,
        ];
    }

    /**
     * Convenience wrapper around setPrimaryForUser that returns the new
     * primary row so the controller doesn't need a follow-up read.
     *
     * Admins have implicit access to every active line, so the repository's
     * membership precondition is bypassed for them by writing the primary
     * directly. All other roles must have an explicit user_service_lines row.
     */
    public function setPrimary(int $userId, int $serviceLineId): ServiceLine
    {
        $role = $this->lookupRole($userId);

        if ($role !== null && in_array($role, self::UNCONDITIONAL_ACCESS_ROLES, true)) {
            $line = $this->repository->findById($serviceLineId);
            if ($line === null || !$line->is_active) {
                throw new InvalidArgumentException(
                    "Service line {$serviceLineId} not found or inactive"
                );
            }

            // Backfill membership so the data invariant "primary implies
            // membership" holds for admins too. Idempotent INSERT IGNORE.
            $this->repository->assignUser($userId, $serviceLineId);

            $stmt = $this->connection->pdo()->prepare(
                'UPDATE users SET primary_service_line_id = :service_line_id WHERE id = :user_id'
            );
            $stmt->execute([
                'user_id' => $userId,
                'service_line_id' => $serviceLineId,
            ]);

            return $line;
        }

        $this->repository->setPrimaryForUser($userId, $serviceLineId);

        $line = $this->repository->findById($serviceLineId);
        if ($line === null) {
            // setPrimaryForUser would have thrown if the line FK was bogus,
            // but be defensive in case of a race with a concurrent delete.
            throw new InvalidArgumentException("Service line {$serviceLineId} not found");
        }

        return $line;
    }

    /**
     * Throws if a different row already uses the slug. $exceptId lets the
     * update flow exclude the row being edited from the uniqueness check.
     */
    public function assertSlugAvailable(string $slug, ?int $exceptId = null): void
    {
        $existing = $this->repository->findBySlug($slug);
        if ($existing === null) {
            return;
        }

        if ($exceptId !== null && $existing->id === $exceptId) {
            return;
        }

        throw new InvalidArgumentException("Service line slug '{$slug}' already exists");
    }

    private function lookupRole(int $userId): ?string
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT role FROM users WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $userId]);
        $role = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($role === false || !isset($role['role'])) {
            return null;
        }

        return (string) $role['role'];
    }
}
