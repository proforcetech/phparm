<?php

namespace App\Services\CMS;

use App\CMS\Models\Component;
use App\CMS\Models\Page;
use App\CMS\Models\Template;
use App\Database\Connection;
use App\Support\Notifications\TemplateEngine;
use PDO;

class CMSRenderingService
{
    private Connection $connection;
    private TemplateEngine $templateEngine;
    private ?CMSCacheService $cache;

    public function __construct(Connection $connection, ?CMSCacheService $cache = null)
    {
        $this->connection = $connection;
        $this->templateEngine = new TemplateEngine();
        $this->cache = $cache;
    }

    /**
     * Render a published page by its slug
     *
     * @param string $slug
     * @return string|null Rendered HTML or null if page not found
     */
    public function renderPage(string $slug): ?string
    {
        $cacheKey = 'page:rendered:' . $slug;

        // Try to get from cache
        if ($this->cache) {
            $cached = $this->cache->get($cacheKey);
            if ($cached !== null) {
                return $cached;
            }
        }

        // Load the page
        $page = $this->loadPublishedPageBySlug($slug);
        if ($page === null) {
            return null;
        }

        // Render the page
        $html = $this->renderPageContent($page);

        // Cache the result
        if ($this->cache && $html !== null) {
            $this->cache->set($cacheKey, $html, 3600); // Cache for 1 hour
        }

        return $html;
    }

    /**
     * Render a page object (for preview or admin use)
     *
     * @param Page $page
     * @return string|null Rendered HTML
     */
    public function renderPageContent(Page $page): ?string
    {
        // If no template is assigned, just return the content wrapped in basic HTML
        if ($page->template_id === null) {
            return $this->renderBasicPage($page);
        }

        // Load the template
        $template = $this->loadTemplate($page->template_id);
        if ($template === null || $template->structure === null) {
            return $this->renderBasicPage($page);
        }

        // Load components
        $header = $page->header_component_id ? $this->loadComponent($page->header_component_id) : null;
        $footer = $page->footer_component_id ? $this->loadComponent($page->footer_component_id) : null;

        // Build placeholder data
        $data = $this->buildPlaceholderData($page, $template, $header, $footer);

        // Load and render dynamic components ({{component:slug}})
        $data = $this->loadDynamicComponents($template->structure, $data);

        // Render the template structure
        $html = $this->templateEngine->render($template->structure, $data);

        // Inject Page and Template assets (CSS/JS) automatically
        // This ensures styles are loaded even if the template doesn't have {{custom_css}} placeholders
        $css = "/* Template CSS */\n" . ($template->default_css ?? '') . "\n/* Page Custom CSS */\n" . ($page->custom_css ?? '');
        $js = "/* Template JS */\n" . ($template->default_js ?? '') . "\n/* Page Custom JS */\n" . ($page->custom_js ?? '');

        return $this->injectAssets($html, $css, $js);
    }

    /**
     * Helper to inject CSS into <head> and JS before </body>
     * * @param string $html
     * @param string $css
     * @param string $js
     * @return string
     */
    private function injectAssets(string $html, string $css, string $js): string
    {
        // Inject CSS
        if (trim($css) !== '') {
            $styleBlock = "<style>\n" . $css . "\n</style>";
            // Try to inject before closing head, otherwise prepend to HTML
            if (stripos($html, '</head>') !== false) {
                $html = str_ireplace('</head>', $styleBlock . "\n</head>", $html);
            } else {
                $html = $styleBlock . $html;
            }
        }

        // Inject JS
        if (trim($js) !== '') {
            $scriptBlock = "<script>\n" . $js . "\n</script>";
            // Try to inject before closing body, otherwise append to HTML
            if (stripos($html, '</body>') !== false) {
                $html = str_ireplace('</body>', $scriptBlock . "\n</body>", $html);
            } else {
                $html .= $scriptBlock;
            }
        }

        return $html;
    }

    /**
     * Load a published page by slug
     *
     * @param string $slug
     * @return Page|null
     */
    private function loadPublishedPageBySlug(string $slug): ?Page
    {
        $sql = 'SELECT * FROM cms_pages
                WHERE slug = :slug
                AND status = "published"
                AND (publish_start_at IS NULL OR publish_start_at <= NOW())
                AND (publish_end_at IS NULL OR publish_end_at >= NOW())
                LIMIT 1';

        $stmt = $this->connection->pdo()->prepare($sql);
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapPage($row);
    }

    /**
     * Load a template by ID
     *
     * @param int $id
     * @return Template|null
     */
    private function loadTemplate(int $id): ?Template
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_templates WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapTemplate($row);
    }

    /**
     * Load a component by ID
     *
     * @param int $id
     * @return Component|null
     */
    private function loadComponent(int $id): ?Component
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_components WHERE id = :id AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapComponent($row);
    }

    /**
     * Load a component by slug
     *
     * @param string $slug
     * @return Component|null
     */
    private function loadComponentBySlug(string $slug): ?Component
    {
        $stmt = $this->connection->pdo()->prepare(
            'SELECT * FROM cms_components WHERE slug = :slug AND is_active = 1 LIMIT 1'
        );
        $stmt->execute(['slug' => $slug]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        return $this->mapComponent($row);
    }

    /**
     * Load and render dynamic components from template
     * Finds all {{component:slug}} placeholders and loads the components
     *
     * @param string $template
     * @param array<string, string> $data
     * @return array<string, string>
     */
    private function loadDynamicComponents(string $template, array $data): array
    {
        // Extract all component slugs from {{component:slug}} patterns
        $componentSlugs = $this->extractComponentSlugs($template);

        // Load and render each component
        foreach ($componentSlugs as $slug) {
            $component = $this->loadComponentBySlug($slug);
            $placeholderKey = 'component:' . $slug;

            if ($component !== null) {
                $data[$placeholderKey] = $this->renderComponent($component);
            } else {
                // Component not found, replace with empty string or comment
                $data[$placeholderKey] = '';
            }
        }

        return $data;
    }

    /**
     * Extract component slugs from template
     * Finds all {{component:slug}} patterns
     *
     * @param string $template
     * @return array<string>
     */
    private function extractComponentSlugs(string $template): array
    {
        $slugs = [];

        // Match all {{component:slug}} patterns
        if (preg_match_all('/\{\{component:([a-zA-Z0-9_-]+)\}\}/', $template, $matches)) {
            $slugs = array_unique($matches[1]);
        }

        return $slugs;
    }

    /**
     * Build placeholder data for template rendering
     *
     * @param Page $page
     * @param Template $template
     * @param Component|null $header
     * @param Component|null $footer
     * @return array<string, string>
     */
    private function buildPlaceholderData(
        Page $page,
        Template $template,
        ?Component $header,
        ?Component $footer
    ): array {
        $data = [
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
            'header' => '',
            'footer' => '',
        ];

        // Render header component
        if ($header !== null) {
            $data['header'] = $this->renderComponent($header);
        }

        // Render footer component
        if ($footer !== null) {
            $data['footer'] = $this->renderComponent($footer);
        }

        return $data;
    }

    /**
     * Render a component with its CSS and JavaScript
     *
     * @param Component $component
     * @return string
     */
    private function renderComponent(Component $component): string
    {
        $html = $component->content;

        // Add component CSS if present
        if ($component->css !== null && trim($component->css) !== '') {
            $html .= "\n<style>\n" . $component->css . "\n</style>";
        }

        // Add component JavaScript if present
        if ($component->javascript !== null && trim($component->javascript) !== '') {
            $html .= "\n<script>\n" . $component->javascript . "\n</script>";
        }

        return $html;
    }

    /**
     * Generate breadcrumb navigation for a page
     *
     * @param Page $page
     * @return string
     */
    private function generateBreadcrumbs(Page $page): string
    {
        // Simple breadcrumb: Home > Page Title
        $breadcrumbs = '<nav class="breadcrumbs">';
        $breadcrumbs .= '<a href="/">Home</a>';
        $breadcrumbs .= ' &raquo; ';
        $breadcrumbs .= '<span>' . htmlspecialchars($page->title) . '</span>';
        $breadcrumbs .= '</nav>';

        return $breadcrumbs;
    }

    /**
     * Render a basic page without a template
     *
     * @param Page $page
     * @return string
     */
    private function renderBasicPage(Page $page): string
    {
        $html = '<!DOCTYPE html>';
        $html .= '<html lang="en">';
        $html .= '<head>';
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

        $html .= '</head>';
        $html .= '<body>';
        $html .= '<main>';
        $html .= $page->content ?? '';
        $html .= '</main>';

        if ($page->custom_js) {
            $html .= '<script>' . $page->custom_js . '</script>';
        }

        $html .= '</body>';
        $html .= '</html>';

        return $html;
    }

    /**
     * Map database row to Page object
     *
     * @param array<string, mixed> $row
     * @return Page
     */
    private function mapPage(array $row): Page
    {
        return new Page([
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'slug' => (string) $row['slug'],
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
     * Map database row to Template object
     *
     * @param array<string, mixed> $row
     * @return Template
     */
    private function mapTemplate(array $row): Template
    {
        return new Template([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'description' => $row['description'] ?? null,
            'structure' => $row['structure'] ?? null,
            'default_css' => $row['default_css'] ?? null,
            'default_js' => $row['default_js'] ?? null,
            'is_active' => (bool) $row['is_active'],
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }

    /**
     * Map database row to Component object
     *
     * @param array<string, mixed> $row
     * @return Component
     */
    private function mapComponent(array $row): Component
    {
        return new Component([
            'id' => (int) $row['id'],
            'name' => (string) $row['name'],
            'slug' => (string) $row['slug'],
            'type' => (string) $row['type'],
            'description' => $row['description'] ?? null,
            'content' => (string) $row['content'],
            'css' => $row['css'] ?? null,
            'javascript' => $row['javascript'] ?? null,
            'is_active' => (bool) $row['is_active'],
            'cache_ttl' => (int) ($row['cache_ttl'] ?? 3600),
            'created_by' => isset($row['created_by']) ? (int) $row['created_by'] : null,
            'updated_by' => isset($row['updated_by']) ? (int) $row['updated_by'] : null,
            'created_at' => $row['created_at'] ?? null,
            'updated_at' => $row['updated_at'] ?? null,
        ]);
    }
}
