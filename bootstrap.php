<?php

require __DIR__ . '/vendor/autoload.php';

use App\Support\Env;

$envFile = __DIR__ . '/.env';
$env = new Env($envFile);

function env(string $key, $default = null) use ($env) {
    return $env->get($key, $default);
}

$config = [
    'database' => require __DIR__ . '/config/database.php',
];

return $config;
