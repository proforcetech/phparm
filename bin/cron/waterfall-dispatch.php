<?php

/**
 * Waterfall Dispatch Processor
 *
 * This cron job processes expired job offers and triggers the next offer
 * in waterfall dispatch sequences.
 *
 * Recommended: Run every 15-30 seconds via cron or supervisor
 *
 * Example cron entry (every minute - adjust for more frequent runs):
 * * * * * * php /path/to/bin/cron/waterfall-dispatch.php >> /var/log/waterfall-dispatch.log 2>&1
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Database\Connection;
use App\Services\Dispatch\WaterfallDispatchService;
use App\Services\Dispatch\DispatchRecommendationService;
use App\Services\Dispatch\DriverJobOfferService;

$config = require __DIR__ . '/../../config/app.php';
$connection = new Connection($config['database']);

$recommendationService = new DispatchRecommendationService($connection);
$offerService = new DriverJobOfferService($connection);
$waterfallService = new WaterfallDispatchService($connection, $recommendationService, $offerService);

echo "[" . date('Y-m-d H:i:s') . "] Waterfall Dispatch Processor started\n";

try {
    // Process expired offers
    $expiredOffers = $waterfallService->processExpiredOffers();

    if (!empty($expiredOffers)) {
        echo "[" . date('Y-m-d H:i:s') . "] Processed " . count($expiredOffers) . " expired offers\n";

        foreach ($expiredOffers as $offer) {
            echo "  - Offer #{$offer['id']} for job {$offer['job_reference']} expired\n";
            if (!empty($offer['waterfall_sequence_id'])) {
                echo "    → Triggered next offer in sequence {$offer['waterfall_sequence_id']}\n";
            }
        }
    } else {
        echo "[" . date('Y-m-d H:i:s') . "] No expired offers to process\n";
    }

    // Check for stalled sequences (no pending offers but sequence still active)
    $activeSequences = $waterfallService->listActiveSequences();
    $stalledCount = 0;

    foreach ($activeSequences as $sequence) {
        $status = $waterfallService->getSequenceStatus($sequence['sequence_reference']);

        if ($status !== null) {
            $hasPendingOffer = false;
            foreach ($status['offers'] as $offer) {
                if ($offer['status'] === 'pending') {
                    $hasPendingOffer = true;
                    break;
                }
            }

            // If no pending offer and still have drivers to try, send next
            if (!$hasPendingOffer && $status['offers_remaining'] > 0) {
                echo "[" . date('Y-m-d H:i:s') . "] Resuming stalled sequence {$sequence['sequence_reference']}\n";
                $waterfallService->sendNextOffer((int)$sequence['id']);
                $stalledCount++;
            }
        }
    }

    if ($stalledCount > 0) {
        echo "[" . date('Y-m-d H:i:s') . "] Resumed $stalledCount stalled sequences\n";
    }

    echo "[" . date('Y-m-d H:i:s') . "] Waterfall Dispatch Processor completed successfully\n";

} catch (Exception $e) {
    echo "[" . date('Y-m-d H:i:s') . "] ERROR: " . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}

exit(0);
