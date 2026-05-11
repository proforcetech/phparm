<?php

use App\Services\VoiceNotes\HeuristicTranscriber;
use App\Services\VoiceNotes\VoiceNoteController;
use App\Services\VoiceNotes\VoiceNoteRepository;
use App\Services\VoiceNotes\VoiceNoteService;
use App\Services\VoiceNotes\VoiceNoteTagRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * Voice-to-text note endpoints (Phase 10.7).
 *
 * Read perm:        voice_notes.view       (all reads + tags autocomplete)
 * Create perm:      voice_notes.create     (record a new audio note)
 * Transcribe perm:  voice_notes.transcribe (manually trigger / cron worker)
 * Manage perm:      voice_notes.manage     (edit metadata, pin/unpin, tags,
 *                                           delete)
 *
 * Transcriber binding defaults to the shipped HeuristicTranscriber (sidecar
 * `.txt` files next to audio). Production deployments swap it out for a
 * Whisper/Deepgram/AssemblyAI implementation by replacing the constructor
 * line below — no other route surface changes.
 *
 * Storage root: AUD-063 hardening — the HeuristicTranscriber now requires
 * a non-empty root so it can verify the resolved audio path stays under it
 * (defends against absolute-path planting and symlink escapes). The root
 * is created on first request if missing so deployments don't have to
 * remember a manual mkdir step.
 */
return function (Router $router, RouteContext $ctx): void {
    $storageRoot = dirname(__DIR__, 2) . '/storage/private/voice_notes';
    if (!is_dir($storageRoot)) {
        // 0750 — owner full, group read+exec, others nothing. Audio files
        // are PII-adjacent (transcripts of customer interactions), so the
        // group-read mask is the floor for letting log/cron workers reach
        // sidecars without giving the rest of the box visibility.
        @mkdir($storageRoot, 0750, true);
    }

    $repo = new VoiceNoteRepository($ctx->connection);
    $tagRepo = new VoiceNoteTagRepository($ctx->connection);
    $transcriber = new HeuristicTranscriber($storageRoot);
    $service = new VoiceNoteService($repo, $tagRepo, $transcriber, $ctx->gate);
    $controller = new VoiceNoteController($service);

    $router->group([Middleware::auth()], function (Router $router) use ($controller) {
        // ── attached lists ──────────────────────────────────────
        $router->get(
            '/api/workorders/{id}/voice-notes',
            function (Request $request) use ($controller) {
                return Response::json($controller->listForWorkorder(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->get(
            '/api/tickets/{id}/voice-notes',
            function (Request $request) use ($controller) {
                return Response::json($controller->listForTicket(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        // ── per-user views ──────────────────────────────────────
        $router->get(
            '/api/voice-notes/my',
            function (Request $request) use ($controller) {
                return Response::json($controller->listMine(
                    $request->getAttribute('user'),
                    $request->query()
                ));
            }
        );

        $router->get(
            '/api/voice-notes/pending',
            function (Request $request) use ($controller) {
                return Response::json($controller->listPendingTranscriptions(
                    $request->getAttribute('user'),
                    $request->query()
                ));
            }
        );

        $router->get(
            '/api/voice-notes/tags',
            function (Request $request) use ($controller) {
                return Response::json($controller->listAllTags(
                    $request->getAttribute('user')
                ));
            }
        );

        // ── single-note CRUD ────────────────────────────────────
        $router->get('/api/voice-notes/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getNote(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/voice-notes', function (Request $request) use ($controller) {
            return Response::created($controller->recordNote(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/voice-notes/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateNote(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/voice-notes/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->deleteNote(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        // ── lifecycle moves ─────────────────────────────────────
        $router->post(
            '/api/voice-notes/{id}/transcribe',
            function (Request $request) use ($controller) {
                return Response::json($controller->transcribeNote(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post('/api/voice-notes/{id}/pin', function (Request $request) use ($controller) {
            return Response::json($controller->pinNote(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/voice-notes/{id}/unpin', function (Request $request) use ($controller) {
            return Response::json($controller->unpinNote(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        // ── tagging ─────────────────────────────────────────────
        $router->put('/api/voice-notes/{id}/tags', function (Request $request) use ($controller) {
            return Response::json($controller->setTags(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });
    });
};
