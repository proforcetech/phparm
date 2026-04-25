<?php

use App\Services\Assets\AssetLifecycleService;
use App\Services\Assets\SiteAssetRepository;
use App\Services\CapitalPlan\AgingAssetReportService;
use App\Services\CapitalPlan\CapitalPlanController;
use App\Services\CapitalPlan\CapitalPlanRepository;
use App\Services\CapitalPlan\CapitalPlanService;
use App\Services\CapitalPlan\CapitalScoringModelRepository;
use App\Support\Http\Middleware;
use App\Support\Http\Request;
use App\Support\Http\Response;
use App\Support\Http\RouteContext;
use App\Support\Http\Router;
use App\Support\Pdf\CapitalRecommendationPdfGenerator;

/**
 * Capital Planner endpoints (Phase 9 of docs/expansion-plan.md).
 *
 * 9.1 — aging asset reports at company / division / portfolio scope.
 *       Read perm: assets.view (company + division) / capital_plans.view (portfolio).
 * 9.2 — tunable lifecycle scoring models with division-scoped fallback.
 *       Read perm: capital_plans.view; write perm: capital_plans.manage.
 * 9.3 — multi-year budget planner + what-if scenarios.
 *       Read perm: capital_plans.view; write perm: capital_plans.manage.
 */
return function (Router $router, RouteContext $ctx): void {
    $siteAssetRepo = new SiteAssetRepository($ctx->connection);
    $lifecycle = new AssetLifecycleService($siteAssetRepo);
    $scoringModels = new CapitalScoringModelRepository($ctx->connection);
    $aging = new AgingAssetReportService(
        $ctx->connection,
        $siteAssetRepo,
        $lifecycle,
        $ctx->gate,
        $scoringModels,
    );
    $planRepo = new CapitalPlanRepository($ctx->connection);
    // Phase 9.4 — PDF generator is constructed lazily-safe (its constructor
    // throws if dompdf is missing), but we still wire it up here so any
    // import failure surfaces at boot rather than at request time.
    $recommendationPdf = null;
    try {
        $recommendationPdf = new CapitalRecommendationPdfGenerator();
    } catch (\Throwable) {
        $recommendationPdf = null;
    }
    $planService = new CapitalPlanService(
        $planRepo,
        $siteAssetRepo,
        $lifecycle,
        $scoringModels,
        $ctx->gate,
        $recommendationPdf,
    );
    $controller = new CapitalPlanController($aging, $scoringModels, $ctx->gate, $planService);

    $router->group([Middleware::auth()], function (Router $router) use ($controller, $ctx) {
        // ─── 9.1 aging report ─────────────────────────────────────────────
        $router->get('/api/capital-plan/aging/company/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->agingForCompany(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->get('/api/capital-plan/aging/division/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->agingForDivision(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->get('/api/capital-plan/aging/portfolio', function (Request $request) use ($controller) {
            return Response::json($controller->agingForPortfolio(
                $request->getAttribute('user')
            ));
        });

        // ─── 9.2 scoring model CRUD ───────────────────────────────────────
        $router->get('/api/capital-plan/scoring-models', function (Request $request) use ($controller) {
            $division = $request->queryParam('division_id');
            return Response::json($controller->listScoringModels(
                $request->getAttribute('user'),
                $division !== null ? (int) $division : null
            ));
        });

        $router->get('/api/capital-plan/scoring-models/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getScoringModel(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->post('/api/capital-plan/scoring-models', function (Request $request) use ($controller) {
            return Response::created($controller->createScoringModel(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->put('/api/capital-plan/scoring-models/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updateScoringModel(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->post(
            '/api/capital-plan/scoring-models/{id}/default',
            function (Request $request) use ($controller) {
                return Response::json($controller->setDefaultScoringModel(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->delete('/api/capital-plan/scoring-models/{id}', function (Request $request) use ($controller) {
            $controller->deleteScoringModel(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        // ─── 9.3 plans + scenarios ────────────────────────────────────────
        $router->get('/api/capital-plan/plans', function (Request $request) use ($controller) {
            return Response::json($controller->listPlans(
                $request->getAttribute('user'),
                [
                    'scope_type' => $request->queryParam('scope_type'),
                    'status' => $request->queryParam('status'),
                    'limit' => $request->queryParam('limit'),
                    'offset' => $request->queryParam('offset'),
                ]
            ));
        });

        $router->post('/api/capital-plan/plans', function (Request $request) use ($controller) {
            return Response::created($controller->createPlan(
                $request->getAttribute('user'),
                $request->body()
            ));
        });

        $router->get('/api/capital-plan/plans/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->getPlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            ));
        });

        $router->put('/api/capital-plan/plans/{id}', function (Request $request) use ($controller) {
            return Response::json($controller->updatePlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->delete('/api/capital-plan/plans/{id}', function (Request $request) use ($controller) {
            $controller->deletePlan(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id')
            );
            return Response::noContent();
        });

        $router->post('/api/capital-plan/plans/{id}/scenarios', function (Request $request) use ($controller) {
            return Response::created($controller->addScenario(
                $request->getAttribute('user'),
                (int) $request->getAttribute('id'),
                $request->body()
            ));
        });

        $router->put(
            '/api/capital-plan/scenarios/{id}',
            function (Request $request) use ($controller) {
                return Response::json($controller->updateScenario(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/capital-plan/scenarios/{id}',
            function (Request $request) use ($controller) {
                $controller->deleteScenario(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                );
                return Response::noContent();
            }
        );

        $router->get(
            '/api/capital-plan/scenarios/{id}/overrides',
            function (Request $request) use ($controller) {
                return Response::json($controller->listOverrides(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        $router->post(
            '/api/capital-plan/scenarios/{id}/overrides',
            function (Request $request) use ($controller) {
                return Response::created($controller->setOverride(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $request->body()
                ));
            }
        );

        $router->delete(
            '/api/capital-plan/overrides/{id}',
            function (Request $request) use ($controller) {
                $controller->removeOverride(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                );
                return Response::noContent();
            }
        );

        $router->get(
            '/api/capital-plan/scenarios/{id}/compute',
            function (Request $request) use ($controller) {
                return Response::json($controller->computeScenario(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id')
                ));
            }
        );

        // ─── 9.4 customer-facing recommendation PDF ───────────────────────
        $router->get(
            '/api/capital-plan/scenarios/{id}/recommendation.pdf',
            function (Request $request) use ($controller, $ctx) {
                $shopSettings = [
                    'shop_name' => $ctx->settingsRepository->get(
                        'shop.name',
                        $ctx->config['settings']['shop_name'] ?? 'Auto Repair Shop'
                    ),
                    'shop_address' => $ctx->settingsRepository->get(
                        'shop.address',
                        $ctx->config['settings']['shop_address'] ?? ''
                    ),
                    'shop_phone' => $ctx->settingsRepository->get(
                        'shop.phone',
                        $ctx->config['settings']['shop_phone'] ?? ''
                    ),
                ];
                $scopeLabel = $request->queryParam('scope_label');
                $pdf = $controller->recommendationPdf(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $shopSettings,
                    is_string($scopeLabel) && $scopeLabel !== '' ? $scopeLabel : null
                );
                $scenarioId = (int) $request->getAttribute('id');
                return Response::make($pdf, 200, [
                    'Content-Type' => 'application/pdf',
                    'Content-Disposition' => 'inline; filename="capital-recommendation-' . $scenarioId . '.pdf"',
                ]);
            }
        );

        $router->get(
            '/api/capital-plan/plans/{id}/compare',
            function (Request $request) use ($controller) {
                $idsParam = $request->queryParam('scenario_ids');
                $ids = [];
                if ($idsParam !== null && $idsParam !== '') {
                    foreach (explode(',', (string) $idsParam) as $tok) {
                        $tok = trim($tok);
                        if ($tok !== '' && ctype_digit($tok)) {
                            $ids[] = (int) $tok;
                        }
                    }
                }
                return Response::json($controller->compareScenarios(
                    $request->getAttribute('user'),
                    (int) $request->getAttribute('id'),
                    $ids
                ));
            }
        );
    });
};
