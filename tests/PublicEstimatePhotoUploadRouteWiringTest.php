<?php

declare(strict_types=1);

$routeFile = __DIR__ . '/../routes/api.php';
$contents = file_get_contents($routeFile);

if ($contents === false) {
    fwrite(STDERR, 'FAILED: unable to read routes/api.php' . PHP_EOL);
    exit(1);
}

$checks = [
    'public estimate route normalizes uploaded photos' => str_contains($contents, 'PublicEstimatePhotoUploadValidator::normalizeFiles($photos)'),
    'public estimate route validates photos before storage' => str_contains($contents, 'PublicEstimatePhotoUploadValidator::validate($photoFile)'),
    'public estimate route derives stored extension from validated MIME' => str_contains($contents, "\$photoUpload['extension']"),
    'public estimate route no longer reads $_FILES photos directly' => !str_contains($contents, "\$_FILES['photos']"),
];

$failed = false;
foreach ($checks as $scenario => $passed) {
    if (!$passed) {
        fwrite(STDERR, 'FAILED: ' . $scenario . PHP_EOL);
        $failed = true;
    }
}

if ($failed) {
    exit(1);
}

echo 'Public estimate photo upload route wiring checks passed.' . PHP_EOL;
