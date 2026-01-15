<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Support\Notifications\NotificationDispatcher;
use InvalidArgumentException;

class InventoryLowStockService
{
    private InventoryItemRepository $repository;
    private ?InventoryStockOrderRepository $stockOrderRepository;
    private ?NotificationDispatcher $notifications;
    private string $templateKey;

    public function __construct(
        InventoryItemRepository $repository,
        ?InventoryStockOrderRepository $stockOrderRepository = null,
        ?NotificationDispatcher $notifications = null,
        string $templateKey = 'inventory.low_stock_alert'
    ) {
        $this->repository = $repository;
        $this->stockOrderRepository = $stockOrderRepository;
        $this->notifications = $notifications;
        $this->templateKey = $templateKey;
    }

    /**
     * Provide summary tile payload for dashboards including top offenders.
     *
     * @return array<string, mixed>
     */
    public function tile(int $limit = 5): array
    {
        $alerts = $this->repository->lowStockAlerts($limit, 0);
        $outOfStock = array_filter($alerts, static fn (array $row) => $row['severity'] === 'out');
        $low = array_filter($alerts, static fn (array $row) => $row['severity'] === 'low');

        return [
            'counts' => [
                'out_of_stock' => count($outOfStock),
                'low_stock' => count($low),
            ],
            'items' => $alerts,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<int, array<string, mixed>>
     */
    public function page(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        $filters['low_stock_only'] = true;
        $items = $this->repository->list($filters, $limit, $offset);
        $stockOrders = [];

        if ($this->stockOrderRepository !== null && $items !== []) {
            $itemIds = array_map(static fn (InventoryItem $item) => $item->id, $items);
            $stockOrders = $this->stockOrderRepository->getOpenOrdersByItemIds($itemIds);
        }

        return array_map(static function (InventoryItem $item) use ($stockOrders) {
            $data = $item->toArray();
            $data['severity'] = $item->stock_quantity === 0 ? 'out' : 'low';
            $data['recommended_reorder'] = max(0, $item->reorder_quantity - $item->stock_quantity);
            $data['expected_arrival_date'] = null;

            $status = $item->stock_quantity === 0 ? 'out_of_stock' : 'backorder';
            if (isset($stockOrders[$item->id])) {
                $order = $stockOrders[$item->id];
                $status = $order['status'] ?? $status;
                $data['expected_arrival_date'] = $order['expected_arrival_date'] ?? null;
            }

            $data['status'] = $status;

            return $data;
        }, $items);
    }

    /**
     * Dispatch an email alert summarizing low/out-of-stock items.
     *
     * @return array<string, mixed>
     */
    public function sendEmailAlert(string $recipient, ?string $subject = null, int $limit = 50): array
    {
        if ($this->notifications === null) {
            throw new InvalidArgumentException('Notification dispatcher is not configured for low stock alerts.');
        }

        $alerts = $this->repository->lowStockAlerts($limit, 0);
        $counts = $this->countAlerts($alerts);
        $itemsList = $this->formatItemsList($alerts);
        $payload = [
            'total' => count($alerts),
            'out_of_stock' => $counts['out_of_stock'],
            'low_stock' => $counts['low_stock'],
            'items_shown' => count($alerts),
            'items_list' => $itemsList,
            'items' => $alerts,
        ];

        $this->notifications->sendMail(
            $this->templateKey,
            $recipient,
            $payload,
            $subject ?? 'Low stock alert'
        );

        return $payload;
    }

    /**
     * @param array<int, array<string, mixed>> $alerts
     * @return array{out_of_stock: int, low_stock: int}
     */
    private function countAlerts(array $alerts): array
    {
        $out = 0;
        $low = 0;

        foreach ($alerts as $alert) {
            if (($alert['severity'] ?? null) === 'out') {
                $out++;
            } else {
                $low++;
            }
        }

        return [
            'out_of_stock' => $out,
            'low_stock' => $low,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $alerts
     */
    private function formatItemsList(array $alerts): string
    {
        if ($alerts === []) {
            return 'No low-stock items were found.';
        }

        $lines = [];
        foreach ($alerts as $alert) {
            $name = (string) ($alert['name'] ?? 'Item');
            $stock = (int) ($alert['stock_quantity'] ?? 0);
            $threshold = (int) ($alert['low_stock_threshold'] ?? 0);
            $reorder = (int) ($alert['recommended_reorder'] ?? 0);
            $status = ($alert['severity'] ?? 'low') === 'out' ? 'OUT' : 'LOW';

            $lines[] = sprintf(
                '- %s [%s] (Qty %d, Threshold %d, Reorder %d)',
                $name,
                $status,
                $stock,
                $threshold,
                $reorder
            );
        }

        return implode("\n", $lines);
    }
}
