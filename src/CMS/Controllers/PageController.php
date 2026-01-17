<?php

namespace App\CMS\Controllers;

use App\CMS\Models\Page;
use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Services\CMS\CMSCacheService;
use App\Services\CMS\CMSComponentUsageService;
use App\Services\CMS\CMSIndexService;
use App\Services\CMS\CMSRenderingService;
use App\Services\CMS\CMSRevisionService;
use DateTimeImmutable;
use PDO;

class PageController
{
    private Connection $connection;
    private AccessGate $gate;
    private ?CMSCacheService $cache;
    private CMSRevisionService $revisions;
    private CMSComponentUsageService $componentUsage;
    private CMSIndexService $indexService;

    public function __construct(
        Connection $connection,
        AccessGate $gate,
        ?CMSCacheService $cache = null,
        ?CMSRevisionService $revisions = null,
        ?CMSComponentUsageService $componentUsage = null,
        ?CMSIndexService $indexService = null
    )
    {
        $this->connection = $connection;
        $this->gate = $gate;
        $this->cache = $cache;
        $this->revisions = $revisions ?? new CMSRevisionService($connection);
        $this->componentUsage = $componentUsage ?? new CMSComponentUsageService($connection);
        $this->indexService = $indexService ?? new CMSIndexService($connection);
    }

/**
     * Render method called by routes/cms.php
     */
    public function render(string $slug): void
    {
        $html = $this->renderPublishedPage($slug);
        
        if ($html === null) {
            throw new \Exception("Page not found");
        }
        
        echo $html;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function index(User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'cms.pages.view');

        $where = [];
        $params = [];

        if (!empty($filters['status'])) {
            $where[] = 'status = :status';
            $params['status'] = $filters['status'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(title LIKE :search OR slug LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : 50;
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $sql = 'SELECT * FROM cms_pages';
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $sql .= ' ORDER BY COALESCE(publish_start_at, published_at) DESC, id DESC LIMIT :limit OFFSET :offset';

        $stmt = $this->connection->pdo()->prepare($sql);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return array_map(fn (array $row) => $this->mapPage($row)->toArray(), $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * @return array<string, mixed>|null
     */
    public function show(User $user, int $id): ?array
    {
        $this->gate->assert($user, 'cms.pages.view');

        $page = $this->find($id);

        return $page?->toArray();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    public function store(User $user, array $data): array
    {
        $this->gate->assert($user, 'cms.pages.create');

        $requestedStatus = $data['status'] ?? 'draft';
        if ($requestedStatus === 'published') {
            $this->assertPublishAccess($user);
        }

        $payload = $this->preparePayload($data, true);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO cms_pages (title, slug, preview_token, category_id, template_id, header_component_id, footer_component_id, custom_css, custom_js, status, meta_title, meta_description, meta_keywords, canonical_url, og_title, og_description, og_image, og_type, og_url, summary, content, component_order, publish_start_at, publish_end_at, published_at, created_at, updated_at) '
            . 'VALUES (:title, :slug, :preview_token, :category_id, :template_id, :header_component_id, :footer_component_id, :custom_css, :custom_js, :status, :meta_title, :meta_description, :meta_keywords, :canonical_url, :og_title, :og_description, :og_image, :og_type, :og_url, :summary, :content, :component_order, :publish_start_at, :publish_end_at, :published_at, NOW(), NOW())'
        );

        $stmt->execute($payload);

        $page = $this->find((int) $this->connection->pdo()->lastInsertId())?->toArray() ?? [];

        if (!empty($page['id'])) {
            $this->revisions->recordRevision('page', (int) $page['id'], $page, $user->id, 'created');
            $this->componentUsage->syncForPage((int) $page['id'], $page);
        }

        $this->invalidateCache($page['slug'] ?? '');
        $this->queuePageRevalidation($page);
        $this->indexService->indexPage($page);

        return $page;
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>|null
     */
    public function update(User $user, int $id, array $data): ?array
    {
        $this->gate->assert($user, 'cms.pages.update');

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
        $payload['id'] = $id;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cms_pages SET title = :title, slug = :slug, preview_token = :preview_token, category_id = :category_id, template_id = :template_id, header_component_id = :header_component_id, footer_component_id = :footer_component_id, custom_css = :custom_css, custom_js = :custom_js, status = :status, meta_title = :meta_title, meta_description = :meta_description, meta_keywords = :meta_keywords, canonical_url = :canonical_url, og_title = :og_title, og_description = :og_description, og_image = :og_image, og_type = :og_type, og_url = :og_url, '
            . 'summary = :summary, content = :content, component_order = :component_order, publish_start_at = :publish_start_at, publish_end_at = :publish_end_at, published_at = :published_at, updated_at = NOW() '
            . 'WHERE id = :id'
        );

        $stmt->execute($payload);

        $this->componentUsage->syncForPage($id, array_merge($payload, ['id' => $id]));

        $this->invalidateCache($payload['slug']);
        if ($payload['slug'] !== $existingSlug) {
            $this->invalidateCache($existingSlug);
        }

        $updated = $this->find($id)?->toArray();
        if ($updated !== null) {
            $this->queuePageRevalidation($updated);
        }

        return $updated;
        $updatedPage = $this->find($id)?->toArray();
        if ($updatedPage !== null) {
            $this->revisions->recordRevision('page', $id, $updatedPage, $user->id, 'updated');
        }

        return $updatedPage;
        $page = $this->find($id)?->toArray();
        if ($page !== null) {
            $this->indexService->indexPage($page);
        }

        return $page;
    }

    public function destroy(User $user, int $id): bool
    {
        $this->gate->assert($user, 'cms.pages.delete');

        $page = $this->find($id)?->toArray();

        $stmt = $this->connection->pdo()->prepare('DELETE FROM cms_pages WHERE id = :id');

        $deleted = $stmt->execute(['id' => $id]);

        if ($deleted && $page !== null) {
            $this->componentUsage->clearForPage((int) $page['id']);
            $this->invalidateCache($page['slug'] ?? '');
            $this->indexService->deleteEntry('page', $id);
        }

        return $deleted;
    }

    /**
     * Publish a page (sets status to 'published')
     *
     * @return array<string, mixed>|null
     */
    public function publish(User $user, int $id): ?array
    {
        $this->assertPublishAccess($user);

        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $publishedAt = $existing->published_at ?? (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cms_pages SET status = :status, published_at = :published_at, updated_at = NOW() WHERE id = :id'
        );

        $stmt->execute([
            'id' => $id,
            'status' => 'published',
            'published_at' => $publishedAt,
        ]);

        $this->invalidateCache($existing->slug);

        $published = $this->find($id)?->toArray();
        if ($published !== null) {
            $this->queuePageRevalidation($published);
        }

        return $published;
        $publishedPage = $this->find($id)?->toArray();
        if ($publishedPage !== null) {
            $this->revisions->recordRevision('page', $id, $publishedPage, $user->id, 'published');
        }

        return $publishedPage;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function revisions(User $user, int $id): ?array
    {
        $this->gate->assert($user, 'cms.pages.view');

        if ($this->find($id) === null) {
            return null;
        }

        return [
            'data' => $this->revisions->listRevisions('page', $id),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function restoreRevision(User $user, int $id, int $revisionId): ?array
    {
        $this->gate->assert($user, 'cms.pages.update');

        $existing = $this->find($id);
        if ($existing === null) {
            return null;
        }

        $revision = $this->revisions->getRevision('page', $id, $revisionId);
        if ($revision === null) {
            return null;
        }

        $snapshot = json_decode($revision['snapshot_data'] ?? '', true);
        if (!is_array($snapshot)) {
            return null;
        }

        $payload = $this->normalizeSnapshotPayload($snapshot);
        $payload['id'] = $id;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cms_pages SET title = :title, slug = :slug, category_id = :category_id, template_id = :template_id, header_component_id = :header_component_id, footer_component_id = :footer_component_id, custom_css = :custom_css, custom_js = :custom_js, status = :status, meta_title = :meta_title, meta_description = :meta_description, meta_keywords = :meta_keywords, '
            . 'summary = :summary, content = :content, publish_start_at = :publish_start_at, publish_end_at = :publish_end_at, published_at = :published_at, updated_at = NOW() '
            . 'WHERE id = :id'
        );

        $stmt->execute($payload);

        $this->invalidateCache($existing->slug);
        if ($existing->slug !== $payload['slug']) {
            $this->invalidateCache($payload['slug']);
        }

        $restored = $this->find($id)?->toArray();
        if ($restored !== null) {
            $this->revisions->recordRevision('page', $id, $restored, $user->id, 'restored');
        }

        return $restored;
        $page = $this->find($id)?->toArray();
        if ($page !== null) {
            $this->indexService->indexPage($page);
        }

        return $page;
    }

    /**
     * Public retrieval of a published page by slug.
     * Supports both base URIs (/page-slug) and nested URIs (/category-slug/page-slug)
     *
     * @return array<string, mixed>|null
     */
    public function publishedPage(string $slug): ?array
    {
        $lookupSlug = $this->normalizedSlug($slug);

        try {
            // Check if this is a nested URI (category/path/page)
            $parts = explode('/', $lookupSlug);

            if (count($parts) > 1) {
                $pageSlug = array_pop($parts);
                $categoryId = $this->resolveCategoryPath($parts);

                if ($categoryId === null) {
                    return null;
                }

                $sql = 'SELECT p.* FROM cms_pages p '
                    . 'WHERE p.slug = :page_slug AND p.category_id = :category_id '
                    . 'AND p.status = "published" '
                    . 'AND (p.publish_start_at IS NULL OR p.publish_start_at <= NOW()) '
                    . 'AND (p.publish_end_at IS NULL OR p.publish_end_at >= NOW()) '
                    . 'ORDER BY p.published_at DESC LIMIT 1';

                $stmt = $this->connection->pdo()->prepare($sql);
                $stmt->execute([
                    'page_slug' => $pageSlug,
                    'category_id' => $categoryId,
                ]);
            } else {
                // Base URI: /page-slug (pages without category or with category_id = NULL)
                $sql = 'SELECT * FROM cms_pages WHERE slug = :slug AND status = "published" '
                    . 'AND category_id IS NULL '
                    . 'AND (publish_start_at IS NULL OR publish_start_at <= NOW()) '
                    . 'AND (publish_end_at IS NULL OR publish_end_at >= NOW()) '
                    . 'ORDER BY published_at DESC LIMIT 1';

                $stmt = $this->connection->pdo()->prepare($sql);
                $stmt->execute(['slug' => $lookupSlug]);
            }

            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row === false) {
                return null;
            }

            $page = $this->mapPage($row)->toArray();
            unset($page['preview_token']);
            return $page;
        } catch (\Throwable $exception) {
            error_log(sprintf(
                'CMS publishedPage lookup failed for slug "%s": %s',
                $lookupSlug,
                $exception->getMessage()
            ));
            return null;
        }
    }

    /**
     * Get a fully rendered published page by slug
     *
     * @param string $slug
     * @return string|null Rendered HTML or null if page not found
     */
    public function renderPublishedPage(string $slug): ?string
    {
        $renderingService = new CMSRenderingService($this->connection, $this->cache);
        return $renderingService->renderPage($slug);
    }

    /**
     * Preview a page with full rendering (for admin use)
     *
     * @param User $user
     * @param int $id
     * @return string|null Rendered HTML or null if page not found
     */
    public function previewPage(User $user, int $id): ?string
    {
        $this->gate->assert($user, 'cms.pages.view');

        $page = $this->find($id);
        if ($page === null) {
            return null;
        }

        $renderingService = new CMSRenderingService($this->connection, $this->cache);
        return $renderingService->renderPageContent($page);
    }

    /**
     * Preview a page via a public token (no auth).
     *
     * @param string $token
     * @return string|null Rendered HTML or null if token invalid
     */
    public function previewPageByToken(string $token): ?string
    {
        if (trim($token) === '') {
            return null;
        }

        $stmt = $this->connection->pdo()->prepare('SELECT * FROM cms_pages WHERE preview_token = :token LIMIT 1');
        $stmt->execute(['token' => $token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $page = $this->mapPage($row);
        $renderingService = new CMSRenderingService($this->connection, $this->cache);
        return $renderingService->renderPageContent($page);
    }

    /**
     * Generate or retrieve a preview token for a page.
     *
     * @param User $user
     * @param int $id
     * @param bool $regenerate
     * @return array<string, mixed>|null
     */
    public function previewToken(User $user, int $id, bool $regenerate = false): ?array
    {
        $this->gate->assert($user, 'cms.pages.view');

        $page = $this->find($id);
        if ($page === null) {
            return null;
        }

        $token = $page->preview_token;
        if ($regenerate || empty($token)) {
            $token = bin2hex(random_bytes(16));
            $stmt = $this->connection->pdo()->prepare(
                'UPDATE cms_pages SET preview_token = :token, updated_at = NOW() WHERE id = :id'
            );
            $stmt->execute([
                'id' => $id,
                'token' => $token,
            ]);
        }

        return [
            'id' => $id,
            'preview_token' => $token,
        ];
    }

    private function find(int $id): ?Page
    {
        $stmt = $this->connection->pdo()->prepare('SELECT * FROM cms_pages WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapPage($row);
    }

    /**
     * @param array<string, mixed> $row
     */
    private function mapPage(array $row): Page
    {
        $componentOrder = null;
        if (array_key_exists('component_order', $row) && $row['component_order'] !== null) {
            $decoded = json_decode((string) $row['component_order'], true);
            if (is_array($decoded)) {
                $componentOrder = array_values($decoded);
            }
        }

        return new Page([
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
            'preview_token' => $row['preview_token'] ?? null,
            'category_id' => isset($row['category_id']) ? (int) $row['category_id'] : null,
            'template_id' => isset($row['template_id']) ? (int) $row['template_id'] : null,
            'header_component_id' => isset($row['header_component_id']) ? (int) $row['header_component_id'] : null,
            'footer_component_id' => isset($row['footer_component_id']) ? (int) $row['footer_component_id'] : null,
            'custom_css' => $row['custom_css'] ?? null,
            'custom_js' => $row['custom_js'] ?? null,
            'status' => (string) $row['status'],
            'meta_title' => $row['meta_title'] ?? null,
            'meta_description' => $row['meta_description'] ?? null,
            'meta_keywords' => $row['meta_keywords'] ?? null,
            'canonical_url' => $row['canonical_url'] ?? null,
            'og_title' => $row['og_title'] ?? null,
            'og_description' => $row['og_description'] ?? null,
            'og_image' => $row['og_image'] ?? null,
            'og_type' => $row['og_type'] ?? null,
            'og_url' => $row['og_url'] ?? null,
            'summary' => $row['summary'] ?? null,
            'content' => $row['content'] ?? null,
            'component_order' => $componentOrder,
            'publish_start_at' => $row['publish_start_at'] ?? null,
            'publish_end_at' => $row['publish_end_at'] ?? null,
            'published_at' => $row['published_at'] ?? null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function preparePayload(array $data, bool $isCreate = true, ?Page $existing = null): array
    {
        $title = $data['title'] ?? $existing?->title ?? 'Untitled Page';
        $slugSource = $data['slug'] ?? $title;
        $status = $data['status'] ?? $existing?->status ?? 'draft';
        $publishedAt = $data['published_at'] ?? $existing?->published_at ?? null;
        $previewToken = $data['preview_token'] ?? $existing?->preview_token ?? null;

        if ($status === 'published' && $publishedAt === null) {
            $publishedAt = (new DateTimeImmutable())->format('Y-m-d H:i:s');
        }

        return [
            'title' => (string) $title,
            'slug' => $this->slugify((string) $slugSource),
            'category_id' => array_key_exists('category_id', $data) ? ($data['category_id'] !== null ? (int) $data['category_id'] : null) : $existing?->category_id,
            'template_id' => array_key_exists('template_id', $data) ? ($data['template_id'] !== null ? (int) $data['template_id'] : null) : $existing?->template_id,
            'header_component_id' => isset($data['header_component_id']) ? (int) $data['header_component_id'] : $existing?->header_component_id,
            'footer_component_id' => isset($data['footer_component_id']) ? (int) $data['footer_component_id'] : $existing?->footer_component_id,
            'custom_css' => $data['custom_css'] ?? $existing?->custom_css,
            'custom_js' => $data['custom_js'] ?? $existing?->custom_js,
            'status' => (string) $status,
            'preview_token' => $previewToken,
            'meta_title' => $data['meta_title'] ?? $existing?->meta_title,
            'meta_description' => $data['meta_description'] ?? $existing?->meta_description,
            'meta_keywords' => $data['meta_keywords'] ?? $existing?->meta_keywords,
            'canonical_url' => $data['canonical_url'] ?? $existing?->canonical_url,
            'og_title' => $data['og_title'] ?? $existing?->og_title,
            'og_description' => $data['og_description'] ?? $existing?->og_description,
            'og_image' => $data['og_image'] ?? $existing?->og_image,
            'og_type' => $data['og_type'] ?? $existing?->og_type,
            'og_url' => $data['og_url'] ?? $existing?->og_url,
            'summary' => $data['summary'] ?? $existing?->summary,
            'content' => $data['content'] ?? $existing?->content,
            'component_order' => $this->serializeComponentOrder($data['component_order'] ?? $existing?->component_order),
            'publish_start_at' => $data['publish_start_at'] ?? $existing?->publish_start_at,
            'publish_end_at' => $data['publish_end_at'] ?? $existing?->publish_end_at,
            'published_at' => $publishedAt,
        ];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function normalizeSnapshotPayload(array $snapshot): array
    {
        $title = (string) ($snapshot['title'] ?? 'Untitled Page');
        $slug = (string) ($snapshot['slug'] ?? $this->slugify($title));

        return [
            'title' => $title,
            'slug' => $slug,
            'category_id' => array_key_exists('category_id', $snapshot) ? ($snapshot['category_id'] !== null ? (int) $snapshot['category_id'] : null) : null,
            'template_id' => array_key_exists('template_id', $snapshot) ? ($snapshot['template_id'] !== null ? (int) $snapshot['template_id'] : null) : null,
            'header_component_id' => array_key_exists('header_component_id', $snapshot) ? ($snapshot['header_component_id'] !== null ? (int) $snapshot['header_component_id'] : null) : null,
            'footer_component_id' => array_key_exists('footer_component_id', $snapshot) ? ($snapshot['footer_component_id'] !== null ? (int) $snapshot['footer_component_id'] : null) : null,
            'custom_css' => $snapshot['custom_css'] ?? null,
            'custom_js' => $snapshot['custom_js'] ?? null,
            'status' => (string) ($snapshot['status'] ?? 'draft'),
            'meta_title' => $snapshot['meta_title'] ?? null,
            'meta_description' => $snapshot['meta_description'] ?? null,
            'meta_keywords' => $snapshot['meta_keywords'] ?? null,
            'summary' => $snapshot['summary'] ?? null,
            'content' => $snapshot['content'] ?? null,
            'publish_start_at' => $snapshot['publish_start_at'] ?? null,
            'publish_end_at' => $snapshot['publish_end_at'] ?? null,
            'published_at' => $snapshot['published_at'] ?? null,
        ];
    }

    /**
     * @param mixed $value
     */
    private function serializeComponentOrder($value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return null;
            }
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return json_encode(array_values($decoded));
            }
        }

        if (is_array($value)) {
            $filtered = array_values(array_filter($value, static fn ($item) => is_numeric($item)));
            return json_encode(array_map('intval', $filtered));
        }

        return null;
    }

    private function invalidateCache(string $slug): void
    {
        $normalizedSlug = $this->normalizedSlug($slug);

        if ($normalizedSlug === '') {
            return;
        }

        $this->cache?->forgetPrefix('page:' . $this->slugify($normalizedSlug));
    }

    /**
     * @param array<string, mixed> $page
     */
    private function queuePageRevalidation(array $page): void
    {
        if (!$this->cache || !$this->cache->shouldPreRenderOnSave()) {
            return;
        }

        if (($page['status'] ?? '') !== 'published') {
            return;
        }

        $slug = (string) ($page['slug'] ?? '');
        if ($slug === '') {
            return;
        }

        $categoryId = isset($page['category_id']) ? (int) $page['category_id'] : null;
        $path = $this->buildPagePath($categoryId, $slug);
        if ($path === '') {
            return;
        }

        $connection = $this->connection;
        $cache = $this->cache;

        $cache->enqueueRevalidation(function () use ($connection, $cache, $path): void {
            $renderingService = new CMSRenderingService($connection, $cache);
            $renderingService->renderPage($path, true);
        });
    }

    private function buildPagePath(?int $categoryId, string $slug): string
    {
        $normalizedSlug = $this->normalizedSlug($slug);
        if ($normalizedSlug === '') {
            return '';
        }

        $segments = $this->resolveCategorySegments($categoryId);
        if (empty($segments)) {
            return $normalizedSlug;
        }

        $segments[] = $normalizedSlug;

        return implode('/', $segments);
    }

    /**
     * @return array<int, string>
     */
    private function resolveCategorySegments(?int $categoryId): array
    {
        if ($categoryId === null) {
            return [];
        }

        $pdo = $this->connection->pdo();
        $segments = [];
        $currentId = $categoryId;

        while ($currentId !== null) {
            $stmt = $pdo->prepare('SELECT slug, parent_id FROM cms_categories WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $currentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                break;
            }

            $segments[] = (string) $row['slug'];
            $parentId = $row['parent_id'];
            $currentId = $parentId !== null ? (int) $parentId : null;
        }

        return array_reverse($segments);
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value ?: uniqid('page-'), '-');
    }

    private function assertPublishAccess(User $user): void
    {
        $this->gate->assert($user, 'cms.pages.publish');
    }

    private function normalizedSlug(string $slug): string
    {
        $trimmed = trim($slug);

        return ltrim($trimmed, '/');
    }

    /**
     * Resolve a nested category path to its deepest category ID.
     *
     * @param array<int, string> $segments
     */
    private function resolveCategoryPath(array $segments): ?int
    {
        $parentId = null;
        $pdo = $this->connection->pdo();

        foreach ($segments as $segment) {
            if ($parentId === null) {
                $stmt = $pdo->prepare(
                    'SELECT id FROM cms_categories WHERE slug = :slug AND status = "published" AND parent_id IS NULL LIMIT 1'
                );
                $stmt->execute(['slug' => $segment]);
            } else {
                $stmt = $pdo->prepare(
                    'SELECT id FROM cms_categories WHERE slug = :slug AND status = "published" AND parent_id = :parent_id LIMIT 1'
                );
                $stmt->execute([
                    'slug' => $segment,
                    'parent_id' => $parentId,
                ]);
            }

            $categoryId = $stmt->fetchColumn();
            if (!$categoryId) {
                return null;
            }

            $parentId = (int) $categoryId;
        }

        return $parentId;
    }
}
