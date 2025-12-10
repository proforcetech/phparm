<?php
$isEdit = isset($page) && !empty($page['id']);
$pageTitle = $isEdit ? 'Edit Page' : 'New Page';
$currentPage = 'pages';

ob_start();
?>

<form method="POST" action="<?= adminUrl($isEdit ? 'pages/update/' . $page['id'] : 'pages/create') ?>" data-validate>
    <?= csrfField() ?>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
        <!-- Main Content -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Page Content</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" for="title">Title *</label>
                        <input
                            type="text"
                            id="title"
                            name="title"
                            class="form-control"
                            value="<?= e($page['title'] ?? '') ?>"
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
                            value="<?= e($page['slug'] ?? '') ?>"
                            data-slug-source="title"
                            required
                        >
                        <span class="form-help">URL path: /<?= e($page['slug'] ?? 'your-page-slug') ?></span>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="content">Content *</label>
                        <textarea
                            id="content"
                            name="content"
                            class="form-control code-editor"
                            rows="20"
                            required
                        ><?= e($page['content'] ?? '') ?></textarea>
                        <span class="form-help">HTML content for the page. Use {{component:slug}} to embed components.</span>
                    </div>
                </div>
            </div>

            <!-- CSS & JavaScript -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Custom Styles & Scripts</h3>
                </div>
                <div class="card-body">
                    <div class="tabs">
                        <button type="button" class="tab active" data-tab="tab-css">CSS</button>
                        <button type="button" class="tab" data-tab="tab-js">JavaScript</button>
                    </div>

                    <div id="tab-css" class="tab-content active">
                        <div class="form-group mb-0">
                            <textarea
                                id="custom_css"
                                name="custom_css"
                                class="form-control code-editor"
                                rows="10"
                                placeholder="/* Custom CSS for this page */"
                            ><?= e($page['custom_css'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div id="tab-js" class="tab-content">
                        <div class="form-group mb-0">
                            <textarea
                                id="custom_js"
                                name="custom_js"
                                class="form-control code-editor"
                                rows="10"
                                placeholder="// Custom JavaScript for this page"
                            ><?= e($page['custom_js'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar -->
        <div>
            <!-- Publish Settings -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Publish</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <div class="form-check">
                            <input
                                type="checkbox"
                                id="is_published"
                                name="is_published"
                                value="1"
                                <?= ($page['is_published'] ?? false) ? 'checked' : '' ?>
                            >
                            <label for="is_published">Published</label>
                        </div>
                    </div>

                    <div class="d-flex gap-1">
                        <button type="submit" class="btn btn-primary" style="flex: 1;">
                            <?= $isEdit ? 'Update Page' : 'Create Page' ?>
                        </button>
                        <?php if ($isEdit && ($page['is_published'] ?? false)): ?>
                            <a href="<?= url($page['slug']) ?>" target="_blank" class="btn btn-secondary">Preview</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- SEO Settings -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">SEO</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" for="meta_description">Meta Description</label>
                        <textarea
                            id="meta_description"
                            name="meta_description"
                            class="form-control"
                            rows="3"
                            maxlength="160"
                        ><?= e($page['meta_description'] ?? '') ?></textarea>
                        <span class="form-help">Max 160 characters</span>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label" for="meta_keywords">Meta Keywords</label>
                        <input
                            type="text"
                            id="meta_keywords"
                            name="meta_keywords"
                            class="form-control"
                            value="<?= e($page['meta_keywords'] ?? '') ?>"
                            placeholder="keyword1, keyword2, keyword3"
                        >
                    </div>
                </div>
            </div>

            <!-- Template Settings -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Template</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" for="template_id">Page Template</label>
                        <select id="template_id" name="template_id" class="form-control">
                            <option value="">Default Template</option>
                            <?php foreach ($templates ?? [] as $template): ?>
                                <option value="<?= $template['id'] ?>" <?= ($page['template_id'] ?? '') == $template['id'] ? 'selected' : '' ?>>
                                    <?= e($template['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="header_component_id">Header Override</label>
                        <select id="header_component_id" name="header_component_id" class="form-control">
                            <option value="">Use Default Header</option>
                            <?php foreach ($headerComponents ?? [] as $comp): ?>
                                <option value="<?= $comp['id'] ?>" <?= ($page['header_component_id'] ?? '') == $comp['id'] ? 'selected' : '' ?>>
                                    <?= e($comp['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label" for="footer_component_id">Footer Override</label>
                        <select id="footer_component_id" name="footer_component_id" class="form-control">
                            <option value="">Use Default Footer</option>
                            <?php foreach ($footerComponents ?? [] as $comp): ?>
                                <option value="<?= $comp['id'] ?>" <?= ($page['footer_component_id'] ?? '') == $comp['id'] ? 'selected' : '' ?>>
                                    <?= e($comp['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Page Hierarchy -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Page Settings</h3>
                </div>
                <div class="card-body">
                    <div class="form-group">
                        <label class="form-label" for="parent_id">Parent Page</label>
                        <select id="parent_id" name="parent_id" class="form-control">
                            <option value="">No Parent (Top Level)</option>
                            <?php foreach ($parentPages ?? [] as $parentPage): ?>
                                <?php if (!$isEdit || $parentPage['id'] != $page['id']): ?>
                                    <option value="<?= $parentPage['id'] ?>" <?= ($page['parent_id'] ?? '') == $parentPage['id'] ? 'selected' : '' ?>>
                                        <?= e($parentPage['title']) ?>
                                    </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="sort_order">Sort Order</label>
                        <input
                            type="number"
                            id="sort_order"
                            name="sort_order"
                            class="form-control"
                            value="<?= e($page['sort_order'] ?? 0) ?>"
                            min="0"
                        >
                    </div>

                    <div class="form-group mb-0">
                        <label class="form-label" for="cache_ttl">Cache TTL (seconds)</label>
                        <input
                            type="number"
                            id="cache_ttl"
                            name="cache_ttl"
                            class="form-control"
                            value="<?= e($page['cache_ttl'] ?? 3600) ?>"
                            min="0"
                        >
                        <span class="form-help">0 = no caching</span>
                    </div>
                </div>
            </div>

            <?php if ($isEdit): ?>
                <!-- Delete -->
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="<?= adminUrl('pages/delete/' . $page['id']) ?>">
                            <?= csrfField() ?>
                            <button type="submit" class="btn btn-danger" style="width: 100%;" data-confirm="Are you sure you want to delete this page? This action cannot be undone.">
                                Delete Page
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
