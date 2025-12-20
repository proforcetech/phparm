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
    $gate = new AccessGate(new RolePermissions($authConfig['roles'] ?? []));

    $reservedPrefixes = [
        'api',
        'health',
        'cp',
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
        try {
            $html = $controller->renderPublishedPage($slug);
            if ($html !== null) {
                return Response::html($html);
            }
        } catch (\Throwable $exception) {
            error_log(sprintf(
                'CMS render failed for slug "%s": %s',
                $slug,
                $exception->getMessage()
            ));
            return Response::serverError('CMS page render failed');
        }
        }

        return null;
    };

    // Public CMS Routes
    // These routes handle the front-end website pages

    // Homepage - serve Vue SPA
    $router->get('/', function (Request $request) use ($pageController, $renderCmsPage) {
        $rendered = $renderCmsPage($pageController, 'home');
        if ($rendered !== null) {
            return $rendered;
        if ($response = $renderCmsPage($pageController, 'home')) {
            return $response;
        }

        $indexPath = __DIR__ . '/../index.html';
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

    // Admin CMS Routes
    // These routes handle the admin panel

    // Dashboard
    $router->get('/cms/admin', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->dashboard();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/dashboard', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->dashboard();
        $content = ob_get_clean();
        return Response::html($content);
    });

    // Authentication
    $router->get('/cms/admin/login', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->loginForm();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/login', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->login();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/logout', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->logout();
        $content = ob_get_clean();
        return Response::html($content);
    });

    // Pages Management
    $router->get('/cms/admin/pages', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->pagesList();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/pages/new', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->pageNew();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/pages/edit/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->pageEdit($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/pages/create', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->pageCreate();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/pages/update/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->pageUpdate($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/pages/delete/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->pageDelete($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    // Components Management
    $router->get('/cms/admin/components', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->componentsList();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/components/new', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->componentNew();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/components/edit/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->componentEdit($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/components/create', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->componentCreate();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/components/update/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->componentUpdate($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/components/delete/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->componentDelete($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/components/duplicate/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->componentDuplicate($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    // Templates Management
    $router->get('/cms/admin/templates', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->templatesList();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/templates/new', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->templateNew();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/templates/edit/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->templateEdit($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/templates/create', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->templateCreate();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/templates/update/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->templateUpdate($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/templates/delete/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->templateDelete($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    // Cache Management
    $router->get('/cms/admin/cache', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->cachePage();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/cache/clear', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->cacheClear();
        $content = ob_get_clean();
        return Response::html($content);
    });

    // Settings
    $router->get('/cms/admin/settings', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->settingsPage();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/settings/update', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->settingsUpdate();
        $content = ob_get_clean();
        return Response::html($content);
    });

    // Users Management
    $router->get('/cms/admin/users', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->usersList();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/users/new', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->userNew();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->get('/cms/admin/users/edit/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->userEdit($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/users/create', function (Request $request) {
        $controller = new AdminController();
        ob_start();
        $controller->userCreate();
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/users/update/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->userUpdate($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    $router->post('/cms/admin/users/delete/{id}', function (Request $request) {
        $controller = new AdminController();
        $id = (int) $request->getAttribute('id');
        ob_start();
        $controller->userDelete($id);
        $content = ob_get_clean();
        return Response::html($content);
    });

    // Static assets (CSS, JS, images)
    $router->get('/cms/assets/{path}', function (Request $request) {
        $path = $request->getAttribute('path');
        $assetPath = CMS_ASSETS . '/' . $path;

        if (!file_exists($assetPath) || !is_file($assetPath)) {
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

    // Catch-all route - serve Vue SPA for all non-reserved paths
    // The Vue SPA will handle routing client-side and make API calls to fetch CMS content
    $router->get('/{path:.+}', function (Request $request) use ($isReservedPath, $pageController, $renderCmsPage) {
        if ($isReservedPath($request->path())) {
            return Response::notFound('Route not found');
        }

        // Try to render a published CMS page first
        $path = $request->path();
        $rendered = $renderCmsPage($pageController, $path);
        if ($rendered !== null) {
            return $rendered;
        if ($response = $renderCmsPage($pageController, $path)) {
            return $response;
        }

        // Serve the Vue SPA entry point for all public routes when no CMS page exists
        // Vue Router will handle routing on the client
        $indexPath = __DIR__ . '/../index.html';
        if (file_exists($indexPath)) {
            return Response::html(file_get_contents($indexPath));
        }
        return Response::notFound('Application not found');
    });
};
