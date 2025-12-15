<?php

namespace App\Services\CMS;

use App\Database\Connection;
use App\Models\User;
use App\Support\Auth\AccessGate;
use App\Services\CMS\CMSCacheService;
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

    public function __construct(Connection $connection, CMSAuthBridge $authBridge, AccessGate $gate, ?CMSCacheService $cacheService = null)
    {
        $this->connection = $connection;
        $this->authBridge = $authBridge;
        $this->gate = $gate;
        $this->tablePrefix = env('CMS_TABLE_PREFIX', 'cms_');
        $this->cacheService = $cacheService;
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
        $stmt = $pdo->prepare("
            SELECT *
            FROM {$this->table('pages')}
            WHERE slug = :slug AND status = 'published'
        ");
        $stmt->execute(['slug' => $slug]);

        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Create page
     */
    public function createPage(?User $user, array $data): array
    {
        $this->requireEditAccess($user);

        $pdo = $this->connection->pdo();

        // Generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['title'] ?? 'untitled');
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('pages')} (
                title, slug, template_id, status, meta_title, meta_description, meta_keywords,
                summary, content, created_at, updated_at
            ) VALUES (
                :title, :slug, :status, :tempate_id, :meta_title, :meta_description, :meta_keywords,
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
            'summary' => $data['summary'] ?? null,
            'content' => $data['content'] ?? '',
        ]);

        $id = (int) $pdo->lastInsertId();

        return $this->getPage($user, $id);
    }

    /**
     * Update page
     */
    public function updatePage(?User $user, int $id, array $data): array
    {
        $this->requireEditAccess($user);

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
            'summary' => $data['summary'] ?? null,
            'content' => $data['content'] ?? '',
        ]);

        // Invalidate cache
        $this->invalidatePageCache($data['slug'] ?? '');

        return $this->getPage($user, $id);
    }

    /**
     * Publish page (sets status to 'published')
     */
    public function publishPage(?User $user, int $id): array
    {
        $this->requireEditAccess($user);

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

        return $this->getPage($user, $id);
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
            $this->invalidatePageCache($page['slug']);
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

        // Generate slug if not provided
        if (empty($data['slug'])) {
            $data['slug'] = $this->generateSlug($data['name'] ?? 'untitled');
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$this->table('components')} (
                name, slug, type, description, content, css, javascript,
                cache_ttl, is_active, created_by, updated_by, created_at, updated_at
            ) VALUES (
                :name, :slug, :type, :description, :content, :css, :javascript,
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
            'cache_ttl' => (int) ($data['cache_ttl'] ?? 3600),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $id = (int) $pdo->lastInsertId();

        return $this->getComponent($user, $id);
    }

    /**
     * Update component
     */
    public function updateComponent(?User $user, int $id, array $data): array
    {
        $this->requireEditAccess($user);

        $pdo = $this->connection->pdo();

        $stmt = $pdo->prepare("
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
            'name' => $data['name'] ?? '',
            'slug' => $data['slug'] ?? '',
            'type' => $data['type'] ?? 'custom',
            'description' => $data['description'] ?? '',
            'content' => $data['content'] ?? '',
            'css' => $data['css'] ?? '',
            'javascript' => $data['javascript'] ?? '',
            'cache_ttl' => (int) ($data['cache_ttl'] ?? 3600),
            'is_active' => !empty($data['is_active']) ? 1 : 0,
            'updated_by' => $user->id,
        ]);

        // Invalidate cache
        $this->invalidateComponentCache($data['slug'] ?? '');

        return $this->getComponent($user, $id);
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

        $pages = $pdo->query("
            SELECT id, title, slug FROM {$this->table('pages')}
            ORDER BY title
        ")->fetchAll(PDO::FETCH_ASSOC);

        return [
            'templates' => $templates,
            'header_components' => $headerComponents,
            'footer_components' => $footerComponents,
            'parent_pages' => $pages,
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

    private function invalidateComponentCache(string $slug): void
    {
        if (empty($slug)) {
            return;
        }

        $pdo = $this->connection->pdo();
        $stmt = $pdo->prepare("DELETE FROM {$this->table('cache')} WHERE cache_key LIKE :key");
        $stmt->execute(['key' => '%component_' . $slug . '%']);

        $this->cacheService?->forgetPrefix('component:' . $slug);
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
}
