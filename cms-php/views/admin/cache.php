<?php
$pageTitle = 'Cache Management';
$currentPage = 'cache';

ob_start();

// Helper function
function formatBytes($bytes, $precision = 2) {
    if ($bytes == 0) return '0 B';
    $units = ['B', 'KB', 'MB', 'GB'];
    $pow = floor(log($bytes) / log(1024));
    return round($bytes / (1024 ** $pow), $precision) . ' ' . $units[$pow];
}
?>

<!-- Cache Status -->
<div class="stats-grid">
    <div class="stat-card">
        <div class="stat-value"><?= $stats['enabled'] ? 'ON' : 'OFF' ?></div>
        <div class="stat-label">Cache Status</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= ucfirst($stats['driver'] ?? 'file') ?></div>
        <div class="stat-label">Cache Driver</div>
    </div>
    <div class="stat-card">
        <div class="stat-value"><?= $stats['file_count'] ?? $stats['database_count'] ?? 0 ?></div>
        <div class="stat-label">Cached Items</div>
    </div>
    <?php if (isset($stats['file_size'])): ?>
        <div class="stat-card">
            <div class="stat-value"><?= formatBytes($stats['file_size']) ?></div>
            <div class="stat-label">Cache Size</div>
        </div>
    <?php endif; ?>
</div>

<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
    <!-- Clear Cache -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Clear Cache</h3>
        </div>
        <div class="card-body">
            <p class="text-muted mb-3">
                Clear cached content to ensure visitors see the latest changes.
            </p>

            <form method="POST" action="<?= adminUrl('cache/clear') ?>">
                <?= csrfField() ?>
                <input type="hidden" name="type" value="all">
                <button type="submit" class="btn btn-danger" style="width: 100%;" data-confirm="Are you sure you want to clear all cache?">
                    Clear All Cache
                </button>
            </form>

            <hr style="border-color: var(--border-color); margin: 1.5rem 0;">

            <p class="text-muted mb-2">Clear by type:</p>

            <div style="display: flex; flex-direction: column; gap: 0.5rem;">
                <form method="POST" action="<?= adminUrl('cache/clear') ?>" style="display: inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="type" value="page">
                    <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%;">
                        Clear Page Cache
                    </button>
                </form>

                <form method="POST" action="<?= adminUrl('cache/clear') ?>" style="display: inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="type" value="component">
                    <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%;">
                        Clear Component Cache
                    </button>
                </form>

                <form method="POST" action="<?= adminUrl('cache/clear') ?>" style="display: inline;">
                    <?= csrfField() ?>
                    <input type="hidden" name="type" value="template">
                    <button type="submit" class="btn btn-secondary btn-sm" style="width: 100%;">
                        Clear Template Cache
                    </button>
                </form>
            </div>
        </div>
    </div>

    <!-- Cache Settings -->
    <div class="card">
        <div class="card-header">
            <h3 class="card-title">Cache Configuration</h3>
        </div>
        <div class="card-body">
            <table class="table">
                <tbody>
                    <tr>
                        <td><strong>Cache Enabled</strong></td>
                        <td>
                            <?php if ($stats['enabled']): ?>
                                <span class="badge badge-success">Yes</span>
                            <?php else: ?>
                                <span class="badge badge-danger">No</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <td><strong>Cache Driver</strong></td>
                        <td><?= ucfirst($stats['driver'] ?? 'file') ?></td>
                    </tr>
                    <tr>
                        <td><strong>Default TTL</strong></td>
                        <td><?= number_format($stats['default_ttl'] ?? 3600) ?> seconds</td>
                    </tr>
                    <tr>
                        <td><strong>Cache Directory</strong></td>
                        <td><code><?= e(CMS_CACHE) ?></code></td>
                    </tr>
                </tbody>
            </table>

            <div class="alert alert-info mt-3">
                <strong>Tip:</strong> Cache settings can be modified in the <code>.env</code> file.
            </div>
        </div>
    </div>
</div>

<!-- Cache Info -->
<div class="card mt-3">
    <div class="card-header">
        <h3 class="card-title">How Caching Works</h3>
    </div>
    <div class="card-body">
        <div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 2rem;">
            <div>
                <h4 style="color: var(--accent); margin-bottom: 0.5rem;">Page Cache</h4>
                <p class="text-muted" style="font-size: 0.9rem;">
                    Fully rendered pages are cached to serve visitors instantly without database queries.
                    Each page can have its own TTL setting.
                </p>
            </div>
            <div>
                <h4 style="color: var(--accent); margin-bottom: 0.5rem;">Component Cache</h4>
                <p class="text-muted" style="font-size: 0.9rem;">
                    Reusable components (headers, footers, widgets) are cached separately.
                    When a component is updated, its cache is automatically invalidated.
                </p>
            </div>
            <div>
                <h4 style="color: var(--accent); margin-bottom: 0.5rem;">Auto-Invalidation</h4>
                <p class="text-muted" style="font-size: 0.9rem;">
                    When you update content in the admin panel, the relevant cache entries are automatically cleared.
                    Expired entries are cleaned up daily.
                </p>
            </div>
        </div>
    </div>
</div>

<?php
$content = ob_get_clean();
include CMS_VIEWS . '/layouts/admin.php';
?>
