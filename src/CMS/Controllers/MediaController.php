<?php

namespace App\CMS\Controllers;

use App\CMS\Models\Media;
use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Services\CMS\CMSCacheService;
use DateTimeImmutable;
use InvalidArgumentException;
use PDO;

class MediaController
{
    private Connection $connection;
    private AccessGate $gate;
    private ?CMSCacheService $cache;

    public function __construct(Connection $connection, AccessGate $gate, ?CMSCacheService $cache = null)
    {
        $this->connection = $connection;
        $this->gate = $gate;
        $this->cache = $cache;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'cms.media.view');

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(file_name LIKE :search OR slug LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        if (array_key_exists('folder', $filters) && $filters['folder'] !== null && $filters['folder'] !== '') {
            if ($filters['folder'] === '__unassigned') {
                $where[] = '(folder IS NULL OR folder = "")';
            } else {
                $where[] = 'folder = :folder';
                $params['folder'] = $filters['folder'];
            }
        }

        if (array_key_exists('tag', $filters) && $filters['tag'] !== null && $filters['tag'] !== '') {
            if ($filters['tag'] === '__untagged') {
                $where[] = '(tags IS NULL OR JSON_LENGTH(tags) = 0)';
            } else {
                $where[] = 'JSON_CONTAINS(tags, :tag)';
                $params['tag'] = json_encode($filters['tag']);
            }
        }

        $sql = 'SELECT * FROM cms_media';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY created_at DESC';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return array_map(fn (array $row) => $this->mapMedia($row)->toArray(), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(User $user, int $id): ?array
    {
        $this->gate->assert($user, 'cms.media.view');

        $media = $this->find($id);

        return $media?->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->gate->assert($user, 'cms.media.create');

        $requestedStatus = $data['status'] ?? 'draft';
        if ($requestedStatus === 'published') {
            $this->assertPublishAccess($user);
        }

        $payload = $this->preparePayload($data, true);
        $variants = $this->generateVariants($payload['url'], $payload['mime_type']);
        $payload['variants'] = $variants !== null ? json_encode($variants, JSON_UNESCAPED_SLASHES) : null;

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO cms_media (file_name, slug, url, mime_type, size_bytes, title, alt_text, folder, tags, status, published_at, variants, created_at, updated_at) '
            . 'VALUES (:file_name, :slug, :url, :mime_type, :size_bytes, :title, :alt_text, :folder, :tags, :status, :published_at, :variants, NOW(), NOW())'
        );
        $stmt->execute($payload);

        $media = $this->find((int) $this->connection->pdo()->lastInsertId())?->toArray() ?? [];

        $this->invalidateCache($media['slug'] ?? '');

        return $media;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->gate->assert($user, 'cms.media.update');

        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $requestedStatus = $data['status'] ?? $existing->status ?? 'draft';
        if ($requestedStatus === 'published') {
            $this->assertPublishAccess($user);
        }

        $existingSlug = $existing->slug;
        $payload = $this->preparePayload($data, false, $existing);
        $variants = $existing->variants;
        $shouldRegenerate = $payload['url'] !== $existing->url
            || $payload['mime_type'] !== $existing->mime_type;
        if ($shouldRegenerate) {
            $this->cleanupVariants($existing->variants);
            $variants = $this->generateVariants($payload['url'], $payload['mime_type']);
        }
        $payload['variants'] = $variants !== null ? json_encode($variants, JSON_UNESCAPED_SLASHES) : null;
        $payload['id'] = $id;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cms_media SET file_name = :file_name, slug = :slug, url = :url, mime_type = :mime_type, size_bytes = :size_bytes, '
            . 'title = :title, alt_text = :alt_text, folder = :folder, tags = :tags, status = :status, published_at = :published_at, variants = :variants, updated_at = NOW() '
            . 'WHERE id = :id'
        );
        $stmt->execute($payload);

        $this->invalidateCache($payload['slug']);
        if ($payload['slug'] !== $existingSlug) {
            $this->invalidateCache($existingSlug);
        }

        return $this->find($id)?->toArray();
    }

    public function destroy(User $user, int $id): bool
    {
        $this->gate->assert($user, 'cms.media.delete');

        $media = $this->find($id)?->toArray();

        $stmt = $this->connection->pdo()->prepare('DELETE FROM cms_media WHERE id = :id');

        $deleted = $stmt->execute(['id' => $id]);

        if ($deleted && $media !== null) {
            $this->cleanupVariants($media['variants'] ?? null);
            $this->invalidateCache($media['slug'] ?? '');
        }

        return $deleted;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function publishedMedia(string $slug): ?array
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_media WHERE slug = :slug AND status = "published" ORDER BY published_at DESC LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapMedia($row)->toArray();
    }

    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    public function metadata(User $user): array
    {
        $this->gate->assert($user, 'cms.media.view');

        $folderStmt = $this->connection->pdo()->query(
            'SELECT folder, COUNT(*) as count FROM cms_media WHERE folder IS NOT NULL AND folder != "" GROUP BY folder ORDER BY folder ASC'
        );

        $folders = [];
        foreach ($folderStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $folders[] = [
                'name' => (string) $row['folder'],
                'count' => (int) $row['count'],
            ];
        }

        $tags = [];
        $tagStmt = $this->connection->pdo()->query(
            'SELECT tags FROM cms_media WHERE tags IS NOT NULL AND JSON_LENGTH(tags) > 0'
        );
        foreach ($tagStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string) $row['tags'], true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $tag) {
                $tagName = trim((string) $tag);
                if ($tagName === '') {
                    continue;
                }
                $tags[$tagName] = ($tags[$tagName] ?? 0) + 1;
            }
        }

        ksort($tags);
        $tagList = [];
        foreach ($tags as $tagName => $count) {
            $tagList[] = [
                'name' => $tagName,
                'count' => $count,
            ];
        }

        return [
            'folders' => $folders,
            'tags' => $tagList,
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function bulkUpdate(User $user, array $data): array
    {
        $this->gate->assert($user, 'cms.media.update');

        $ids = $data['ids'] ?? [];
        if (!is_array($ids) || $ids === []) {
            throw new InvalidArgumentException('ids is required');
        }

        $sets = [];
        $params = [];

        if (array_key_exists('folder', $data)) {
            $folder = is_string($data['folder']) ? trim($data['folder']) : null;
            $sets[] = 'folder = :folder';
            $params['folder'] = $folder !== '' ? $folder : null;
        }

        if (array_key_exists('tags', $data)) {
            $tags = $this->normalizeTags($data['tags']);
            $sets[] = 'tags = :tags';
            $params['tags'] = $tags === [] ? null : json_encode($tags);
        }

        if ($sets === []) {
            throw new InvalidArgumentException('No fields provided to update');
        }

        $placeholders = [];
        foreach ($ids as $index => $id) {
            $key = 'id_' . $index;
            $placeholders[] = ':' . $key;
            $params[$key] = (int) $id;
        }

        $sql = 'UPDATE cms_media SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id IN (' . implode(', ', $placeholders) . ')';
        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute($params);

        return [
            'updated' => $stmt->rowCount(),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function renameFolder(User $user, array $data): array
    {
        $this->gate->assert($user, 'cms.media.update');

        $from = isset($data['from']) ? trim((string) $data['from']) : '';
        $to = isset($data['to']) ? trim((string) $data['to']) : '';

        if ($from === '') {
            throw new InvalidArgumentException('from is required');
        }

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cms_media SET folder = :to, updated_at = NOW() WHERE folder = :from'
        );

        $stmt->execute([
            'from' => $from,
            'to' => $to === '' ? null : $to,
        ]);

        return [
            'updated' => $stmt->rowCount(),
        ];
    }

    private function find(int $id): ?Media
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM cms_media WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapMedia($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapMedia(array $row): Media
    {
        $variants = null;
        if (!empty($row['variants'])) {
            $decoded = json_decode((string) $row['variants'], true);
            $variants = is_array($decoded) ? $decoded : null;
        }

        $tags = [];
        if (!empty($row['tags'])) {
            $decoded = json_decode((string) $row['tags'], true);
            if (is_array($decoded)) {
                $tags = array_values(array_filter(array_map('trim', $decoded), 'strlen'));
            }
        }

        return new Media([
            'id' => (int) $row['id'],
            'file_name' => (string) $row['file_name'],
            'slug' => (string) $row['slug'],
            'url' => (string) $row['url'],
            'mime_type' => $row['mime_type'] ?? null,
            'size_bytes' => isset($row['size_bytes']) ? (int) $row['size_bytes'] : null,
            'title' => $row['title'] ?? null,
            'alt_text' => $row['alt_text'] ?? null,
            'folder' => $row['folder'] ?? null,
            'tags' => $tags,
            'status' => (string) $row['status'],
            'published_at' => $row['published_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
            'variants' => $variants,
            'srcset' => $this->buildSrcset($variants, 'responsive'),
            'webp_srcset' => $this->buildSrcset($variants, 'webp'),
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, bool $isCreate = true, ?Media $existing = null): array
    {
        $fileName = $data['file_name'] ?? $existing?->file_name ?? 'media';
        $slugSource = $data['slug'] ?? $fileName;
        $status = $data['status'] ?? $existing?->status ?? 'draft';
        $publishedAt = $data['published_at'] ?? $existing?->published_at ?? null;
        $folder = $data['folder'] ?? $existing?->folder ?? null;
        $tags = array_key_exists('tags', $data) ? $this->normalizeTags($data['tags']) : ($existing?->tags ?? []);

        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        return [
            'file_name' => (string) $fileName,
            'slug' => $this->slugify((string) $slugSource),
            'url' => (string) ($data['url'] ?? $existing?->url ?? ''),
            'mime_type' => $data['mime_type'] ?? $existing?->mime_type,
            'size_bytes' => isset($data['size_bytes']) ? (int) $data['size_bytes'] : $existing?->size_bytes,
            'title' => $data['title'] ?? $existing?->title,
            'alt_text' => $data['alt_text'] ?? $existing?->alt_text,
            'folder' => is_string($folder) && trim($folder) !== '' ? trim($folder) : null,
            'tags' => $tags === [] ? null : json_encode($tags),
            'status' => (string) $status,
            'published_at' => $publishedAt,
            'variants' => null,
        ];
    }

    /**
     * @param mixed $value
     * @return array<int, string>
     */
    private function normalizeTags($value): array
    {
        if ($value === null) {
            return [];
        }

        if (is_string($value)) {
            $value = array_filter(array_map('trim', explode(',', $value)));
        }

        if (!is_array($value)) {
            return [];
        }

        $tags = [];
        foreach ($value as $tag) {
            $tagName = trim((string) $tag);
            if ($tagName === '') {
                continue;
            }
            $tags[] = $tagName;
        }

        return array_values(array_unique($tags));
    }

    private function invalidateCache(string $slug): void
    {
        if ($slug === '') {
            return;
        }

        $this->cache?->forgetPrefix('media:' . $this->slugify($slug));
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value ?: uniqid('media-'), '-');
    }

    /**
     * @return array<string, mixed>|null
     */
    private function generateVariants(string $url, ?string $mimeType): ?array
    {
        if ($url === '') {
            return null;
        }

        $localPath = $this->resolveLocalPath($url);
        if ($localPath === null || !is_file($localPath)) {
            return null;
        }

        $imageInfo = @getimagesize($localPath);
        if ($imageInfo === false) {
            return null;
        }

        $sourceMime = $mimeType ?? $imageInfo['mime'] ?? null;
        if ($sourceMime === null || !$this->isImageMimeType($sourceMime)) {
            return null;
        }

        $width = (int) $imageInfo[0];
        $height = (int) $imageInfo[1];

        $variants = [
            'original' => [
                'url' => $url,
                'width' => $width,
                'height' => $height,
                'mime_type' => $sourceMime,
                'size_bytes' => filesize($localPath) ?: null,
            ],
            'responsive' => [],
            'webp' => [],
        ];

        $responsiveWidths = [320, 640, 960, 1280, 1600];
        $responsiveWidths = array_values(array_filter($responsiveWidths, fn (int $target) => $target < $width));
        if (empty($responsiveWidths)) {
            return $variants;
        }

        $sourceImage = $this->createImageResource($localPath, $sourceMime);
        if ($sourceImage === null) {
            return $variants;
        }

        $dir = dirname($localPath);
        $fileBase = pathinfo($localPath, PATHINFO_FILENAME);
        $extension = strtolower(pathinfo($localPath, PATHINFO_EXTENSION));

        foreach ($responsiveWidths as $targetWidth) {
            $ratio = $targetWidth / $width;
            $targetHeight = (int) round($height * $ratio);
            $resized = $this->resizeImage($sourceImage, $width, $height, $targetWidth, $targetHeight, $sourceMime);
            if ($resized === null) {
                continue;
            }

            $variantPath = sprintf('%s/%s-%dw.%s', $dir, $fileBase, $targetWidth, $extension);
            $variantUrl = $this->buildVariantUrl($url, '-' . $targetWidth . 'w', $extension);
            if ($this->writeImage($resized, $variantPath, $sourceMime)) {
                $variants['responsive'][] = [
                    'url' => $variantUrl,
                    'width' => $targetWidth,
                    'height' => $targetHeight,
                    'mime_type' => $sourceMime,
                    'size_bytes' => filesize($variantPath) ?: null,
                ];
            }

            if (function_exists('imagewebp')) {
                $webpPath = sprintf('%s/%s-%dw.webp', $dir, $fileBase, $targetWidth);
                $webpUrl = $this->buildVariantUrl($url, '-' . $targetWidth . 'w', 'webp');
                if (imagewebp($resized, $webpPath, 82)) {
                    $variants['webp'][] = [
                        'url' => $webpUrl,
                        'width' => $targetWidth,
                        'height' => $targetHeight,
                        'mime_type' => 'image/webp',
                        'size_bytes' => filesize($webpPath) ?: null,
                    ];
                }
            }

            imagedestroy($resized);
        }

        imagedestroy($sourceImage);

        return $variants;
    }

    private function buildSrcset(?array $variants, string $key): ?string
    {
        if ($variants === null || empty($variants[$key]) || !is_array($variants[$key])) {
            return null;
        }

        $entries = [];
        foreach ($variants[$key] as $variant) {
            if (!isset($variant['url'], $variant['width'])) {
                continue;
            }
            $entries[] = sprintf('%s %dw', $variant['url'], (int) $variant['width']);
        }

        if ($key === 'responsive' && isset($variants['original']['url'], $variants['original']['width'])) {
            $entries[] = sprintf('%s %dw', $variants['original']['url'], (int) $variants['original']['width']);
        }

        return empty($entries) ? null : implode(', ', $entries);
    }

    private function resolveLocalPath(string $url): ?string
    {
        if ($url === '') {
            return null;
        }

        if (is_file($url)) {
            return $url;
        }

        $parsed = parse_url($url);
        $path = $parsed['path'] ?? $url;
        $path = ltrim($path, '/');

        $basePath = dirname(__DIR__, 3);
        $candidates = [
            $basePath . '/' . $path,
            $basePath . '/public/' . $path,
            $basePath . '/storage/public/' . $path,
            $basePath . '/storage/' . $path,
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    private function isImageMimeType(string $mimeType): bool
    {
        return in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true);
    }

    private function buildVariantUrl(string $url, string $suffix, string $extension): string
    {
        $parsed = parse_url($url);
        $path = $parsed['path'] ?? '';
        $dir = rtrim(str_replace('\\', '/', dirname($path)), '/');
        $filename = pathinfo($path, PATHINFO_FILENAME);
        $variantPath = '';
        if ($dir !== '' && $dir !== '.' && $dir !== '/') {
            $variantPath = $dir . '/';
        }
        $variantPath .= $filename . $suffix . '.' . $extension;

        $variantUrl = $variantPath;
        if (isset($parsed['scheme'], $parsed['host'])) {
            $variantUrl = $parsed['scheme'] . '://' . $parsed['host'];
            if (isset($parsed['port'])) {
                $variantUrl .= ':' . $parsed['port'];
            }
            $variantUrl .= $variantPath;
        }

        if (isset($parsed['query'])) {
            $variantUrl .= '?' . $parsed['query'];
        }

        if (isset($parsed['fragment'])) {
            $variantUrl .= '#' . $parsed['fragment'];
        }

        return $variantUrl;
    }

    private function createImageResource(string $path, string $mimeType): ?\GdImage
    {
        return match ($mimeType) {
            'image/jpeg' => @imagecreatefromjpeg($path) ?: null,
            'image/png' => @imagecreatefrompng($path) ?: null,
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) ?: null : null,
            default => null,
        };
    }

    private function resizeImage(\GdImage $source, int $sourceWidth, int $sourceHeight, int $targetWidth, int $targetHeight, string $mimeType): ?\GdImage
    {
        $destination = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($destination === false) {
            return null;
        }

        if ($mimeType === 'image/png' || $mimeType === 'image/webp') {
            imagealphablending($destination, false);
            imagesavealpha($destination, true);
            $transparent = imagecolorallocatealpha($destination, 0, 0, 0, 127);
            imagefilledrectangle($destination, 0, 0, $targetWidth, $targetHeight, $transparent);
        }

        if (!imagecopyresampled($destination, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight)) {
            imagedestroy($destination);
            return null;
        }

        return $destination;
    }

    private function writeImage(\GdImage $image, string $path, string $mimeType): bool
    {
        return match ($mimeType) {
            'image/jpeg' => imagejpeg($image, $path, 82),
            'image/png' => imagepng($image, $path, 6),
            'image/webp' => function_exists('imagewebp') ? imagewebp($image, $path, 82) : false,
            default => false,
        };
    }

    /**
     * @param array<string, mixed>|null $variants
     */
    private function cleanupVariants(?array $variants): void
    {
        if ($variants === null) {
            return;
        }

        $groups = ['responsive', 'webp'];
        foreach ($groups as $group) {
            if (empty($variants[$group]) || !is_array($variants[$group])) {
                continue;
            }
            foreach ($variants[$group] as $variant) {
                if (!is_array($variant) || empty($variant['url'])) {
                    continue;
                }
                $path = $this->resolveLocalPath((string) $variant['url']);
                if ($path !== null && is_file($path)) {
                    @unlink($path);
                }
            }
        }
    }

    private function assertPublishAccess(User $user): void
    {
        $this->gate->assert($user, 'cms.media.publish');
    }
}
