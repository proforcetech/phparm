<?php
$pageTitle = 'Pages';
$currentPage = 'pages';

$headerActions = '<a href="' . adminUrl('pages/new') . '" class="btn btn-primary">New Page</a>';

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">All Pages (<?= count($pages) ?>)</h3>
        <div class="d-flex gap-1">
            <input type="text" id="search-pages" class="form-control" placeholder="Search pages..." style="width: 250px;">
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($pages)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" />
                </svg>
                <h3>No pages yet</h3>
                <p>Create your first page to get started.</p>
                <a href="<?= adminUrl('pages/new') ?>" class="btn btn-primary mt-2">Create Page</a>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table" id="pages-table">
                    <thead>
                        <tr>
                            <th>Title</th>
                            <th>Slug</th>
                            <th>Template</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($pages as $page): ?>
                            <tr data-searchable="<?= e(strtolower($page['title'] . ' ' . $page['slug'])) ?>">
                                <td>
                                    <a href="<?= adminUrl('pages/edit/' . $page['id']) ?>">
                                        <strong><?= e($page['title']) ?></strong>
                                    </a>
                                </td>
                                <td class="text-muted">/<?= e($page['slug']) ?></td>
                                <td><?= e($page['template_name'] ?? 'Default') ?></td>
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
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= adminUrl('pages/edit/' . $page['id']) ?>" class="btn btn-secondary btn-sm" title="Edit">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                            </svg>
                                        </a>
                                        <?php if ($page['is_published']): ?>
                                            <a href="<?= url($page['slug']) ?>" target="_blank" class="btn btn-secondary btn-sm" title="View">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                </svg>
                                            </a>
                                        <?php endif; ?>
                                        <form method="POST" action="<?= adminUrl('pages/delete/' . $page['id']) ?>" style="display: inline;">
                                            <?= csrfField() ?>
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete" data-confirm="Are you sure you want to delete this page?">
                                                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                                                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" />
                                                </svg>
                                            </button>
                                        </form>
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

$scripts = <<<HTML
<script>
document.getElementById('search-pages')?.addEventListener('input', function(e) {
    const search = e.target.value.toLowerCase();
    document.querySelectorAll('#pages-table tbody tr').forEach(row => {
        const searchable = row.dataset.searchable || '';
        row.style.display = searchable.includes(search) ? '' : 'none';
    });
});
</script>
HTML;

include CMS_VIEWS . '/layouts/admin.php';
?>
