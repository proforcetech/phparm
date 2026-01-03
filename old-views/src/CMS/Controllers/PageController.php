<?php

namespace App\CMS\Controllers;

use App\CMS\Models\Page;
use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Services\CMS\CMSCacheService;
use App\Services\CMS\CMSRenderingService;
use DateTimeImmutable;
use PDO;

class PageController
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

        $payload = $this->preparePayload($data, true);

        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO cms_pages (title, slug, category_id, template_id, header_component_id, footer_component_id, custom_css, custom_js, status, meta_title, meta_description, meta_keywords, summary, content, publish_start_at, publish_end_at, published_at, created_at, updated_at) '
            . 'VALUES (:title, :slug, :category_id, :template_id, :header_component_id, :footer_component_id, :custom_css, :custom_js, :status, :meta_title, :meta_description, :meta_keywords, :summary, :content, :publish_start_at, :publish_end_at, :published_at, NOW(), NOW())'
        );

        $stmt->execute($payload);

        $page = $this->find((int) $this->connection->pdo()->lastInsertId())?->toArray() ?? [];

        $this->invalidateCache($page['slug'] ?? '');

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

        $existingSlug = $existing->slug;
        $payload = $this->preparePayload($data, false, $existing);
        $payload['id'] = $id;

        $stmt = $this->connection->pdo()->prepare(
            'UPDATE cms_pages SET title = :title, slug = :slug, category_id = :category_id, template_id = :template_id, header_component_id = :header_component_id, footer_component_id = :footer_component_id, custom_css = :custom_css, custom_js = :custom_js, status = :status, meta_title = :meta_title, meta_description = :meta_description, meta_keywords = :meta_keywords, '
            . 'summary = :summary, content = :content, publish_start_at = :publish_start_at, publish_end_at = :publish_end_at, published_at = :published_at, updated_at = NOW() '
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
        $this->gate->assert($user, 'cms.pages.delete');

        $page = $this->find($id)?->toArray();

        $stmt = $this->connection->pdo()->prepare('DELETE FROM cms_pages WHERE id = :id');

        $deleted = $stmt->execute(['id' => $id]);

        if ($deleted && $page !== null) {
            $this->invalidateCache($page['slug'] ?? '');
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
        $this->gate->assert($user, 'cms.pages.update');

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

        return $this->find($id)?->toArray();
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

        // Check if this is a nested URI (category/page)
        $parts = explode('/', $lookupSlug);

        if (count($parts) === 2) {
            // Nested URI: /category-slug/page-slug
            [$categorySlug, $pageSlug] = $parts;

            $sql = 'SELECT p.* FROM cms_pages p '
                . 'INNER JOIN cms_categories c ON p.category_id = c.id '
                . 'WHERE p.slug = :page_slug AND c.slug = :category_slug '
                . 'AND p.status = "published" AND c.status = "published" '
                . 'AND (p.publish_start_at IS NULL OR p.publish_start_at <= NOW()) '
                . 'AND (p.publish_end_at IS NULL OR p.publish_end_at >= NOW()) '
                . 'ORDER BY p.published_at DESC LIMIT 1';

            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute([
                'page_slug' => $pageSlug,
                'category_slug' => $categorySlug
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

        return $this->mapPage($row)->toArray();
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
        return new Page([
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
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
            'summary' => $row['summary'] ?? null,
            'content' => $row['content'] ?? null,
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
            'meta_title' => $data['meta_title'] ?? $existing?->meta_title,
            'meta_description' => $data['meta_description'] ?? $existing?->meta_description,
            'meta_keywords' => $data['meta_keywords'] ?? $existing?->meta_keywords,
            'summary' => $data['summary'] ?? $existing?->summary,
            'content' => $data['content'] ?? $existing?->content,
            'publish_start_at' => $data['publish_start_at'] ?? $existing?->publish_start_at,
            'publish_end_at' => $data['publish_end_at'] ?? $existing?->publish_end_at,
            'published_at' => $publishedAt,
        ];
    }

    private function invalidateCache(string $slug): void
    {
        $normalizedSlug = $this->normalizedSlug($slug);

        if ($normalizedSlug === '') {
            return;
        }

        $this->cache?->forgetPrefix('page:' . $this->slugify($normalizedSlug));
    }

    private function slugify(string $value): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9]+/', '-', $value) ?? '';
        return trim($value ?: uniqid('page-'), '-');
    }

    private function normalizedSlug(string $slug): string
    {
        $trimmed = trim($slug);

        return ltrim($trimmed, '/');
    }
}
