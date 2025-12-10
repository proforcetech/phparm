<?php
$pageTitle = 'Components';
$currentPage = 'components';

$headerActions = '<a href="' . adminUrl('components/new') . '" class="btn btn-primary">New Component</a>';

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">All Components (<?= count($components) ?>)</h3>
        <div class="d-flex gap-1">
            <select id="filter-type" class="form-control" style="width: 150px;">
                <option value="">All Types</option>
                <?php foreach (\CMS\Models\Component::getTypes() as $value => $label): ?>
                    <option value="<?= $value ?>"><?= $label ?></option>
                <?php endforeach; ?>
            </select>
            <input type="text" id="search-components" class="form-control" placeholder="Search..." style="width: 200px;">
        </div>
    </div>
    <div class="card-body" style="padding: 0;">
        <?php if (empty($components)): ?>
            <div class="empty-state">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M14.25 6.087c0-.355.186-.676.401-.959.221-.29.349-.634.349-1.003 0-1.036-1.007-1.875-2.25-1.875s-2.25.84-2.25 1.875c0 .369.128.713.349 1.003.215.283.401.604.401.959v0a.64.64 0 01-.657.643 48.39 48.39 0 01-4.163-.3c.186 1.613.293 3.25.315 4.907a.656.656 0 01-.658.663v0c-.355 0-.676-.186-.959-.401a1.647 1.647 0 00-1.003-.349c-1.036 0-1.875 1.007-1.875 2.25s.84 2.25 1.875 2.25c.369 0 .713-.128 1.003-.349.283-.215.604-.401.959-.401v0c.31 0 .555.26.532.57a48.039 48.039 0 01-.642 5.056c1.518.19 3.058.309 4.616.354a.64.64 0 00.657-.643v0c0-.355-.186-.676-.401-.959a1.647 1.647 0 01-.349-1.003c0-1.035 1.008-1.875 2.25-1.875 1.243 0 2.25.84 2.25 1.875 0 .369-.128.713-.349 1.003-.215.283-.4.604-.4.959v0c0 .333.277.599.61.58a48.1 48.1 0 005.427-.63 48.05 48.05 0 00.582-4.717.532.532 0 00-.533-.57v0c-.355 0-.676.186-.959.401-.29.221-.634.349-1.003.349-1.035 0-1.875-1.007-1.875-2.25s.84-2.25 1.875-2.25c.37 0 .713.128 1.003.349.283.215.604.401.959.401v0a.656.656 0 00.658-.663 48.422 48.422 0 00-.37-5.36c-1.886.342-3.81.574-5.766.689a.578.578 0 01-.61-.58v0z" />
                </svg>
                <h3>No components yet</h3>
                <p>Create reusable components like headers and footers.</p>
                <a href="<?= adminUrl('components/new') ?>" class="btn btn-primary mt-2">Create Component</a>
            </div>
        <?php else: ?>
            <div class="table-wrapper">
                <table class="table" id="components-table">
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th>Slug</th>
                            <th>Type</th>
                            <th>Status</th>
                            <th>Updated</th>
                            <th style="width: 150px;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($components as $component): ?>
                            <tr data-type="<?= e($component['type']) ?>" data-searchable="<?= e(strtolower($component['name'] . ' ' . $component['slug'])) ?>">
                                <td>
                                    <a href="<?= adminUrl('components/edit/' . $component['id']) ?>">
                                        <strong><?= e($component['name']) ?></strong>
                                    </a>
                                    <?php if ($component['description']): ?>
                                        <div class="text-muted" style="font-size: 0.8rem;">
                                            <?= e(substr($component['description'], 0, 60)) ?>...
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted"><?= e($component['slug']) ?></td>
                                <td>
                                    <span class="badge badge-info"><?= ucfirst($component['type']) ?></span>
                                </td>
                                <td>
                                    <?php if ($component['is_active']): ?>
                                        <span class="badge badge-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge badge-warning">Inactive</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-muted">
                                    <?= date('M j, Y', strtotime($component['updated_at'])) ?>
                                </td>
                                <td>
                                    <div class="table-actions">
                                        <a href="<?= adminUrl('components/edit/' . $component['id']) ?>" class="btn btn-secondary btn-sm" title="Edit">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                            </svg>
                                        </a>
                                        <a href="<?= adminUrl('components/duplicate/' . $component['id']) ?>" class="btn btn-secondary btn-sm" title="Duplicate">
                                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75" />
                                            </svg>
                                        </a>
                                        <form method="POST" action="<?= adminUrl('components/delete/' . $component['id']) ?>" style="display: inline;">
                                            <?= csrfField() ?>
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete" data-confirm="Are you sure you want to delete this component?">
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
document.getElementById('search-components')?.addEventListener('input', filterTable);
document.getElementById('filter-type')?.addEventListener('change', filterTable);

function filterTable() {
    const search = document.getElementById('search-components').value.toLowerCase();
    const type = document.getElementById('filter-type').value;

    document.querySelectorAll('#components-table tbody tr').forEach(row => {
        const matchesSearch = !search || (row.dataset.searchable || '').includes(search);
        const matchesType = !type || row.dataset.type === type;
        row.style.display = matchesSearch && matchesType ? '' : 'none';
    });
}
</script>
HTML;

include CMS_VIEWS . '/layouts/admin.php';
?>
