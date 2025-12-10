<?php
$isEdit = isset($user) && !empty($user['id']);
$pageTitle = $isEdit ? 'Edit User' : 'New User';
$currentPage = 'users';

ob_start();
?>

<form method="POST" action="<?= adminUrl($isEdit ? 'users/update/' . $user['id'] : 'users/create') ?>" data-validate>
    <?= csrfField() ?>

    <div style="display: grid; grid-template-columns: 2fr 1fr; gap: 1.5rem;">
        <!-- Main Content -->
        <div>
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">User Details</h3>
                </div>
                <div class="card-body">
                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="username">Username *</label>
                            <input
                                type="text"
                                id="username"
                                name="username"
                                class="form-control"
                                value="<?= e($user['username'] ?? '') ?>"
                                required
                                <?= $isEdit ? 'readonly' : '' ?>
                            >
                            <?php if ($isEdit): ?>
                                <span class="form-help">Username cannot be changed.</span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="email">Email *</label>
                            <input
                                type="email"
                                id="email"
                                name="email"
                                class="form-control"
                                value="<?= e($user['email'] ?? '') ?>"
                                required
                            >
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label class="form-label" for="password">
                                Password <?= $isEdit ? '' : '*' ?>
                            </label>
                            <input
                                type="password"
                                id="password"
                                name="password"
                                class="form-control"
                                <?= $isEdit ? '' : 'required' ?>
                                minlength="8"
                            >
                            <?php if ($isEdit): ?>
                                <span class="form-help">Leave blank to keep current password.</span>
                            <?php else: ?>
                                <span class="form-help">Minimum 8 characters.</span>
                            <?php endif; ?>
                        </div>

                        <div class="form-group">
                            <label class="form-label" for="role">Role *</label>
                            <select id="role" name="role" class="form-control" required>
                                <?php foreach (\CMS\Models\User::getRoles() as $value => $label): ?>
                                    <option value="<?= $value ?>" <?= ($user['role'] ?? 'editor') === $value ? 'selected' : '' ?>>
                                        <?= $label ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <span class="form-help">
                                <strong>Admin:</strong> Full access |
                                <strong>Editor:</strong> Manage content |
                                <strong>Viewer:</strong> View only
                            </span>
                        </div>
                    </div>
                </div>
            </div>

            <?php if ($isEdit && isset($user['created_at'])): ?>
                <div class="card">
                    <div class="card-header">
                        <h3 class="card-title">Account Information</h3>
                    </div>
                    <div class="card-body">
                        <table class="table">
                            <tbody>
                                <tr>
                                    <td><strong>Created</strong></td>
                                    <td><?= date('F j, Y \a\t g:i A', strtotime($user['created_at'])) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Last Updated</strong></td>
                                    <td><?= date('F j, Y \a\t g:i A', strtotime($user['updated_at'])) ?></td>
                                </tr>
                                <tr>
                                    <td><strong>Last Login</strong></td>
                                    <td><?= $user['last_login'] ? date('F j, Y \a\t g:i A', strtotime($user['last_login'])) : 'Never' ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
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
                                <?= ($user['is_active'] ?? true) ? 'checked' : '' ?>
                            >
                            <label for="is_active">Account Active</label>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary" style="width: 100%;">
                        <?= $isEdit ? 'Update User' : 'Create User' ?>
                    </button>
                </div>
            </div>

            <!-- Permissions Info -->
            <div class="card">
                <div class="card-header">
                    <h3 class="card-title">Role Permissions</h3>
                </div>
                <div class="card-body">
                    <div style="font-size: 0.85rem;">
                        <p class="mb-1"><strong class="text-danger">Administrator</strong></p>
                        <ul class="text-muted" style="margin-left: 1rem; margin-bottom: 1rem;">
                            <li>All permissions</li>
                            <li>Manage users</li>
                            <li>System settings</li>
                        </ul>

                        <p class="mb-1"><strong style="color: var(--info);">Editor</strong></p>
                        <ul class="text-muted" style="margin-left: 1rem; margin-bottom: 1rem;">
                            <li>Create/edit pages</li>
                            <li>Manage components</li>
                            <li>Clear cache</li>
                        </ul>

                        <p class="mb-1"><strong style="color: var(--warning);">Viewer</strong></p>
                        <ul class="text-muted" style="margin-left: 1rem;">
                            <li>View dashboard</li>
                            <li>View content</li>
                            <li>No editing access</li>
                        </ul>
                    </div>
                </div>
            </div>

            <?php if ($isEdit && $user['id'] !== currentUserId()): ?>
                <!-- Delete -->
                <div class="card">
                    <div class="card-body">
                        <form method="POST" action="<?= adminUrl('users/delete/' . $user['id']) ?>">
                            <?= csrfField() ?>
                            <button type="submit" class="btn btn-danger" style="width: 100%;" data-confirm="Are you sure you want to delete this user? This action cannot be undone.">
                                Delete User
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
