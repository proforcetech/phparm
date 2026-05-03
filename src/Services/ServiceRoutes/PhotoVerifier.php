<?php

namespace App\Services\ServiceRoutes;

use App\Database\Connection;
use App\Models\RouteVisit;
use App\Models\RouteVisitPhoto;
use GdImage;
use PDO;

/**
 * Phase 15 / S8 of docs/woms-expansion-plan.md — verification heuristics for
 * recurring service-route photos.
 *
 * Three heuristics, scored independently per photo and aggregated per visit:
 *
 *   1. EXIF time-window — the photo's capture timestamp must land within
 *      ±N minutes of the visit's arrived_at. Catches "I took this yesterday
 *      and uploaded it this morning."
 *
 *   2. EXIF geo-fence — the photo's GPS coords must land within R metres
 *      of the stop's site coords (haversine). Catches "I took this in the
 *      truck before driving to site."
 *
 *   3. Perceptual-hash duplicate — the photo's dHash must differ from any
 *      recent photo for the same customer by more than D bits (Hamming).
 *      Catches the classic "tech reuses last week's clean shot."
 *
 * Heuristic results are advisory: verification_passed = 1 / 0 with a
 * human-readable verification_notes. Nothing here blocks the transition;
 * the workflow is "let the tech complete, then flag for the back office."
 *
 * Extraction (extractFromFile) is the upload-time hook used by the
 * controller. evaluateVisit() is the completion-time hook used by
 * RouteVisitService when moving a visit into 'completed'.
 */
class PhotoVerifier
{
    /**
     * Default thresholds. Override per-instance via the $config constructor
     * arg — e.g. a stricter geo radius for high-value sites.
     *
     * @var array<string, int|float>
     */
    private const DEFAULTS = [
        'time_window_minutes' => 60,
        'geo_radius_meters' => 250.0,
        'dup_max_hamming_distance' => 6,    // <= this is "duplicate"
        'dup_lookback_days' => 30,
        'dup_candidate_limit' => 200,
    ];

    /**
     * Hex digit → bit count. Used by hammingHex() to count differing bits
     * a nibble at a time without falling back to gmp_*().
     */
    private const HAMMING_NIBBLE = [
        0x0 => 0, 0x1 => 1, 0x2 => 1, 0x3 => 2,
        0x4 => 1, 0x5 => 2, 0x6 => 2, 0x7 => 3,
        0x8 => 1, 0x9 => 2, 0xA => 2, 0xB => 3,
        0xC => 2, 0xD => 3, 0xE => 3, 0xF => 4,
    ];

    /** @var array<string, int|float> */
    private array $config;

    /**
     * @param array<string, int|float> $config overrides for the DEFAULTS keys
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly RouteVisitRepository $visits,
        private readonly RouteVisitPhotoRepository $photos,
        private readonly RouteStopRepository $stops,
        private readonly ServiceRouteRepository $routes,
        array $config = [],
    ) {
        $this->config = array_merge(self::DEFAULTS, $config);
    }

    // ---- extraction (upload-time hook) -----------------------------------

    /**
     * Pull EXIF datetime + GPS + perceptual hash from a freshly-stored
     * image. Returns an array suitable for spreading directly into the
     * route_visit_photos insert payload. Any field may be null when the
     * source image lacks the data or the runtime can't read it (e.g. HEIC
     * without Imagick).
     *
     * @return array{exif_taken_at: ?string, exif_lat: ?float, exif_lng: ?float,
     *               perceptual_hash: ?string}
     */
    public function extractFromFile(string $absolutePath, string $mimeType): array
    {
        $exif = $this->extractExif($absolutePath, $mimeType);
        return [
            'exif_taken_at' => $exif['exif_taken_at'],
            'exif_lat' => $exif['exif_lat'],
            'exif_lng' => $exif['exif_lng'],
            'perceptual_hash' => $this->computeDHash($absolutePath, $mimeType),
        ];
    }

    // ---- evaluation (completion-time hook) -------------------------------

    /**
     * Score every photo on the visit against the three heuristics and
     * return an aggregate verdict + per-photo flags.
     *
     * Side effect: writes verification_passed + verification_notes onto
     * the visit row. Caller (RouteVisitService) decides whether to gate
     * the transition on the verdict (currently advisory only).
     *
     * @return array{passed: bool, notes: string,
     *               flags: array<int, array{photo_id: int, reason: string,
     *                                        detail: string}>}
     */
    public function evaluateVisit(int $visitId): array
    {
        $visit = $this->visits->findById($visitId);
        if ($visit === null) {
            return [
                'passed' => false,
                'notes' => 'Visit not found.',
                'flags' => [],
            ];
        }
        $stop = $this->stops->findById((int) $visit->route_stop_id);
        $route = $this->routes->findById((int) $visit->service_route_id);
        $photos = $this->photos->listForVisit($visitId);

        $sitePoint = $stop !== null ? $this->loadSiteCoords((int) $stop->site_id) : null;
        $arrivedEpoch = $visit->arrived_at !== null ? strtotime((string) $visit->arrived_at) : null;
        $arrivedEpoch = $arrivedEpoch === false ? null : $arrivedEpoch;

        $flags = [];
        if ($photos === []) {
            // No photos to check — leave verification_passed null (= "not
            // yet evaluated") so the back office can spot it.
            $this->visits->setVerification($visitId, null, 'No photos uploaded for this visit.');
            return [
                'passed' => false,
                'notes' => 'No photos uploaded for this visit.',
                'flags' => [],
            ];
        }

        $candidateHashes = null; // lazy: load only when at least one photo has a hash
        foreach ($photos as $photo) {
            $this->checkTimeWindow($photo, $arrivedEpoch, $flags);
            $this->checkGeoFence($photo, $sitePoint, $flags);

            if ($photo->perceptual_hash !== null && $photo->perceptual_hash !== '') {
                if ($candidateHashes === null) {
                    $candidateHashes = $route !== null
                        ? $this->loadDuplicateCandidates(
                            (int) $route->customer_id,
                            $visitId
                        )
                        : [];
                }
                $this->checkDuplicate($photo, $candidateHashes, $flags);
            }
        }

        $passed = $flags === [];
        $notes = $this->summarize($passed, count($photos), $flags);
        $this->visits->setVerification($visitId, $passed, $notes);

        return ['passed' => $passed, 'notes' => $notes, 'flags' => $flags];
    }

    // ---- per-heuristic checks --------------------------------------------

    /**
     * @param array<int, array{photo_id: int, reason: string, detail: string}> $flags
     */
    private function checkTimeWindow(RouteVisitPhoto $photo, ?int $arrivedEpoch, array &$flags): void
    {
        if ($arrivedEpoch === null || $photo->exif_taken_at === null) {
            return;
        }
        $exifEpoch = strtotime((string) $photo->exif_taken_at);
        if ($exifEpoch === false) {
            return;
        }
        $diffSeconds = abs($exifEpoch - $arrivedEpoch);
        $windowSeconds = ((int) $this->config['time_window_minutes']) * 60;
        if ($diffSeconds > $windowSeconds) {
            $diffMinutes = (int) round($diffSeconds / 60);
            $flags[] = [
                'photo_id' => (int) $photo->id,
                'reason' => 'time_window',
                'detail' => sprintf(
                    'EXIF time differs from arrival by %d min (window %d min).',
                    $diffMinutes,
                    $this->config['time_window_minutes']
                ),
            ];
        }
    }

    /**
     * @param array{lat: float, lng: float}|null $sitePoint
     * @param array<int, array{photo_id: int, reason: string, detail: string}> $flags
     */
    private function checkGeoFence(RouteVisitPhoto $photo, ?array $sitePoint, array &$flags): void
    {
        if ($sitePoint === null
            || $photo->exif_lat === null
            || $photo->exif_lng === null
        ) {
            return;
        }
        $distance = self::haversineMeters(
            (float) $photo->exif_lat,
            (float) $photo->exif_lng,
            $sitePoint['lat'],
            $sitePoint['lng']
        );
        $radius = (float) $this->config['geo_radius_meters'];
        if ($distance > $radius) {
            $flags[] = [
                'photo_id' => (int) $photo->id,
                'reason' => 'geo_fence',
                'detail' => sprintf(
                    'Photo is %d m from site (radius %d m).',
                    (int) round($distance),
                    (int) round($radius)
                ),
            ];
        }
    }

    /**
     * @param array<int, array{id: int, route_visit_id: int, perceptual_hash: string, uploaded_at: string}> $candidates
     * @param array<int, array{photo_id: int, reason: string, detail: string}> $flags
     */
    private function checkDuplicate(RouteVisitPhoto $photo, array $candidates, array &$flags): void
    {
        $hash = (string) $photo->perceptual_hash;
        $maxHamming = (int) $this->config['dup_max_hamming_distance'];

        $bestMatch = null;
        $bestDist = PHP_INT_MAX;
        foreach ($candidates as $cand) {
            $dist = self::hammingHex($hash, $cand['perceptual_hash']);
            if ($dist >= 0 && $dist < $bestDist) {
                $bestDist = $dist;
                $bestMatch = $cand;
            }
        }
        if ($bestMatch !== null && $bestDist <= $maxHamming) {
            $flags[] = [
                'photo_id' => (int) $photo->id,
                'reason' => 'duplicate',
                'detail' => sprintf(
                    'Looks like photo #%d from visit #%d (Hamming distance %d, threshold %d).',
                    (int) $bestMatch['id'],
                    (int) $bestMatch['route_visit_id'],
                    $bestDist,
                    $maxHamming
                ),
            ];
        }
    }

    /**
     * @param array<int, array{photo_id: int, reason: string, detail: string}> $flags
     */
    private function summarize(bool $passed, int $photoCount, array $flags): string
    {
        if ($passed) {
            return sprintf('Passed: %d photo(s) within window, on-site, and unique.', $photoCount);
        }
        $reasons = [];
        foreach ($flags as $flag) {
            $reasons[$flag['reason']] = ($reasons[$flag['reason']] ?? 0) + 1;
        }
        $parts = [];
        foreach ($reasons as $reason => $count) {
            $parts[] = sprintf('%s × %d', $reason, $count);
        }
        return sprintf(
            'Flagged %d issue(s) across %d photo(s): %s.',
            count($flags),
            $photoCount,
            implode(', ', $parts)
        );
    }

    // ---- duplicate-candidate loader --------------------------------------

    /**
     * Pull the recent perceptual hashes for the given customer so we can
     * compute Hamming distance in PHP (the existing
     * findRecentByPerceptualHash repo method is exact-match only and
     * misses near-duplicates).
     *
     * @return array<int, array{id: int, route_visit_id: int, perceptual_hash: string, uploaded_at: string}>
     */
    private function loadDuplicateCandidates(int $customerId, int $excludeVisitId): array
    {
        $sinceDate = date(
            'Y-m-d H:i:s',
            strtotime(sprintf('-%d days', (int) $this->config['dup_lookback_days']))
        );
        $stmt = $this->connection->pdo()->prepare(
            'SELECT p.id, p.route_visit_id, p.perceptual_hash, p.uploaded_at
               FROM route_visit_photos p
               INNER JOIN route_visits v ON v.id = p.route_visit_id
               INNER JOIN service_routes r ON r.id = v.service_route_id
              WHERE r.customer_id = :customer_id
                AND p.uploaded_at >= :since
                AND p.route_visit_id <> :exclude
                AND p.perceptual_hash IS NOT NULL
              ORDER BY p.uploaded_at DESC
              LIMIT :lim'
        );
        $stmt->bindValue('customer_id', $customerId, PDO::PARAM_INT);
        $stmt->bindValue('since', $sinceDate, PDO::PARAM_STR);
        $stmt->bindValue('exclude', $excludeVisitId, PDO::PARAM_INT);
        $stmt->bindValue('lim', (int) $this->config['dup_candidate_limit'], PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * @return array{lat: float, lng: float}|null
     */
    private function loadSiteCoords(int $siteId): ?array
    {
        if ($siteId <= 0) {
            return null;
        }
        $stmt = $this->connection->pdo()->prepare(
            'SELECT latitude, longitude FROM sites WHERE id = :id LIMIT 1'
        );
        $stmt->execute(['id' => $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row || $row['latitude'] === null || $row['longitude'] === null) {
            return null;
        }
        return ['lat' => (float) $row['latitude'], 'lng' => (float) $row['longitude']];
    }

    // ---- EXIF extraction --------------------------------------------------

    /**
     * @return array{exif_taken_at: ?string, exif_lat: ?float, exif_lng: ?float}
     */
    private function extractExif(string $path, string $mimeType): array
    {
        $blank = ['exif_taken_at' => null, 'exif_lat' => null, 'exif_lng' => null];
        if (!function_exists('exif_read_data')) {
            return $blank;
        }
        // exif_read_data is reliable for JPEG; HEIC/PNG generally lack the
        // segments we care about. Skip silently for those.
        if ($mimeType !== 'image/jpeg') {
            return $blank;
        }
        $data = @exif_read_data($path);
        if ($data === false || !is_array($data)) {
            return $blank;
        }

        $takenAt = null;
        foreach (['DateTimeOriginal', 'DateTimeDigitized', 'DateTime'] as $key) {
            if (!empty($data[$key])) {
                $normalized = preg_replace('/^(\d{4}):(\d{2}):(\d{2})/', '$1-$2-$3', (string) $data[$key]);
                $epoch = strtotime((string) $normalized);
                if ($epoch !== false) {
                    $takenAt = date('Y-m-d H:i:s', $epoch);
                    break;
                }
            }
        }

        $lat = null;
        $lng = null;
        if (!empty($data['GPSLatitude']) && !empty($data['GPSLatitudeRef'])
            && is_array($data['GPSLatitude'])
        ) {
            $lat = self::convertExifGps($data['GPSLatitude'], (string) $data['GPSLatitudeRef']);
        }
        if (!empty($data['GPSLongitude']) && !empty($data['GPSLongitudeRef'])
            && is_array($data['GPSLongitude'])
        ) {
            $lng = self::convertExifGps($data['GPSLongitude'], (string) $data['GPSLongitudeRef']);
        }

        return [
            'exif_taken_at' => $takenAt,
            'exif_lat' => $lat,
            'exif_lng' => $lng,
        ];
    }

    /**
     * Convert EXIF coord array (degrees/minutes/seconds as "n/d" strings)
     * + hemisphere ref ("N"/"S"/"E"/"W") to a signed decimal degree.
     *
     * @param array<int, string> $coord
     */
    private static function convertExifGps(array $coord, string $hemisphere): ?float
    {
        if (count($coord) < 3) {
            return null;
        }
        $degrees = self::fractionToFloat((string) $coord[0]);
        $minutes = self::fractionToFloat((string) $coord[1]);
        $seconds = self::fractionToFloat((string) $coord[2]);
        if ($degrees === null) {
            return null;
        }
        $value = $degrees + ($minutes ?? 0.0) / 60.0 + ($seconds ?? 0.0) / 3600.0;
        $hemisphere = strtoupper(trim($hemisphere));
        if ($hemisphere === 'S' || $hemisphere === 'W') {
            $value *= -1;
        }
        return $value;
    }

    private static function fractionToFloat(string $fraction): ?float
    {
        if (!str_contains($fraction, '/')) {
            return is_numeric($fraction) ? (float) $fraction : null;
        }
        [$num, $den] = explode('/', $fraction, 2);
        if (!is_numeric($num) || !is_numeric($den) || (float) $den === 0.0) {
            return null;
        }
        return (float) $num / (float) $den;
    }

    // ---- perceptual hash (dHash via GD) ----------------------------------

    /**
     * dHash: resize to 9x8 grayscale, then for each row produce 8 bits by
     * comparing adjacent pixels (left < right → 1). 64 bits total →
     * 16-char lowercase hex. Returns null if GD can't decode the image.
     */
    private function computeDHash(string $absolutePath, string $mimeType): ?string
    {
        $img = $this->loadGdImage($absolutePath, $mimeType);
        if ($img === null) {
            return null;
        }
        $small = imagecreatetruecolor(9, 8);
        if ($small === false) {
            imagedestroy($img);
            return null;
        }
        if (!imagecopyresampled(
            $small,
            $img,
            0, 0, 0, 0,
            9, 8,
            imagesx($img),
            imagesy($img)
        )) {
            imagedestroy($small);
            imagedestroy($img);
            return null;
        }

        $bits = '';
        for ($y = 0; $y < 8; $y++) {
            for ($x = 0; $x < 8; $x++) {
                $left = self::grayPixel($small, $x, $y);
                $right = self::grayPixel($small, $x + 1, $y);
                $bits .= ($left < $right) ? '1' : '0';
            }
        }
        imagedestroy($small);
        imagedestroy($img);

        $hex = '';
        for ($i = 0; $i < 64; $i += 4) {
            $hex .= dechex((int) bindec(substr($bits, $i, 4)));
        }
        return $hex;
    }

    private function loadGdImage(string $path, string $mimeType): ?GdImage
    {
        if (!extension_loaded('gd')) {
            return null;
        }
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => @imagecreatefrompng($path) ?: null,
            'image/webp' => function_exists('imagecreatefromwebp')
                ? (@imagecreatefromwebp($path) ?: null)
                : null,
            default => null,
        };
    }

    private static function grayPixel(GdImage $img, int $x, int $y): int
    {
        $rgb = imagecolorat($img, $x, $y);
        $r = ($rgb >> 16) & 0xFF;
        $g = ($rgb >> 8) & 0xFF;
        $b = $rgb & 0xFF;
        return (int) (0.299 * $r + 0.587 * $g + 0.114 * $b);
    }

    // ---- math helpers -----------------------------------------------------

    /**
     * Great-circle distance in metres between two WGS84 points.
     */
    public static function haversineMeters(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $earthRadius = 6371000.0; // metres
        $latRad1 = deg2rad($lat1);
        $latRad2 = deg2rad($lat2);
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat / 2) ** 2 + cos($latRad1) * cos($latRad2) * sin($dLng / 2) ** 2;
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Bit-count of two equal-length lowercase hex strings. Returns -1 if
     * the inputs differ in length (caller's bug).
     */
    public static function hammingHex(string $a, string $b): int
    {
        $len = strlen($a);
        if ($len !== strlen($b) || $len === 0) {
            return -1;
        }
        $diff = 0;
        for ($i = 0; $i < $len; $i++) {
            $xor = hexdec($a[$i]) ^ hexdec($b[$i]);
            $diff += self::HAMMING_NIBBLE[$xor & 0xF];
        }
        return $diff;
    }
}
