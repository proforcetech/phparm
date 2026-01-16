<?php

namespace App\Services\CMS;

use App\CMS\Models\Component;
use App\CMS\Models\Page;
use App\CMS\Models\Template;
use App\Database\Connection;
use App\Services\Dispatch\TrafficAwareEtaService;
use App\Support\Notifications\TemplateEngine;
use PDO;

class CMSRenderingService
{
    private Connection $connection;
    private TemplateEngine $templateEngine;
    private ?CMSCacheService $cache;
    private CMSDynamicComponentService $dynamicComponents;

    public function __construct(Connection $connection, ?CMSCacheService $cache = null)
    {
        $this->connection = $connection;
        $this->templateEngine = new TemplateEngine();
        $this->cache = $cache;
        $this->dynamicComponents = new CMSDynamicComponentService(
            $connection,
            new TrafficAwareEtaService($connection, $this->loadDispatchConfig()['eta'] ?? [])
        );
    }

    public function renderPage(string $slug): ?string
    {
        $cacheKey = 'page:rendered:' . $slug;

        try {
            if ($this->cache) {
                $cached = $this->cache->get($cacheKey);
                if ($cached !== null) {
                    return $cached;
                }
            }

            $page = $this->loadPublishedPageBySlug($slug);
            if ($page === null) {
                return null;
            }

            $html = $this->renderPageContent($page);

            if ($this->cache && $html !== null) {
                $this->cache->set($cacheKey, $html, 3600);
            }

            return $html;
        } catch (\Throwable $exception) {
            error_log(sprintf(
                'CMS renderPage failed for slug "%s": %s',
                $slug,
                $exception->getMessage()
            ));
            return null;
        }
    }

    public function renderPageContent(Page $page): ?string
    {
        if ($page->template_id === null) {
            return $this->renderBasicPage($page);
        }

        $template = $this->loadTemplate($page->template_id);
        if ($template === null || $template->structure === null) {
            return $this->renderBasicPage($page);
        }

        $header = $page->header_component_id ? $this->loadComponent($page->header_component_id) : null;
        $footer = $page->footer_component_id ? $this->loadComponent($page->footer_component_id) : null;

        $data = $this->buildPlaceholderData($page, $template, $header, $footer);

        // Handle dynamic components in page content
        if (!empty($data['content'])) {
            $data['content'] = $this->hydrateInternalLinks($data['content']);
            $contentData = $this->loadDynamicComponents($data['content'], []);
            if (!empty($contentData)) {
                $data['content'] = $this->templateEngine->render($contentData['__template'], $contentData);
            }
        }

        // Handle dynamic components in template structure
        $data = $this->loadDynamicComponents($template->structure, $data);
        $html = $this->templateEngine->render($data['__template'], $data);

        return $this->injectAssets($html, $page, $template->default_css ?? '', $page->custom_css ?? '', $template->default_js ?? '', $page->custom_js ?? '');
    }

private function loadDynamicComponents(string $template, array $data): array
{
    $slugs = $this->extractComponentSlugs($template);

    foreach ($slugs as $slug) {
        $normalizedKey = 'component_' . str_replace('-', '_', $slug);
        $placeholder = '{{component:' . $slug . '}}';
        $component = $this->loadComponentBySlug($slug);

        if ($component !== null) {
            $data[$normalizedKey] = $this->renderComponent($component);
            $template = str_replace($placeholder, '{{' . $normalizedKey . '}}', $template);
        } else {
            $data[$normalizedKey] = '';
            $template = str_replace($placeholder, '', $template);
        }
    }

    $data['__template'] = $template;

    return $data;
}

private function extractComponentSlugs(string $template): array
{
    $slugs = [];
    if (preg_match_all('/\{\{component:([a-zA-Z0-9_-]+)\}\}/', $template, $matches)) {
        $slugs = array_unique($matches[1]);
    }
    return $slugs;
}

    private function injectAssets(string $html, Page $page, string $templateCss, string $pageCss, string $templateJs, string $pageJs): string
    {
        // Inject meta tags
        $metaTags = '';

        // Add title tag if not already present in the HTML
        if (stripos($html, '<title>') === false) {
            $metaTags .= '<title>' . htmlspecialchars($page->meta_title ?? $page->title) . '</title>' . "\n";
        }

        // Add meta description
        if ($page->meta_description) {
            $metaTags .= '<meta name="description" content="' . htmlspecialchars($page->meta_description) . '">' . "\n";
        }

        // Add meta keywords
        if ($page->meta_keywords) {
            $metaTags .= '<meta name="keywords" content="' . htmlspecialchars($page->meta_keywords) . '">' . "\n";
        }

        if ($page->canonical_url && !$this->hasCanonicalLink($html)) {
            $metaTags .= '<link rel="canonical" href="' . htmlspecialchars($page->canonical_url) . '">' . "\n";
        }

        $hasOpenGraph = $page->og_title || $page->og_description || $page->og_image || $page->og_type || $page->og_url;
        if ($hasOpenGraph) {
            $ogTitle = $page->og_title ?? $page->meta_title ?? $page->title;
            $ogDescription = $page->og_description ?? $page->meta_description;
            $ogImage = $page->og_image;
            $ogType = $page->og_type;
            $ogUrl = $page->og_url ?? $page->canonical_url;

            if ($ogTitle && !$this->hasMetaProperty($html, 'og:title')) {
                $metaTags .= '<meta property="og:title" content="' . htmlspecialchars($ogTitle) . '">' . "\n";
            }

            if ($ogDescription && !$this->hasMetaProperty($html, 'og:description')) {
                $metaTags .= '<meta property="og:description" content="' . htmlspecialchars($ogDescription) . '">' . "\n";
            }

            if ($ogImage && !$this->hasMetaProperty($html, 'og:image')) {
                $metaTags .= '<meta property="og:image" content="' . htmlspecialchars($ogImage) . '">' . "\n";
            }

            if ($ogType && !$this->hasMetaProperty($html, 'og:type')) {
                $metaTags .= '<meta property="og:type" content="' . htmlspecialchars($ogType) . '">' . "\n";
            }

            if ($ogUrl && !$this->hasMetaProperty($html, 'og:url')) {
                $metaTags .= '<meta property="og:url" content="' . htmlspecialchars($ogUrl) . '">' . "\n";
            }
        }

        // Inject CSS
        $css = '';
        if ($templateCss) $css .= "/* Template CSS */\n" . $templateCss . "\n";
        if ($pageCss) $css .= "/* Page CSS */\n" . $pageCss;

        if (trim($css) !== '') {
            $styleBlock = "<style>\n" . $css . "\n</style>";
            $metaTags .= $styleBlock . "\n";
        }

        // Inject meta tags and CSS into <head>
        if (trim($metaTags) !== '') {
            $html = (stripos($html, '</head>') !== false)
                ? str_ireplace('</head>', $metaTags . '</head>', $html)
                : $metaTags . $html;
        }

        // Inject JS
        $js = '';
        if ($templateJs) $js .= "/* Template JS */\n" . $templateJs . "\n";
        if ($pageJs) $js .= "/* Page JS */\n" . $pageJs;

        if (trim($js) !== '') {
            $scriptBlock = "<script>\n" . $js . "\n</script>";
            $html = (stripos($html, '</body>') !== false)
                ? str_ireplace('</body>', $scriptBlock . "\n</body>", $html)
                : $html . $scriptBlock;
        }

        return $html;
    }

    private function buildPlaceholderData(Page $page, Template $template, ?Component $header, ?Component $footer): array
    {
        return [
            'title' => $page->title,
            'content' => $page->content ?? '',
            'summary' => $page->summary ?? '',
            'meta_title' => $page->meta_title ?? $page->title,
            'meta_description' => $page->meta_description ?? '',
            'meta_keywords' => $page->meta_keywords ?? '',
            'canonical_url' => $page->canonical_url ?? '',
            'og_title' => $page->og_title ?? '',
            'og_description' => $page->og_description ?? '',
            'og_image' => $page->og_image ?? '',
            'og_type' => $page->og_type ?? '',
            'og_url' => $page->og_url ?? '',
            'slug' => $page->slug,
            'year' => date('Y'),
            'breadcrumbs' => $this->generateBreadcrumbs($page),
            'default_css' => $template->default_css ?? '',
            'custom_css' => $page->custom_css ?? '',
            'default_js' => $template->default_js ?? '',
            'custom_js' => $page->custom_js ?? '',
            'header' => $header ? $this->renderComponent($header) : '',
            'footer' => $footer ? $this->renderComponent($footer) : '',
        ];
    }

    private function renderComponent(Component $component): string
    {
        if ($this->isDynamicComponent($component)) {
            return $this->renderDynamicComponent($component);
        }

        $html = $component->content;

        if (!empty($component->css)) {
            $html .= "\n<style>\n" . $component->css . "\n</style>";
        }

        if (!empty($component->javascript)) {
            $html .= "\n<script>\n" . $component->javascript . "\n</script>";
        }

        return $html;
    }

    private function renderDynamicComponent(Component $component): string
    {
        $cacheKey = null;
        $ttl = $this->resolveDynamicCacheTtl($component);

        if ($this->cache && $ttl > 0) {
            $cacheKey = $this->cache->buildKey(
                'component',
                $component->slug . ':dynamic:' . md5($component->content ?? ''),
                'default',
                'html'
            );
            $cached = $this->cache->get($cacheKey);
            if (is_string($cached)) {
                return $cached;
            }
        }

        $html = $this->dynamicComponents->render($component) ?? $component->content;

        if (!empty($component->css)) {
            $html .= "\n<style>\n" . $component->css . "\n</style>";
        }

        if (!empty($component->javascript)) {
            $html .= "\n<script>\n" . $component->javascript . "\n</script>";
        }

        if ($this->cache && $ttl > 0 && $cacheKey !== null) {
            $this->cache->set($cacheKey, $html, $ttl);
        }

        return $html;
    }

    private function isDynamicComponent(Component $component): bool
    {
        return in_array($component->type, ['live_coverage_map', 'eta', 'estimated_wait_time'], true);
    }

    private function resolveDynamicCacheTtl(Component $component): int
    {
        $ttl = (int) ($component->cache_ttl ?? 0);

        if ($ttl <= 0) {
            return 0;
        }

        return min($ttl, 300);
    }

    private function renderBasicPage(Page $page): string
    {
        $html = '<!DOCTYPE html><html lang="en"><head>';
        $html .= '<meta charset="UTF-8">';
        $html .= '<meta name="viewport" content="width=device-width, initial-scale=1.0">';
        $html .= '<title>' . htmlspecialchars($page->meta_title ?? $page->title) . '</title>';

        if ($page->meta_description) {
            $html .= '<meta name="description" content="' . htmlspecialchars($page->meta_description) . '">';
        }

        if ($page->meta_keywords) {
            $html .= '<meta name="keywords" content="' . htmlspecialchars($page->meta_keywords) . '">';
        }

        if ($page->canonical_url) {
            $html .= '<link rel="canonical" href="' . htmlspecialchars($page->canonical_url) . '">';
        }

        $hasOpenGraph = $page->og_title || $page->og_description || $page->og_image || $page->og_type || $page->og_url;
        if ($hasOpenGraph) {
            $ogTitle = $page->og_title ?? $page->meta_title ?? $page->title;
            $ogDescription = $page->og_description ?? $page->meta_description;
            $ogImage = $page->og_image;
            $ogType = $page->og_type;
            $ogUrl = $page->og_url ?? $page->canonical_url;

            if ($ogTitle) {
                $html .= '<meta property="og:title" content="' . htmlspecialchars($ogTitle) . '">';
            }

            if ($ogDescription) {
                $html .= '<meta property="og:description" content="' . htmlspecialchars($ogDescription) . '">';
            }

            if ($ogImage) {
                $html .= '<meta property="og:image" content="' . htmlspecialchars($ogImage) . '">';
            }

            if ($ogType) {
                $html .= '<meta property="og:type" content="' . htmlspecialchars($ogType) . '">';
            }

            if ($ogUrl) {
                $html .= '<meta property="og:url" content="' . htmlspecialchars($ogUrl) . '">';
            }
        }

        if ($page->custom_css) {
            $html .= '<style>' . $page->custom_css . '</style>';
        }

        $content = $this->hydrateInternalLinks($page->content ?? '');
        $html .= '</head><body><main>' . $content . '</main>';

        if ($page->custom_js) {
            $html .= '<script>' . $page->custom_js . '</script>';
        }

        $html .= '</body></html>';

        return $html;
    }

    private function generateBreadcrumbs(Page $page): string
    {
        return '<nav class="breadcrumbs"><a href="/">Home</a> &raquo; <span>' . htmlspecialchars($page->title) . '</span></nav>';
    }

    private function hasMetaProperty(string $html, string $property): bool
    {
        return preg_match('/<meta[^>]+property=[\'"]' . preg_quote($property, '/') . '[\'"]/i', $html) === 1;
    }

    private function hasCanonicalLink(string $html): bool
    {
        return preg_match('/<link[^>]+rel=[\'"]canonical[\'"]/i', $html) === 1;
    private function hydrateInternalLinks(string $content): string
    {
        if ($content === '' || stripos($content, 'data-cms-page-id') === false) {
            return $content;
        }

        if (!preg_match_all('/data-cms-page-id=["\'](\d+)["\']/', $content, $matches)) {
            return $content;
        }

        $pageIds = array_values(array_unique(array_map('intval', $matches[1])));
        if (empty($pageIds)) {
            return $content;
        }

        $placeholders = implode(',', array_fill(0, count($pageIds), '?'));
        $stmt = $this->connection->pdo()->prepare(
            "SELECT id, slug, category_id FROM cms_pages WHERE id IN ({$placeholders})"
        );
        $stmt->execute($pageIds);
        $pages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$pages) {
            return $content;
        }

        $pageMap = [];
        foreach ($pages as $page) {
            $pageMap[(int) $page['id']] = $page;
        }

        $categoryPathCache = [];

        return preg_replace_callback(
            '/<a\s+[^>]*data-cms-page-id=["\'](\d+)["\'][^>]*>/i',
            function (array $matches) use ($pageMap, &$categoryPathCache): string {
                $pageId = (int) $matches[1];
                if (!isset($pageMap[$pageId])) {
                    return $matches[0];
                }

                $url = $this->buildPageUrl($pageMap[$pageId], $categoryPathCache);
                if ($url === null) {
                    return $matches[0];
                }

                return $this->replaceAnchorHref($matches[0], $url);
            },
            $content
        );
    }

    /**
     * @param array<string, mixed> $page
     * @param array<int, array<int, string>|null> $categoryPathCache
     */
    private function buildPageUrl(array $page, array &$categoryPathCache): ?string
    {
        $slug = $page['slug'] ?? null;
        if (!$slug) {
            return null;
        }

        $categoryId = isset($page['category_id']) ? (int) $page['category_id'] : null;
        if ($categoryId === null) {
            return '/' . $slug;
        }

        $categoryPath = $this->resolveCategorySlugPath($categoryId, $categoryPathCache);
        if (empty($categoryPath)) {
            return '/' . $slug;
        }

        return '/' . implode('/', $categoryPath) . '/' . $slug;
    }

    /**
     * @param array<int, array<int, string>|null> $categoryPathCache
     * @return array<int, string>|null
     */
    private function resolveCategorySlugPath(int $categoryId, array &$categoryPathCache): ?array
    {
        if (array_key_exists($categoryId, $categoryPathCache)) {
            return $categoryPathCache[$categoryId];
        }

        $segments = [];
        $currentId = $categoryId;

        while ($currentId !== null) {
            $stmt = $this->connection->pdo()->prepare(
                'SELECT id, slug, parent_id FROM cms_categories WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $currentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $categoryPathCache[$categoryId] = null;
                return null;
            }

            $segments[] = $row['slug'];
            $currentId = $row['parent_id'] ? (int) $row['parent_id'] : null;
        }

        $segments = array_reverse($segments);
        $categoryPathCache[$categoryId] = $segments;

        return $segments;
    }

    private function replaceAnchorHref(string $anchorTag, string $url): string
    {
        $safeUrl = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');

        if (preg_match('/href=["\'][^"\']*["\']/', $anchorTag)) {
            return preg_replace('/href=["\'][^"\']*["\']/', 'href="' . $safeUrl . '"', $anchorTag, 1);
        }

        return rtrim($anchorTag, '>') . ' href="' . $safeUrl . '">';
    }

    private function loadPublishedPageBySlug(string $slug): ?Page
    {
        $normalized = $this->normalizeSlugPath($slug);
        if ($normalized === '') {
            return null;
        }

        $parts = explode('/', $normalized);

        if (count($parts) > 1) {
            $pageSlug = array_pop($parts);
            $categoryId = $this->resolveCategoryPath($parts);

            if ($categoryId === null) {
                return null;
            }

            $sql = 'SELECT * FROM cms_pages WHERE slug = :slug AND category_id = :category_id AND status = "published"
                AND (publish_start_at IS NULL OR publish_start_at <= NOW())
                AND (publish_end_at IS NULL OR publish_end_at >= NOW()) LIMIT 1';

            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute([
                'slug' => $pageSlug,
                'category_id' => $categoryId,
            ]);
        } else {
            $sql = 'SELECT * FROM cms_pages WHERE slug = :slug AND status = "published"
                AND category_id IS NULL
                AND (publish_start_at IS NULL OR publish_start_at <= NOW())
                AND (publish_end_at IS NULL OR publish_end_at >= NOW()) LIMIT 1';

            $stmt = $this->connection->pdo()->prepare($sql);
            $stmt->execute(['slug' => $normalized]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapPage($row) : null;
    }

    private function normalizeSlugPath(string $slug): string
    {
        return ltrim(trim($slug), '/');
    }

    /**
     * @param array<int, string> $segments
     */
    private function resolveCategoryPath(array $segments): ?int
    {
        $pdo = $this->connection->pdo();
        $parentId = null;

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

    private function loadTemplate(int $id): ?Template
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_templates WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapTemplate($row) : null;
    }

    private function loadComponentBySlug(string $slug): ?Component
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_components WHERE slug = :slug AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapComponent($row) : null;
    }

    private function loadComponent(int $id): ?Component
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_components WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapComponent($row) : null;
    }

    private function mapPage(array $row): Page
    {
        return new Page($row);
    }

    private function mapTemplate(array $row): Template
    {
        return new Template($row);
    }

    private function mapComponent(array $row): Component
    {
        return new Component($row);
    }

    private function loadDispatchConfig(): array
    {
        $configPath = __DIR__ . '/../../../config/dispatch.php';

        if (!is_file($configPath)) {
            return [];
        }

        $config = require $configPath;
        return is_array($config) ? $config : [];
    }
}
