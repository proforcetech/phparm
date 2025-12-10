<?php
$pageTitle = 'Settings';
$currentPage = 'settings';

ob_start();
?>

<form method="POST" action="<?= adminUrl('settings/update') ?>">
    <?= csrfField() ?>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 1.5rem;">
        <!-- Site Settings -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Site Settings</h3>
            </div>
            <div class="card-body">
                <?php foreach ($settings['site'] ?? [] as $setting): ?>
                    <div class="form-group">
                        <label class="form-label" for="<?= e($setting['setting_key']) ?>">
                            <?= e(ucwords(str_replace('_', ' ', str_replace('site_', '', $setting['setting_key'])))) ?>
                        </label>
                        <?php if ($setting['setting_type'] === 'boolean'): ?>
                            <select
                                id="<?= e($setting['setting_key']) ?>"
                                name="<?= e($setting['setting_key']) ?>"
                                class="form-control"
                            >
                                <option value="1" <?= $setting['setting_value'] === '1' ? 'selected' : '' ?>>Yes</option>
                                <option value="0" <?= $setting['setting_value'] === '0' ? 'selected' : '' ?>>No</option>
                            </select>
                        <?php elseif ($setting['setting_type'] === 'html'): ?>
                            <textarea
                                id="<?= e($setting['setting_key']) ?>"
                                name="<?= e($setting['setting_key']) ?>"
                                class="form-control"
                                rows="4"
                            ><?= e($setting['setting_value']) ?></textarea>
                        <?php else: ?>
                            <input
                                type="<?= $setting['setting_type'] === 'integer' ? 'number' : 'text' ?>"
                                id="<?= e($setting['setting_key']) ?>"
                                name="<?= e($setting['setting_key']) ?>"
                                class="form-control"
                                value="<?= e($setting['setting_value']) ?>"
                            >
                        <?php endif; ?>
                        <?php if ($setting['description']): ?>
                            <span class="form-help"><?= e($setting['description']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Contact Settings -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Contact Information</h3>
            </div>
            <div class="card-body">
                <?php foreach ($settings['contact'] ?? [] as $setting): ?>
                    <div class="form-group">
                        <label class="form-label" for="<?= e($setting['setting_key']) ?>">
                            <?= e(ucwords(str_replace('_', ' ', str_replace('contact_', '', $setting['setting_key'])))) ?>
                        </label>
                        <input
                            type="text"
                            id="<?= e($setting['setting_key']) ?>"
                            name="<?= e($setting['setting_key']) ?>"
                            class="form-control"
                            value="<?= e($setting['setting_value']) ?>"
                        >
                        <?php if ($setting['description']): ?>
                            <span class="form-help"><?= e($setting['description']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Default Components -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Default Components</h3>
            </div>
            <div class="card-body">
                <?php
                $componentSettings = array_filter($settings['other'] ?? [], function($s) {
                    return strpos($s['setting_key'], '_component') !== false || strpos($s['setting_key'], '_template') !== false;
                });
                foreach ($componentSettings as $setting):
                ?>
                    <div class="form-group">
                        <label class="form-label" for="<?= e($setting['setting_key']) ?>">
                            <?= e(ucwords(str_replace('_', ' ', str_replace('default_', '', $setting['setting_key'])))) ?>
                        </label>
                        <input
                            type="text"
                            id="<?= e($setting['setting_key']) ?>"
                            name="<?= e($setting['setting_key']) ?>"
                            class="form-control"
                            value="<?= e($setting['setting_value']) ?>"
                        >
                        <?php if ($setting['description']): ?>
                            <span class="form-help"><?= e($setting['description']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Cache & Performance -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Cache & Performance</h3>
            </div>
            <div class="card-body">
                <?php foreach ($settings['cache'] ?? [] as $setting): ?>
                    <div class="form-group">
                        <label class="form-label" for="<?= e($setting['setting_key']) ?>">
                            <?= e(ucwords(str_replace('_', ' ', str_replace('cache_', '', $setting['setting_key'])))) ?>
                        </label>
                        <?php if ($setting['setting_type'] === 'boolean'): ?>
                            <select
                                id="<?= e($setting['setting_key']) ?>"
                                name="<?= e($setting['setting_key']) ?>"
                                class="form-control"
                            >
                                <option value="1" <?= $setting['setting_value'] === '1' ? 'selected' : '' ?>>Enabled</option>
                                <option value="0" <?= $setting['setting_value'] === '0' ? 'selected' : '' ?>>Disabled</option>
                            </select>
                        <?php else: ?>
                            <input
                                type="<?= $setting['setting_type'] === 'integer' ? 'number' : 'text' ?>"
                                id="<?= e($setting['setting_key']) ?>"
                                name="<?= e($setting['setting_key']) ?>"
                                class="form-control"
                                value="<?= e($setting['setting_value']) ?>"
                            >
                        <?php endif; ?>
                        <?php if ($setting['description']): ?>
                            <span class="form-help"><?= e($setting['description']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Other Settings -->
        <div class="card">
            <div class="card-header">
                <h3 class="card-title">Other Settings</h3>
            </div>
            <div class="card-body">
                <?php
                $otherSettings = array_filter($settings['other'] ?? [], function($s) {
                    return strpos($s['setting_key'], '_component') === false && strpos($s['setting_key'], '_template') === false;
                });
                foreach ($otherSettings as $setting):
                ?>
                    <div class="form-group">
                        <label class="form-label" for="<?= e($setting['setting_key']) ?>">
                            <?= e(ucwords(str_replace('_', ' ', $setting['setting_key']))) ?>
                        </label>
                        <?php if ($setting['setting_type'] === 'boolean'): ?>
                            <select
                                id="<?= e($setting['setting_key']) ?>"
                                name="<?= e($setting['setting_key']) ?>"
                                class="form-control"
                            >
                                <option value="1" <?= $setting['setting_value'] === '1' ? 'selected' : '' ?>>Yes</option>
                                <option value="0" <?= $setting['setting_value'] === '0' ? 'selected' : '' ?>>No</option>
                            </select>
                        <?php else: ?>
                            <input
                                type="text"
                                id="<?= e($setting['setting_key']) ?>"
                                name="<?= e($setting['setting_key']) ?>"
                                class="form-control"
                                value="<?= e($setting['setting_value']) ?>"
                            >
                        <?php endif; ?>
                        <?php if ($setting['description']): ?>
                            <span class="form-help"><?= e($setting['description']) ?></span>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-body">
            <button type="submit" class="btn btn-primary btn-lg">
                Save Settings
            </button>
        </div>
    </div>
</form>

<?php
$content = ob_get_clean();
include CMS_VIEWS . '/layouts/admin.php';
?>
