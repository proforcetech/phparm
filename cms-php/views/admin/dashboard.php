<?php
$pageTitle = 'Dashboard';
$currentPage = 'dashboard';

ob_start();
?>

<!-- Stats Grid -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $stats['pages'] ?? 0 ?></div>
        <div class="stat-label">Total Pages</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['published'] ?? 0 ?></div>
        <div class="stat-label">Published Pages</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['components'] ?? 0 ?></div>
        <div class="stat-label">Components</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['templates'] ?? 0 ?></div>
        <div class="stat-label">Templates</div>
    </div>
</div>

<div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
    <!-- Recent Pages -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Recent Pages</h3>
            <a href="<?= adminUrl('pages/new') ?>" class="btn btn-primary btn-sm">New Page</a>
        </div>
        <div class="card-body">
            <?php if (empty($recentPages)): ?>
                <div class="empty-state">
                    <p>No pages yet. Create your first page!</p>
                </div>
            <?php else: ?>
                <div class="table-wrapper">
                    <table class="table">
                        <thead>
                            <tr>
                                <th>Title</th>
                                <th>Status</th>
                                <th>Updated</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentPages as $page): ?>
                                <tr>
                                    <td>
                                        <a href="<?= adminUrl('pages/edit/' . $page['id']) ?>">
                                            <?= e($page['title']) ?>
                                        </a>
                                        <div class="text-muted" style="font-size: 0.8rem;">
                                            /<?= e($page['slug']) ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if ($page['is_published']): ?>
                                            <span class="badge badge-success">Published</span>
                                        <?php else: ?>
                                            <span class="badge badge-warning">Draft</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-muted">
                                        <?= date('M j, Y', strtotime($page['updated_at'])) ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Quick Actions & Cache Status -->
    <div>
        <!-- Quick Actions -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Quick Actions</h3>
            </div>
            <div class="card-body">
                <div style="display: flex; flex-direction: column; gap: 0.75rem;">
                    <a href="<?= adminUrl('pages/new') ?>" class="btn btn-secondary" style="justify-content: flex-start;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Create New Page
                    </a>
                    <a href="<?= adminUrl('components/new') ?>" class="btn btn-secondary" style="justify-content: flex-start;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" />
                        </svg>
                        Create New Component
                    </a>
                    <a href="<?= adminUrl('cache') ?>" class="btn btn-secondary" style="justify-content: flex-start;">
                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="18" height="18">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                        Clear Cache
                    </a>
                </div>
            </div>
        </div>

        <!-- Cache Status -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Cache Status</h3>
            </div>
            <div class="card-body">
                <div style="margin-bottom: 1rem;">
                    <div class="d-flex justify-between mb-1">
                        <span>Status</span>
                        <?php if ($cacheStats['enabled'] ?? false): ?>
                            <span class="badge badge-success">Enabled</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Disabled</span>
                        <?php endif; ?>
                    </div>
                    <div class="d-flex justify-between mb-1">
                        <span>Driver</span>
                        <span class="text-muted"><?= ucfirst($cacheStats['driver'] ?? 'file') ?></span>
                    </div>
                    <div class="d-flex justify-between mb-1">
                        <span>Cached Items</span>
                        <span class="text-muted"><?= $cacheStats['file_count'] ?? $cacheStats['database_count'] ?? 0 ?></span>
                    </div>
                    <?php if (isset($cacheStats['file_size'])): ?>
                        <div class="d-flex justify-between">
                            <span>Cache Size</span>
                            <span class="text-muted"><?= formatBytes($cacheStats['file_size']) ?></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();

// Helper function for formatting bytes
function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}

include CMS_VIEWS . '/layouts/admin.php';
?>
