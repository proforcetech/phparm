<?php

namespace App\Services\ServiceRoutes;

use App\Models\RouteStop;
use App\Models\RouteVisit;
use App\Models\RouteVisitPhoto;
use App\Models\ServiceRoute;
use App\Models\User;
use App\Support\Auth\AccessGate;
use DateTimeImmutable;
use InvalidArgumentException;
use RuntimeException;

/**
 * HTTP controller for /api/service-routes — Phase 15 / M7 of
 * docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   service_routes.view    — read routes, stops, visits, photos
 *   service_routes.manage  — create/edit/delete routes & stops, manually
 *                            trigger the generator, reassign visits
 *   service_routes.execute — mobile-side: scan QR, transition visits,
 *                            record/delete photos. Granted to techs without
 *                            granting them route-definition rights.
 *
 * All write paths route through RouteVisitService / repositories so the
 * state machine, row locks, and audit trail stay in sync.
 */
class ServiceRouteController
{
    public function __construct(
        private readonly ServiceRouteRepository $routes,
        private readonly RouteStopRepository $stops,
        private readonly RouteVisitRepository $visits,
        private readonly RouteVisitPhotoRepository $photos,
        private readonly RouteVisitService $visitService,
        private readonly RouteVisitGenerator $generator,
        private readonly AccessGate $gate,
        private readonly ?PhotoVerifier $verifier = null,
    ) {
    }

    // ---- routes: list / show / write -------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{service_routes: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function index(User $user, array $filters = [], int $page = 1, int $perPage = 50): array
    {
        $this->gate->assert($user, 'service_routes.view');

        $page = max(1, $page);
        $perPage = min(200, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->routes->list($filters, $perPage, $offset);
        $total = $this->routes->count($filters);

        return [
            'service_routes' => array_map([self::class, 'routeToArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * Show a route with its stops attached. Visit history lives behind a
     * separate endpoint to keep this payload bounded.
     *
     * @return array<string, mixed>
     */
    public function show(User $user, int $id): array
    {
        $this->gate->assert($user, 'service_routes.view');

        $route = $this->routes->findById($id);
        if ($route === null) {
            throw new InvalidArgumentException("service_route {$id} not found");
        }

        $payload = self::routeToArray($route);
        $payload['stops'] = array_map(
            [self::class, 'stopToArray'],
            $this->stops->listForRoute($id)
        );
        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function store(User $user, array $body): array
    {
        $this->gate->assert($user, 'service_routes.manage');

        $route = $this->routes->create($body);
        return self::routeToArray($route);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function update(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'service_routes.manage');

        $route = $this->routes->update($id, $body);
        return self::routeToArray($route);
    }

    public function destroy(User $user, int $id): void
    {
        $this->gate->assert($user, 'service_routes.manage');

        $this->routes->delete($id);
    }

    /**
     * Manually run the visit generator for a single route. Useful from the
     * admin UI when the user just edited the recurrence rule and wants to
     * see the planned visits without waiting for the next cron tick.
     *
     * @return array{route: array<string, mixed>, visits_created: int}
     */
    public function runGenerator(User $user, int $id): array
    {
        $this->gate->assert($user, 'service_routes.manage');

        $route = $this->routes->findById($id);
        if ($route === null) {
            throw new InvalidArgumentException("service_route {$id} not found");
        }

        $created = $this->generator->generateForRoute($route, new DateTimeImmutable('now'));
        $refreshed = $this->routes->findById($id);
        return [
            'route' => self::routeToArray($refreshed ?? $route),
            'visits_created' => count($created),
        ];
    }

    // ---- stops -----------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listStops(User $user, int $routeId): array
    {
        $this->gate->assert($user, 'service_routes.view');

        if ($this->routes->findById($routeId) === null) {
            throw new InvalidArgumentException("service_route {$routeId} not found");
        }
        return array_map(
            [self::class, 'stopToArray'],
            $this->stops->listForRoute($routeId)
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function createStop(User $user, int $routeId, array $body): array
    {
        $this->gate->assert($user, 'service_routes.manage');

        if ($this->routes->findById($routeId) === null) {
            throw new InvalidArgumentException("service_route {$routeId} not found");
        }
        $body['service_route_id'] = $routeId;
        $stop = $this->stops->create($body);
        return self::stopToArray($stop);
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function updateStop(User $user, int $stopId, array $body): array
    {
        $this->gate->assert($user, 'service_routes.manage');

        $stop = $this->stops->update($stopId, $body);
        return self::stopToArray($stop);
    }

    public function deleteStop(User $user, int $stopId): void
    {
        $this->gate->assert($user, 'service_routes.manage');

        $this->stops->delete($stopId);
    }

    /**
     * Bulk reorder. Body shape: `{ "order": [{"id": 12, "sequence": 1}, ...] }`.
     *
     * @param array<string, mixed> $body
     * @return array<int, array<string, mixed>>
     */
    public function reorderStops(User $user, int $routeId, array $body): array
    {
        $this->gate->assert($user, 'service_routes.manage');

        if ($this->routes->findById($routeId) === null) {
            throw new InvalidArgumentException("service_route {$routeId} not found");
        }
        $order = $body['order'] ?? [];
        if (!is_array($order) || $order === []) {
            throw new InvalidArgumentException('order array is required');
        }
        $sequenceById = [];
        foreach ($order as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $stopId = (int) ($entry['id'] ?? 0);
            $seq = (int) ($entry['sequence'] ?? 0);
            if ($stopId > 0 && $seq > 0) {
                $sequenceById[$stopId] = $seq;
            }
        }
        if ($sequenceById === []) {
            throw new InvalidArgumentException('order entries must have id + sequence');
        }
        $this->stops->reorder($routeId, $sequenceById);

        return array_map(
            [self::class, 'stopToArray'],
            $this->stops->listForRoute($routeId)
        );
    }

    // ---- visits ----------------------------------------------------------

    /**
     * @param array<string, mixed> $filters
     * @return array{route_visits: array<int, array<string, mixed>>, total: int, limit: int, offset: int}
     */
    public function listVisits(User $user, array $filters = [], int $page = 1, int $perPage = 100): array
    {
        $this->gate->assert($user, 'service_routes.view');

        $page = max(1, $page);
        $perPage = min(500, max(1, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->visits->list($filters, $perPage, $offset);
        $total = $this->visits->count($filters);

        return [
            'route_visits' => array_map([self::class, 'visitToArray'], $rows),
            'total' => $total,
            'limit' => $perPage,
            'offset' => $offset,
        ];
    }

    /**
     * Visit detail with photos attached.
     *
     * @return array<string, mixed>
     */
    public function showVisit(User $user, int $id): array
    {
        $this->gate->assert($user, 'service_routes.view');

        $visit = $this->visits->findById($id);
        if ($visit === null) {
            throw new InvalidArgumentException("route_visit {$id} not found");
        }

        $payload = self::visitToArray($visit);
        $payload['allowed_transitions'] = $visit->allowedTransitions();
        $payload['photos'] = array_map(
            [self::class, 'photoToArray'],
            $this->photos->listForVisit($id)
        );
        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function transitionVisit(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'service_routes.execute');

        $toStatus = trim((string) ($body['status'] ?? ''));
        if ($toStatus === '') {
            throw new InvalidArgumentException('status is required');
        }

        $extra = [];
        foreach (['skip_reason', 'verification_passed', 'verification_notes',
                  'workorder_id', 'arrival_lat', 'arrival_lng'] as $key) {
            if (array_key_exists($key, $body)) {
                $extra[$key] = $body[$key];
            }
        }

        $visit = $this->visitService->transition($id, $toStatus, (int) $user->id, $extra);
        $payload = self::visitToArray($visit);
        $payload['allowed_transitions'] = $visit->allowedTransitions();
        return $payload;
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function reassignVisit(User $user, int $id, array $body): array
    {
        $this->gate->assert($user, 'service_routes.manage');

        $assigned = isset($body['assigned_user_id']) && $body['assigned_user_id'] !== ''
            ? (int) $body['assigned_user_id']
            : null;
        $visit = $this->visitService->reassign($id, $assigned, (int) $user->id);
        return self::visitToArray($visit);
    }

    // ---- QR scan flow ----------------------------------------------------

    /**
     * Token-based scan endpoint for the mobile app. Returns the visit +
     * stop + route bundle so the tech can confirm before arriving.
     *
     * @return array<string, mixed>|null
     */
    public function scan(User $user, string $token): ?array
    {
        $this->gate->assert($user, 'service_routes.execute');

        $resolved = $this->visitService->scanQrToken($token);
        if ($resolved === null) {
            return null;
        }
        $visitArr = self::visitToArray($resolved['visit']);
        $visitArr['allowed_transitions'] = $resolved['visit']->allowedTransitions();
        return [
            'visit' => $visitArr,
            'stop' => self::stopToArray($resolved['stop']),
            'route' => self::routeToArray($resolved['route']),
        ];
    }

    // ---- photos ----------------------------------------------------------

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listPhotos(User $user, int $visitId): array
    {
        $this->gate->assert($user, 'service_routes.view');

        if ($this->visits->findById($visitId) === null) {
            throw new InvalidArgumentException("route_visit {$visitId} not found");
        }
        return array_map(
            [self::class, 'photoToArray'],
            $this->photos->listForVisit($visitId)
        );
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function recordPhoto(User $user, int $visitId, array $body): array
    {
        $this->gate->assert($user, 'service_routes.execute');

        $photo = $this->visitService->recordPhoto($visitId, (int) $user->id, $body);
        return self::photoToArray($photo);
    }

    /**
     * Multipart upload variant — used by the mobile/PWA flow where the tech
     * captures a photo directly from the device camera. The file is moved
     * into `public/uploads/route-visits/` and the metadata (path, mime, size)
     * is then handed to recordPhoto() in the same way as the JSON variant.
     *
     * @param array{tmp_name?: string, name?: string, size?: int, error?: int, type?: string} $file
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function uploadPhoto(User $user, int $visitId, array $file, array $body = []): array
    {
        $this->gate->assert($user, 'service_routes.execute');

        if (empty($file['tmp_name']) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('media file is required');
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            throw new InvalidArgumentException('Invalid media upload');
        }

        $mime = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
        if (!in_array($mime, self::ALLOWED_PHOTO_MIME_TYPES, true)) {
            throw new InvalidArgumentException("Unsupported media type '{$mime}'");
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > self::MAX_PHOTO_BYTES) {
            throw new InvalidArgumentException('Photo file size out of range');
        }

        $extension = self::extensionForMime($mime, (string) ($file['name'] ?? ''));
        $uploadDir = dirname(__DIR__, 3) . '/public/uploads/route-visits';
        if (!is_dir($uploadDir) && !mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            throw new RuntimeException('Unable to prepare upload directory');
        }
        $filename = sprintf('rv_%d_%s.%s', $visitId, bin2hex(random_bytes(8)), $extension);
        $destination = $uploadDir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $destination)) {
            throw new RuntimeException('Unable to store media');
        }

        $relativePath = '/uploads/route-visits/' . $filename;
        $payload = [
            'file_path' => $relativePath,
            'file_mime' => $mime,
            'file_size_bytes' => $size,
        ];

        // S8: extract EXIF + perceptual hash from the just-stored file. The
        // verifier returns nulls for fields it can't read, so client-supplied
        // values (and only those) win on conflict.
        if ($this->verifier !== null) {
            $extracted = $this->verifier->extractFromFile($destination, $mime);
            foreach ($extracted as $key => $value) {
                if ($value !== null) {
                    $payload[$key] = $value;
                }
            }
        }
        // Optional fields the mobile app may send alongside the file
        // (override anything the server-side extractor produced).
        foreach (['caption', 'exif_taken_at', 'exif_lat', 'exif_lng', 'perceptual_hash'] as $key) {
            if (isset($body[$key]) && $body[$key] !== '') {
                $payload[$key] = $body[$key];
            }
        }

        $photo = $this->visitService->recordPhoto($visitId, (int) $user->id, $payload);
        return self::photoToArray($photo);
    }

    public function deletePhoto(User $user, int $photoId): void
    {
        $this->gate->assert($user, 'service_routes.execute');

        $this->visitService->deletePhoto($photoId, (int) $user->id);
    }

    private const ALLOWED_PHOTO_MIME_TYPES = [
        'image/jpeg',
        'image/png',
        'image/heic',
        'image/heif',
        'image/webp',
    ];

    private const MAX_PHOTO_BYTES = 16 * 1024 * 1024;

    private static function extensionForMime(string $mime, string $originalName): string
    {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/heic' => 'heic',
            'image/heif' => 'heif',
            'image/webp' => 'webp',
        ];
        if (isset($map[$mime])) {
            return $map[$mime];
        }
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        return preg_match('/^[a-z0-9]{1,8}$/', $ext) ? $ext : 'bin';
    }

    // ---- serializers -----------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    public static function routeToArray(ServiceRoute $row): array
    {
        return [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'service_line_id' => $row->service_line_id,
            'default_assigned_user_id' => $row->default_assigned_user_id,
            'name' => $row->name,
            'description' => $row->description,
            'status' => $row->status,
            'recurrence_type' => $row->recurrence_type,
            'recurrence_interval' => $row->recurrence_interval,
            'recurrence_days_of_week' => $row->recurrence_days_of_week,
            'days_of_week' => $row->daysOfWeek(),
            'recurrence_day_of_month' => $row->recurrence_day_of_month,
            'recurrence_time_of_day' => $row->recurrence_time_of_day,
            'start_date' => $row->start_date,
            'end_date' => $row->end_date,
            'generation_horizon_days' => $row->generation_horizon_days,
            'last_generated_through' => $row->last_generated_through,
            'photo_verification_required' => (bool) $row->photo_verification_required,
            'min_photos_per_visit' => $row->min_photos_per_visit,
            'estimated_visit_minutes' => $row->estimated_visit_minutes,
            'notes' => $row->notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function stopToArray(RouteStop $row): array
    {
        return [
            'id' => $row->id,
            'service_route_id' => $row->service_route_id,
            'sequence' => $row->sequence,
            'site_id' => $row->site_id,
            'site_asset_id' => $row->site_asset_id,
            'stop_name' => $row->stop_name,
            'estimated_minutes' => $row->estimated_minutes,
            'checklist_template_id' => $row->checklist_template_id,
            'required_photos' => $row->required_photos,
            'notes' => $row->notes,
            'is_active' => (bool) $row->is_active,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function visitToArray(RouteVisit $row): array
    {
        return [
            'id' => $row->id,
            'service_route_id' => $row->service_route_id,
            'route_stop_id' => $row->route_stop_id,
            'workorder_id' => $row->workorder_id,
            'assigned_user_id' => $row->assigned_user_id,
            'scheduled_for' => $row->scheduled_for,
            'scheduled_window_minutes' => $row->scheduled_window_minutes,
            'status' => $row->status,
            'is_terminal' => $row->isTerminal(),
            'qr_token' => $row->qr_token,
            'en_route_at' => $row->en_route_at,
            'arrived_at' => $row->arrived_at,
            'arrival_lat' => $row->arrival_lat,
            'arrival_lng' => $row->arrival_lng,
            'completed_at' => $row->completed_at,
            'skipped_at' => $row->skipped_at,
            'skip_reason' => $row->skip_reason,
            'missed_at' => $row->missed_at,
            'photos_uploaded' => $row->photos_uploaded,
            'verification_passed' => $row->verification_passed,
            'verification_notes' => $row->verification_notes,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function photoToArray(RouteVisitPhoto $row): array
    {
        return [
            'id' => $row->id,
            'route_visit_id' => $row->route_visit_id,
            'uploaded_by_user_id' => $row->uploaded_by_user_id,
            'file_path' => $row->file_path,
            'file_mime' => $row->file_mime,
            'file_size_bytes' => $row->file_size_bytes,
            'exif_taken_at' => $row->exif_taken_at,
            'exif_lat' => $row->exif_lat,
            'exif_lng' => $row->exif_lng,
            'perceptual_hash' => $row->perceptual_hash,
            'caption' => $row->caption,
            'uploaded_at' => $row->uploaded_at,
            'created_at' => $row->created_at,
            'updated_at' => $row->updated_at,
        ];
    }
}
