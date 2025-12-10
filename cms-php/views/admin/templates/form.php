<?php
$isEdit = isset($template) && !empty($template['id']);
$pageTitle = $isEdit ? 'Edit Template' : 'New Template';
$currentPage = 'templates';

ob_start();
?>

<form method="POST" action="<?= adminUrl($isEdit ? 'templates/update/' . $template['id'] : 'templates/create') ?>" data-validate>
    <?= csrfField() ?>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
        <!-- Main Content -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Template Details</h3>
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
                                value="<?= e($template['name'] ?? '') ?>"
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
                                value="<?= e($template['slug'] ?? '') ?>"
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
                        ><?= e($template['description'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label" for="structure">HTML Structure *</label>
                        <textarea
                            id="structure"
                            name="structure"
                            class="form-control code-editor"
                            rows="25"
                            required
                        ><?= e($template['structure'] ?? $defaultStructure ?? '') ?></textarea>
                        <span class="form-help">
                            Available placeholders: {{title}}, {{meta_description}}, {{meta_keywords}}, {{header}}, {{footer}}, {{content}}, {{breadcrumbs}}, {{default_css}}, {{default_js}}, {{custom_css}}, {{custom_js}}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Default CSS & JavaScript -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Default Styles & Scripts</h3>
                </div>
                <div class="card-body">
                    <div class="tabs">
                        <button type="button" class="tab active" data-tab="tab-css">Default CSS</button>
                        <button type="button" class="tab" data-tab="tab-js">Default JavaScript</button>
                    </div>

                    <div id="tab-css" class="tab-content active">
                        <div class="form-group mb-0">
                            <textarea
                                id="default_css"
                                name="default_css"
                                class="form-control code-editor"
                                rows="15"
                                placeholder="/* Default CSS applied to all pages using this template */"
                            ><?= e($template['default_css'] ?? '') ?></textarea>
                        </div>
                    </div>

                    <div id="tab-js" class="tab-content">
                        <div class="form-group mb-0">
                            <textarea
                                id="default_js"
                                name="default_js"
                                class="form-control code-editor"
                                rows="15"
                                placeholder="// Default JavaScript for pages using this template"
                            ><?= e($template['default_js'] ?? '') ?></textarea>
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
                                <?= ($template['is_active'] ?? true) ? 'checked' : '' ?>
                            >
                            <label for="is_active">Active</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <?= $isEdit ? 'Update Template' : 'Create Template' ?>
                    </button>
                </div>
            </div>

            <!-- Placeholder Reference -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Placeholders</h3>
                </div>
                <div class="card-body">
                    <div style="font-size: 0.85rem;">
                        <p class="text-muted mb-1"><strong>Required:</strong></p>
                        <code>{{content}}</code> - Page content<br><br>

                        <p class="text-muted mb-1"><strong>Optional:</strong></p>
                        <code>{{title}}</code> - Page title<br>
                        <code>{{meta_description}}</code><br>
                        <code>{{meta_keywords}}</code><br>
                        <code>{{header}}</code> - Header component<br>
                        <code>{{footer}}</code> - Footer component<br>
                        <code>{{breadcrumbs}}</code><br>
                        <code>{{default_css}}</code><br>
                        <code>{{default_js}}</code><br>
                        <code>{{custom_css}}</code><br>
                        <code>{{custom_js}}</code>
                    </div>
                </div>
            </div>

            <?php if ($isEdit): ?>
                <?php
                $templateModel = new \CMS\Models\Template();
                $pageCount = $templateModel->countPages($template['id']);
                ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Usage</h3>
                    </div>
                    <div class="card-body">
                        <p>This template is used by <strong><?= $pageCount ?></strong> page(s).</p>
                        <?php if ($pageCount > 0): ?>
                            <a href="<?= adminUrl('pages') ?>" class="btn btn-secondary btn-sm">View Pages</a>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if ($pageCount === 0): ?>
                    <!-- Delete -->
                    <div class="card">
                        <div class="card-body">
                            <form method="POST" action="<?= adminUrl('templates/delete/' . $template['id']) ?>">
                                <?= csrfField() ?>
                                <button type="submit" class="btn btn-danger" style="width: 100%;" data-confirm="Are you sure you want to delete this template?">
                                    Delete Template
                                </button>
                            </form>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</form>

<?php
$content = ob_get_clean();

// Default template structure for new templates
if (!$isEdit && empty($template['structure'])) {
    $defaultStructure = <<<'HTML'
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

include CMS_VIEWS . '/layouts/admin.php';
?>
