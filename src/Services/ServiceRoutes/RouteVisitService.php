<?php

namespace App\Services\ServiceRoutes;

use App\Database\Connection;
use App\Models\RouteStop;
use App\Models\RouteVisit;
use App\Models\RouteVisitPhoto;
use App\Models\ServiceRoute;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Phase 15 / M7 of docs/woms-expansion-plan.md — visit lifecycle coordinator.
 *
 * The repositories handle reads and the raw INSERT/UPDATE primitives. State
 * transitions, the completion photo guard, the QR-scan validation, and the
 * audit writes go through this service so the route_visits row stays
 * consistent with route_visit_photos.photos_uploaded counter and the audit
 * ledger captures every move.
 *
 * Concurrency: transition() opens a transaction and re-loads the visit with
 * SELECT ... FOR UPDATE before validating the move. This prevents two
 * concurrent arrive/complete attempts on the same visit from both moving
 * the row (which would double-stamp arrived_at and confuse the verifier).
 *
 * Photo recording is in this service rather than its own — recordPhoto()
 * pairs the photos.create() insert with route_visits.photos_uploaded
 * increment inside one transaction so the denormalized counter never drifts.
 */
class RouteVisitService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ServiceRouteRepository $routes,
        private readonly RouteStopRepository $stops,
        private readonly RouteVisitRepository $visits,
        private readonly RouteVisitPhotoRepository $photos,
        private readonly AuditLogger $audit,
        private readonly ?PhotoVerifier $verifier = null,
    ) {
    }

    // ---- state machine ----------------------------------------------------

    /**
     * Move the visit to the target status. Validates against
     * RouteVisit::TRANSITIONS, opens a transaction, takes a row lock,
     * applies the timestamped state write, audits the move, and returns
     * the updated row.
     *
     * @param array{assigned_user_id?: int|null, workorder_id?: int|null,
     *              arrival_lat?: float|string|null, arrival_lng?: float|string|null,
     *              skip_reason?: string|null,
     *              verification_passed?: bool|int|null,
     *              verification_notes?: string|null} $extra
     */
    public function transition(int $visitId, string $toStatus, int $actorId, array $extra = []): RouteVisit
    {
        if (!in_array($toStatus, RouteVisit::ALLOWED_STATUSES, true)) {
            throw new InvalidArgumentException("Invalid target status '{$toStatus}'");
        }

        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            $visit = $this->visits->findByIdForUpdate($visitId);
            if ($visit === null) {
                throw new InvalidArgumentException("route_visit {$visitId} not found");
            }
            if ($visit->status === $toStatus) {
                if ($startedTx) {
                    $pdo->commit();
                }
                return $visit;
            }
            if (!$visit->canTransitionTo($toStatus)) {
                throw new InvalidArgumentException(
                    "Cannot move route_visit {$visitId} from '{$visit->status}' to '{$toStatus}'"
                );
            }

            // Completion guard: enforce minimum photo count before the row
            // moves into a terminal state we can't unwind.
            if ($toStatus === RouteVisit::STATUS_COMPLETED) {
                $this->assertPhotoRequirementMet($visit);
            }
            if ($toStatus === RouteVisit::STATUS_SKIPPED) {
                $reason = $extra['skip_reason'] ?? null;
                if ($reason === null || trim((string) $reason) === '') {
                    throw new InvalidArgumentException('skip_reason is required when skipping a visit');
                }
            }

            $writes = $this->normalizeExtra($extra);
            $updated = $this->visits->applyTransition($visitId, $toStatus, $writes);

            if ($startedTx) {
                $pdo->commit();
            }

            $this->audit->log(new AuditEntry(
                'route_visit.transitioned',
                'route_visit',
                (string) $visitId,
                $actorId,
                [
                    'from' => $visit->status,
                    'to' => $toStatus,
                    'extra' => $writes,
                ]
            ));

            // S8 verification runs post-commit on completion so the photo
            // set is finalized. Verdict is advisory — failures must not
            // un-complete the visit, so swallow exceptions and audit them.
            if ($toStatus === RouteVisit::STATUS_COMPLETED && $this->verifier !== null) {
                try {
                    $verdict = $this->verifier->evaluateVisit($visitId);
                    $updated = $this->visits->findById($visitId) ?? $updated;
                    $this->audit->log(new AuditEntry(
                        'route_visit.verified',
                        'route_visit',
                        (string) $visitId,
                        $actorId,
                        [
                            'passed' => $verdict['passed'],
                            'notes' => $verdict['notes'],
                            'flag_count' => count($verdict['flags']),
                        ]
                    ));
                } catch (Throwable $verifyErr) {
                    $this->audit->log(new AuditEntry(
                        'route_visit.verification_failed',
                        'route_visit',
                        (string) $visitId,
                        $actorId,
                        ['error' => $verifyErr->getMessage()]
                    ));
                }
            }

            return $updated;
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /** Convenience: planned/en_route → en_route. Idempotent. */
    public function markEnRoute(int $visitId, int $actorId): RouteVisit
    {
        return $this->transition($visitId, RouteVisit::STATUS_EN_ROUTE, $actorId);
    }

    /**
     * Convenience: → arrived. Optionally records the device's GPS at the
     * moment of arrival so the S8 verifier can geo-fence the photos.
     */
    public function arrive(
        int $visitId,
        int $actorId,
        ?float $lat = null,
        ?float $lng = null
    ): RouteVisit {
        $extra = [];
        if ($lat !== null) {
            $extra['arrival_lat'] = $lat;
        }
        if ($lng !== null) {
            $extra['arrival_lng'] = $lng;
        }
        return $this->transition($visitId, RouteVisit::STATUS_ARRIVED, $actorId, $extra);
    }

    /** Convenience: arrived → completed (with photo guard). */
    public function complete(int $visitId, int $actorId): RouteVisit
    {
        return $this->transition($visitId, RouteVisit::STATUS_COMPLETED, $actorId);
    }

    /** Convenience: planned/en_route/arrived → skipped. Reason required. */
    public function skip(int $visitId, int $actorId, string $reason): RouteVisit
    {
        return $this->transition($visitId, RouteVisit::STATUS_SKIPPED, $actorId, [
            'skip_reason' => $reason,
        ]);
    }

    /**
     * Direct assignment write that bypasses the state machine. Used by the
     * dispatcher view to re-assign a planned visit. Refuses to retarget a
     * visit that's already in flight (en_route/arrived) so we don't yank a
     * stop out from under the on-site tech.
     */
    public function reassign(int $visitId, ?int $assignedUserId, int $actorId): RouteVisit
    {
        $visit = $this->visits->findById($visitId);
        if ($visit === null) {
            throw new InvalidArgumentException("route_visit {$visitId} not found");
        }
        if (!in_array($visit->status, [RouteVisit::STATUS_PLANNED], true)) {
            throw new InvalidArgumentException(
                "Cannot reassign route_visit {$visitId} in status '{$visit->status}'"
            );
        }
        $updated = $this->visits->reassign($visitId, $assignedUserId);
        $this->audit->log(new AuditEntry(
            'route_visit.reassigned',
            'route_visit',
            (string) $visitId,
            $actorId,
            [
                'from_user_id' => $visit->assigned_user_id,
                'to_user_id' => $assignedUserId,
            ]
        ));
        return $updated;
    }

    // ---- QR scan flow ------------------------------------------------------

    /**
     * Mobile-side scan endpoint. Resolves a QR token to its visit + the
     * stop it belongs to so the tech can confirm "yes I'm at the right
     * place" before arriving.
     *
     * @return array{visit: RouteVisit, stop: RouteStop, route: ServiceRoute}|null
     */
    public function scanQrToken(string $token): ?array
    {
        $visit = $this->visits->findByQrToken($token);
        if ($visit === null) {
            return null;
        }
        $stop = $this->stops->findById($visit->route_stop_id);
        $route = $this->routes->findById($visit->service_route_id);
        if ($stop === null || $route === null) {
            // Should not happen — FK CASCADE on route_visits would have
            // removed the visit. Treat as not found.
            return null;
        }
        return ['visit' => $visit, 'stop' => $stop, 'route' => $route];
    }

    // ---- photo recording --------------------------------------------------

    /**
     * Insert a photo and bump the visit's denormalized counter inside the
     * same transaction. Refuses photos for terminal-state visits — once a
     * visit is completed/skipped/missed we don't accept new evidence
     * because the audit trail would no longer match what the tech saw.
     *
     * @param array<string, mixed> $photoData
     */
    public function recordPhoto(int $visitId, int $uploaderUserId, array $photoData): RouteVisitPhoto
    {
        $visit = $this->visits->findById($visitId);
        if ($visit === null) {
            throw new InvalidArgumentException("route_visit {$visitId} not found");
        }
        if ($visit->isTerminal()) {
            throw new InvalidArgumentException(
                "Cannot upload photo to route_visit {$visitId} in terminal status '{$visit->status}'"
            );
        }

        $payload = $photoData;
        $payload['route_visit_id'] = $visitId;
        $payload['uploaded_by_user_id'] = $uploaderUserId;

        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            $photo = $this->photos->create($payload);
            $this->visits->incrementPhotoCounter($visitId, 1);
            if ($startedTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'route_visit_photo.recorded',
            'route_visit',
            (string) $visitId,
            $uploaderUserId,
            [
                'photo_id' => $photo->id,
                'file_path' => $photo->file_path,
                'has_perceptual_hash' => $photo->perceptual_hash !== null,
            ]
        ));
        return $photo;
    }

    /**
     * Delete a photo and decrement the visit's counter. Used when a tech
     * uploads the wrong shot before completion. Refuses on terminal visits
     * for the same reason recordPhoto() does.
     */
    public function deletePhoto(int $photoId, int $actorId): void
    {
        $photo = $this->photos->findById($photoId);
        if ($photo === null) {
            return;
        }
        $visit = $this->visits->findById($photo->route_visit_id);
        if ($visit !== null && $visit->isTerminal()) {
            throw new InvalidArgumentException(
                "Cannot delete photo from route_visit {$visit->id} in terminal status '{$visit->status}'"
            );
        }

        $pdo = $this->connection->pdo();
        $startedTx = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTx = true;
        }

        try {
            $this->photos->delete($photoId);
            $this->visits->incrementPhotoCounter($photo->route_visit_id, -1);
            if ($startedTx) {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($startedTx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        $this->audit->log(new AuditEntry(
            'route_visit_photo.deleted',
            'route_visit',
            (string) $photo->route_visit_id,
            $actorId,
            ['photo_id' => $photoId, 'file_path' => $photo->file_path]
        ));
    }

    // ---- cron sweepers ----------------------------------------------------

    /**
     * Walk visits whose scheduled window has expired but are still open
     * (planned/en_route/arrived) and mark them missed. Returns the number
     * of visits updated. Each transition runs through transition() so the
     * audit ledger captures the auto-miss.
     */
    public function sweepOverdueOpen(?DateTimeImmutable $now = null, int $batchSize = 200): int
    {
        $now ??= new DateTimeImmutable('now');
        $stale = $this->visits->listOverdueOpen($now->format('Y-m-d H:i:s'), $batchSize);

        $marked = 0;
        foreach ($stale as $visit) {
            // arrived → missed isn't a legal transition (a tech who arrived
            // should explicitly skip with a reason). Only auto-miss the
            // planned/en_route slice.
            if (!in_array($visit->status, [
                RouteVisit::STATUS_PLANNED,
                RouteVisit::STATUS_EN_ROUTE,
            ], true)) {
                continue;
            }
            try {
                $this->transition($visit->id, RouteVisit::STATUS_MISSED, 0);
                $marked++;
            } catch (Throwable $e) {
                // Don't let one bad row stop the sweep — log and continue.
                $this->audit->log(new AuditEntry(
                    'route_visit.sweep_failed',
                    'route_visit',
                    (string) $visit->id,
                    0,
                    ['error' => $e->getMessage()]
                ));
            }
        }
        return $marked;
    }

    // ---- helpers ----------------------------------------------------------

    private function assertPhotoRequirementMet(RouteVisit $visit): void
    {
        $stop = $this->stops->findById($visit->route_stop_id);
        $route = $this->routes->findById($visit->service_route_id);
        if ($stop === null || $route === null) {
            throw new RuntimeException(
                "route_visit {$visit->id} missing parent stop or route"
            );
        }
        $required = $stop->effectiveRequiredPhotos($route);
        if ($required > 0 && $visit->photos_uploaded < $required) {
            throw new InvalidArgumentException(
                "Cannot complete route_visit {$visit->id}: requires {$required} "
                . "photo(s), have {$visit->photos_uploaded}"
            );
        }
    }

    /**
     * Normalize the extra-write payload before it reaches the repository so
     * the SQL layer only ever sees scalars + nulls.
     *
     * @param array<string, mixed> $extra
     * @return array<string, mixed>
     */
    private function normalizeExtra(array $extra): array
    {
        $out = [];
        foreach (['assigned_user_id', 'workorder_id'] as $key) {
            if (array_key_exists($key, $extra)) {
                $out[$key] = $extra[$key] === null || $extra[$key] === ''
                    ? null
                    : (int) $extra[$key];
            }
        }
        foreach (['arrival_lat', 'arrival_lng'] as $key) {
            if (array_key_exists($key, $extra)) {
                $out[$key] = $extra[$key] === null || $extra[$key] === ''
                    ? null
                    : (string) $extra[$key];
            }
        }
        if (array_key_exists('skip_reason', $extra)) {
            $out['skip_reason'] = $extra['skip_reason'] === null
                ? null
                : (string) $extra['skip_reason'];
        }
        if (array_key_exists('verification_passed', $extra)) {
            $out['verification_passed'] = $extra['verification_passed'] === null
                ? null
                : ($extra['verification_passed'] ? 1 : 0);
        }
        if (array_key_exists('verification_notes', $extra)) {
            $out['verification_notes'] = $extra['verification_notes'] === null
                ? null
                : (string) $extra['verification_notes'];
        }
        return $out;
    }
}
