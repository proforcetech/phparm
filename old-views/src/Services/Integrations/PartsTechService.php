<?php

namespace App\Services\Integrations;

use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\SettingsRepository;
use InvalidArgumentException;

class PartsTechService
{
    private SettingsRepository $settings;
    private ?AuditLogger $audit;
    /**
     * @var callable
     */
    private $httpClient;

    public function __construct(SettingsRepository $settings, ?AuditLogger $audit = null, ?callable $httpClient = null)
    {
        $this->settings = $settings;
        $this->audit = $audit;
        $this->httpClient = $httpClient ?? [$this, 'sendHttpRequest'];
    }

    public function isConfigured(): bool
    {
        $key = (string) ($this->settings->get('integrations.partstech.api_key') ?? '');

        return trim($key) !== '';
    }

    /**
     * @return array{label: string, vehicle: array<string, string|null>}
     */
    public function decodeVin(string $vin): array
    {
        if (!$this->isConfigured()) {
            throw new InvalidArgumentException('PartsTech API key missing');
        }

        $vin = strtoupper(trim($vin));
        if ($vin === '') {
            throw new InvalidArgumentException('VIN is required');
        }

        $response = $this->request('GET', '/catalog/v1/vehicles/lookup', ['vin' => $vin]);

        $vehicle = $response['data'] ?? [];
        $label = trim(($vehicle['year'] ?? '') . ' ' . ($vehicle['make'] ?? '') . ' ' . ($vehicle['model'] ?? ''));

        return [
            'label' => $label,
            'vehicle' => [
                'year' => $vehicle['year'] ?? null,
                'make' => $vehicle['make'] ?? null,
                'model' => $vehicle['model'] ?? null,
                'engine' => $vehicle['engine'] ?? null,
            ],
        ];
    }

    /**
     * @param array<string, string|null> $vehicle
     * @return array<int, array{name: string, brand: string, partNumber: string, price: float|null, priceFormatted: string}>
     */
    public function searchParts(string $keyword, array $vehicle = []): array
    {
        if (!$this->isConfigured()) {
            throw new InvalidArgumentException('PartsTech API key missing');
        }

        $payload = [
            'query' => [
                'keyword' => $keyword,
            ],
            'options' => [
                'includePricing' => true,
            ],
        ];

        $vehiclePayload = array_filter([
            'year' => $vehicle['year'] ?? null,
            'make' => $vehicle['make'] ?? null,
            'model' => $vehicle['model'] ?? null,
            'engine' => $vehicle['engine'] ?? null,
        ]);
        if (!empty($vehiclePayload)) {
            $payload['vehicle'] = $vehiclePayload;
        }

        $response = $this->request('POST', '/catalog/v2/parts/search', $payload);

        $results = [];
        foreach (($response['items'] ?? []) as $item) {
            $price = isset($item['price']) ? (float) $item['price'] : null;
            $markup = $this->applyMarkup($price);
            $results[] = [
                'name' => $item['description'] ?? ($item['partNumber'] ?? ''),
                'brand' => $item['brand'] ?? '',
                'partNumber' => $item['partNumber'] ?? '',
                'price' => $markup['amount'],
                'priceFormatted' => $markup['formatted'],
            ];
        }

        return $results;
    }

    /**
     * @param mixed $body
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, $body): array
    {
        $base = rtrim((string) ($this->settings->get('integrations.partstech.api_base') ?? 'https://api.partstech.com'), '/');
        $apiKey = (string) ($this->settings->get('integrations.partstech.api_key') ?? '');
        $url = $base . $path;

        $headers = [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'Authorization' => 'Bearer ' . $apiKey,
            'X-Api-Key' => $apiKey,
        ];

        if ($method === 'GET' && is_array($body)) {
            $url .= '?' . http_build_query($body);
            $body = null;
        }

        $response = call_user_func($this->httpClient, $method, $url, $headers, $body);

        if (!is_array($response) || !isset($response['status'], $response['body'])) {
            $this->logError('invalid_response', 'Malformed HTTP response');
            throw new InvalidArgumentException('Invalid response from PartsTech');
        }

        $status = (int) $response['status'];
        $decoded = json_decode((string) $response['body'], true);
        if (!is_array($decoded)) {
            $this->logError('invalid_json', $response['body']);
            throw new InvalidArgumentException('Invalid response from PartsTech');
        }

        if ($status >= 400) {
            $message = $this->extractErrorMessage($decoded) ?? 'PartsTech request failed';
            $this->logError('http_' . $status, $decoded, $message);
            throw new InvalidArgumentException($message);
        }

        return $decoded;
    }

    private function extractErrorMessage(array $data): ?string
    {
        if (!empty($data['message']) && is_string($data['message'])) {
            return $data['message'];
        }
        if (!empty($data['error']) && is_string($data['error'])) {
            return $data['error'];
        }
        if (!empty($data['errors']) && is_array($data['errors'])) {
            foreach ($data['errors'] as $error) {
                if (is_array($error) && !empty($error['message']) && is_string($error['message'])) {
                    return $error['message'];
                }
            }
        }

        return null;
    }

    /**
     * @param float|null $price
     * @return array{amount: float|null, formatted: string}
     */
    private function applyMarkup(?float $price): array
    {
        if ($price === null) {
            return ['amount' => null, 'formatted' => ''];
        }

        $tiers = $this->settings->get('integrations.partstech.markup_tiers', []);
        $amount = $price;
        if (is_array($tiers)) {
            foreach ($tiers as $tier) {
                if (!is_array($tier) || !isset($tier['rate'])) {
                    continue;
                }
                $amount = $price + ($price * ((float) $tier['rate'] / 100));
                break;
            }
        }

        return [
            'amount' => $amount,
            'formatted' => '$' . number_format($amount, 2),
        ];
    }

    private function logError(string $code, $detail, ?string $displayMessage = null): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry('integration.partstech_error', 'integration', 0, null, [
            'code' => $code,
            'detail' => $detail,
            'message' => $displayMessage,
        ]));
    }

    /**
     * @param array<string, string> $headers
     * @param mixed $body
     * @return array{status: int, body: string}
     */
    private function sendHttpRequest(string $method, string $url, array $headers, $body): array
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);

        $formattedHeaders = [];
        foreach ($headers as $key => $value) {
            $formattedHeaders[] = $key . ': ' . $value;
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $formattedHeaders);

        if ($method !== 'GET' && $body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, is_string($body) ? $body : json_encode($body));
        }

        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($responseBody === false) {
            $this->logError('request_failed', $error ?: 'Unknown error');
            throw new InvalidArgumentException('Failed to contact PartsTech');
        }

        return ['status' => $status, 'body' => $responseBody];
    }
}

