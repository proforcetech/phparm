<?php

namespace App\Services\Inspection;

use App\Support\Auth\AccessGate;
use InvalidArgumentException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Throwable;

/**
 * Controller for Inspection-to-Estimate Bridge API endpoints.
 */
class InspectionEstimateBridgeController
{
    private InspectionEstimateBridgeService $service;
    private AccessGate $gate;

    public function __construct(InspectionEstimateBridgeService $service, AccessGate $gate)
    {
        $this->service = $service;
        $this->gate = $gate;
    }

    /**
     * GET /api/inspections/{id}/failed-items
     * Get list of failed items from an inspection report.
     */
    public function failedItems(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $user = $this->gate->user($request);
            $this->gate->assert($user, 'inspections.view');

            $reportId = (int) ($args['id'] ?? 0);
            if ($reportId <= 0) {
                return $this->jsonError($response, 'Invalid report ID', 400);
            }

            $failedItems = $this->service->identifyFailedItems($reportId);

            return $this->json($response, [
                'success' => true,
                'report_id' => $reportId,
                'failed_items' => $failedItems,
                'total_count' => count($failedItems),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Failed to retrieve failed items: ' . $e->getMessage(), 500);
        }
    }

    /**
     * GET /api/inspections/{id}/recommendations
     * Get recommendations for an inspection report.
     */
    public function recommendations(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $user = $this->gate->user($request);
            $this->gate->assert($user, 'inspections.view');

            $reportId = (int) ($args['id'] ?? 0);
            if ($reportId <= 0) {
                return $this->jsonError($response, 'Invalid report ID', 400);
            }

            $recommendations = $this->service->getRecommendations($reportId);

            return $this->json($response, [
                'success' => true,
                'report_id' => $reportId,
                'recommendations' => $recommendations,
                'pending_count' => count(array_filter($recommendations, fn($r) => $r['status'] === 'pending')),
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 404);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Failed to retrieve recommendations: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inspections/{id}/add-to-estimate
     * Add selected failed items to an existing estimate.
     * Body: { estimate_id: int, item_ids: int[], labor_rate?: float, tax_rate?: float }
     */
    public function addToEstimate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $user = $this->gate->user($request);
            $this->gate->assert($user, 'estimates.edit');

            $reportId = (int) ($args['id'] ?? 0);
            if ($reportId <= 0) {
                return $this->jsonError($response, 'Invalid report ID', 400);
            }

            $body = $this->parseBody($request);

            $estimateId = (int) ($body['estimate_id'] ?? 0);
            if ($estimateId <= 0) {
                return $this->jsonError($response, 'estimate_id is required', 400);
            }

            $itemIds = $body['item_ids'] ?? [];
            if (!is_array($itemIds) || empty($itemIds)) {
                return $this->jsonError($response, 'item_ids array is required', 400);
            }

            $laborRate = (float) ($body['labor_rate'] ?? 100.00);
            $taxRate = (float) ($body['tax_rate'] ?? 0.0);

            $result = $this->service->addToEstimate(
                $reportId,
                $estimateId,
                array_map('intval', $itemIds),
                $user->id ?? null,
                $laborRate,
                $taxRate
            );

            return $this->json($response, [
                'success' => true,
                'message' => 'Items added to estimate successfully',
                'data' => $result,
            ]);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Failed to add items to estimate: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inspections/{id}/create-estimate
     * Create a new estimate from selected failed items.
     * Body: { item_ids: int[], labor_rate?: float, tax_rate?: float }
     */
    public function createEstimate(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $user = $this->gate->user($request);
            $this->gate->assert($user, 'estimates.create');

            $reportId = (int) ($args['id'] ?? 0);
            if ($reportId <= 0) {
                return $this->jsonError($response, 'Invalid report ID', 400);
            }

            $body = $this->parseBody($request);

            $itemIds = $body['item_ids'] ?? [];
            if (!is_array($itemIds) || empty($itemIds)) {
                return $this->jsonError($response, 'item_ids array is required', 400);
            }

            $laborRate = (float) ($body['labor_rate'] ?? 100.00);
            $taxRate = (float) ($body['tax_rate'] ?? 0.0);

            $result = $this->service->createEstimateFromFailedItems(
                $reportId,
                array_map('intval', $itemIds),
                $user->id ?? null,
                $laborRate,
                $taxRate
            );

            return $this->json($response, [
                'success' => true,
                'message' => 'Estimate created successfully',
                'data' => $result,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Failed to create estimate: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inspections/{id}/add-to-workorder
     * Add selected failed items to an active workorder as a sub-estimate.
     * Body: { workorder_id: int, item_ids: int[], labor_rate?: float, tax_rate?: float }
     */
    public function addToWorkorder(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $user = $this->gate->user($request);
            $this->gate->assert($user, 'workorders.edit');

            $reportId = (int) ($args['id'] ?? 0);
            if ($reportId <= 0) {
                return $this->jsonError($response, 'Invalid report ID', 400);
            }

            $body = $this->parseBody($request);

            $workorderId = (int) ($body['workorder_id'] ?? 0);
            if ($workorderId <= 0) {
                return $this->jsonError($response, 'workorder_id is required', 400);
            }

            $itemIds = $body['item_ids'] ?? [];
            if (!is_array($itemIds) || empty($itemIds)) {
                return $this->jsonError($response, 'item_ids array is required', 400);
            }

            $laborRate = (float) ($body['labor_rate'] ?? 100.00);
            $taxRate = (float) ($body['tax_rate'] ?? 0.0);

            $result = $this->service->addToWorkorder(
                $reportId,
                $workorderId,
                array_map('intval', $itemIds),
                $user->id ?? null,
                $laborRate,
                $taxRate
            );

            return $this->json($response, [
                'success' => true,
                'message' => 'Sub-estimate created and linked to workorder',
                'data' => $result,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return $this->jsonError($response, $e->getMessage(), 400);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Failed to add items to workorder: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inspections/{id}/recommendations/{itemId}/decline
     * Decline a recommendation.
     */
    public function declineRecommendation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $user = $this->gate->user($request);
            $this->gate->assert($user, 'inspections.edit');

            $reportId = (int) ($args['id'] ?? 0);
            $itemId = (int) ($args['itemId'] ?? 0);

            if ($reportId <= 0 || $itemId <= 0) {
                return $this->jsonError($response, 'Invalid IDs', 400);
            }

            $success = $this->service->declineRecommendation($reportId, $itemId, $user->id ?? null);

            return $this->json($response, [
                'success' => $success,
                'message' => $success ? 'Recommendation declined' : 'Recommendation not found',
            ]);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Failed to decline recommendation: ' . $e->getMessage(), 500);
        }
    }

    /**
     * POST /api/inspections/{id}/recommendations/{itemId}/defer
     * Defer a recommendation for later.
     */
    public function deferRecommendation(ServerRequestInterface $request, ResponseInterface $response, array $args): ResponseInterface
    {
        try {
            $user = $this->gate->user($request);
            $this->gate->assert($user, 'inspections.edit');

            $reportId = (int) ($args['id'] ?? 0);
            $itemId = (int) ($args['itemId'] ?? 0);

            if ($reportId <= 0 || $itemId <= 0) {
                return $this->jsonError($response, 'Invalid IDs', 400);
            }

            $success = $this->service->deferRecommendation($reportId, $itemId, $user->id ?? null);

            return $this->json($response, [
                'success' => $success,
                'message' => $success ? 'Recommendation deferred' : 'Recommendation not found',
            ]);
        } catch (Throwable $e) {
            return $this->jsonError($response, 'Failed to defer recommendation: ' . $e->getMessage(), 500);
        }
    }

    // -------------------------------------------------------------------------
    // Response helpers
    // -------------------------------------------------------------------------

    /**
     * @param array<string, mixed> $data
     */
    private function json(ResponseInterface $response, array $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_THROW_ON_ERROR));
        return $response
            ->withStatus($status)
            ->withHeader('Content-Type', 'application/json');
    }

    private function jsonError(ResponseInterface $response, string $message, int $status = 400): ResponseInterface
    {
        return $this->json($response, [
            'success' => false,
            'error' => $message,
        ], $status);
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBody(ServerRequestInterface $request): array
    {
        $body = $request->getParsedBody();
        if (is_array($body)) {
            return $body;
        }

        $contents = (string) $request->getBody();
        if ($contents === '') {
            return [];
        }

        $decoded = json_decode($contents, true);
        return is_array($decoded) ? $decoded : [];
    }
}
