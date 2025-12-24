<?php

namespace App\Services\Inventory;

use App\Models\InventoryItem;
use App\Support\Notifications\NotificationDispatcher;
use InvalidArgumentException;

class InventoryLowStockService
{
    private InventoryItemRepository $repository;
    private ?NotificationDispatcher $notifications;
    private string $templateKey;

    public function __construct(
        InventoryItemRepository $repository,
        ?NotificationDispatcher $notifications = null,
        string $templateKey = 'inventory.low_stock_alert'
    ) {
        $this->repository = $repository;
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

        return array_map(static function (InventoryItem $item) {
            $data = $item->toArray();
            $data['severity'] = $item->stock_quantity === 0 ? 'out' : 'low';
            $data['recommended_reorder'] = max(0, $item->reorder_quantity - $item->stock_quantity);

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
        $payload = [
            'total' => count($alerts),
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
}
