<?php

require __DIR__ . '/vendor/autoload.php';

use App\Support\Env;

$envFile = __DIR__ . '/.env';
$GLOBALS['env'] = new Env($envFile);

function env(string $key, $default = null) {
    return $GLOBALS['env']->get($key, $default);
}

$config = [
    'database' => require __DIR__ . '/config/database.php',
    'settings' => require __DIR__ . '/config/settings.php',
    'filesystems' => require __DIR__ . '/config/filesystems.php',
    'notifications' => require __DIR__ . '/config/notifications.php',
    'audit' => require __DIR__ . '/config/audit.php',
    'auth' => require __DIR__ . '/config/auth.php',
    'appointments' => require __DIR__ . '/config/appointments.php',
    'cms' => require __DIR__ . '/config/cms.php',
    'recaptcha' => require __DIR__ . '/config/recaptcha.php',
];

return $config;
