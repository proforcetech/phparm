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
use CMS\Controllers\AdminController;
use CMS\Controllers\PageController;

return function (Router $router, array $config, $connection) {
    $cmsConfig = $config['cms'] ?? [];
    $cmsCache = new CMSCacheService($cmsConfig);

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

    // Public CMS Routes
    // These routes handle the front-end website pages

    // Homepage
    $router->get('/', function (Request $request) use ($cmsCache, $localeResolver) {
        $locale = $localeResolver($request);
        $cacheKey = $cmsCache->buildKey('page', 'home', $locale, 'html');

        if ($cached = $cmsCache->get($cacheKey)) {
            return Response::html((string) $cached);
        }

        $controller = new PageController();
        ob_start();
        $controller->renderHome();
        $content = ob_get_clean();

        if ($cmsCache->isEnabled()) {
            $cmsCache->set($cacheKey, $content);
        }

        return Response::html($content);
    });

    // Sitemap
    $router->get('/sitemap.xml', function (Request $request) use ($cmsCache, $localeResolver) {
        $locale = $localeResolver($request);
        $cacheKey = $cmsCache->buildKey('sitemap', 'index', $locale, 'xml');

        if ($cached = $cmsCache->get($cacheKey)) {
            return Response::make((string) $cached, 200, ['Content-Type' => 'application/xml']);
        }

        $controller = new PageController();
        ob_start();
        $controller->renderSitemap();
        $content = ob_get_clean();

        if ($cmsCache->isEnabled()) {
            $cmsCache->set($cacheKey, $content);
        }

        return Response::make($content, 200, ['Content-Type' => 'application/xml']);
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

    // Dynamic page rendering by slug (root-level)
    $router->get('/{path:.+}', function (Request $request) use ($cmsCache, $localeResolver, $isReservedPath) {
        if ($isReservedPath($request->path())) {
            return Response::notFound('Route not found');
        }

        $controller = new PageController();
        $path = (string) $request->getAttribute('path');
        $slug = ltrim($path, '/');
        $slugWithLeadingSlash = '/' . $slug;
        $locale = $localeResolver($request);
        $cacheKey = $cmsCache->buildKey('page', $slugWithLeadingSlash, $locale, 'html');

        if ($cached = $cmsCache->get($cacheKey)) {
            return Response::html((string) $cached);
        }

        ob_start();
        try {
            $controller->render($slug);
            $content = ob_get_clean();

            if ($cmsCache->isEnabled()) {
                $cmsCache->set($cacheKey, $content);
            }

            return Response::html($content);
        } catch (\Exception $e) {
            ob_end_clean();
            return Response::notFound('Page not found: ' . e($e->getMessage()));
        }
    });
};
