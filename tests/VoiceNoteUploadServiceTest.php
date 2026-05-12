<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

use App\Models\User;
use App\Services\VoiceNotes\VoiceNoteUploadService;

/**
 * R-01 — VoiceNoteUploadService coverage. The service is the ONLY
 * supported way to land an audio file into the voice-notes tree, so
 * the test surface is deliberately wider than a normal "happy path
 * plus one negative". We exercise:
 *
 *   - constructor: empty storage root + non-positive max bytes blow up.
 *   - happy path: ingest writes a file at {yyyy}/{mm}/{user_id}/{ulid}.{ext}
 *     and returns a struct populated with the sniffed mime, the byte
 *     size, the sha256 hex digest, the storage-relative path, the
 *     extension keyed off the sniffed mime, and the sanitised
 *     original_name.
 *   - sha256 correctness: the digest matches hash_file('sha256', src).
 *   - MIME rejection: text bytes, junk bytes, and the wrong-extension
 *     case (file claims .mp3 in the upload name but is really plain
 *     text — server sniffs and rejects).
 *   - size cap: oversized payloads reported via $file['size'] > max.
 *   - empty files rejected.
 *   - missing tmp_name / wrong UPLOAD_ERR_* code rejected.
 *   - authorless actor rejected (would otherwise file under `0/`).
 *   - resolveStoredFile:
 *       * normal hit returns the realpath.
 *       * `..` segment refused with a RuntimeException.
 *       * absolute path refused.
 *       * missing file returns null (caller turns that into a 404).
 *       * path that escapes the root via symlink refused.
 *
 * Test ergonomics: PHP's `is_uploaded_file()` requires an actual SAPI
 * upload context, so we instantiate the service with the
 * `requireUploadedFile: false` escape hatch. The protected `persist()`
 * method already falls back to rename + copy when that flag is off, so
 * we can round-trip a real tempnam() file through the pipeline.
 */

class VusFakeStorage
{
    public string $root;

    public function __construct()
    {
        $this->root = sys_get_temp_dir() . '/vn_upload_test_' . bin2hex(random_bytes(6));
        mkdir($this->root, 0750, true);
        register_shutdown_function(function () {
            $this->rrmdir($this->root);
        });
    }

    private function rrmdir(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $rii = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($rii as $f) {
            $real = $f->getPathname();
            // realpath() collapses through symlinks — here we want to
            // delete the link itself, not its target.
            if (is_link($real)) {
                unlink($real);
            } elseif ($f->isDir()) {
                rmdir($real);
            } else {
                unlink($real);
            }
        }
        rmdir($path);
    }
}

function vusAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $e = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $a = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$e}, got {$a}");
    }
}

function vusAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function vusAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new RuntimeException(
                "FAIL {$msg}: got " . get_class($e) . " (\"" . $e->getMessage() . "\") expected {$expectedClass}"
            );
        }
        return;
    }
    throw new RuntimeException("FAIL {$msg}: no exception thrown (expected {$expectedClass})");
}

function vusMakeUser(int $id = 7): User
{
    $u = new User();
    $u->id = $id;
    return $u;
}

function vusMakeUploadService(string $root, int $maxBytes = 1024 * 1024): VoiceNoteUploadService
{
    return new VoiceNoteUploadService(
        storageRoot: $root,
        allowedMimeMap: null,
        maxUploadBytes: $maxBytes,
        requireUploadedFile: false,
    );
}

/**
 * Drop $bytes into a tempnam() file and return a $_FILES-style array
 * pointing at it. Mirrors how PHP populates $_FILES for a multipart
 * upload (tmp_name, name, type, size, error).
 *
 * @return array<string, mixed>
 */
function vusMakeFakeUpload(string $bytes, string $originalName = 'recording.mp3'): array
{
    $tmp = tempnam(sys_get_temp_dir(), 'vn_test_');
    if ($tmp === false) {
        throw new RuntimeException('tempnam failed');
    }
    file_put_contents($tmp, $bytes);
    return [
        'tmp_name' => $tmp,
        'name' => $originalName,
        // type is the CLIENT-supplied content type — the service ignores
        // it on purpose. Browsers and curl users both lie about this.
        'type' => 'application/octet-stream',
        'size' => strlen($bytes),
        'error' => UPLOAD_ERR_OK,
    ];
}

const VUS_MP3_BYTES = "\xFF\xFB\x90\x00";
const VUS_WAV_HEADER = "RIFF\x24\x00\x00\x00WAVEfmt \x10\x00\x00\x00\x01\x00\x01\x00\x44\xAC\x00\x00\x88\x58\x01\x00\x02\x00\x10\x00data\x00\x00\x00\x00";

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Constructor invariants ────

$tests['constructor_rejects_empty_storage_root'] = function () {
    vusAssertThrows(
        fn() => new VoiceNoteUploadService(storageRoot: '', requireUploadedFile: false),
        RuntimeException::class,
        'empty storage root must not be silently accepted'
    );
};

$tests['constructor_rejects_zero_or_negative_max_bytes'] = function () {
    vusAssertThrows(
        fn() => new VoiceNoteUploadService(storageRoot: '/tmp', maxUploadBytes: 0, requireUploadedFile: false),
        RuntimeException::class,
        'maxUploadBytes must be positive'
    );
    vusAssertThrows(
        fn() => new VoiceNoteUploadService(storageRoot: '/tmp', maxUploadBytes: -5, requireUploadedFile: false),
        RuntimeException::class,
        'negative maxUploadBytes must throw'
    );
};

// ──── Happy path ────

$tests['ingest_persists_file_and_returns_full_struct'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $bytes = VUS_MP3_BYTES . str_repeat("\x00", 256);
    $file = vusMakeFakeUpload($bytes, 'field-note.mp3');

    $upload = $service->ingest(vusMakeUser(42), $file, new DateTimeImmutable('2026-03-15 10:00:00'));

    vusAssertSame('mp3', $upload['audio_format']);
    vusAssertSame('audio/mpeg', $upload['audio_mime']);
    vusAssertSame(strlen($bytes), $upload['audio_size_bytes']);
    vusAssertSame(64, strlen($upload['audio_sha256_hash']), 'sha256 is 64 hex chars');
    vusAssertSame(hash('sha256', $bytes), $upload['audio_sha256_hash'], 'sha256 matches file bytes');
    vusAssertSame('field-note.mp3', $upload['original_name']);

    // Path: 2026/03/42/{ULID}.mp3
    vusAssertTrue(
        (bool) preg_match(
            '#^2026/03/42/[0-9A-HJKMNP-TV-Z]{26}\.mp3$#',
            $upload['audio_path']
        ),
        'audio_path follows yyyy/mm/user_id/ULID.ext format, got: ' . $upload['audio_path']
    );

    // File actually landed on disk with the right bytes.
    $absolute = $storage->root . '/' . $upload['audio_path'];
    vusAssertTrue(is_file($absolute), 'file persisted on disk');
    vusAssertSame($bytes, file_get_contents($absolute), 'bytes round-trip intact');
};

$tests['ingest_accepts_wav_and_uses_wav_extension'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    // R-01 — the extension is derived from the SNIFFED mime, not from
    // the uploaded filename. Even though we name this `recording.mp3`,
    // the service should sniff RIFF/WAVE and store it as `.wav`.
    $file = vusMakeFakeUpload(VUS_WAV_HEADER, 'recording.mp3');

    $upload = $service->ingest(vusMakeUser(1), $file);

    vusAssertSame('wav', $upload['audio_format'], 'extension comes from sniffed mime');
    vusAssertSame('audio/x-wav', $upload['audio_mime']);
    vusAssertTrue(
        str_ends_with($upload['audio_path'], '.wav'),
        'storage path uses the sniffed extension'
    );
};

$tests['ingest_uses_provided_clock_for_yyyy_mm_partition'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $file = vusMakeFakeUpload(VUS_MP3_BYTES . str_repeat("\x00", 16));

    $upload = $service->ingest(
        vusMakeUser(123),
        $file,
        new DateTimeImmutable('2024-12-31 23:59:59')
    );
    vusAssertTrue(
        str_starts_with($upload['audio_path'], '2024/12/123/'),
        'clock controls yyyy/mm partition: ' . $upload['audio_path']
    );
};

// ──── MIME validation ────

$tests['ingest_rejects_text_bytes'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $file = vusMakeFakeUpload("this is plainly not an audio file at all", 'spoof.mp3');

    vusAssertThrows(
        fn() => $service->ingest(vusMakeUser(7), $file),
        InvalidArgumentException::class,
        'text/plain must be rejected even when uploaded as .mp3'
    );
};

$tests['ingest_rejects_junk_octet_stream'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    // 32 random bytes — sniffs as application/octet-stream.
    $file = vusMakeFakeUpload(random_bytes(32), 'r.mp3');

    vusAssertThrows(
        fn() => $service->ingest(vusMakeUser(7), $file),
        InvalidArgumentException::class,
        'application/octet-stream is not in the audio allowlist'
    );
};

$tests['ingest_ignores_client_supplied_type'] = function () {
    // R-01 — the client's $_FILES['type'] is untrusted. Even when the
    // client lies and says "audio/mpeg", the service must sniff and
    // reject text/plain bytes.
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $file = vusMakeFakeUpload("hello world", 'r.mp3');
    $file['type'] = 'audio/mpeg';

    vusAssertThrows(
        fn() => $service->ingest(vusMakeUser(7), $file),
        InvalidArgumentException::class,
        'client-supplied Content-Type must not bypass server-side sniffing'
    );
};

// ──── Size + presence ────

$tests['ingest_rejects_oversize_payload'] = function () {
    $storage = new VusFakeStorage();
    // 16-byte cap — tiny for the test, normal for the spec is 25 MB.
    $service = vusMakeUploadService($storage->root, maxBytes: 16);
    $bytes = VUS_MP3_BYTES . str_repeat("\x00", 256);
    $file = vusMakeFakeUpload($bytes);

    vusAssertThrows(
        fn() => $service->ingest(vusMakeUser(7), $file),
        InvalidArgumentException::class,
        'payload over max bytes must be rejected'
    );
};

$tests['ingest_rejects_empty_file'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $file = vusMakeFakeUpload('', 'empty.mp3');

    vusAssertThrows(
        fn() => $service->ingest(vusMakeUser(7), $file),
        InvalidArgumentException::class,
        'zero-byte upload must be rejected before sniff'
    );
};

$tests['ingest_rejects_missing_tmp_name'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    vusAssertThrows(
        fn() => $service->ingest(vusMakeUser(7), ['error' => UPLOAD_ERR_OK]),
        InvalidArgumentException::class,
        'missing tmp_name must be rejected'
    );
};

$tests['ingest_rejects_non_ok_upload_error_code'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $tmp = tempnam(sys_get_temp_dir(), 'vn_test_');
    file_put_contents($tmp, VUS_MP3_BYTES);
    vusAssertThrows(
        fn() => $service->ingest(
            vusMakeUser(7),
            [
                'tmp_name' => $tmp,
                'name' => 'r.mp3',
                'type' => 'audio/mpeg',
                'size' => 4,
                'error' => UPLOAD_ERR_PARTIAL,
            ]
        ),
        InvalidArgumentException::class,
        'UPLOAD_ERR_PARTIAL must be reported'
    );
    @unlink($tmp);
};

$tests['ingest_rejects_actor_without_id'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $file = vusMakeFakeUpload(VUS_MP3_BYTES . str_repeat("\x00", 16));

    $u = new User();
    // u->id intentionally left null
    vusAssertThrows(
        fn() => $service->ingest($u, $file),
        RuntimeException::class,
        'authorless ingest must not silently write under 0/'
    );
};

// ──── Path-resolution containment ────

$tests['resolveStoredFile_returns_realpath_for_normal_hit'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $file = vusMakeFakeUpload(VUS_MP3_BYTES . str_repeat("\x00", 16));
    $upload = $service->ingest(vusMakeUser(7), $file, new DateTimeImmutable('2026-03-15'));

    $resolved = $service->resolveStoredFile($upload['audio_path']);
    vusAssertTrue($resolved !== null, 'resolves to a non-null path');
    vusAssertTrue(is_file($resolved), 'resolved path points at a real file');
    vusAssertSame(
        realpath($storage->root . '/' . $upload['audio_path']),
        $resolved
    );
};

$tests['resolveStoredFile_refuses_dotdot_segment'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    vusAssertThrows(
        fn() => $service->resolveStoredFile('../../etc/passwd'),
        RuntimeException::class,
        'path with .. must be refused, not silently null'
    );
};

$tests['resolveStoredFile_refuses_absolute_path'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    vusAssertThrows(
        fn() => $service->resolveStoredFile('/etc/passwd'),
        RuntimeException::class,
        'absolute path must be refused'
    );
};

$tests['resolveStoredFile_refuses_null_byte'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    vusAssertThrows(
        fn() => $service->resolveStoredFile("a/b\0/c"),
        RuntimeException::class,
        'null byte in path must be refused'
    );
};

$tests['resolveStoredFile_returns_null_for_missing'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    vusAssertSame(
        null,
        $service->resolveStoredFile('2026/03/7/01ABCDEFGHJKMNPQRSTVWXYZ12.mp3'),
        'missing file returns null (route layer maps that to 404)'
    );
};

$tests['resolveStoredFile_refuses_symlink_escape'] = function () {
    // Symlink-escape: plant a symlink inside the storage root that
    // targets /etc/passwd; resolveStoredFile must catch it via the
    // realpath containment check.
    if (DIRECTORY_SEPARATOR === '\\') {
        // Windows test runner — skip; symlinks need admin there.
        return;
    }
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $linkRel = 'escape.mp3';
    $linkAbs = $storage->root . '/' . $linkRel;
    if (!@symlink('/etc/passwd', $linkAbs)) {
        // CI sandbox without symlink permission — skip silently.
        return;
    }
    vusAssertThrows(
        fn() => $service->resolveStoredFile($linkRel),
        RuntimeException::class,
        'symlink escape via realpath must be caught'
    );
};

// ──── Original-name sanitisation ────

$tests['ingest_sanitises_original_name_traversal'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $file = vusMakeFakeUpload(
        VUS_MP3_BYTES . str_repeat("\x00", 16),
        '../../etc/passwd.mp3'
    );
    $upload = $service->ingest(vusMakeUser(7), $file);
    // Slashes collapse to underscores; the name is informational
    // only and must not be usable as a path component.
    vusAssertTrue(
        !str_contains($upload['original_name'], '/'),
        'sanitised original_name removes slashes'
    );
    vusAssertTrue(
        !str_contains($upload['original_name'], '\\'),
        'sanitised original_name removes backslashes'
    );
};

$tests['ingest_sanitises_control_chars_from_original_name'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $file = vusMakeFakeUpload(
        VUS_MP3_BYTES . str_repeat("\x00", 16),
        "note\x00\x01\x07.mp3"
    );
    $upload = $service->ingest(vusMakeUser(7), $file);
    vusAssertSame('note.mp3', $upload['original_name'],
        'control characters and null bytes stripped from original_name');
};

// ──── ULID uniqueness ────

$tests['ingest_generates_distinct_ulids_per_call'] = function () {
    $storage = new VusFakeStorage();
    $service = vusMakeUploadService($storage->root);
    $a = $service->ingest(
        vusMakeUser(7),
        vusMakeFakeUpload(VUS_MP3_BYTES . str_repeat("\x00", 16))
    );
    $b = $service->ingest(
        vusMakeUser(7),
        vusMakeFakeUpload(VUS_MP3_BYTES . str_repeat("\x00", 16))
    );
    vusAssertTrue(
        $a['audio_path'] !== $b['audio_path'],
        'consecutive uploads from the same user produce distinct ULIDs'
    );
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "VoiceNoteUploadServiceTest\n";
$pass = 0;
$fail = 0;
foreach ($tests as $name => $fn) {
    try {
        $fn();
        echo "  ✓ {$name}\n";
        $pass++;
    } catch (Throwable $e) {
        echo "  ✗ {$name}: " . $e->getMessage() . "\n";
        $fail++;
    }
}
echo "\n{$pass} passed, {$fail} failed\n";
if ($fail > 0) {
    exit(1);
}
