<?php

declare(strict_types=1);

require __DIR__ . '/test_bootstrap.php';

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    echo "VoiceNoteServiceTest\n";
    echo "  skipped — pdo_sqlite extension not available in this environment\n";
    exit(0);
}

use App\Database\Connection;
use App\Models\User;
use App\Models\VoiceNote;
use App\Services\VoiceNotes\HeuristicTranscriber;
use App\Services\VoiceNotes\TranscriberInterface;
use App\Services\VoiceNotes\TranscriptionResult;
use App\Services\VoiceNotes\VoiceNoteController;
use App\Services\VoiceNotes\VoiceNoteRepository;
use App\Services\VoiceNotes\VoiceNoteService;
use App\Services\VoiceNotes\VoiceNoteTagRepository;
use App\Services\VoiceNotes\VoiceNoteUploadService;
use App\Support\Auth\AccessGate;
use App\Support\Auth\UnauthorizedException;

/**
 * Phase 10.7 of docs/expansion-plan.md — Voice-to-text notes for techs.
 *
 * Covers:
 *   - VoiceNote model constants (STATUSES, ALLOWED_TRANSITIONS,
 *     VISIBILITIES, SUPPORTED_FORMATS).
 *   - HeuristicTranscriber sidecar pattern: returns transcript when .txt
 *     exists, returns degraded placeholder when missing, throws on empty
 *     sidecar, refuses paths with `..`.
 *   - VoiceNoteRepository CRUD + listForWorkorder pinned-first ordering +
 *     listPendingTranscriptions oldest-first + listForAuthor.
 *   - VoiceNoteTagRepository idempotent addTag, replaceTags diff,
 *     normalisation (case, whitespace).
 *   - VoiceNoteService.record validates audio_path / format / visibility,
 *     forces author_user_id from actor, refuses .. in path.
 *   - transcribe() pending → completed via heuristic sidecar.
 *   - transcribe() pending → failed when transcriber throws.
 *   - transcribe() failed → completed via retry path.
 *   - transcribe() on completed re-runs in place without status flip.
 *   - updateNote validates visibility, allows pin/notes/transcript edit.
 *   - pin/unpin lifecycle.
 *   - setTags returns added/removed diff, dedupes case-collisions.
 *   - deleteNote sweeps tags via FK cascade.
 *   - All permission gates (.view, .create, .transcribe, .manage).
 *   - Controller envelope shape; getNote merges tags into payload.
 */

class VnInMemoryConnection extends Connection
{
    public function __construct(private PDO $inner)
    {
    }
    public function pdo(): PDO
    {
        return $this->inner;
    }
}

function vnSetUpDatabase(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    // SQLite needs FK enforcement turned on explicitly for ON DELETE CASCADE
    // to fire — without this, deleting a voice_note leaves orphan tags.
    $pdo->exec('PRAGMA foreign_keys = ON');

    $pdo->exec("CREATE TABLE voice_notes (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        workorder_id INTEGER NULL,
        ticket_id INTEGER NULL,
        vehicle_id INTEGER NULL,
        author_user_id INTEGER NULL,
        audio_path TEXT NOT NULL,
        audio_format TEXT NOT NULL DEFAULT 'mp3',
        audio_mime TEXT NULL,
        audio_size_bytes INTEGER NULL,
        audio_sha256_hash TEXT NULL,
        duration_seconds REAL NULL,
        transcript TEXT NULL,
        transcript_language TEXT NULL,
        transcription_provider TEXT NOT NULL DEFAULT 'heuristic_v1',
        transcription_status TEXT NOT NULL DEFAULT 'pending',
        transcription_started_at TEXT NULL,
        transcription_completed_at TEXT NULL,
        transcription_failure_reason TEXT NULL,
        confidence REAL NULL,
        visibility TEXT NOT NULL DEFAULT 'internal',
        pinned INTEGER NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        updated_at TEXT DEFAULT CURRENT_TIMESTAMP
    )");

    $pdo->exec("CREATE TABLE voice_note_tags (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        voice_note_id INTEGER NOT NULL,
        tag TEXT NOT NULL,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP,
        UNIQUE (voice_note_id, tag),
        FOREIGN KEY (voice_note_id) REFERENCES voice_notes(id) ON DELETE CASCADE
    )");

    return $pdo;
}

class VnPermissiveGate extends AccessGate
{
    /** @var array<string, bool> */
    public array $denials = [];
    public function __construct()
    {
    }
    public function can(User $user, string $permission, mixed $resource = null): bool
    {
        return empty($this->denials[$permission]);
    }
    public function assert(User $user, string $permission, mixed $resource = null): void
    {
        if (!empty($this->denials[$permission])) {
            throw new UnauthorizedException('User lacks permission: ' . $permission);
        }
    }
}

/**
 * In-memory transcriber that returns canned results — for forcing edge cases
 * (errors, language hints, low-confidence transcripts).
 */
class VnCannedTranscriber implements TranscriberInterface
{
    public int $callCount = 0;
    public ?string $lastPath = null;
    public ?string $lastHint = null;

    public function __construct(
        private readonly TranscriptionResult|Throwable $resultOrThrowable,
        private readonly string $label = 'canned_v1',
    ) {
    }
    public function transcribe(string $audioPath, ?string $languageHint = null): TranscriptionResult
    {
        $this->callCount++;
        $this->lastPath = $audioPath;
        $this->lastHint = $languageHint;
        if ($this->resultOrThrowable instanceof Throwable) {
            throw $this->resultOrThrowable;
        }
        return $this->resultOrThrowable;
    }
    public function label(): string
    {
        return $this->label;
    }
}

/**
 * Test double for VoiceNoteUploadService — bypasses constructor + ingest.
 * Returns a fixed upload struct so controller tests can exercise
 * recordNote() without touching the filesystem.
 */
class VnFakeUploadService extends VoiceNoteUploadService
{
    public ?array $lastFile = null;
    public ?array $lastActor = null;
    public function __construct()
    {
    }
    public function ingest(User $actor, array $file, ?DateTimeImmutable $now = null): array
    {
        $this->lastFile = $file;
        $this->lastActor = ['id' => $actor->id ?? null];
        return [
            'audio_path' => 'workorders/42/test.mp3',
            'audio_format' => 'mp3',
            'audio_mime' => 'audio/mpeg',
            'audio_size_bytes' => 1024,
            'audio_sha256_hash' => str_repeat('a', 64),
            'original_name' => 'recording.mp3',
        ];
    }
    public function resolveStoredFile(string $relativePath): ?string
    {
        return null;
    }
}

function makeVnFixture(?TranscriberInterface $transcriber = null): array
{
    $pdo = vnSetUpDatabase();
    $conn = new VnInMemoryConnection($pdo);
    $gate = new VnPermissiveGate();
    $repo = new VoiceNoteRepository($conn);
    $tagRepo = new VoiceNoteTagRepository($conn);
    $transcriber = $transcriber ?? new VnCannedTranscriber(new TranscriptionResult(
        transcript: 'fallback transcript',
        confidence: 0.9,
        language: 'en-US',
        durationSeconds: 12.5,
    ));
    $service = new VoiceNoteService($repo, $tagRepo, $transcriber, $gate);
    $uploads = new VnFakeUploadService();
    $controller = new VoiceNoteController($service, $uploads);
    return compact(
        'pdo', 'conn', 'gate', 'repo', 'tagRepo', 'transcriber',
        'service', 'uploads', 'controller'
    );
}

/**
 * Build a valid VoiceNoteUploadService::ingest() result for the
 * metadata-row tests. The audio_path can point at any string — the
 * service layer no longer validates it (path safety is the upload
 * service's job, exercised in VoiceNoteUploadServiceTest).
 */
function vnFakeUpload(string $audioPath = 'workorders/42/a.mp3', string $format = 'mp3'): array
{
    return [
        'audio_path' => $audioPath,
        'audio_format' => $format,
        'audio_mime' => $format === 'mp3' ? 'audio/mpeg' : "audio/{$format}",
        'audio_size_bytes' => 1024,
        'audio_sha256_hash' => str_repeat('a', 64),
        'original_name' => 'recording.' . $format,
    ];
}

function vnAssertSame($expected, $actual, string $msg = ''): void
{
    if ($expected !== $actual) {
        $expS = is_scalar($expected) || $expected === null ? var_export($expected, true) : json_encode($expected);
        $actS = is_scalar($actual) || $actual === null ? var_export($actual, true) : json_encode($actual);
        throw new RuntimeException("FAIL {$msg}: expected {$expS}, got {$actS}");
    }
}

function vnAssertTrue(bool $actual, string $msg = ''): void
{
    if (!$actual) {
        throw new RuntimeException("FAIL {$msg}");
    }
}

function vnAssertThrows(callable $fn, string $expectedClass, string $msg = ''): void
{
    try {
        $fn();
    } catch (Throwable $e) {
        if (!($e instanceof $expectedClass)) {
            throw new RuntimeException("FAIL {$msg}: got " . get_class($e) . " expected {$expectedClass}");
        }
        return;
    }
    throw new RuntimeException("FAIL {$msg}: no exception thrown (expected {$expectedClass})");
}

function makeVnUser(int $id = 7, string $role = 'technician'): User
{
    $u = new User();
    $u->id = $id;
    $u->role = $role;
    return $u;
}

/**
 * Build a temp dir + audio file (+ optional sidecar) on disk for the
 * heuristic-transcriber tests. The temp tree is registered with the
 * shutdown handler so it gets cleaned up at the end of the run.
 *
 * @return array{root: string, audioRel: string, audioAbs: string}
 */
function vnMakeAudioTree(?string $sidecarText = null, string $basename = 'note.mp3'): array
{
    $root = sys_get_temp_dir() . '/vn_test_' . bin2hex(random_bytes(6));
    mkdir($root, 0755, true);
    $rel = 'workorders/77/' . $basename;
    $abs = $root . '/' . $rel;
    if (!is_dir(dirname($abs))) {
        mkdir(dirname($abs), 0755, true);
    }
    file_put_contents($abs, "fake-audio-bytes");
    if ($sidecarText !== null) {
        file_put_contents($abs . '.txt', $sidecarText);
    }
    register_shutdown_function(function () use ($root) {
        if (is_dir($root)) {
            $rii = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($rii as $f) {
                $f->isDir() ? rmdir($f->getRealPath()) : unlink($f->getRealPath());
            }
            rmdir($root);
        }
    });
    return ['root' => $root, 'audioRel' => $rel, 'audioAbs' => $abs];
}

// ---------------------------------------------------------------------------
// Tests
// ---------------------------------------------------------------------------

$tests = [];

// ──── Model constants ────

$tests['statuses_published'] = function () {
    vnAssertSame(
        ['pending', 'transcribing', 'completed', 'failed'],
        VoiceNote::STATUSES
    );
};

$tests['allowed_transitions_published'] = function () {
    // pending → transcribing
    vnAssertTrue(in_array('pending', VoiceNote::ALLOWED_TRANSITIONS[VoiceNote::STATUS_TRANSCRIBING], true),
        'pending → transcribing allowed');
    // failed → transcribing (retry)
    vnAssertTrue(in_array('failed', VoiceNote::ALLOWED_TRANSITIONS[VoiceNote::STATUS_TRANSCRIBING], true),
        'failed → transcribing allowed');
    // transcribing → completed
    vnAssertTrue(in_array('transcribing', VoiceNote::ALLOWED_TRANSITIONS[VoiceNote::STATUS_COMPLETED], true),
        'transcribing → completed allowed');
    // transcribing → failed
    vnAssertTrue(in_array('transcribing', VoiceNote::ALLOWED_TRANSITIONS[VoiceNote::STATUS_FAILED], true),
        'transcribing → failed allowed');
};

$tests['visibilities_published'] = function () {
    vnAssertSame(['internal', 'customer_visible'], VoiceNote::VISIBILITIES);
};

$tests['supported_formats_published'] = function () {
    vnAssertTrue(in_array('mp3', VoiceNote::SUPPORTED_FORMATS, true), 'mp3 supported');
    vnAssertTrue(in_array('wav', VoiceNote::SUPPORTED_FORMATS, true), 'wav supported');
    vnAssertTrue(in_array('webm', VoiceNote::SUPPORTED_FORMATS, true), 'webm supported');
};

// ──── Heuristic transcriber ────

$tests['heuristic_label_is_versioned'] = function () {
    // AUD-063 — empty root is now an opt-in test escape hatch only.
    $t = new HeuristicTranscriber('', allowEmptyRootForTests: true);
    vnAssertSame('heuristic_v1', $t->label());
};

$tests['heuristic_constructor_rejects_empty_root_in_production'] = function () {
    vnAssertThrows(
        fn() => new HeuristicTranscriber(''),
        RuntimeException::class,
        'empty storage root must be opt-in for tests only'
    );
};

$tests['heuristic_refuses_absolute_path'] = function () {
    $t = new HeuristicTranscriber('/tmp');
    vnAssertThrows(
        fn() => $t->transcribe('/etc/passwd.mp3'),
        RuntimeException::class,
        'absolute paths must be refused at the transcriber boundary'
    );
};

$tests['heuristic_refuses_null_byte'] = function () {
    $t = new HeuristicTranscriber('/tmp');
    vnAssertThrows(
        fn() => $t->transcribe("note.mp3\0/etc/passwd"),
        RuntimeException::class,
        'null bytes in audio_path must be refused'
    );
};

$tests['heuristic_returns_sidecar_contents'] = function () {
    $tree = vnMakeAudioTree("Brake bleed complete on RR caliper.\nNo leaks observed.");
    $t = new HeuristicTranscriber($tree['root']);
    $result = $t->transcribe($tree['audioRel'], 'en-US');
    vnAssertTrue(str_contains($result->transcript, 'Brake bleed complete'),
        'sidecar text returned as transcript');
    vnAssertSame('en-US', $result->language);
    vnAssertSame(1.0, $result->confidence);
    vnAssertTrue($result->durationSeconds > 0, 'duration estimate populated');
};

$tests['heuristic_returns_placeholder_without_sidecar'] = function () {
    $tree = vnMakeAudioTree(null);
    $t = new HeuristicTranscriber($tree['root']);
    $result = $t->transcribe($tree['audioRel']);
    vnAssertTrue(str_contains($result->transcript, 'no transcription provider configured'),
        'placeholder transcript returned');
    vnAssertSame(0.0, $result->confidence, 'placeholder confidence is zero');
};

$tests['heuristic_throws_on_empty_sidecar'] = function () {
    $tree = vnMakeAudioTree('');
    $t = new HeuristicTranscriber($tree['root']);
    vnAssertThrows(
        fn() => $t->transcribe($tree['audioRel']),
        RuntimeException::class,
        'empty sidecar should throw'
    );
};

$tests['heuristic_refuses_dotdot_in_path'] = function () {
    $t = new HeuristicTranscriber('/tmp');
    vnAssertThrows(
        fn() => $t->transcribe('../../etc/passwd.mp3'),
        RuntimeException::class,
        'should refuse path with .. segment'
    );
};

// ──── Repository basics ────

$tests['repo_create_returns_full_row'] = function () {
    $f = makeVnFixture();
    $note = $f['repo']->create([
        'workorder_id' => 42,
        'author_user_id' => 7,
        'audio_path' => 'workorders/42/note.mp3',
        'audio_format' => 'mp3',
        'audio_size_bytes' => 12345,
    ]);
    vnAssertTrue($note->id > 0, 'id assigned');
    vnAssertSame(42, $note->workorder_id);
    vnAssertSame(7, $note->author_user_id);
    vnAssertSame('workorders/42/note.mp3', $note->audio_path);
    vnAssertSame('pending', $note->transcription_status);
};

$tests['repo_listForWorkorder_pinned_first'] = function () {
    $f = makeVnFixture();
    $first = $f['repo']->create(['workorder_id' => 50, 'audio_path' => 'a.mp3']);
    $second = $f['repo']->create(['workorder_id' => 50, 'audio_path' => 'b.mp3', 'pinned' => true]);
    $third = $f['repo']->create(['workorder_id' => 50, 'audio_path' => 'c.mp3']);
    $notes = $f['repo']->listForWorkorder(50);
    vnAssertSame(3, count($notes));
    // Pinned (b.mp3) should be first regardless of insertion order.
    vnAssertSame($second->id, $notes[0]->id, 'pinned floats to top');
    // Then newest-first by id desc among unpinned.
    vnAssertSame($third->id, $notes[1]->id);
    vnAssertSame($first->id, $notes[2]->id);
};

$tests['repo_listForTicket_filters_by_ticket'] = function () {
    $f = makeVnFixture();
    $f['repo']->create(['ticket_id' => 1, 'audio_path' => 'a.mp3']);
    $f['repo']->create(['ticket_id' => 2, 'audio_path' => 'b.mp3']);
    $f['repo']->create(['ticket_id' => 1, 'audio_path' => 'c.mp3']);
    vnAssertSame(2, count($f['repo']->listForTicket(1)));
    vnAssertSame(1, count($f['repo']->listForTicket(2)));
};

$tests['repo_listForAuthor_returns_per_user'] = function () {
    $f = makeVnFixture();
    $f['repo']->create(['author_user_id' => 7, 'audio_path' => 'a.mp3']);
    $f['repo']->create(['author_user_id' => 8, 'audio_path' => 'b.mp3']);
    $f['repo']->create(['author_user_id' => 7, 'audio_path' => 'c.mp3']);
    vnAssertSame(2, count($f['repo']->listForAuthor(7)));
    vnAssertSame(1, count($f['repo']->listForAuthor(8)));
};

$tests['repo_listPendingTranscriptions_oldest_first'] = function () {
    $f = makeVnFixture();
    $first = $f['repo']->create(['audio_path' => 'a.mp3']);
    $second = $f['repo']->create(['audio_path' => 'b.mp3']);
    $f['repo']->update($second->id, ['transcription_status' => 'completed']);
    $third = $f['repo']->create(['audio_path' => 'c.mp3']);
    $pending = $f['repo']->listPendingTranscriptions();
    vnAssertSame(2, count($pending));
    vnAssertSame($first->id, $pending[0]->id, 'oldest pending first');
    vnAssertSame($third->id, $pending[1]->id);
};

$tests['repo_update_writes_writable_columns_only'] = function () {
    $f = makeVnFixture();
    $note = $f['repo']->create(['audio_path' => 'a.mp3']);
    $f['repo']->update($note->id, [
        'transcript' => 'hello',
        'transcription_status' => 'completed',
        'pinned' => true,
        // Try to write a non-writable column — should be silently ignored.
        'created_at' => '1900-01-01 00:00:00',
    ]);
    $reloaded = $f['repo']->findById($note->id);
    vnAssertSame('hello', $reloaded->transcript);
    vnAssertSame('completed', $reloaded->transcription_status);
    vnAssertTrue($reloaded->pinned, 'pinned flag set');
    vnAssertTrue($reloaded->created_at !== '1900-01-01 00:00:00',
        'created_at not overwritten');
};

// ──── Tag repository ────

$tests['tag_repo_addTag_idempotent'] = function () {
    $f = makeVnFixture();
    $note = $f['repo']->create(['audio_path' => 'a.mp3']);
    vnAssertTrue($f['tagRepo']->addTag($note->id, 'brake'));
    vnAssertTrue(!$f['tagRepo']->addTag($note->id, 'brake'),
        'duplicate tag is no-op');
    vnAssertSame(1, count($f['tagRepo']->listForNote($note->id)));
};

$tests['tag_repo_normalises_case_and_whitespace'] = function () {
    $f = makeVnFixture();
    $note = $f['repo']->create(['audio_path' => 'a.mp3']);
    $f['tagRepo']->addTag($note->id, '  Brake   ');
    $f['tagRepo']->addTag($note->id, 'BRAKE');
    $tags = $f['tagRepo']->listForNote($note->id);
    vnAssertSame(1, count($tags), 'case + whitespace collapse to single tag');
    vnAssertSame('brake', $tags[0]->tag);
};

$tests['tag_repo_replaceTags_returns_diff'] = function () {
    $f = makeVnFixture();
    $note = $f['repo']->create(['audio_path' => 'a.mp3']);
    $f['tagRepo']->addTag($note->id, 'brake');
    $f['tagRepo']->addTag($note->id, 'tire');
    $diff = $f['tagRepo']->replaceTags($note->id, ['tire', 'oil']);
    sort($diff['added']);
    sort($diff['removed']);
    vnAssertSame(['oil'], $diff['added']);
    vnAssertSame(['brake'], $diff['removed']);
    $current = array_map(static fn($t) => $t->tag, $f['tagRepo']->listForNote($note->id));
    sort($current);
    vnAssertSame(['oil', 'tire'], $current);
};

$tests['tag_repo_listAllTags_distinct'] = function () {
    $f = makeVnFixture();
    $a = $f['repo']->create(['audio_path' => 'a.mp3']);
    $b = $f['repo']->create(['audio_path' => 'b.mp3']);
    $f['tagRepo']->addTag($a->id, 'brake');
    $f['tagRepo']->addTag($b->id, 'brake');
    $f['tagRepo']->addTag($b->id, 'tire');
    $all = $f['tagRepo']->listAllTags();
    sort($all);
    vnAssertSame(['brake', 'tire'], $all);
};

// ──── Service.record ────

$tests['record_persists_with_actor_as_author'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(
        makeVnUser(7),
        vnFakeUpload('workorders/42/note.m4a', 'm4a'),
        ['workorder_id' => 42]
    );
    vnAssertSame(7, $note->author_user_id, 'author_user_id from actor, not payload');
    vnAssertSame('m4a', $note->audio_format);
    vnAssertSame('audio/m4a', $note->audio_mime, 'mime from upload struct');
    vnAssertSame(1024, $note->audio_size_bytes, 'size from upload struct');
    vnAssertSame(64, strlen((string) $note->audio_sha256_hash), 'sha256 hex captured');
    vnAssertSame('pending', $note->transcription_status);
    vnAssertSame('canned_v1', $note->transcription_provider, 'provider stamped from transcriber label');
};

$tests['record_ignores_payload_author_id'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(
        makeVnUser(7),
        vnFakeUpload(),
        [
            // Attempt to spoof author — should be overwritten by actor id.
            'author_user_id' => 999,
        ]
    );
    vnAssertSame(7, $note->author_user_id);
};

$tests['record_rejects_client_supplied_audio_path'] = function () {
    // R-01 — the legacy contract let the client pass audio_path in JSON.
    // That field is now server-managed; any payload still using the
    // old shape must be rejected loudly so the bypass isn't silently
    // tolerated.
    $f = makeVnFixture();
    vnAssertThrows(
        fn() => $f['service']->record(
            makeVnUser(7),
            vnFakeUpload(),
            ['audio_path' => '/etc/passwd']
        ),
        InvalidArgumentException::class,
        'legacy client-supplied audio_path must be rejected'
    );
};

$tests['record_rejects_client_supplied_audio_mime'] = function () {
    $f = makeVnFixture();
    vnAssertThrows(
        fn() => $f['service']->record(
            makeVnUser(7),
            vnFakeUpload(),
            ['audio_mime' => 'audio/wav']
        ),
        InvalidArgumentException::class,
        'audio_mime is server-managed'
    );
};

$tests['record_rejects_client_supplied_audio_sha256'] = function () {
    $f = makeVnFixture();
    vnAssertThrows(
        fn() => $f['service']->record(
            makeVnUser(7),
            vnFakeUpload(),
            ['audio_sha256_hash' => str_repeat('b', 64)]
        ),
        InvalidArgumentException::class,
        'audio_sha256_hash is server-managed'
    );
};

$tests['record_rejects_client_supplied_audio_size'] = function () {
    $f = makeVnFixture();
    vnAssertThrows(
        fn() => $f['service']->record(
            makeVnUser(7),
            vnFakeUpload(),
            ['audio_size_bytes' => 1]
        ),
        InvalidArgumentException::class,
        'audio_size_bytes is server-managed'
    );
};

$tests['record_rejects_client_supplied_audio_format'] = function () {
    $f = makeVnFixture();
    vnAssertThrows(
        fn() => $f['service']->record(
            makeVnUser(7),
            vnFakeUpload(),
            ['audio_format' => 'wav']
        ),
        InvalidArgumentException::class,
        'audio_format is server-managed'
    );
};

$tests['record_rejects_malformed_upload_struct'] = function () {
    $f = makeVnFixture();
    // Missing audio_sha256_hash
    vnAssertThrows(
        fn() => $f['service']->record(
            makeVnUser(7),
            [
                'audio_path' => 'a.mp3',
                'audio_format' => 'mp3',
                'audio_mime' => 'audio/mpeg',
                'audio_size_bytes' => 1024,
            ],
            []
        ),
        InvalidArgumentException::class,
        'partial upload struct must be rejected'
    );
};

$tests['record_rejects_short_sha256'] = function () {
    $f = makeVnFixture();
    $upload = vnFakeUpload();
    $upload['audio_sha256_hash'] = 'shorthash';
    vnAssertThrows(
        fn() => $f['service']->record(makeVnUser(7), $upload, []),
        InvalidArgumentException::class,
        'sha256 hex must be exactly 64 chars'
    );
};

$tests['record_rejects_unknown_visibility'] = function () {
    $f = makeVnFixture();
    vnAssertThrows(
        fn() => $f['service']->record(
            makeVnUser(7),
            vnFakeUpload(),
            ['visibility' => 'public']
        ),
        InvalidArgumentException::class
    );
};

$tests['record_requires_create_permission'] = function () {
    $f = makeVnFixture();
    $f['gate']->denials['voice_notes.create'] = true;
    vnAssertThrows(
        fn() => $f['service']->record(makeVnUser(7), vnFakeUpload(), []),
        UnauthorizedException::class
    );
};

// ──── Service.transcribe ────

$tests['transcribe_pending_to_completed_via_canned'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $now = new DateTimeImmutable('2026-04-25 12:00:00');
    $result = $f['service']->transcribe(makeVnUser(7), $note->id, $now);
    vnAssertSame('completed', $result->transcription_status);
    vnAssertSame('fallback transcript', $result->transcript);
    vnAssertSame(0.9, $result->confidence);
    vnAssertSame('en-US', $result->transcript_language);
    vnAssertSame('2026-04-25 12:00:00', $result->transcription_completed_at);
};

$tests['transcribe_uses_recorder_supplied_duration'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), [
        'duration_seconds' => 99.0,
    ]);
    $result = $f['service']->transcribe(makeVnUser(7), $note->id);
    vnAssertSame(99.0, $result->duration_seconds,
        'recorder-supplied duration is authoritative');
};

$tests['transcribe_fills_duration_when_recorder_omitted'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $result = $f['service']->transcribe(makeVnUser(7), $note->id);
    vnAssertSame(12.5, $result->duration_seconds,
        'transcriber-supplied duration fills when recorder omitted');
};

$tests['transcribe_pending_to_failed_when_transcriber_throws'] = function () {
    $boom = new VnCannedTranscriber(new RuntimeException('whisper API unreachable'));
    $f = makeVnFixture($boom);
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $result = $f['service']->transcribe(makeVnUser(7), $note->id);
    vnAssertSame('failed', $result->transcription_status);
    vnAssertSame('whisper API unreachable', $result->transcription_failure_reason);
};

$tests['transcribe_failed_to_completed_via_retry'] = function () {
    $f = makeVnFixture();
    // Create a note that's already failed.
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['repo']->update($note->id, [
        'transcription_status' => 'failed',
        'transcription_failure_reason' => 'previous attempt timed out',
    ]);
    $result = $f['service']->transcribe(makeVnUser(7), $note->id);
    vnAssertSame('completed', $result->transcription_status);
    vnAssertSame(null, $result->transcription_failure_reason,
        'previous failure reason cleared on success');
};

$tests['transcribe_completed_re_runs_in_place_without_status_flip'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['service']->transcribe(makeVnUser(7), $note->id);
    // Swap in a different transcriber via fixture rebuild — but we can also
    // just call transcribe again and verify the row stays 'completed'.
    $second = $f['service']->transcribe(makeVnUser(7), $note->id);
    vnAssertSame('completed', $second->transcription_status, 'still completed');
    vnAssertSame(2, $f['transcriber']->callCount, 'transcriber called twice');
};

$tests['transcribe_with_heuristic_sidecar_end_to_end'] = function () {
    $tree = vnMakeAudioTree('Replaced front pads, no further symptoms.');
    $transcriber = new HeuristicTranscriber($tree['root']);
    $f = makeVnFixture($transcriber);
    $note = $f['service']->record(
        makeVnUser(7),
        vnFakeUpload($tree['audioRel'], 'mp3'),
        []
    );
    $result = $f['service']->transcribe(makeVnUser(7), $note->id);
    vnAssertSame('completed', $result->transcription_status);
    vnAssertTrue(str_contains($result->transcript, 'Replaced front pads'),
        'sidecar transcript flowed end-to-end');
    vnAssertSame('heuristic_v1', $result->transcription_provider);
};

$tests['transcribe_requires_transcribe_permission'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['gate']->denials['voice_notes.transcribe'] = true;
    vnAssertThrows(
        fn() => $f['service']->transcribe(makeVnUser(7), $note->id),
        UnauthorizedException::class
    );
};

$tests['transcribe_rejects_unknown_note'] = function () {
    $f = makeVnFixture();
    vnAssertThrows(
        fn() => $f['service']->transcribe(makeVnUser(7), 9999),
        InvalidArgumentException::class
    );
};

// ──── Service.updateNote / pin ────

$tests['updateNote_allows_visibility_change'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $updated = $f['service']->updateNote(makeVnUser(7), $note->id, [
        'visibility' => 'customer_visible',
    ]);
    vnAssertSame('customer_visible', $updated->visibility);
};

$tests['updateNote_rejects_unknown_visibility'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    vnAssertThrows(
        fn() => $f['service']->updateNote(makeVnUser(7), $note->id, [
            'visibility' => 'private',
        ]),
        InvalidArgumentException::class
    );
};

$tests['updateNote_allows_transcript_edit'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $updated = $f['service']->updateNote(makeVnUser(7), $note->id, [
        'transcript' => 'manual override',
    ]);
    vnAssertSame('manual override', $updated->transcript);
};

$tests['pin_unpin_lifecycle'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    vnAssertTrue(!$note->pinned, 'fresh note unpinned');
    $pinned = $f['service']->pin(makeVnUser(7), $note->id);
    vnAssertTrue($pinned->pinned, 'pin sets pinned');
    $unpinned = $f['service']->unpin(makeVnUser(7), $note->id);
    vnAssertTrue(!$unpinned->pinned, 'unpin clears pinned');
};

$tests['updateNote_requires_manage_permission'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['gate']->denials['voice_notes.manage'] = true;
    vnAssertThrows(
        fn() => $f['service']->updateNote(makeVnUser(7), $note->id, ['notes' => 'x']),
        UnauthorizedException::class
    );
};

// ──── Service.deleteNote / cascade ────

$tests['deleteNote_sweeps_tags_via_cascade'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['tagRepo']->addTag($note->id, 'brake');
    $f['tagRepo']->addTag($note->id, 'tire');
    $f['service']->deleteNote(makeVnUser(7), $note->id);
    vnAssertSame(null, $f['repo']->findById($note->id), 'note gone');
    vnAssertSame(0, count($f['tagRepo']->listForNote($note->id)),
        'cascade swept tags');
};

$tests['deleteNote_requires_manage_permission'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['gate']->denials['voice_notes.manage'] = true;
    vnAssertThrows(
        fn() => $f['service']->deleteNote(makeVnUser(7), $note->id),
        UnauthorizedException::class
    );
};

// ──── Service.setTags ────

$tests['setTags_returns_added_removed_diff'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['tagRepo']->addTag($note->id, 'brake');
    $bundle = $f['service']->setTags(makeVnUser(7), $note->id, ['brake', 'oil']);
    vnAssertSame(['oil'], $bundle['added']);
    vnAssertSame([], $bundle['removed']);
    sort($bundle['tags']);
    vnAssertSame(['brake', 'oil'], $bundle['tags']);
};

$tests['setTags_requires_manage_permission'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['gate']->denials['voice_notes.manage'] = true;
    vnAssertThrows(
        fn() => $f['service']->setTags(makeVnUser(7), $note->id, ['brake']),
        UnauthorizedException::class
    );
};

// ──── Service.reads ────

$tests['listForWorkorder_requires_view_permission'] = function () {
    $f = makeVnFixture();
    $f['gate']->denials['voice_notes.view'] = true;
    vnAssertThrows(
        fn() => $f['service']->listForWorkorder(makeVnUser(7), 42),
        UnauthorizedException::class
    );
};

$tests['listPendingTranscriptions_requires_view_permission'] = function () {
    $f = makeVnFixture();
    $f['gate']->denials['voice_notes.view'] = true;
    vnAssertThrows(
        fn() => $f['service']->listPendingTranscriptions(makeVnUser(7)),
        UnauthorizedException::class
    );
};

$tests['getNoteWithTags_returns_bundle'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['tagRepo']->addTag($note->id, 'brake');
    $bundle = $f['service']->getNoteWithTags(makeVnUser(7), $note->id);
    vnAssertSame($note->id, $bundle['note']->id);
    vnAssertSame(['brake'], $bundle['tags']);
};

// ──── Controller envelope shape ────

$tests['controller_recordNote_returns_data_envelope'] = function () {
    // R-01 — recordNote now takes (actor, $_FILES-style array, $payload).
    // The fake upload service ignores the file struct and returns canned
    // metadata; the payload carries the optional fields.
    $f = makeVnFixture();
    $resp = $f['controller']->recordNote(
        makeVnUser(7),
        ['tmp_name' => '/tmp/fake', 'name' => 'r.mp3', 'size' => 1024, 'error' => 0],
        ['workorder_id' => 42]
    );
    vnAssertTrue(array_key_exists('data', $resp));
    vnAssertSame(42, $resp['data']['workorder_id']);
    vnAssertSame('pending', $resp['data']['transcription_status']);
    vnAssertSame('audio/mpeg', $resp['data']['audio_mime'], 'mime persisted from upload');
    vnAssertSame(64, strlen((string) $resp['data']['audio_sha256_hash']));
};

$tests['controller_listForWorkorder_returns_data_envelope'] = function () {
    $f = makeVnFixture();
    $f['service']->record(makeVnUser(7), vnFakeUpload(), ['workorder_id' => 42]);
    $f['service']->record(makeVnUser(7), vnFakeUpload(), ['workorder_id' => 42]);
    $resp = $f['controller']->listForWorkorder(makeVnUser(7), 42);
    vnAssertTrue(array_key_exists('data', $resp));
    vnAssertSame(2, count($resp['data']));
};

$tests['controller_getNote_merges_tags_into_payload'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $f['tagRepo']->addTag($note->id, 'brake');
    $resp = $f['controller']->getNote(makeVnUser(7), $note->id);
    vnAssertTrue(array_key_exists('tags', $resp['data']),
        'getNote merges tags into the data payload');
    vnAssertSame(['brake'], $resp['data']['tags']);
    // R-01 — controller synthesises the auth-gated audio stream URL so the
    // React detail modal has a stable href.
    vnAssertSame(
        "/api/voice-notes/{$note->id}/audio",
        $resp['data']['audio_url'],
        'audio_url is server-synthesised, never derived from audio_path'
    );
};

$tests['controller_setTags_returns_added_and_removed'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $resp = $f['controller']->setTags(makeVnUser(7), $note->id, ['tags' => ['oil', 'tire']]);
    sort($resp['data']['added']);
    vnAssertSame(['oil', 'tire'], $resp['data']['added']);
    vnAssertSame([], $resp['data']['removed']);
};

$tests['controller_transcribeNote_returns_completed_envelope'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $resp = $f['controller']->transcribeNote(makeVnUser(7), $note->id);
    vnAssertSame('completed', $resp['data']['transcription_status']);
    vnAssertSame('fallback transcript', $resp['data']['transcript']);
};

$tests['controller_pin_unpin_round_trip'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $pinned = $f['controller']->pinNote(makeVnUser(7), $note->id);
    vnAssertTrue($pinned['data']['pinned']);
    $unpinned = $f['controller']->unpinNote(makeVnUser(7), $note->id);
    vnAssertTrue(!$unpinned['data']['pinned']);
};

$tests['controller_deleteNote_returns_deleted_marker'] = function () {
    $f = makeVnFixture();
    $note = $f['service']->record(makeVnUser(7), vnFakeUpload(), []);
    $resp = $f['controller']->deleteNote(makeVnUser(7), $note->id);
    vnAssertSame(true, $resp['data']['deleted']);
    vnAssertSame(null, $f['repo']->findById($note->id));
};

// ---------------------------------------------------------------------------
// Runner
// ---------------------------------------------------------------------------

echo "VoiceNoteServiceTest\n";
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
