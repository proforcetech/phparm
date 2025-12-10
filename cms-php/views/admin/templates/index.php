<?php
$pageTitle = 'Templates';
$currentPage = 'templates';

$headerActions = '<a href="' . adminUrl('templates/new') . '" class="btn btn-primary">New Template</a>';

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">All Templates (<?= count($templates) ?>)</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($templates)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6.429 9.75L2.25 12l4.179 2.25m0-4.5l5.571 3 5.571-3m-11.142 0L2.25 7.5 12 2.25l9.75 5.25-4.179 2.25m0 0L21.75 12l-4.179 2.25m0 0l4.179 2.25L12 21.75 2.25 16.5l4.179-2.25m11.142 0l-5.571 3-5.571-3" />
                </svg>
                <h3>No templates yet</h3>
                <p>Create page templates to define layouts.</p>
                <a href="<?= adminUrl('templates/new') ?>" class="btn btn-primary mt-2">Create Template</a>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Pages</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $templateModel = new \CMS\Models\Template();
                        foreach ($templates as $template):
                            $pageCount = $templateModel->countPages($template['id']);
                        ?>
                            <tr>
                                <td>
                                    <a href="<?= adminUrl('templates/edit/' . $template['id']) ?>">
                                        <strong><?= e($template['name']) ?></strong>
                                    </a>
                                    <?php if ($template['description']): ?>
                                        <div class="text-muted" style="font-size: 0.8rem;">
                                            <?= e(substr($template['description'], 0, 60)) ?>...
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?= e($template['slug']) ?></td>
                                <td>
                                    <span class="badge badge-info"><?= $pageCount ?> pages</span>
                                </td>
                                <td>
                                    <?php if ($template['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted">
                                    <?= date('M j, Y', strtotime($template['updated_at'])) ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= adminUrl('templates/edit/' . $template['id']) ?>" class="btn btn-secondary btn-sm" title="Edit">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                            </svg>
                                        </a>
                                        <?php if ($pageCount === 0): ?>
                                            <form method="POST" action="<?= adminUrl('templates/delete/' . $template['id']) ?>" style="display: inline;">
                                                <?= csrfField() ?>
                                                <button type="submit" class="btn btn-danger btn-sm" title="Delete" data-confirm="Are you sure you want to delete this template?">
                                                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                    </svg>
                                                </button>
                                            </form>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php
$content = ob_get_clean();
include CMS_VIEWS . '/layouts/admin.php';
?>
