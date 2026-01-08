<?php

namespace App\Services\Integrations;

use App\Database\Connection;
use InvalidArgumentException;
use RuntimeException;

/**
 * Adapter for PartsTech API integration.
 * Handles parts search, pricing, availability, and ordering through PartsTech.
 */
class PartsTechAdapter
{
    private Connection $connection;
    private string $apiKey;
    private string $shopId;
    private string $baseUrl;
    private bool $enabled;

    private const API_BASE_URL = 'https://api.partstech.com/v1';

    public function __construct(Connection $connection)
    {
        $this->connection = $connection;
        $this->loadConfig();
    }

    /**
     * Search for parts by term and optional vehicle.
     *
     * @param array{year?: int, make?: string, model?: string, engine?: string} $vehicle
     * @return array<int, array<string, mixed>>
     */
    public function searchParts(string $query, array $vehicle = [], int $limit = 20): array
    {
        $this->assertEnabled();

        $params = [
            'q' => $query,
            'limit' => $limit,
        ];

        if (!empty($vehicle['year'])) {
            $params['year'] = $vehicle['year'];
        }
        if (!empty($vehicle['make'])) {
            $params['make'] = $vehicle['make'];
        }
        if (!empty($vehicle['model'])) {
            $params['model'] = $vehicle['model'];
        }
        if (!empty($vehicle['engine'])) {
            $params['engine'] = $vehicle['engine'];
        }

        $response = $this->request('GET', '/parts/search', $params);

        return $this->transformPartsResponse($response['parts'] ?? []);
    }

    /**
     * Get part details including pricing and availability.
     *
     * @return array<string, mixed>|null
     */
    public function getPartDetails(string $partId): ?array
    {
        $this->assertEnabled();

        try {
            $response = $this->request('GET', "/parts/{$partId}");
            return $this->transformPartDetail($response);
        } catch (RuntimeException $e) {
            return null;
        }
    }

    /**
     * Get real-time availability and pricing for multiple parts.
     *
     * @param array<int, string> $partIds
     * @return array<string, array<string, mixed>>
     */
    public function getAvailability(array $partIds): array
    {
        $this->assertEnabled();

        $response = $this->request('POST', '/parts/availability', [
            'part_ids' => $partIds,
            'shop_id' => $this->shopId,
        ]);

        $result = [];
        foreach ($response['availability'] ?? [] as $item) {
            $result[$item['part_id']] = [
                'part_id' => $item['part_id'],
                'in_stock' => $item['in_stock'] ?? false,
                'quantity_available' => $item['quantity_available'] ?? 0,
                'cost' => $item['cost'] ?? null,
                'list_price' => $item['list_price'] ?? null,
                'supplier' => $item['supplier'] ?? null,
                'eta' => $item['eta'] ?? null,
                'status' => $this->determineAvailabilityStatus($item),
            ];
        }

        return $result;
    }

    /**
     * Create a quote/cart in PartsTech.
     *
     * @param array<int, array{part_id: string, quantity: int}> $items
     * @return array<string, mixed>
     */
    public function createQuote(array $items, ?string $referenceNumber = null): array
    {
        $this->assertEnabled();

        $response = $this->request('POST', '/quotes', [
            'shop_id' => $this->shopId,
            'reference' => $referenceNumber,
            'items' => array_map(fn($item) => [
                'part_id' => $item['part_id'],
                'quantity' => $item['quantity'],
            ], $items),
        ]);

        return [
            'quote_id' => $response['quote_id'] ?? null,
            'total' => $response['total'] ?? 0,
            'items' => $response['items'] ?? [],
            'expires_at' => $response['expires_at'] ?? null,
        ];
    }

    /**
     * Convert a quote to an order.
     *
     * @return array<string, mixed>
     */
    public function placeOrder(string $quoteId, array $options = []): array
    {
        $this->assertEnabled();

        $payload = [
            'quote_id' => $quoteId,
            'shop_id' => $this->shopId,
        ];

        if (!empty($options['shipping_method'])) {
            $payload['shipping_method'] = $options['shipping_method'];
        }
        if (!empty($options['notes'])) {
            $payload['notes'] = $options['notes'];
        }

        $response = $this->request('POST', '/orders', $payload);

        return [
            'order_id' => $response['order_id'] ?? null,
            'status' => $response['status'] ?? 'pending',
            'total' => $response['total'] ?? 0,
            'estimated_delivery' => $response['estimated_delivery'] ?? null,
            'tracking' => $response['tracking'] ?? null,
        ];
    }

    /**
     * Get order status.
     *
     * @return array<string, mixed>|null
     */
    public function getOrderStatus(string $orderId): ?array
    {
        $this->assertEnabled();

        try {
            $response = $this->request('GET', "/orders/{$orderId}");

            return [
                'order_id' => $response['order_id'] ?? $orderId,
                'status' => $response['status'] ?? 'unknown',
                'items' => $response['items'] ?? [],
                'tracking' => $response['tracking'] ?? null,
                'shipped_at' => $response['shipped_at'] ?? null,
                'delivered_at' => $response['delivered_at'] ?? null,
            ];
        } catch (RuntimeException $e) {
            return null;
        }
    }

    /**
     * Cancel an order if possible.
     */
    public function cancelOrder(string $orderId): bool
    {
        $this->assertEnabled();

        try {
            $this->request('POST', "/orders/{$orderId}/cancel");
            return true;
        } catch (RuntimeException $e) {
            return false;
        }
    }

    /**
     * Get supported vehicle years.
     *
     * @return array<int>
     */
    public function getYears(): array
    {
        $this->assertEnabled();

        $response = $this->request('GET', '/vehicles/years');
        return $response['years'] ?? [];
    }

    /**
     * Get makes for a year.
     *
     * @return array<string>
     */
    public function getMakes(int $year): array
    {
        $this->assertEnabled();

        $response = $this->request('GET', '/vehicles/makes', ['year' => $year]);
        return $response['makes'] ?? [];
    }

    /**
     * Get models for a year and make.
     *
     * @return array<string>
     */
    public function getModels(int $year, string $make): array
    {
        $this->assertEnabled();

        $response = $this->request('GET', '/vehicles/models', [
            'year' => $year,
            'make' => $make,
        ]);
        return $response['models'] ?? [];
    }

    /**
     * Check if PartsTech integration is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    // -------------------------------------------------------------------------
    // Private methods
    // -------------------------------------------------------------------------

    private function loadConfig(): void
    {
        $stmt = $this->connection->pdo()->prepare(<<<SQL
            SELECT `key`, `value` FROM settings
            WHERE `key` IN ('partstech_enabled', 'partstech_api_key', 'partstech_shop_id')
        SQL);
        $stmt->execute();

        $settings = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $settings[$row['key']] = $row['value'];
        }

        $this->enabled = ($settings['partstech_enabled'] ?? 'false') === 'true';
        $this->apiKey = $settings['partstech_api_key'] ?? '';
        $this->shopId = $settings['partstech_shop_id'] ?? '';
        $this->baseUrl = self::API_BASE_URL;
    }

    private function assertEnabled(): void
    {
        if (!$this->enabled) {
            throw new InvalidArgumentException('PartsTech integration is not enabled.');
        }

        if (empty($this->apiKey)) {
            throw new InvalidArgumentException('PartsTech API key is not configured.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function request(string $method, string $endpoint, array $data = []): array
    {
        $url = $this->baseUrl . $endpoint;

        if ($method === 'GET' && !empty($data)) {
            $url .= '?' . http_build_query($data);
        }

        $ch = curl_init();

        $headers = [
            'Authorization: Bearer ' . $this->apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
            'X-Shop-Id: ' . $this->shopId,
        ];

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);

        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);

        curl_close($ch);

        if ($error) {
            throw new RuntimeException('PartsTech API error: ' . $error);
        }

        if ($httpCode >= 400) {
            $errorData = json_decode($response, true);
            $message = $errorData['message'] ?? 'Unknown error';
            throw new RuntimeException("PartsTech API error ({$httpCode}): {$message}");
        }

        return json_decode($response, true) ?? [];
    }

    /**
     * @param array<int, array<string, mixed>> $parts
     * @return array<int, array<string, mixed>>
     */
    private function transformPartsResponse(array $parts): array
    {
        return array_map(fn($part) => [
            'part_id' => $part['id'] ?? null,
            'part_number' => $part['part_number'] ?? null,
            'description' => $part['description'] ?? '',
            'brand' => $part['brand'] ?? null,
            'category' => $part['category'] ?? null,
            'cost' => $part['cost'] ?? null,
            'list_price' => $part['list_price'] ?? null,
            'image_url' => $part['image_url'] ?? null,
            'specifications' => $part['specifications'] ?? [],
            'compatibility' => $part['compatibility'] ?? [],
        ], $parts);
    }

    /**
     * @param array<string, mixed> $part
     * @return array<string, mixed>
     */
    private function transformPartDetail(array $part): array
    {
        return [
            'part_id' => $part['id'] ?? null,
            'part_number' => $part['part_number'] ?? null,
            'description' => $part['description'] ?? '',
            'brand' => $part['brand'] ?? null,
            'category' => $part['category'] ?? null,
            'cost' => $part['cost'] ?? null,
            'list_price' => $part['list_price'] ?? null,
            'core_charge' => $part['core_charge'] ?? null,
            'image_url' => $part['image_url'] ?? null,
            'images' => $part['images'] ?? [],
            'specifications' => $part['specifications'] ?? [],
            'compatibility' => $part['compatibility'] ?? [],
            'suppliers' => $part['suppliers'] ?? [],
            'alternatives' => $part['alternatives'] ?? [],
        ];
    }

    /**
     * @param array<string, mixed> $item
     */
    private function determineAvailabilityStatus(array $item): string
    {
        if (($item['in_stock'] ?? false) && ($item['quantity_available'] ?? 0) > 0) {
            return 'in_stock';
        }

        if (!empty($item['eta'])) {
            return 'available';
        }

        return 'special_order';
    }
}
