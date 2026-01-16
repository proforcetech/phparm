<?php

namespace App\Services\CMS;

use App\CMS\Models\Component;
use App\CMS\Models\Page;
use App\CMS\Models\Template;
use App\Database\Connection;
use App\Support\Notifications\TemplateEngine;
use App\Services\CMS\CMSAssetBundler;
use PDO;

class CMSRenderingService
{
    private Connection $connection;
    private TemplateEngine $templateEngine;
    private ?CMSCacheService $cache;
    private ?CMSAssetBundler $assetBundler;
    /**
     * @var array{css: array<int, string>, js: array<int, string>}
     */
    private array $componentAssets = ['css' => [], 'js' => []];

    public function __construct(Connection $connection, ?CMSCacheService $cache = null)
    {
        $this->connection = $connection;
        $this->templateEngine = new TemplateEngine();
        $this->cache = $cache;
        $this->assetBundler = $this->initializeAssetBundler();
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
        $this->resetComponentAssets();

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
            $contentData = $this->loadDynamicComponents($data['content'], []);
            if (!empty($contentData)) {
                $data['content'] = $this->templateEngine->render($contentData['__template'], $contentData);
            }
        }

        // Handle dynamic components in template structure
        $data = $this->loadDynamicComponents($template->structure, $data);
        $html = $this->templateEngine->render($data['__template'], $data);

        $bundleTags = $this->buildComponentBundleTags($page);

        return $this->injectAssets(
            $html,
            $page,
            $template->default_css ?? '',
            $page->custom_css ?? '',
            $template->default_js ?? '',
            $page->custom_js ?? '',
            $bundleTags
        );
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

    /**
     * @param array{css?: string, js?: string} $bundleTags
     */
    private function injectAssets(
        string $html,
        Page $page,
        string $templateCss,
        string $pageCss,
        string $templateJs,
        string $pageJs,
        array $bundleTags = []
    ): string
    {
        // Inject meta tags
        $metaTags = '';
        $cssBundleTags = $bundleTags['css'] ?? '';
        $jsBundleTags = $bundleTags['js'] ?? '';

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

        if (trim($cssBundleTags) !== '') {
            $metaTags .= $cssBundleTags;
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
            $jsOutput = $jsBundleTags . $scriptBlock;
        } else {
            $jsOutput = $jsBundleTags;
        }

        if (trim($jsOutput) !== '') {
            $html = (stripos($html, '</body>') !== false)
                ? str_ireplace('</body>', $jsOutput . "\n</body>", $html)
                : $html . $jsOutput;
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
        $this->collectComponentAssets($component);
        $html = $component->content;

        if (!empty($component->css)) {
            $html .= "\n<style>\n" . $component->css . "\n</style>";
        }

        if (!empty($component->javascript)) {
            $html .= "\n<script>\n" . $component->javascript . "\n</script>";
        }

        return $html;
    }

    private function initializeAssetBundler(): ?CMSAssetBundler
    {
        if (!defined('CMS_ASSETS') || !defined('CMS_CACHE')) {
            return null;
        }

        return new CMSAssetBundler(CMS_ASSETS, CMS_CACHE, '/cms/assets');
    }

    private function resetComponentAssets(): void
    {
        $this->componentAssets = ['css' => [], 'js' => []];
    }

    /**
     * @param array{css: array<int, string>, js: array<int, string>} $assets
     */
    private function mergeComponentAssets(array $assets): void
    {
        foreach ($assets as $type => $entries) {
            foreach ($entries as $entry) {
                $this->componentAssets[$type][] = $entry;
            }
        }
    }

    private function collectComponentAssets(Component $component): void
    {
        $this->mergeComponentAssets([
            'css' => $this->parseAssetList($component->css_assets ?? null),
            'js' => $this->parseAssetList($component->js_assets ?? null),
        ]);
    }

    /**
     * @return array<int, string>
     */
    private function parseAssetList(?string $assets): array
    {
        if ($assets === null) {
            return [];
        }

        $assets = trim($assets);
        if ($assets === '') {
            return [];
        }

        $parts = preg_split('/[\r\n,]+/', $assets);
        if ($parts === false) {
            return [];
        }

        $entries = array_map('trim', $parts);
        $entries = array_filter($entries, static fn (string $entry) => $entry !== '');

        return array_values($entries);
    }

    /**
     * @return array{css: string, js: string}
     */
    private function buildComponentBundleTags(Page $page): array
    {
        if ($this->assetBundler === null) {
            return ['css' => '', 'js' => ''];
        }

        $pageKey = $page->slug !== '' ? $page->slug : ('page-' . $page->id);

        return $this->assetBundler->buildAssetTags(
            $pageKey,
            $this->componentAssets['css'],
            $this->componentAssets['js']
        );
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

        if ($page->custom_css) {
            $html .= '<style>' . $page->custom_css . '</style>';
        }

        $html .= '</head><body><main>' . ($page->content ?? '') . '</main>';

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
}
