<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login | FixItForUs CMS</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Bebas+Neue&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= url('cms-php/assets/css/admin.css') ?>">
</head>
<body>
    <div class="login-wrapper">
        <div class="login-card">
            <div class="login-logo">
                <h1>FixIt<span>ForUs</span></h1>
                <p class="text-muted">Content Management System</p>
            </div>

            <?php if ($error = getFlash('error')): ?>
                <div class="alert alert-error mb-2">
                    <?= e($error) ?>
                </div>
            <?php endif; ?>

            <form method="POST" action="<?= adminUrl('login') ?>">
                <?= csrfField() ?>

                <div class="form-group">
                    <label class="form-label" for="username">Username</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        class="form-control"
                        placeholder="Enter your username"
                        required
                        autofocus
                    >
                </div>

                <div class="form-group">
                    <label class="form-label" for="password">Password</label>
                    <input
                        type="password"
                        id="password"
                        name="password"
                        class="form-control"
                        placeholder="Enter your password"
                        required
                    >
                </div>

                <button type="submit" class="btn btn-primary btn-lg" style="width: 100%;">
                    Sign In
                </button>
            </form>

            <p class="text-muted mt-3" style="text-align: center; font-size: 0.85rem;">
                <a href="<?= url() ?>">Back to website</a>
            </p>
        </div>
    </div>
</body>
</html>
