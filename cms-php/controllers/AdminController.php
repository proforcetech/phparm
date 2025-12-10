<?php
/**
 * Admin Controller
 * Handles all admin panel routes and actions
 * FixItForUs CMS
 */

namespace CMS\Controllers;

use CMS\Models\Page;
use CMS\Models\Component;
use CMS\Models\Template;
use CMS\Models\User;
use CMS\Models\Cache;
use CMS\Models\Setting;

class AdminController
{
    private Page $pageModel;
    private Component $componentModel;
    private Template $templateModel;
    private User $userModel;
    private Cache $cacheModel;
    private Setting $settingModel;

    public function __construct()
    {
        $this->pageModel = new Page();
        $this->componentModel = new Component();
        $this->templateModel = new Template();
        $this->userModel = new User();
        $this->cacheModel = new Cache();
        $this->settingModel = new Setting();
    }

    /**
     * Require authentication
     */
    private function requireAuth(): void
    {
        if (!isLoggedIn()) {
            redirect(adminUrl('login'));
        }
    }

    /**
     * Require admin role
     */
    private function requireAdmin(): void
    {
        $this->requireAuth();
        if (!hasRole('admin')) {
            flash('error', 'You do not have permission to access this area.');
            redirect(adminUrl());
        }
    }

    /**
     * Dashboard
     */
    public function dashboard(): void
    {
        $this->requireAuth();

        $stats = [
            'pages' => $this->pageModel->count(),
            'published' => $this->pageModel->count(true),
            'components' => $this->componentModel->count(),
            'templates' => $this->templateModel->count(),
        ];

        $recentPages = $this->pageModel->getAll();
        $recentPages = array_slice($recentPages, 0, 5);

        $cacheStats = $this->cacheModel->getStats();

        include CMS_VIEWS . '/admin/dashboard.php';
    }

    // ================================================
    // Authentication
    // ================================================

    /**
     * Login page
     */
    public function loginForm(): void
    {
        if (isLoggedIn()) {
            redirect(adminUrl());
        }
        include CMS_VIEWS . '/admin/login.php';
    }

    /**
     * Process login
     */
    public function login(): void
    {
        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request. Please try again.');
            redirect(adminUrl('login'));
        }

        $username = $_POST['username'] ?? '';
        $password = $_POST['password'] ?? '';

        if ($this->userModel->login($username, $password)) {
            flash('success', 'Welcome back!');
            redirect(adminUrl());
        } else {
            flash('error', 'Invalid username or password.');
            redirect(adminUrl('login'));
        }
    }

    /**
     * Logout
     */
    public function logout(): void
    {
        $this->userModel->logout();
        flash('success', 'You have been logged out.');
        redirect(adminUrl('login'));
    }

    // ================================================
    // Pages
    // ================================================

    /**
     * List all pages
     */
    public function pagesList(): void
    {
        $this->requireAuth();
        $pages = $this->pageModel->getAll();
        include CMS_VIEWS . '/admin/pages/index.php';
    }

    /**
     * New page form
     */
    public function pageNew(): void
    {
        $this->requireAuth();
        $page = [];
        $templates = $this->templateModel->getAll(true);
        $headerComponents = $this->componentModel->getByType('header');
        $footerComponents = $this->componentModel->getByType('footer');
        $parentPages = $this->pageModel->getAll();
        include CMS_VIEWS . '/admin/pages/form.php';
    }

    /**
     * Edit page form
     */
    public function pageEdit(int $id): void
    {
        $this->requireAuth();
        $page = $this->pageModel->getById($id);
        if (!$page) {
            flash('error', 'Page not found.');
            redirect(adminUrl('pages'));
        }
        $templates = $this->templateModel->getAll(true);
        $headerComponents = $this->componentModel->getByType('header');
        $footerComponents = $this->componentModel->getByType('footer');
        $parentPages = $this->pageModel->getAll();
        include CMS_VIEWS . '/admin/pages/form.php';
    }

    /**
     * Create page
     */
    public function pageCreate(): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('pages/new'));
        }

        $data = [
            'title' => $_POST['title'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'content' => $_POST['content'] ?? '',
            'meta_description' => $_POST['meta_description'] ?? '',
            'meta_keywords' => $_POST['meta_keywords'] ?? '',
            'template_id' => $_POST['template_id'] ?: null,
            'header_component_id' => $_POST['header_component_id'] ?: null,
            'footer_component_id' => $_POST['footer_component_id'] ?: null,
            'custom_css' => $_POST['custom_css'] ?? '',
            'custom_js' => $_POST['custom_js'] ?? '',
            'parent_id' => $_POST['parent_id'] ?: null,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'cache_ttl' => (int) ($_POST['cache_ttl'] ?? 3600),
            'is_published' => isset($_POST['is_published']) ? 1 : 0,
        ];

        try {
            $id = $this->pageModel->create($data);
            flash('success', 'Page created successfully.');
            redirect(adminUrl('pages/edit/' . $id));
        } catch (\Exception $e) {
            flash('error', 'Failed to create page: ' . $e->getMessage());
            redirect(adminUrl('pages/new'));
        }
    }

    /**
     * Update page
     */
    public function pageUpdate(int $id): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('pages/edit/' . $id));
        }

        $data = [
            'title' => $_POST['title'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'content' => $_POST['content'] ?? '',
            'meta_description' => $_POST['meta_description'] ?? '',
            'meta_keywords' => $_POST['meta_keywords'] ?? '',
            'template_id' => $_POST['template_id'] ?: null,
            'header_component_id' => $_POST['header_component_id'] ?: null,
            'footer_component_id' => $_POST['footer_component_id'] ?: null,
            'custom_css' => $_POST['custom_css'] ?? '',
            'custom_js' => $_POST['custom_js'] ?? '',
            'parent_id' => $_POST['parent_id'] ?: null,
            'sort_order' => (int) ($_POST['sort_order'] ?? 0),
            'cache_ttl' => (int) ($_POST['cache_ttl'] ?? 3600),
            'is_published' => isset($_POST['is_published']) ? 1 : 0,
        ];

        try {
            $this->pageModel->update($id, $data);
            // Invalidate page cache
            $this->cacheModel->invalidatePage($data['slug']);
            flash('success', 'Page updated successfully.');
        } catch (\Exception $e) {
            flash('error', 'Failed to update page: ' . $e->getMessage());
        }

        redirect(adminUrl('pages/edit/' . $id));
    }

    /**
     * Delete page
     */
    public function pageDelete(int $id): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('pages'));
        }

        $page = $this->pageModel->getById($id);
        if ($page) {
            $this->pageModel->delete($id);
            $this->cacheModel->invalidatePage($page['slug']);
            flash('success', 'Page deleted successfully.');
        } else {
            flash('error', 'Page not found.');
        }

        redirect(adminUrl('pages'));
    }

    // ================================================
    // Components
    // ================================================

    /**
     * List all components
     */
    public function componentsList(): void
    {
        $this->requireAuth();
        $components = $this->componentModel->getAll();
        include CMS_VIEWS . '/admin/components/index.php';
    }

    /**
     * New component form
     */
    public function componentNew(): void
    {
        $this->requireAuth();
        $component = [];
        include CMS_VIEWS . '/admin/components/form.php';
    }

    /**
     * Edit component form
     */
    public function componentEdit(int $id): void
    {
        $this->requireAuth();
        $component = $this->componentModel->getById($id);
        if (!$component) {
            flash('error', 'Component not found.');
            redirect(adminUrl('components'));
        }
        include CMS_VIEWS . '/admin/components/form.php';
    }

    /**
     * Create component
     */
    public function componentCreate(): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('components/new'));
        }

        $data = [
            'name' => $_POST['name'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'type' => $_POST['type'] ?? 'custom',
            'description' => $_POST['description'] ?? '',
            'content' => $_POST['content'] ?? '',
            'css' => $_POST['css'] ?? '',
            'javascript' => $_POST['javascript'] ?? '',
            'cache_ttl' => (int) ($_POST['cache_ttl'] ?? 3600),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        try {
            $id = $this->componentModel->create($data);
            flash('success', 'Component created successfully.');
            redirect(adminUrl('components/edit/' . $id));
        } catch (\Exception $e) {
            flash('error', 'Failed to create component: ' . $e->getMessage());
            redirect(adminUrl('components/new'));
        }
    }

    /**
     * Update component
     */
    public function componentUpdate(int $id): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('components/edit/' . $id));
        }

        $data = [
            'name' => $_POST['name'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'type' => $_POST['type'] ?? 'custom',
            'description' => $_POST['description'] ?? '',
            'content' => $_POST['content'] ?? '',
            'css' => $_POST['css'] ?? '',
            'javascript' => $_POST['javascript'] ?? '',
            'cache_ttl' => (int) ($_POST['cache_ttl'] ?? 3600),
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        try {
            $this->componentModel->update($id, $data);
            $this->cacheModel->invalidateComponent($data['slug']);
            flash('success', 'Component updated successfully.');
        } catch (\Exception $e) {
            flash('error', 'Failed to update component: ' . $e->getMessage());
        }

        redirect(adminUrl('components/edit/' . $id));
    }

    /**
     * Delete component
     */
    public function componentDelete(int $id): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('components'));
        }

        $component = $this->componentModel->getById($id);
        if ($component) {
            $this->componentModel->delete($id);
            $this->cacheModel->invalidateComponent($component['slug']);
            flash('success', 'Component deleted successfully.');
        } else {
            flash('error', 'Component not found.');
        }

        redirect(adminUrl('components'));
    }

    /**
     * Duplicate component
     */
    public function componentDuplicate(int $id): void
    {
        $this->requireAuth();

        $newId = $this->componentModel->duplicate($id);
        if ($newId) {
            flash('success', 'Component duplicated successfully.');
            redirect(adminUrl('components/edit/' . $newId));
        } else {
            flash('error', 'Failed to duplicate component.');
            redirect(adminUrl('components'));
        }
    }

    // ================================================
    // Templates
    // ================================================

    /**
     * List all templates
     */
    public function templatesList(): void
    {
        $this->requireAuth();
        $templates = $this->templateModel->getAll();
        include CMS_VIEWS . '/admin/templates/index.php';
    }

    /**
     * New template form
     */
    public function templateNew(): void
    {
        $this->requireAuth();
        $template = [];
        include CMS_VIEWS . '/admin/templates/form.php';
    }

    /**
     * Edit template form
     */
    public function templateEdit(int $id): void
    {
        $this->requireAuth();
        $template = $this->templateModel->getById($id);
        if (!$template) {
            flash('error', 'Template not found.');
            redirect(adminUrl('templates'));
        }
        include CMS_VIEWS . '/admin/templates/form.php';
    }

    /**
     * Create template
     */
    public function templateCreate(): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('templates/new'));
        }

        $data = [
            'name' => $_POST['name'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'description' => $_POST['description'] ?? '',
            'structure' => $_POST['structure'] ?? '',
            'default_css' => $_POST['default_css'] ?? '',
            'default_js' => $_POST['default_js'] ?? '',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        try {
            $id = $this->templateModel->create($data);
            flash('success', 'Template created successfully.');
            redirect(adminUrl('templates/edit/' . $id));
        } catch (\Exception $e) {
            flash('error', 'Failed to create template: ' . $e->getMessage());
            redirect(adminUrl('templates/new'));
        }
    }

    /**
     * Update template
     */
    public function templateUpdate(int $id): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('templates/edit/' . $id));
        }

        $data = [
            'name' => $_POST['name'] ?? '',
            'slug' => $_POST['slug'] ?? '',
            'description' => $_POST['description'] ?? '',
            'structure' => $_POST['structure'] ?? '',
            'default_css' => $_POST['default_css'] ?? '',
            'default_js' => $_POST['default_js'] ?? '',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        try {
            $this->templateModel->update($id, $data);
            $this->cacheModel->invalidateTemplate($data['slug']);
            flash('success', 'Template updated successfully.');
        } catch (\Exception $e) {
            flash('error', 'Failed to update template: ' . $e->getMessage());
        }

        redirect(adminUrl('templates/edit/' . $id));
    }

    /**
     * Delete template
     */
    public function templateDelete(int $id): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('templates'));
        }

        $template = $this->templateModel->getById($id);
        if ($template) {
            if ($this->templateModel->delete($id)) {
                $this->cacheModel->invalidateTemplate($template['slug']);
                flash('success', 'Template deleted successfully.');
            } else {
                flash('error', 'Cannot delete template. It may be in use by pages.');
            }
        } else {
            flash('error', 'Template not found.');
        }

        redirect(adminUrl('templates'));
    }

    // ================================================
    // Cache Management
    // ================================================

    /**
     * Cache management page
     */
    public function cachePage(): void
    {
        $this->requireAuth();
        $stats = $this->cacheModel->getStats();
        include CMS_VIEWS . '/admin/cache.php';
    }

    /**
     * Clear cache
     */
    public function cacheClear(): void
    {
        $this->requireAuth();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('cache'));
        }

        $type = $_POST['type'] ?? 'all';

        if ($type === 'all') {
            $count = $this->cacheModel->clearAll();
        } else {
            $count = $this->cacheModel->clearByType($type);
        }

        flash('success', "Cache cleared successfully. {$count} entries removed.");
        redirect(adminUrl('cache'));
    }

    // ================================================
    // Settings
    // ================================================

    /**
     * Settings page
     */
    public function settingsPage(): void
    {
        $this->requireAdmin();
        $settings = $this->settingModel->getGrouped();
        include CMS_VIEWS . '/admin/settings.php';
    }

    /**
     * Update settings
     */
    public function settingsUpdate(): void
    {
        $this->requireAdmin();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('settings'));
        }

        unset($_POST['csrf_token']);

        foreach ($_POST as $key => $value) {
            $this->settingModel->set($key, $value);
        }

        flash('success', 'Settings updated successfully.');
        redirect(adminUrl('settings'));
    }

    // ================================================
    // Users
    // ================================================

    /**
     * List all users
     */
    public function usersList(): void
    {
        $this->requireAdmin();
        $users = $this->userModel->getAll();
        include CMS_VIEWS . '/admin/users/index.php';
    }

    /**
     * New user form
     */
    public function userNew(): void
    {
        $this->requireAdmin();
        $user = [];
        include CMS_VIEWS . '/admin/users/form.php';
    }

    /**
     * Edit user form
     */
    public function userEdit(int $id): void
    {
        $this->requireAdmin();
        $user = $this->userModel->getById($id);
        if (!$user) {
            flash('error', 'User not found.');
            redirect(adminUrl('users'));
        }
        include CMS_VIEWS . '/admin/users/form.php';
    }

    /**
     * Create user
     */
    public function userCreate(): void
    {
        $this->requireAdmin();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('users/new'));
        }

        $data = [
            'username' => $_POST['username'] ?? '',
            'email' => $_POST['email'] ?? '',
            'password' => $_POST['password'] ?? '',
            'role' => $_POST['role'] ?? 'editor',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        // Validation
        if ($this->userModel->usernameExists($data['username'])) {
            flash('error', 'Username already exists.');
            redirect(adminUrl('users/new'));
        }

        if ($this->userModel->emailExists($data['email'])) {
            flash('error', 'Email already exists.');
            redirect(adminUrl('users/new'));
        }

        try {
            $id = $this->userModel->create($data);
            flash('success', 'User created successfully.');
            redirect(adminUrl('users/edit/' . $id));
        } catch (\Exception $e) {
            flash('error', 'Failed to create user: ' . $e->getMessage());
            redirect(adminUrl('users/new'));
        }
    }

    /**
     * Update user
     */
    public function userUpdate(int $id): void
    {
        $this->requireAdmin();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('users/edit/' . $id));
        }

        $data = [
            'username' => $_POST['username'] ?? '',
            'email' => $_POST['email'] ?? '',
            'role' => $_POST['role'] ?? 'editor',
            'is_active' => isset($_POST['is_active']) ? 1 : 0,
        ];

        // Only update password if provided
        if (!empty($_POST['password'])) {
            $data['password'] = $_POST['password'];
        }

        // Validation
        if ($this->userModel->usernameExists($data['username'], $id)) {
            flash('error', 'Username already exists.');
            redirect(adminUrl('users/edit/' . $id));
        }

        if ($this->userModel->emailExists($data['email'], $id)) {
            flash('error', 'Email already exists.');
            redirect(adminUrl('users/edit/' . $id));
        }

        try {
            $this->userModel->update($id, $data);
            flash('success', 'User updated successfully.');
        } catch (\Exception $e) {
            flash('error', 'Failed to update user: ' . $e->getMessage());
        }

        redirect(adminUrl('users/edit/' . $id));
    }

    /**
     * Delete user
     */
    public function userDelete(int $id): void
    {
        $this->requireAdmin();

        if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
            flash('error', 'Invalid request.');
            redirect(adminUrl('users'));
        }

        if ($id === currentUserId()) {
            flash('error', 'You cannot delete your own account.');
            redirect(adminUrl('users'));
        }

        if ($this->userModel->delete($id)) {
            flash('success', 'User deleted successfully.');
        } else {
            flash('error', 'Cannot delete this user.');
        }

        redirect(adminUrl('users'));
    }
}
