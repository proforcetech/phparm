<?php

/**
 * CMS Routes
 *
 * Routes for the integrated CMS system
 */

use App\CMS\CMSBootstrap;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\Router;
use App\Services\CMS\CMSCacheService;
use App\CMS\Controllers\PageController;
use App\Support\Auth\AccessGate;
use App\Support\Auth\RolePermissions;

return function (Router $router, array $config, $connection) {
    $cmsConfig = $config['cms'] ?? [];
    $cmsCache = new CMSCacheService($cmsConfig);

    // Initialize AccessGate for PageController
    $authConfig = $config['auth'] ?? [];
    $resolvedRolePermissions = null;
    $rolePermissionsResolver = function () use ($connection, $authConfig, &$resolvedRolePermissions): RolePermissions {
        if ($resolvedRolePermissions instanceof RolePermissions) {
            return $resolvedRolePermissions;
        }

        $resolvedRolePermissions = RolePermissions::fromDatabase($connection, $authConfig['roles'] ?? []);

        return $resolvedRolePermissions;
    };
    $gate = new AccessGate(new class($rolePermissionsResolver, $authConfig['roles'] ?? []) extends RolePermissions {
        /** @var callable */
        private $resolver;
        private ?RolePermissions $resolved = null;

        /**
         * @param callable(): RolePermissions $resolver
         * @param array<string, array{label: string, description: string, permissions: string[], requires_2fa?: bool}> $defaultRoles
         */
        public function __construct(callable $resolver, array $defaultRoles)
        {
            parent::__construct($defaultRoles);
            $this->resolver = $resolver;
        }

        private function resolved(): RolePermissions
        {
            if ($this->resolved instanceof RolePermissions) {
                return $this->resolved;
            }

            $resolver = $this->resolver;
            $this->resolved = $resolver();

            return $this->resolved;
        }

        public function hasRole(string $role): bool
        {
            return $this->resolved()->hasRole($role);
        }

        public function roleDefinitions(): array
        {
            return $this->resolved()->roleDefinitions();
        }

        public function validateRole(string $role): void
        {
            $this->resolved()->validateRole($role);
        }

        public function permissionsFor(string $role): array
        {
            return $this->resolved()->permissionsFor($role);
        }

        public function hasPermission(string $role, string $permission): bool
        {
            return $this->resolved()->hasPermission($role, $permission);
        }

        public function availablePermissions(): array
        {
            return $this->resolved()->availablePermissions();
        }
    });

    $reservedPrefixes = [
        'api',
        'cp',
        'health',
        'cms',
        'cms/assets',
        'assets',
        'static',
        'storage',
        'build',
        'js',
        'css',
        'img',
        'images',
    ];

    $isReservedPath = static function (string $path) use ($reservedPrefixes): bool {
        $normalized = ltrim(strtolower($path), '/');

        foreach ($reservedPrefixes as $prefix) {
            $normalizedPrefix = ltrim(strtolower($prefix), '/');

            if ($normalized === $normalizedPrefix || str_starts_with($normalized, $normalizedPrefix . '/')) {
                return true;
            }
        }

        return false;
    };

    $localeResolver = function (Request $request): string {
        $locale = $request->queryParam('locale');
        if (!empty($locale)) {
            return (string) $locale;
        }

        $acceptLanguage = $request->header('ACCEPT-LANGUAGE');
        if (!empty($acceptLanguage)) {
            return explode(',', (string) $acceptLanguage)[0];
        }

        return 'en';
    };

    // Initialize CMS
    $cmsBootstrap = new CMSBootstrap($cmsConfig);
    $cmsBootstrap->init();
    $pageController = new PageController($connection, $gate, $cmsCache);

    /**
     * Attempt to render a CMS page by path.
     * Returns null when the page does not exist so the SPA can handle the request.
     * Returns a 5xx response when rendering fails for an existing page to avoid silently
     * falling back to the SPA with an empty screen.
     */
    $renderCmsPage = static function (PageController $controller, string $path): ?Response {
        $slug = trim($path, '/');
        $slug = $slug === '' ? 'home' : $slug;

        $page = null;

        try {
            $page = $controller->publishedPage($slug);
        } catch (\Throwable $exception) {
            error_log(sprintf(
                'CMS lookup failed for slug "%s": %s',
                $slug,
                $exception->getMessage()
            ));
            return Response::serverError('CMS page lookup failed');
        }

        if ($page === null) {
            return null;
        }

        try {
            $html = $controller->renderPublishedPage($slug);

            if ($html !== null && trim($html) !== '') {
                return Response::html($html);
            }

            error_log(sprintf('CMS render returned empty output for slug "%s"', $slug));
            return Response::serverError('CMS page could not be rendered');

        } catch (\Throwable $exception) {
            error_log(sprintf(
                'CMS render failed for slug "%s": %s',
                $slug,
                $exception->getMessage()
            ));
            return Response::serverError('CMS page render failed');
        }
    };

    // Public CMS Routes
    // These routes handle the front-end website pages

    // Homepage - serve the SPA entry point
    $router->get('/', function (Request $request) use ($pageController, $renderCmsPage) {
        $response = $renderCmsPage($pageController, 'home');
        if ($response !== null) {
            return $response;
        }

        $indexPath = __DIR__ . '/../public/index.html';
        if (file_exists($indexPath)) {
            return Response::html(file_get_contents($indexPath));
        }
        return Response::notFound('Application not found');
    });

    // Sitemap - placeholder for future implementation
    $router->get('/sitemap.xml', function (Request $request) {
        // TODO: Implement sitemap generation
        return Response::make('<?xml version="1.0" encoding="UTF-8"?><urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"></urlset>', 200, ['Content-Type' => 'application/xml']);
    });

    // Legacy CMS HTML admin routes are retired.
    // Redirect safe legacy GET entry points to the authenticated SPA-admin surface
    // and reject legacy state-changing form posts instead of proxying them.
    $legacyCmsAdminRedirect = static function (string $target): Response {
        return Response::redirect($target, 302);
    };

    $legacyCmsAdminRetired = static function (): Response {
        return Response::json([
            'error' => 'Legacy CMS HTML admin path has been retired',
            'message' => 'Use the authenticated CMS admin at /cp/cms and the /api/cms endpoints instead.',
        ], 410);
    };

    $router->get('/cms/admin', static function (Request $request) use ($legacyCmsAdminRedirect) {
        return $legacyCmsAdminRedirect('/cp/cms');
    });

    $router->get('/cms/admin/{path:.+}', static function (Request $request) use ($legacyCmsAdminRedirect) {
        $path = trim((string) $request->getAttribute('path', ''), '/');

        if ($path === 'login') {
            return $legacyCmsAdminRedirect('/cp/login');
        }

        if ($path === 'logout') {
            return $legacyCmsAdminRedirect('/cp/login');
        }

        return $legacyCmsAdminRedirect('/cp/cms');
    });

    $router->post('/cms/admin', static function (Request $request) use ($legacyCmsAdminRetired) {
        return $legacyCmsAdminRetired();
    });

    $router->post('/cms/admin/{path:.+}', static function (Request $request) use ($legacyCmsAdminRetired) {
        return $legacyCmsAdminRetired();
    });

    // Static assets (CSS, JS, images)
    $router->get('/cms/assets/{path}', function (Request $request) {
        $path = $request->getAttribute('path');
        $assetRoots = [];

        if (defined('CMS_CACHE')) {
            $assetRoots[] = rtrim(CMS_CACHE, '/') . '/assets';
        }

        if (defined('CMS_ASSETS')) {
            $assetRoots[] = rtrim(CMS_ASSETS, '/');
        }

        $assetPath = null;

        foreach ($assetRoots as $root) {
            $candidate = realpath($root . '/' . $path);
            if ($candidate === false) {
                continue;
            }

            if (str_starts_with($candidate, $root . DIRECTORY_SEPARATOR) && is_file($candidate)) {
                $assetPath = $candidate;
                break;
            }
        }

        if ($assetPath === null) {
            return Response::notFound('Asset not found');
        }

        // Determine content type
        $extension = pathinfo($assetPath, PATHINFO_EXTENSION);
        $contentTypes = [
            'css' => 'text/css',
            'js' => 'application/javascript',
            'jpg' => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'svg' => 'image/svg+xml',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            'ttf' => 'font/ttf',
        ];

        $contentType = $contentTypes[$extension] ?? 'application/octet-stream';
        $content = file_get_contents($assetPath);

        return Response::make($content, 200, ['Content-Type' => $contentType]);
    });

    // Catch-all route - serve the SPA entry point for all non-reserved paths
    // The SPA will handle routing client-side and make API calls to fetch CMS content
$router->get('/{path:.+}', function (Request $request) use ($isReservedPath, $pageController, $renderCmsPage) {
    // If it's a reserved path (like /cp/...), do NOT try to render a CMS page.
    // Instead, jump straight to serving the index.html SPA.
    if (!$isReservedPath($request->path())) {
        // Only try to render CMS pages for non-system paths
        $path = $request->path();
        $response = $renderCmsPage($pageController, $path);
        if ($response !== null) {
            return $response;
        }
    }

    // Serve the SPA entry point
    $indexPath = __DIR__ . '/../public/index.html';
    if (file_exists($indexPath)) {
        return Response::html(file_get_contents($indexPath));
    }
    return Response::notFound('Application not found');
    });
};
