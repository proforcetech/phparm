<?php

use App\Services\POS\PosHeartbeatIngestionService;
use App\Services\POS\PosHeartbeatRepository;
use App\Services\POS\PosTerminalController;
use App\Services\POS\PosTerminalRepository;
use App\Services\POS\PosTerminalService;
use App\Services\Security\ProgrammingLogRepository;
use App\Services\Tickets\TicketRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;

/**
 * POS terminal endpoints — Phase 16 / S2 of docs/woms-expansion-plan.md.
 *
 * Read perm:  pos_terminals.view
 * Write perm: pos_terminals.manage
 *
 * Endpoint groups:
 *   /api/pos/terminals                              — list + create
 *   /api/pos/terminals/{id}                         — show + update + delete
 *   /api/pos/terminals/{id}/rotate-secret           — rotate shared HMAC secret
 *   /api/pos/terminals/{id}/heartbeats              — paged heartbeat history
 *   /api/pos/heartbeats/{terminal_code}             — PUBLIC webhook receiver
 *                                                     (HMAC-verified, no auth)
 */
return function (Router $router, RouteContext $ctx): void {
    $terminalRepo = new PosTerminalRepository($ctx->connection);
    $heartbeatRepo = new PosHeartbeatRepository($ctx->connection);
    $logRepo = new ProgrammingLogRepository($ctx->connection);
    $ticketRepo = new TicketRepository($ctx->connection);
    $service = new PosTerminalService(
        $ctx->connection,
        $terminalRepo,
        $logRepo,
        $ctx->auditLogger,
    );
    $ingestion = new PosHeartbeatIngestionService(
        $ctx->connection,
        $terminalRepo,
        $heartbeatRepo,
        $logRepo,
        $ticketRepo,
        $ctx->auditLogger,
    );
    $controller = new PosTerminalController(
        $terminalRepo,
        $heartbeatRepo,
        $logRepo,
        $service,
        $ctx->gate,
    );

    // ---- PUBLIC: heartbeat webhook receiver -------------------------------
    //
    // Uses HMAC-SHA256 signature verification (X-PHParm-Signature header)
    // against the terminal's stored shared_secret — no JWT auth. The
    // service maps signature/payload errors into result codes that we turn
    // into appropriate HTTP statuses below.
    $router->post('/api/pos/heartbeats/{terminal_code}', function (Request $request) use ($ingestion) {
        $terminalCode = (string) $request->getAttribute('terminal_code');
        $signature = (string) ($request->header('X-PHParm-Signature')
            ?? $request->header('X-PHPArm-Signature')
            ?? '');
        $rawBody = $request->rawBody();
        $sourceIp = $request->getClientIp();

        $result = $ingestion->receive($terminalCode, $signature, $rawBody, $sourceIp);

        return match ($result['result']) {
            PosHeartbeatIngestionService::RESULT_OK => Response::json([
                'success' => true,
                'data' => [
                    'heartbeat_id' => $result['heartbeat']?->id,
                    'received_at' => $result['heartbeat']?->received_at,
                ],
            ]),
            PosHeartbeatIngestionService::RESULT_BAD_SIGNATURE => Response::unauthorized($result['message']),
            PosHeartbeatIngestionService::RESULT_UNKNOWN_TERMINAL => Response::notFound($result['message']),
            PosHeartbeatIngestionService::RESULT_DISABLED_TERMINAL => Response::forbidden($result['message']),
            PosHeartbeatIngestionService::RESULT_BAD_PAYLOAD => Response::badRequest($result['message']),
            default => Response::badRequest('unknown ingest result'),
        };
    });

    // ---- AUTHED: admin terminal management --------------------------------
    $router->group([Middleware::auth()], function (Router $router) use ($controller) {

        $router->get('/api/pos/terminals', function (Request $request) use ($controller) {
            $filters = [
                'customer_id' => $request->queryParam('customer_id'),
                'site_id' => $request->queryParam('site_id'),
                'status' => $request->queryParam('status'),
                'search' => $request->queryParam('search'),
            ];
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 100);

            return Response::json([
                'data' => $controller->index(
                    $request->getAttribute('user'),
                    array_filter($filters, static fn ($v) => $v !== null && $v !== ''),
                    $page,
                    $perPage
                ),
            ]);
        });

        $router->get('/api/pos/terminals/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->show(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ),
            ]);
        });

        $router->post('/api/pos/terminals', function (Request $request) use ($controller) {
            return Response::created([
                'data' => $controller->store(
                    $request->getAttribute('user'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->put('/api/pos/terminals/{id}', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->update(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body(),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->post('/api/pos/terminals/{id}/rotate-secret', function (Request $request) use ($controller) {
            return Response::json([
                'data' => $controller->rotateSecret(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->getClientIp()
                ),
            ]);
        });

        $router->delete('/api/pos/terminals/{id}', function (Request $request) use ($controller) {
            $controller->destroy(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->getClientIp()
            );
            return Response::noContent();
        });

        $router->get('/api/pos/terminals/{id}/heartbeats', function (Request $request) use ($controller) {
            $page = (int) $request->queryParam('page', 1);
            $perPage = (int) $request->queryParam('per_page', 100);
            return Response::json([
                'data' => $controller->listHeartbeats(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $page,
                    $perPage
                ),
            ]);
        });
    });
};
