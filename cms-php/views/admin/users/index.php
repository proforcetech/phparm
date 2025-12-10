<?php
$pageTitle = 'Users';
$currentPage = 'users';

$headerActions = '<a href="' . adminUrl('users/new') . '" class="btn btn-primary">New User</a>';

ob_start();
?>

<div class="card">
    <div class="card-header">
        <h3 class="card-title">All Users (<?= count($users) ?>)</h3>
    </div>
    <div class="card-body" style="padding: 0;">
        <div class="table-wrapper">
            <table class="table">
                <thead>
                    <tr>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Role</th>
                        <th>Status</th>
                        <th>Last Login</th>
                        <th style="width: 150px;">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td>
                                <div class="d-flex align-center gap-1">
                                    <div class="user-avatar" style="width: 32px; height: 32px; font-size: 0.8rem;">
                                        <?= strtoupper(substr($user['username'], 0, 1)) ?>
                                    </div>
                                    <div>
                                        <a href="<?= adminUrl('users/edit/' . $user['id']) ?>">
                                            <strong><?= e($user['username']) ?></strong>
                                        </a>
                                        <?php if ($user['id'] === currentUserId()): ?>
                                            <span class="badge badge-info" style="font-size: 0.65rem;">You</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="text-muted"><?= e($user['email']) ?></td>
                            <td>
                                <?php
                                $roleBadge = match($user['role']) {
                                    'admin' => 'badge-danger',
                                    'editor' => 'badge-info',
                                    default => 'badge-warning',
                                };
                                ?>
                                <span class="badge <?= $roleBadge ?>"><?= ucfirst($user['role']) ?></span>
                            </td>
                            <td>
                                <?php if ($user['is_active']): ?>
                                    <span class="badge badge-success">Active</span>
                                <?php else: ?>
                                    <span class="badge badge-warning">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-muted">
                                <?= $user['last_login'] ? date('M j, Y H:i', strtotime($user['last_login'])) : 'Never' ?>
                            </td>
                            <td>
                                <div class="table-actions">
                                    <a href="<?= adminUrl('users/edit/' . $user['id']) ?>" class="btn btn-secondary btn-sm" title="Edit">
                                        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" width="16" height="16">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" />
                                        </svg>
                                    </a>
                                    <?php if ($user['id'] !== currentUserId()): ?>
                                        <form method="POST" action="<?= adminUrl('users/delete/' . $user['id']) ?>" style="display: inline;">
                                            <?= csrfField() ?>
                                            <button type="submit" class="btn btn-danger btn-sm" title="Delete" data-confirm="Are you sure you want to delete this user?">
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
    </div>
</div>

<?php
$content = ob_get_clean();
include CMS_VIEWS . '/layouts/admin.php';
?>
