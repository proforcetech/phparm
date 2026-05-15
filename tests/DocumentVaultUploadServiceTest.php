<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Services\Documents\DocumentVaultUploadService;

$rootDir = sys_get_temp_dir() . '/document-vault-test-' . bin2hex(random_bytes(6));
$legacyDir = sys_get_temp_dir() . '/document-vault-legacy-test-' . bin2hex(random_bytes(6));
mkdir($rootDir, 0775, true);
mkdir($legacyDir, 0775, true);

$pdfPath = tempnam(sys_get_temp_dir(), 'pdf');
file_put_contents($pdfPath, "%PDF-1.4\n1 0 obj\n<<>>\nendobj\ntrailer\n<<>>\n%%EOF\n", LOCK_EX);

$service = new DocumentVaultUploadService($rootDir, $legacyDir);
$stored = $service->store([
    'name' => '../Policy.pdf',
    'tmp_name' => $pdfPath,
    'size' => filesize($pdfPath),
    'error' => UPLOAD_ERR_OK,
], false);

$resolved = $service->resolveStoredPath($stored['file_path']);
$legacyPath = $legacyDir . '/legacy.pdf';
file_put_contents($legacyPath, 'legacy', LOCK_EX);
$legacyResolved = $service->resolveStoredPath('/uploads/document-vault/legacy.pdf');

$results = [
    [
        'scenario' => 'stores new document outside public-relative path',
        'passed' => str_starts_with($stored['file_path'], 'document-vault/')
            && !str_starts_with($stored['file_path'], '/uploads/'),
    ],
    [
        'scenario' => 'sanitizes original filename',
        'passed' => $stored['file_name'] === '.._Policy.pdf',
    ],
    [
        'scenario' => 'resolves stored document within private root',
        'passed' => is_string($resolved) && str_starts_with($resolved, realpath($rootDir)),
    ],
    [
        'scenario' => 'resolves legacy public document paths for compatibility',
        'passed' => $legacyResolved === realpath($legacyPath),
    ],
    [
        'scenario' => 'rejects traversal paths',
        'passed' => $service->resolveStoredPath('document-vault/../secret.pdf') === null,
    ],
];

if (is_string($resolved)) {
    @unlink($resolved);
}
@unlink($legacyPath);
@rmdir($legacyDir);
array_map('rmdir', glob($rootDir . '/*') ?: []);
@rmdir($rootDir);

$failures = array_filter($results, static fn (array $row): bool => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo 'Document Vault upload service tests passed.' . PHP_EOL;
