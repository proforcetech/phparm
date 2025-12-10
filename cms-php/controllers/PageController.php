<?php
/**
 * Page Controller
 * Handles front-end page rendering with caching
 * FixItForUs CMS
 */

namespace CMS\Controllers;

use CMS\Models\Page;
use CMS\Models\Component;
use CMS\Models\Template;
use CMS\Models\Cache;
use CMS\Models\Setting;

class PageController
{
    private Page $pageModel;
    private Component $componentModel;
    private Template $templateModel;
    private Cache $cacheModel;
    private Setting $settingModel;

    public function __construct()
    {
        $this->pageModel = new Page();
        $this->componentModel = new Component();
        $this->templateModel = new Template();
        $this->cacheModel = new Cache();
        $this->settingModel = new Setting();
    }

    /**
     * Render a page by slug
     */
    public function render(string $slug): void
    {
        // Check cache first
        $cached = $this->cacheModel->getPage($slug);
        if ($cached !== null) {
            echo $cached;
            return;
        }

        // Get page data
        $page = $this->pageModel->getPublishedBySlug($slug);

        if (!$page) {
            $this->render404();
            return;
        }

        // Render the page
        $output = $this->renderPage($page);

        // Cache the result if TTL > 0
        if (($page['cache_ttl'] ?? 0) > 0) {
            $this->cacheModel->cachePage($slug, $output, $page['cache_ttl']);
        }

        echo $output;
    }

    /**
     * Render the homepage
     */
    public function renderHome(): void
    {
        $this->render('home');
    }

    /**
     * Render 404 page
     */
    public function render404(): void
    {
        http_response_code(404);

        // Try to find a custom 404 page
        $page = $this->pageModel->getPublishedBySlug('404');

        if ($page) {
            echo $this->renderPage($page);
        } else {
            // Default 404
            $this->renderDefault404();
        }
    }

    /**
     * Default 404 page
     */
    private function renderDefault404(): void
    {
        $siteName = $this->settingModel->get('site_name', 'FixItForUs');

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Page Not Found | {$siteName}</title>
    <style>
        body {
            font-family: -apple-system, BlinkMacSystemFont, sans-serif;
            background: #0d0f12;
            color: #f5f5f5;
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            margin: 0;
            text-align: center;
        }
        h1 { font-size: 4rem; color: #ff6b2c; margin: 0; }
        p { color: #a0a0a0; }
        a { color: #ff6b2c; }
    </style>
</head>
<body>
    <div>
        <h1>404</h1>
        <p>The page you're looking for doesn't exist.</p>
        <a href="/">Go Home</a>
    </div>
</body>
</html>
HTML;
    }

    /**
     * Render a complete page
     */
    private function renderPage(array $page): string
    {
        // Get template structure
        $template = $page['template_structure'] ?? $this->getDefaultTemplate();

        // Get default header and footer slugs from settings
        $defaultHeader = $this->settingModel->get('default_header_component', 'main-header');
        $defaultFooter = $this->settingModel->get('default_footer_component', 'main-footer');

        // Render header (use page override or default)
        $headerContent = '';
        if (!empty($page['header_content'])) {
            $headerContent = $this->wrapComponentOutput(
                $page['header_content'],
                $page['header_css'] ?? '',
                $page['header_js'] ?? ''
            );
        } else {
            $headerContent = $this->componentModel->render($defaultHeader);
        }

        // Render footer (use page override or default)
        $footerContent = '';
        if (!empty($page['footer_content'])) {
            $footerContent = $this->wrapComponentOutput(
                $page['footer_content'],
                $page['footer_css'] ?? '',
                $page['footer_js'] ?? ''
            );
        } else {
            $footerContent = $this->componentModel->render($defaultFooter);
        }

        // Process page content (replace component placeholders)
        $content = $this->processContent($page['content']);

        // Render breadcrumbs
        $breadcrumbs = $this->renderBreadcrumbs($page);

        // Replace all placeholders in template
        $output = $template;
        $replacements = [
            '{{title}}' => e($page['title']),
            '{{meta_description}}' => e($page['meta_description'] ?? ''),
            '{{meta_keywords}}' => e($page['meta_keywords'] ?? ''),
            '{{header}}' => $headerContent,
            '{{footer}}' => $footerContent,
            '{{content}}' => $content,
            '{{breadcrumbs}}' => $breadcrumbs,
            '{{default_css}}' => $page['default_css'] ?? '',
            '{{default_js}}' => $page['default_js'] ?? '',
            '{{custom_css}}' => $page['custom_css'] ?? '',
            '{{custom_js}}' => $page['custom_js'] ?? '',
        ];

        foreach ($replacements as $placeholder => $value) {
            $output = str_replace($placeholder, $value, $output);
        }

        return $output;
    }

    /**
     * Wrap component output with CSS and JS
     */
    private function wrapComponentOutput(string $content, string $css = '', string $js = ''): string
    {
        $output = '';

        if (!empty($css)) {
            $output .= '<style>' . $css . '</style>';
        }

        $output .= $content;

        if (!empty($js)) {
            $output .= '<script>' . $js . '</script>';
        }

        return $output;
    }

    /**
     * Process content and replace component placeholders
     * Syntax: {{component:slug}}
     */
    private function processContent(string $content): string
    {
        return preg_replace_callback(
            '/\{\{component:([a-z0-9-_]+)\}\}/i',
            function ($matches) {
                $slug = $matches[1];

                // Check component cache
                $cached = $this->cacheModel->getComponent($slug);
                if ($cached !== null) {
                    return $cached;
                }

                // Render component
                $rendered = $this->componentModel->render($slug);

                // Cache the component
                $component = $this->componentModel->getActiveBySlug($slug);
                if ($component && ($component['cache_ttl'] ?? 0) > 0) {
                    $this->cacheModel->cacheComponent($slug, $rendered, $component['cache_ttl']);
                }

                return $rendered;
            },
            $content
        );
    }

    /**
     * Render breadcrumbs
     */
    private function renderBreadcrumbs(array $page): string
    {
        // Check if page has custom breadcrumbs
        if (!empty($page['breadcrumbs'])) {
            $breadcrumbs = json_decode($page['breadcrumbs'], true);
        } else {
            // Auto-generate from page hierarchy
            $breadcrumbs = $this->pageModel->getHierarchy($page['id']);
        }

        if (empty($breadcrumbs)) {
            return '';
        }

        $items = ['<a href="/">Home</a>'];

        foreach ($breadcrumbs as $i => $crumb) {
            $isLast = $i === count($breadcrumbs) - 1;

            if ($isLast) {
                $items[] = '<span>' . e($crumb['title']) . '</span>';
            } else {
                $items[] = '<a href="/' . e($crumb['slug']) . '">' . e($crumb['title']) . '</a>';
            }
        }

        return '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol>' .
               implode('<li class="separator">/</li>', array_map(fn($item) => "<li>{$item}</li>", $items)) .
               '</ol></nav>';
    }

    /**
     * Get default template structure
     */
    private function getDefaultTemplate(): string
    {
        $defaultSlug = $this->settingModel->get('default_template', 'default');
        $template = $this->templateModel->getActiveBySlug($defaultSlug);

        if ($template) {
            return $template['structure'];
        }

        // Fallback basic template
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="{{meta_description}}">
    <meta name="keywords" content="{{meta_keywords}}">
    <title>{{title}} | FixItForUs</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>{{default_css}}</style>
    <style>{{custom_css}}</style>
</head>
<body>
    {{header}}
    <main>
        {{breadcrumbs}}
        {{content}}
    </main>
    {{footer}}
    <script>{{default_js}}</script>
    <script>{{custom_js}}</script>
</body>
</html>
HTML;
    }

    /**
     * Get sitemap data
     */
    public function getSitemap(): array
    {
        return $this->pageModel->getAll(true);
    }

    /**
     * Render XML sitemap
     */
    public function renderSitemap(): void
    {
        header('Content-Type: application/xml; charset=utf-8');

        $pages = $this->getSitemap();
        $baseUrl = rtrim(env('APP_URL', ''), '/');

        echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
        echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

        // Homepage
        echo "  <url>\n";
        echo "    <loc>{$baseUrl}/</loc>\n";
        echo "    <changefreq>weekly</changefreq>\n";
        echo "    <priority>1.0</priority>\n";
        echo "  </url>\n";

        foreach ($pages as $page) {
            if ($page['slug'] === 'home' || $page['slug'] === '404') {
                continue;
            }

            $loc = $baseUrl . '/' . $page['slug'];
            $lastmod = date('Y-m-d', strtotime($page['updated_at']));

            echo "  <url>\n";
            echo "    <loc>{$loc}</loc>\n";
            echo "    <lastmod>{$lastmod}</lastmod>\n";
            echo "    <changefreq>monthly</changefreq>\n";
            echo "    <priority>0.8</priority>\n";
            echo "  </url>\n";
        }

        echo '</urlset>';
    }
}
