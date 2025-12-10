<?php
$isEdit = isset($component) && !empty($component['id']);
$pageTitle = $isEdit ? 'Edit Component' : 'New Component';
$currentPage = 'components';

ob_start();
?>

<form method="POST" action="<?= adminUrl($isEdit ? 'components/update/' . $component['id'] : 'components/create') ?>" data-validate>
    <?= csrfField() ?>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
        <!-- Main Content -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Component Details</h3>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="name">Name *</label>
                            <input
                                type="text"
                                id="name"
                                name="name"
                                class="form-control"
                                value="<?= e($component['name'] ?? '') ?>"
                                required
                            >
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="slug">Slug *</label>
                            <input
                                type="text"
                                id="slug"
                                name="slug"
                                class="form-control"
                                value="<?= e($component['slug'] ?? '') ?>"
                                data-slug-source="name"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="description">Description</label>
                        <textarea
                            id="description"
                            name="description"
                            class="form-control"
                            rows="2"
                        ><?= e($component['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="content">HTML Content *</label>
                        <textarea
                            id="content"
                            name="content"
                            class="form-control code-editor"
                            rows="20"
                            required
                        ><?= e($component['content'] ?? '') ?></textarea>
                        <span class="form-help">The HTML markup for this component.</span>
                    </div>
                </div>
            </div>

            <!-- CSS & JavaScript -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Styles & Scripts</h3>
                </div>
                <div class="card-body">
                    <div class="tabs">
                        <button type="button" class="tab active" data-tab="tab-css">CSS</button>
                        <button type="button" class="tab" data-tab="tab-js">JavaScript</button>
                    </div>

                    <div id="tab-css" class="tab-content active">
                        <div class="form-group mb-0">
                            <textarea
                                id="css"
                                name="css"
                                class="form-control code-editor"
                                rows="12"
                                placeholder="/* Component styles */"
                            ><?= e($component['css'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div id="tab-js" class="tab-content">
                        <div class="form-group mb-0">
                            <textarea
                                id="javascript"
                                name="javascript"
                                class="form-control code-editor"
                                rows="12"
                                placeholder="// Component JavaScript"
                            ><?= e($component['javascript'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div>
            <!-- Save & Status -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Save</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <div class="form-check">
                            <input
                                type="checkbox"
                                id="is_active"
                                name="is_active"
                                value="1"
                                <?= ($component['is_active'] ?? true) ? 'checked' : '' ?>
                            >
                            <label for="is_active">Active</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <?= $isEdit ? 'Update Component' : 'Create Component' ?>
                    </button>
                </div>
            </div>

            <!-- Component Settings -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Settings</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" for="type">Type *</label>
                        <select id="type" name="type" class="form-control" required>
                            <?php foreach (\CMS\Models\Component::getTypes() as $value => $label): ?>
                                <option value="<?= $value ?>" <?= ($component['type'] ?? 'custom') === $value ? 'selected' : '' ?>>
                                    <?= $label ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label" for="cache_ttl">Cache TTL (seconds)</label>
                        <input
                            type="number"
                            id="cache_ttl"
                            name="cache_ttl"
                            class="form-control"
                            value="<?= e($component['cache_ttl'] ?? 3600) ?>"
                            min="0"
                        >
                        <span class="form-help">0 = no caching</span>
                    </div>
                </div>
            </div>

            <!-- Usage Info -->
            <?php if ($isEdit): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Usage</h3>
                    </div>
                    <div class="card-body">
                        <p class="text-muted" style="font-size: 0.85rem;">
                            To use this component in pages, add:
                        </p>
                        <code style="display: block; padding: 0.5rem; background: var(--bg-tertiary); border-radius: 4px; font-size: 0.8rem; margin-top: 0.5rem;">
                            {{component:<?= e($component['slug']) ?>}}
                        </code>
                    </div>
                </div>
            <?php endif; ?>

            <?php if ($isEdit): ?>
                <!-- Delete -->
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="<?= adminUrl('components/delete/' . $component['id']) ?>">
                            <?= csrfField() ?>
                            <button type="submit" class="btn btn-danger" style="width: 100%;" data-confirm="Are you sure you want to delete this component? This action cannot be undone.">
                                Delete Component
                            </button>
                        </form>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php
$content = ob_get_clean();
include CMS_VIEWS . '/layouts/admin.php';
?>
