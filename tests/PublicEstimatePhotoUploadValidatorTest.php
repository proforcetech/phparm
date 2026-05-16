<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Services\EstimateRequest\PublicEstimatePhotoUploadValidator;

$imagePath = tempnam(sys_get_temp_dir(), 'img');
file_put_contents(
    $imagePath,
    base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wCEAAkGBxAQEBAQEBAPEA8QDw8PDw8PDw8QDw8QFREWFhURFRUYHSggGBolGxUVITEhJSkrLi4uFx8zODMsNygtLisBCgoKDg0OGxAQGyslICYtLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLS0tLf/AABEIAAEAAgMBIgACEQEDEQH/xAAVAAEBAAAAAAAAAAAAAAAAAAAABf/EABQQAQAAAAAAAAAAAAAAAAAAAAD/2gAMAwEAAhADEAAAAc8f/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAEP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAEP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAEP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAEP/aAAgBAQABPyF//9k='),
    LOCK_EX
);

$textPath = tempnam(sys_get_temp_dir(), 'txt');
file_put_contents($textPath, 'not an image', LOCK_EX);

$results = [];

$valid = PublicEstimatePhotoUploadValidator::validate([
    'name' => 'photo.JPG',
    'tmp_name' => $imagePath,
    'size' => filesize($imagePath),
    'error' => UPLOAD_ERR_OK,
], false);

$results[] = [
    'scenario' => 'jpeg image is accepted and normalized to safe extension',
    'passed' => $valid['mime_type'] === 'image/jpeg' && $valid['extension'] === 'jpg',
];

$sanitized = PublicEstimatePhotoUploadValidator::validate([
    'name' => '../photo.JPG',
    'tmp_name' => $imagePath,
    'size' => filesize($imagePath),
    'error' => UPLOAD_ERR_OK,
], false);

$results[] = [
    'scenario' => 'original filename metadata is sanitized',
    'passed' => $sanitized['original_name'] === '.._photo.JPG',
];

$textRejected = false;
try {
    PublicEstimatePhotoUploadValidator::validate([
        'name' => 'note.txt',
        'tmp_name' => $textPath,
        'size' => filesize($textPath),
        'error' => UPLOAD_ERR_OK,
    ], false);
} catch (InvalidArgumentException $exception) {
    $textRejected = true;
}

$results[] = [
    'scenario' => 'non-image file is rejected',
    'passed' => $textRejected,
];

$oversizedRejected = false;
try {
    PublicEstimatePhotoUploadValidator::validate([
        'name' => 'oversized.jpg',
        'tmp_name' => $imagePath,
        'size' => PublicEstimatePhotoUploadValidator::maxBytes() + 1,
        'error' => UPLOAD_ERR_OK,
    ], false);
} catch (InvalidArgumentException $exception) {
    $oversizedRejected = true;
}

$results[] = [
    'scenario' => 'oversized image is rejected',
    'passed' => $oversizedRejected,
];

$normalized = PublicEstimatePhotoUploadValidator::normalizeFiles([
    'name' => ['first.jpg', 'second.png', 'third.gif'],
    'type' => ['image/jpeg', 'image/png', 'image/gif'],
    'tmp_name' => ['/tmp/first', '/tmp/second', '/tmp/third'],
    'error' => [UPLOAD_ERR_OK, UPLOAD_ERR_OK, UPLOAD_ERR_OK],
    'size' => [10, 20, 30],
], 2);

$results[] = [
    'scenario' => 'multi-file upload shape is normalized and capped',
    'passed' => count($normalized) === 2
        && $normalized[0]['name'] === 'first.jpg'
        && $normalized[1]['tmp_name'] === '/tmp/second',
];

@unlink($imagePath);
@unlink($textPath);

$failures = array_filter($results, static fn (array $row): bool => $row['passed'] === false);

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAILED: ' . $failure['scenario'] . PHP_EOL);
    }
    exit(1);
}

echo "All public estimate photo upload validator tests passed." . PHP_EOL;
