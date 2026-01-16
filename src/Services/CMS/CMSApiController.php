<?php

namespace App\Services\CMS;

use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Services\CMS\CMSCacheService;
use App\Services\CMS\CMSRevisionService;
use App\Services\CMS\CMSComponentUsageService;
use App\Services\CMS\CMSIndexService;
use PDO;

/**
 * CMS API Controller
 *
 * Provides API endpoints for CMS management through phparm's
 * JWT-authenticated API layer.
 */
class CMSApiController
{
    private Connection $connection;
    private CMSAuthBridge $authBridge;
    private string $tablePrefix;
    private ?CMSCacheService $cacheService;
    private AccessGate $gate;
    private CMSRevisionService $revisions;

    public function __construct(Connection $connection, CMSAuthBridge $authBridge, AccessGate $gate, ?CMSCacheService $cacheService = null, ?CMSRevisionService $revisions = null)
    private CMSComponentUsageService $componentUsage;
    private CMSIndexService $indexService;

    public function __construct(
        Connection $connection,
        CMSAuthBridge $authBridge,
        AccessGate $gate,
        ?CMSCacheService $cacheService = null,
        ?CMSComponentUsageService $componentUsage = null
    )
    {
        $this->connection = $connection;
        $this->authBridge = $authBridge;
        $this->gate = $gate;
        $this->tablePrefix = env('CMS_TABLE_PREFIX', 'cms_');
        $this->cacheService = $cacheService;
        $this->revisions = $revisions ?? new CMSRevisionService($connection);
        $this->componentUsage = $componentUsage ?? new CMSComponentUsageService($connection);
        $this->indexService = new CMSIndexService($connection, $this->tablePrefix);
    }

    /**
     * Get table name with prefix
     */
    private function table(string $name): string
    {
        return $this->tablePrefix . $name;
    }

    // ================================================
    // Dashboard
    // ================================================

    /**
     * Get CMS dashboard statistics
     */
    public function dashboard(?User $user): array
    {
        $this->gate->assert($user, 'cms.dashboard.view');
        $this->authBridge->initializeCMSSession($user);

        $pdo = $this->connection->pdo();

        // Get page counts
        $pageCount = $pdo->query("SELECT COUNT(*) FROM {$this->table('pages')}")->fetchColumn();
        $publishedCount = $pdo->query("SELECT COUNT(*) FROM {$this->table('pages')} WHERE status = 'published'")->fetchColumn();
        $componentCount = $pdo->query("SELECT COUNT(*) FROM {$this->table('components')}")->fetchColumn();
        $templateCount = $pdo->query("SELECT COUNT(*) FROM {$this->table('templates')}")->fetchColumn();

        // Get recent pages
        $recentPages = $pdo->query("
            SELECT id, title, slug, status, updated_at
            FROM {$this->table('pages')}
            ORDER BY updated_at DESC
            LIMIT 5
        ")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'stats' => [
                'pages' => (int) $pageCount,
                'published' => (int) $publishedCount,
                'drafts' => (int) $pageCount - (int) $publishedCount,
                'components' => (int) $componentCount,
                'templates' => (int) $templateCount,
            ],
            'recent_pages' => $recentPages,
            'user_role' => $this->authBridge->getCMSRole($user),
        ];
    }

    // ================================================
    // Pages
    // ================================================

    /**
     * List all pages
     */
    public function listPages(?User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'cms.pages.view');
        $this->authBridge->initializeCMSSession($user);

        $pdo = $this->connection->pdo();
        $where = [];
        $params = [];

        if (isset($filters['status'])) {
            if ($filters['status'] === 'published') {
                $where[] = 'is_published = 1';
            } elseif ($filters['status'] === 'draft') {
                $where[] = 'is_published = 0';
            }
        }

        if (!empty($filters['search'])) {
            $where[] = '(title LIKE :search OR slug LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT p.*, t.name as template_name
            FROM {$this->table('pages')} p
            LEFT JOIN {$this->table('templates')} t ON p.template_id = t.id
            {$whereClause}
            ORDER BY p.sort_order ASC, p.title ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Get single page
     */
    public function getPage(?User $user, int $id): ?array
    {
        $this->gate->assert($user, 'cms.pages.view');
        $this->authBridge->initializeCMSSession($user);

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare("
            SELECT p.*, t.name as template_name
            FROM {$this->table('pages')} p
            LEFT JOIN {$this->table('templates')} t ON p.template_id = t.id
            WHERE p.id = :id
        ");
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Get page by slug (public access - only returns published pages)
     */
    public function getPageBySlug(string $slug): ?array
    {
        $pdo = $this->connection->pdo();
        $lookupSlug = $this->normalizeSlugPath($slug);
        if ($lookupSlug === '') {
            return null;
        }

        $parts = explode('/', $lookupSlug);

        if (count($parts) > 1) {
            $pageSlug = array_pop($parts);
            $categoryId = $this->resolveCategoryPath($parts);

            if ($categoryId === null) {
                return null;
            }

            $stmt = $pdo->prepare("
                SELECT *
                FROM {$this->table('pages')}
                WHERE slug = :slug AND category_id = :category_id AND status = 'published'
                AND (publish_start_at IS NULL OR publish_start_at <= NOW())
                AND (publish_end_at IS NULL OR publish_end_at >= NOW())
            ");
            $stmt->execute([
                'slug' => $pageSlug,
                'category_id' => $categoryId,
            ]);
        } else {
            $stmt = $pdo->prepare("
                SELECT *
                FROM {$this->table('pages')}
                WHERE slug = :slug AND status = 'published' AND category_id IS NULL
                AND (publish_start_at IS NULL OR publish_start_at <= NOW())
                AND (publish_end_at IS NULL OR publish_end_at >= NOW())
            ");
            $stmt->execute(['slug' => $lookupSlug]);
        }

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create page
     */
    public function createPage(?User $user, array $data): array
    {
        $this->requireEditAccess($user);

        $requestedStatus = $data['status'] ?? 'draft';
        if ($requestedStatus === 'published') {
            $this->requirePublishAccess($user);
        }

        $pdo = $this->connection->pdo();

        // Generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['title'] ?? 'untitled');
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('pages')} (
                title, slug, template_id, status, meta_title, meta_description, meta_keywords,
                canonical_url, og_title, og_description, og_image, og_type, og_url,
                summary, content, created_at, updated_at
            ) VALUES (
                :title, :slug, :status, :tempate_id, :meta_title, :meta_description, :meta_keywords,
                :canonical_url, :og_title, :og_description, :og_image, :og_type, :og_url,
                :summary, :content, NOW(), NOW()
            )
        ");

        $stmt->execute([
            'title' => $data['title'] ?? '',
			'template_id' => $data['template_id'] ?? null,
            'slug' => $data['slug'],
            'status' => $data['status'] ?? 'draft',
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
            'canonical_url' => $data['canonical_url'] ?? null,
            'og_title' => $data['og_title'] ?? null,
            'og_description' => $data['og_description'] ?? null,
            'og_image' => $data['og_image'] ?? null,
            'og_type' => $data['og_type'] ?? null,
            'og_url' => $data['og_url'] ?? null,
            'summary' => $data['summary'] ?? null,
            'content' => $data['content'] ?? '',
        ]);

        $id = (int) $pdo->lastInsertId();

        $page = $this->getPage($user, $id);
        if ($page !== null) {
            $this->queuePageRevalidation($page);
        }

        return $page ?? [];
        if ($page) {
            $this->componentUsage->syncForPage($id, $page);
            $this->indexService->indexPage($page);
        }

        return $page;
    }

    /**
     * Update page
     */
    public function updatePage(?User $user, int $id, array $data): array
    {
        $this->requireEditAccess($user);

        $existing = $this->getPage($user, $id);
        if ($existing === null) {
            throw new \RuntimeException('Page not found');
        }

        $requestedStatus = $data['status'] ?? $existing['status'] ?? 'draft';
        if ($requestedStatus === 'published') {
            $this->requirePublishAccess($user);
        }

        $pdo = $this->connection->pdo();

        $stmt = $pdo->prepare("
            UPDATE {$this->table('pages')} SET
                title = :title,
                slug = :slug,
	   template_id = :template_id,
                status = :status,
                meta_title = :meta_title,
                meta_description = :meta_description,
                meta_keywords = :meta_keywords,
                canonical_url = :canonical_url,
                og_title = :og_title,
                og_description = :og_description,
                og_image = :og_image,
                og_type = :og_type,
                og_url = :og_url,
                summary = :summary,
                content = :content,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $id,
            'title' => $data['title'] ?? '',
            'slug' => $data['slug'] ?? '',
	'template_id' => $data['template_id'] ?? '',
            'status' => $data['status'] ?? 'draft',
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'meta_keywords' => $data['meta_keywords'] ?? null,
            'canonical_url' => $data['canonical_url'] ?? null,
            'og_title' => $data['og_title'] ?? null,
            'og_description' => $data['og_description'] ?? null,
            'og_image' => $data['og_image'] ?? null,
            'og_type' => $data['og_type'] ?? null,
            'og_url' => $data['og_url'] ?? null,
            'summary' => $data['summary'] ?? null,
            'content' => $data['content'] ?? '',
        ]);

        // Invalidate cache
        $this->invalidatePageCache($data['slug'] ?? '');

        $page = $this->getPage($user, $id);
        if ($page !== null) {
            $this->queuePageRevalidation($page);
        }

        return $page ?? [];
        if ($page) {
            $this->componentUsage->syncForPage($id, $page);
            $this->indexService->indexPage($page);
        }

        return $page;
    }

    /**
     * Publish page (sets status to 'published')
     */
    public function publishPage(?User $user, int $id): array
    {
        $this->requirePublishAccess($user);

        $pdo = $this->connection->pdo();

        // Get page slug for cache invalidation
        $page = $this->getPage($user, $id);
        if (!$page) {
            throw new \RuntimeException('Page not found');
        }

        $stmt = $pdo->prepare("
            UPDATE {$this->table('pages')} SET
                status = 'published',
                published_at = COALESCE(published_at, NOW()),
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $id,
        ]);

        // Invalidate cache
        $this->invalidatePageCache($page['slug']);

        $published = $this->getPage($user, $id);
        if ($published !== null) {
            $this->queuePageRevalidation($published);
        }

        return $published ?? [];
        $page = $this->getPage($user, $id);
        if ($page) {
            $this->indexService->indexPage($page);
        }

        return $page;
    }

    /**
     * Delete page
     */
    public function deletePage(?User $user, int $id): bool
    {
        $this->requireEditAccess($user);

        $pdo = $this->connection->pdo();

        // Get page slug for cache invalidation
        $page = $this->getPage($user, $id);
        if ($page) {
            $stmt = $pdo->prepare("DELETE FROM {$this->table('pages')} WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $this->componentUsage->clearForPage($id);
            $this->invalidatePageCache($page['slug']);
            $this->indexService->deleteEntry('page', $id);
            return true;
        }

        return false;
    }

    // ================================================
    // Components
    // ================================================

    /**
     * List all components
     */
    public function listComponents(?User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'cms.components.view');
        $this->authBridge->initializeCMSSession($user);

        $pdo = $this->connection->pdo();
        $where = [];
        $params = [];

        if (!empty($filters['type'])) {
            $where[] = 'type = :type';
            $params['type'] = $filters['type'];
        }

        if (!empty($filters['search'])) {
            $where[] = '(name LIKE :search OR slug LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT *
            FROM {$this->table('components')}
            {$whereClause}
            ORDER BY type ASC, name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }

    /**
     * Get single component
     */
    public function getComponent(?User $user, int $id): ?array
    {
        $this->gate->assert($user, "cms.components.view");
        $this->authBridge->initializeCMSSession($user);

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare("SELECT * FROM {$this->table('components')} WHERE id = :id");
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create component
     */
    public function createComponent(?User $user, array $data): array
    {
        $this->requireEditAccess($user);

        $pdo = $this->connection->pdo();

        $this->validateComponentData($data);

        // Generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['name'] ?? 'untitled');
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('components')} (
                name, slug, type, description, content, css, javascript, css_assets, js_assets,
                cache_ttl, is_active, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :name, :slug, :type, :description, :content, :css, :javascript, :css_assets, :js_assets,
                :cache_ttl, :is_active, :created_by, :updated_by, NOW(), NOW()
            )
        ");

        $stmt->execute([
            'name' => $data['name'] ?? '',
            'slug' => $data['slug'],
            'type' => $data['type'] ?? 'custom',
            'description' => $data['description'] ?? '',
            'content' => $data['content'] ?? '',
            'css' => $data['css'] ?? '',
            'javascript' => $data['javascript'] ?? '',
            'css_assets' => $data['css_assets'] ?? '',
            'js_assets' => $data['js_assets'] ?? '',
            'cache_ttl' => (int) ($data['cache_ttl'] ?? 3600),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $id = (int) $pdo->lastInsertId();

        $component = $this->getComponent($user, $id);
        if ($component !== null) {
            $this->revisions->recordRevision('component', $id, $component, $user?->id, 'created');
        }

        return $component ?? [];
        if ($component) {
            $this->indexService->indexComponent($component);
        }

        return $component;
    }

    /**
     * Update component
     */
    public function updateComponent(?User $user, int $id, array $data): array
    {
        $this->requireEditAccess($user);

        $pdo = $this->connection->pdo();
        $existing = $this->getComponent($user, $id);
        $existingSlug = $existing['slug'] ?? null;

        $this->validateComponentData($data);

        $stmt = $pdo->prepare("
            UPDATE {$this->table('components')} SET
                name = :name,
                slug = :slug,
                type = :type,
                description = :description,
                content = :content,
                css = :css,
                javascript = :javascript,
                css_assets = :css_assets,
                js_assets = :js_assets,
                cache_ttl = :cache_ttl,
                is_active = :is_active,
                updated_by = :updated_by,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $id,
            'name' => $data['name'] ?? '',
            'slug' => $data['slug'] ?? ($existing['slug'] ?? ''),
            'type' => $data['type'] ?? 'custom',
            'description' => $data['description'] ?? '',
            'content' => $data['content'] ?? '',
            'css' => $data['css'] ?? '',
            'javascript' => $data['javascript'] ?? '',
            'css_assets' => $data['css_assets'] ?? '',
            'js_assets' => $data['js_assets'] ?? '',
            'cache_ttl' => (int) ($data['cache_ttl'] ?? 3600),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_by' => $user->id,
        ]);

        // Invalidate cache
        $newSlug = (string) ($data['slug'] ?? ($existing['slug'] ?? ''));
        if ($newSlug !== '') {
            $this->invalidateComponentCache($newSlug);
        }
        if ($existing !== null && $existing['slug'] !== $newSlug) {
            $this->invalidateComponentCache($existing['slug']);
        }

        $this->queueComponentRevalidation($id, array_filter([
            $existing['slug'] ?? null,
            $newSlug !== '' ? $newSlug : null,
        ]));
        $this->invalidateComponentCache($data['slug'] ?? '');
        if ($existingSlug && $existingSlug !== ($data['slug'] ?? $existingSlug)) {
            $this->invalidateComponentCache($existingSlug);
        }
        $this->invalidatePageCaches($this->componentUsage->findPageSlugsForComponent($id));

        $component = $this->getComponent($user, $id);
        if ($component !== null) {
            $this->revisions->recordRevision('component', $id, $component, $user?->id, 'updated');
        }

        return $component ?? [];
        if ($component) {
            $this->indexService->indexComponent($component);
        }

        return $component;
    }

    /**
     * Delete component
     */
    public function deleteComponent(?User $user, int $id): bool
    {
        $this->requireEditAccess($user);

        $pdo = $this->connection->pdo();

        $component = $this->getComponent($user, $id);
        if ($component) {
            $stmt = $pdo->prepare("DELETE FROM {$this->table('components')} WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $this->invalidateComponentCache($component['slug']);
            $this->queueComponentRevalidation($id, [$component['slug']]);
            $this->invalidatePageCaches($this->componentUsage->findPageSlugsForComponent($id));
            $this->indexService->deleteEntry('component', $id);
            return true;
        }

        return false;
    }

    /**
     * Duplicate component
     */
    public function duplicateComponent(?User $user, int $id): ?array
    {
        $this->requireEditAccess($user);

        $component = $this->getComponent($user, $id);
        if (!$component) {
            return null;
        }

        $component['name'] = $component['name'] . ' (Copy)';
        $component['slug'] = $component['slug'] . '-copy-' . time();
        unset($component['id']);

        return $this->createComponent($user, $component);
    }

    /**
     * List component revisions
     */
    public function listComponentRevisions(?User $user, int $id): ?array
    {
        $this->gate->assert($user, 'cms.components.view');
        $this->authBridge->initializeCMSSession($user);

        if ($this->getComponent($user, $id) === null) {
            return null;
        }

        return [
            'data' => $this->revisions->listRevisions('component', $id),
        ];
    }

    /**
     * Restore component revision
     */
    public function restoreComponentRevision(?User $user, int $id, int $revisionId): ?array
    {
        $this->requireEditAccess($user);

        $existing = $this->getComponent($user, $id);
        if ($existing === null) {
            return null;
        }

        $revision = $this->revisions->getRevision('component', $id, $revisionId);
        if ($revision === null) {
            return null;
        }

        $snapshot = json_decode($revision['snapshot_data'] ?? '', true);
        if (!is_array($snapshot)) {
            return null;
        }

        $payload = $this->normalizeComponentSnapshot($snapshot);

        $stmt = $this->connection->pdo()->prepare("
            UPDATE {$this->table('components')} SET
                name = :name,
                slug = :slug,
                type = :type,
                description = :description,
                content = :content,
                css = :css,
                javascript = :javascript,
                cache_ttl = :cache_ttl,
                is_active = :is_active,
                updated_by = :updated_by,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $id,
            'name' => $payload['name'],
            'slug' => $payload['slug'],
            'type' => $payload['type'],
            'description' => $payload['description'],
            'content' => $payload['content'],
            'css' => $payload['css'],
            'javascript' => $payload['javascript'],
            'cache_ttl' => $payload['cache_ttl'],
            'is_active' => $payload['is_active'],
            'updated_by' => $user?->id,
        ]);

        $this->invalidateComponentCache($existing['slug'] ?? '');
        if (($existing['slug'] ?? '') !== $payload['slug']) {
            $this->invalidateComponentCache($payload['slug']);
        }

        $component = $this->getComponent($user, $id);
        if ($component !== null) {
            $this->revisions->recordRevision('component', $id, $component, $user?->id, 'restored');
        }

        return $component;
    }

    // ================================================
    // Templates
    // ================================================

/**
     * List all templates
     */
    public function listTemplates(?User $user, array $filters = []): array
    {
        $this->gate->assert($user, 'cms.templates.view');
        $this->authBridge->initializeCMSSession($user);

        $pdo = $this->connection->pdo();
        $where = [];
        $params = [];

        // FIXED: Check that active is not an empty string before filtering
        if (isset($filters['active']) && $filters['active'] !== '') {
            $where[] = 'is_active = :active';
            $params['active'] = $filters['active'] ? 1 : 0;
        }

        if (!empty($filters['search'])) {
            $where[] = '(name LIKE :search OR slug LIKE :search)';
            $params['search'] = '%' . $filters['search'] . '%';
        }

        $whereClause = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

        $sql = "
            SELECT *
            FROM {$this->table('templates')}
            {$whereClause}
            ORDER BY name ASC
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return [
            'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        ];
    }
    
    /**
     * Get single template
     */
    public function getTemplate(?User $user, int $id): ?array
    {
        error_log("getTemplate: User role = " . ($user->role ?? 'NULL'));
        error_log("getTemplate: About to check cms.templates.view permission");
        $this->gate->assert($user, 'cms.templates.view');
        error_log("getTemplate: Permission check passed");
        $this->authBridge->initializeCMSSession($user);

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare("SELECT * FROM {$this->table('templates')} WHERE id = :id");
        $stmt->execute(['id' => $id]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create template
     */
    public function createTemplate(?User $user, array $data): array
    {
        error_log("createTemplate: User role = " . ($user->role ?? 'NULL'));
        error_log("createTemplate: About to check admin access");
        $this->requireAdminAccess($user);
        error_log("createTemplate: Admin access check passed");

        $pdo = $this->connection->pdo();

        // Generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['name'] ?? 'untitled');
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('templates')} (
                name, slug, description, structure, default_css, default_js,
                is_active, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :name, :slug, :description, :structure, :default_css, :default_js,
                :is_active, :created_by, :updated_by, NOW(), NOW()
            )
        ");

        $stmt->execute([
            'name' => $data['name'] ?? '',
            'slug' => $data['slug'],
            'description' => $data['description'] ?? '',
            'structure' => $data['structure'] ?? '',
            'default_css' => $data['default_css'] ?? '',
            'default_js' => $data['default_js'] ?? '',
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $id = (int) $pdo->lastInsertId();

        return $this->getTemplate($user, $id);
    }

    /**
     * Update template
     */
    public function updateTemplate(?User $user, int $id, array $data): array
    {
        $this->requireAdminAccess($user);

        $pdo = $this->connection->pdo();

        $stmt = $pdo->prepare("
            UPDATE {$this->table('templates')} SET
                name = :name,
                slug = :slug,
                description = :description,
                structure = :structure,
                default_css = :default_css,
                default_js = :default_js,
                is_active = :is_active,
                updated_by = :updated_by,
                updated_at = NOW()
            WHERE id = :id
        ");

        $stmt->execute([
            'id' => $id,
            'name' => $data['name'] ?? '',
            'slug' => $data['slug'] ?? '',
            'description' => $data['description'] ?? '',
            'structure' => $data['structure'] ?? '',
            'default_css' => $data['default_css'] ?? '',
            'default_js' => $data['default_js'] ?? '',
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_by' => $user->id,
        ]);

        // Invalidate cache
        $this->invalidateTemplateCache($data['slug'] ?? '');

        return $this->getTemplate($user, $id);
    }

    /**
     * Delete template
     */
    public function deleteTemplate(?User $user, int $id): bool
    {
        $this->requireAdminAccess($user);

        $pdo = $this->connection->pdo();

        // Check if template is in use
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$this->table('pages')} WHERE template_id = :id");
        $stmt->execute(['id' => $id]);
        if ((int) $stmt->fetchColumn() > 0) {
            throw new \RuntimeException('Cannot delete template. It is in use by one or more pages.');
        }

        $template = $this->getTemplate($user, $id);
        if ($template) {
            $stmt = $pdo->prepare("DELETE FROM {$this->table('templates')} WHERE id = :id");
            $stmt->execute(['id' => $id]);
            $this->invalidateTemplateCache($template['slug']);
            return true;
        }

        return false;
    }

    // ================================================
    // CMS Settings
    // ================================================

    /**
     * Get all CMS settings
     */
    public function getSettings(?User $user): array
    {
        $this->requireAdminAccess($user);

        $pdo = $this->connection->pdo();
        $stmt = $pdo->query("SELECT * FROM {$this->table('settings')} ORDER BY setting_key");

        $settings = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $settings[$row['setting_key']] = [
                'value' => $row['setting_value'],
                'type' => $row['setting_type'] ?? 'string',
                'description' => $row['description'] ?? '',
            ];
        }

        return ['settings' => $settings];
    }

    /**
     * Update CMS settings
     */
    public function updateSettings(?User $user, array $settings): array
    {
        $this->requireAdminAccess($user);

        $pdo = $this->connection->pdo();

        foreach ($settings as $key => $value) {
            $stmt = $pdo->prepare("
                INSERT INTO {$this->table('settings')} (setting_key, setting_value, updated_at)
                VALUES (:key, :value, NOW())
                ON DUPLICATE KEY UPDATE setting_value = :value, updated_at = NOW()
            ");
            $stmt->execute(['key' => $key, 'value' => $value]);
        }

        return $this->getSettings($user);
    }

    // ================================================
    // Cache Management
    // ================================================

    /**
     * Get cache statistics
     */
    public function getCacheStats(?User $user): array
    {
        $this->gate->assert($user, 'cms.*');
        $this->authBridge->initializeCMSSession($user);

        $pdo = $this->connection->pdo();

        $stats = $pdo->query("
            SELECT
                type,
                COUNT(*) as count,
                SUM(CASE WHEN expires_at > NOW() THEN 1 ELSE 0 END) as active
            FROM {$this->table('cache')}
            GROUP BY type
        ")->fetchAll(PDO::FETCH_ASSOC);

        $totalCount = $pdo->query("SELECT COUNT(*) FROM {$this->table('cache')}")->fetchColumn();
        $expiredCount = $pdo->query("SELECT COUNT(*) FROM {$this->table('cache')} WHERE expires_at <= NOW()")->fetchColumn();

        return [
            'total' => (int) $totalCount,
            'expired' => (int) $expiredCount,
            'by_type' => $stats,
        ];
    }

    /**
     * Clear cache
     */
    public function clearCache(?User $user, ?string $type = null): array
    {
        $this->requireAdminAccess($user);

        $pdo = $this->connection->pdo();
        $count = 0;

        if ($type === null || $type === 'all') {
            $count = $pdo->exec("DELETE FROM {$this->table('cache')}");

            // Also clear file cache
            $cacheDir = CMS_ROOT . '/cache';
            if (is_dir($cacheDir)) {
                $files = glob($cacheDir . '/*.cache');
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                        $count++;
                    }
                }
            }
        } else {
            $stmt = $pdo->prepare("DELETE FROM {$this->table('cache')} WHERE type = :type");
            $stmt->execute(['type' => $type]);
            $count = $stmt->rowCount();
        }

        return [
            'cleared' => $count,
            'message' => "Cleared {$count} cache entries",
        ];
    }

    // ================================================
    // Helpers for templates/components selection
    // ================================================

    /**
     * Get options for page form (templates, components)
     */
    public function getPageFormOptions(?User $user): array
    {
        $this->gate->assert($user, 'cms.pages.view');
        $this->authBridge->initializeCMSSession($user);

        $pdo = $this->connection->pdo();

        $templates = $pdo->query("
            SELECT id, name FROM {$this->table('templates')}
            WHERE is_active = 1 ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $headerComponents = $pdo->query("
            SELECT id, name FROM {$this->table('components')}
            WHERE type = 'header' AND is_active = 1 ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $footerComponents = $pdo->query("
            SELECT id, name FROM {$this->table('components')}
            WHERE type = 'footer' AND is_active = 1 ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $components = $pdo->query("
            SELECT id, name, type FROM {$this->table('components')}
            WHERE type NOT IN ('header', 'footer') AND is_active = 1 ORDER BY name
        ")->fetchAll(PDO::FETCH_ASSOC);

        $pages = $pdo->query("
            SELECT id, title, slug FROM {$this->table('pages')}
            ORDER BY title
        ")->fetchAll(PDO::FETCH_ASSOC);

        $categories = $pdo->query("
            SELECT id, name, slug, parent_id FROM {$this->table('categories')}
            WHERE status = 'published' ORDER BY sort_order, name
        ")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'templates' => $templates,
            'header_components' => $headerComponents,
            'footer_components' => $footerComponents,
            'components' => $components,
            'parent_pages' => $pages,
            'categories' => $categories,
        ];
    }

    // ================================================
    // Authorization Helpers
    // ================================================

    private function requireAccess(?User $user): void
    {
        // Use AccessGate for consistent permission checking
        $this->gate->assert($user, 'cms.dashboard.view');
        // Initialize CMS session after successful access check
        $this->authBridge->initializeCMSSession($user);
    }

    private function requireEditAccess(?User $user): void
    {
        // Use AccessGate for consistent permission checking
        $this->gate->assert($user, 'cms.pages.update');
        // Initialize CMS session after successful access check
        $this->authBridge->initializeCMSSession($user);
    }

    private function requireAdminAccess(?User $user): void
    {
        // Use AccessGate for consistent permission checking
        $this->gate->assert($user, 'cms.*');
        // Initialize CMS session after successful access check
        $this->authBridge->initializeCMSSession($user);
    }

    private function requirePublishAccess(?User $user): void
    {
        $this->gate->assert($user, 'cms.pages.publish');
        $this->authBridge->initializeCMSSession($user);
    }

    // ================================================
    // Utility Helpers
    // ================================================

    private function generateSlug(string $title): string
    {
        $slug = strtolower(trim($title));
        $slug = preg_replace('/[^a-z0-9-]/', '-', $slug);
        $slug = preg_replace('/-+/', '-', $slug);
        $slug = trim($slug, '-');

        return $slug ?: 'untitled-' . time();
    }

    /**
     * Normalize incoming slug paths by trimming whitespace and leading slashes.
     */
    private function normalizeSlugPath(string $slug): string
    {
        return ltrim(trim($slug), '/');
    }

    /**
     * Resolve a category path to its deepest category ID.
     *
     * @param array<int, string> $segments
     */
    private function resolveCategoryPath(array $segments): ?int
    {
        $pdo = $this->connection->pdo();
        $parentId = null;

        foreach ($segments as $segment) {
            if ($parentId === null) {
                $stmt = $pdo->prepare("
                    SELECT id FROM {$this->table('categories')}
                    WHERE slug = :slug AND status = 'published' AND parent_id IS NULL
                    LIMIT 1
                ");
                $stmt->execute(['slug' => $segment]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT id FROM {$this->table('categories')}
                    WHERE slug = :slug AND status = 'published' AND parent_id = :parent_id
                    LIMIT 1
                ");
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

    private function invalidatePageCache(string $slug): void
    {
        if (empty($slug)) {
            return;
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare("DELETE FROM {$this->table('cache')} WHERE cache_key LIKE :key");
        $stmt->execute(['key' => '%page_' . $slug . '%']);

        $this->cacheService?->forgetPrefix('page:' . $slug);
    }

    /**
     * @param array<int, string> $slugs
     */
    private function invalidatePageCaches(array $slugs): void
    {
        $uniqueSlugs = array_values(array_unique(array_filter($slugs)));
        if (empty($uniqueSlugs)) {
            return;
        }

        foreach ($uniqueSlugs as $slug) {
            $this->invalidatePageCache($slug);
        }
    }

    private function invalidateComponentCache(string $slug): void
    {
        if (empty($slug)) {
            return;
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare("DELETE FROM {$this->table('cache')} WHERE cache_key LIKE :key");
        $stmt->execute(['key' => '%component_' . $slug . '%']);

        $this->cacheService?->forgetPrefix('component:' . $slug);
        $this->invalidateAllPageCache();
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private function normalizeComponentSnapshot(array $snapshot): array
    {
        $name = (string) ($snapshot['name'] ?? '');
        $slug = (string) ($snapshot['slug'] ?? $this->generateSlug($name ?: 'component'));

        return [
            'name' => $name,
            'slug' => $slug,
            'type' => (string) ($snapshot['type'] ?? 'custom'),
            'description' => $snapshot['description'] ?? '',
            'content' => $snapshot['content'] ?? '',
            'css' => $snapshot['css'] ?? '',
            'javascript' => $snapshot['javascript'] ?? '',
            'cache_ttl' => (int) ($snapshot['cache_ttl'] ?? 0),
            'is_active' => !empty($snapshot['is_active']) ? 1 : 0,
        ];
    }

    private function invalidateTemplateCache(string $slug): void
    {
        if (empty($slug)) {
            return;
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare("DELETE FROM {$this->table('cache')} WHERE cache_key LIKE :key");
        $stmt->execute(['key' => '%template_' . $slug . '%']);

        $this->cacheService?->forgetPrefix('template:' . $slug);
    }

    /**
     * @param array<string, mixed> $page
     */
    private function queuePageRevalidation(array $page): void
    {
        if (!$this->cacheService || !$this->cacheService->shouldPreRenderOnSave()) {
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
        $cache = $this->cacheService;

        $cache->enqueueRevalidation(function () use ($connection, $cache, $path): void {
            $renderingService = new CMSRenderingService($connection, $cache);
            $renderingService->renderPage($path, true);
        });
    }

    /**
     * @param array<int, string> $slugs
     */
    private function queueComponentRevalidation(int $componentId, array $slugs): void
    {
        if (!$this->cacheService || !$this->cacheService->shouldRevalidateOnChange()) {
            return;
        }

        if ($componentId <= 0 && empty($slugs)) {
            return;
        }

        $pages = $this->findPagesUsingComponent($componentId, $slugs);
        foreach ($pages as $page) {
            $this->queuePageRevalidation($page);
        }
    }

    private function buildPagePath(?int $categoryId, string $slug): string
    {
        $normalizedSlug = $this->normalizeSlugPath($slug);
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
            $stmt = $pdo->prepare("SELECT slug, parent_id FROM {$this->table('categories')} WHERE id = :id LIMIT 1");
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

    /**
     * @param array<int, string> $slugs
     * @return array<int, array<string, mixed>>
     */
    private function findPagesUsingComponent(int $componentId, array $slugs): array
    {
        $pdo = $this->connection->pdo();
        $conditions = [
            '(header_component_id = :component_id OR footer_component_id = :component_id)',
        ];
        $params = ['component_id' => $componentId];

        $tagIndex = 0;
        foreach ($slugs as $slug) {
            $slug = trim($slug);
            if ($slug === '') {
                continue;
            }

            $tagKey = 'tag_' . $tagIndex;
            $conditions[] = "content LIKE :{$tagKey}";
            $params[$tagKey] = '%{{component:' . $slug . '}}%';
            $tagIndex++;
        }

        $sql = "SELECT id, slug, category_id, status
            FROM {$this->table('pages')}
            WHERE status = 'published'
            AND (" . implode(' OR ', $conditions) . ')';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);

        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    private function invalidateAllPageCache(): void
    {
        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare("DELETE FROM {$this->table('cache')} WHERE cache_key LIKE :key");
        $stmt->execute(['key' => '%page_%']);

        $this->cacheService?->forgetPrefix('page:');
    private function validateComponentData(array $data): void
    {
        $type = $data['type'] ?? 'custom';
        $content = (string) ($data['content'] ?? '');
        $errors = [];

        $rules = [
            'header' => [
                'cta' => 'Header components require a call-to-action link with text (e.g., <a href="/contact">Contact Us</a>).',
            ],
            'navigation' => [
                'links' => 'Navigation components require at least one link with text (e.g., <a href="/about">About</a>).',
            ],
            'sidebar' => [
                'image' => 'Sidebar components require at least one image URL (e.g., <img src="https://example.com/image.jpg">).',
            ],
            'widget' => [
                'cta' => 'Widget components require a call-to-action link with text (e.g., <a href="/signup">Get Started</a>).',
                'image' => 'Widget components require at least one image URL (e.g., <img src="https://example.com/image.jpg">).',
            ],
            'footer' => [
                'links' => 'Footer components require at least one link with text (e.g., <a href="/privacy">Privacy Policy</a>).',
            ],
            'custom' => [],
        ];

        $typeRules = $rules[$type] ?? [];
        if ($typeRules === []) {
            return;
        }

        $linksWithText = $this->extractLinksWithText($content);
        $imageSources = $this->extractImageSources($content);

        foreach ($typeRules as $rule => $message) {
            if ($rule === 'cta' && count($linksWithText) === 0) {
                $errors[] = $message;
            }

            if ($rule === 'links' && count($linksWithText) === 0) {
                $errors[] = $message;
            }

            if ($rule === 'image' && count($imageSources) === 0) {
                $errors[] = $message;
            }
        }

        if ($errors !== []) {
            throw new \InvalidArgumentException('Component validation failed: ' . implode(' ', $errors));
        }
    }

    /**
     * @return array<int, array{href: string, text: string}>
     */
    private function extractLinksWithText(string $content): array
    {
        $matches = [];
        preg_match_all('/<a[^>]*href=[\'"]([^\'"]+)[\'"][^>]*>(.*?)<\/a>/is', $content, $matches, PREG_SET_ORDER);

        $links = [];
        foreach ($matches as $match) {
            $href = trim($match[1] ?? '');
            $text = trim(strip_tags($match[2] ?? ''));
            if ($href !== '' && $text !== '') {
                $links[] = ['href' => $href, 'text' => $text];
            }
        }

        return $links;
    }

    /**
     * @return array<int, string>
     */
    private function extractImageSources(string $content): array
    {
        $matches = [];
        preg_match_all('/<img[^>]*src=[\'"]([^\'"]+)[\'"][^>]*>/is', $content, $matches);
        $sources = array_filter(array_map('trim', $matches[1] ?? []), static fn ($src) => $src !== '');

        return array_values($sources);
    }
}
