<?php

namespace App\Services\Integrations\ThirdParty;

use RuntimeException;

/**
 * Shared scaffolding for every adapter. Concrete adapters override
 * the metadata methods and implement testConnection() + sync().
 *
 * The HTTP shim (`request`) is intentionally minimal — it exists so
 * adapters can be wired without each one rolling its own curl block,
 * but it accepts a callable override at construction time so tests
 * can substitute a fixture without touching the network.
 */
abstract class AbstractIntegrationAdapter implements IntegrationAdapterInterface
{
    /** @var (callable(string, string, array<string, string>, ?string): array{status: int, body: string})|null */
    private $httpClient;

    /**
     * @param (callable(string, string, array<string, string>, ?string): array{status: int, body: string})|null $httpClient
     */
    public function __construct(?callable $httpClient = null)
    {
        $this->httpClient = $httpClient;
    }

    public function defaultCadenceMinutes(): ?int
    {
        return 60;
    }

    /**
     * Pluck a required credential out of the dict and fail loudly if
     * absent. Adapters call this at the top of testConnection / sync
     * so the orchestrator's try/catch records "missing client_secret"
     * rather than a confusing JSON-parse error from the remote API.
     *
     * @param array<string, mixed> $credentials
     */
    protected function requireCredential(array $credentials, string $key): string
    {
        $val = $credentials[$key] ?? null;
        if (!is_string($val) || $val === '') {
            throw new RuntimeException("Missing required credential: {$key}");
        }
        return $val;
    }

    /**
     * @param array<string, mixed> $settings
     */
    protected function setting(array $settings, string $key, mixed $default = null): mixed
    {
        return $settings[$key] ?? $default;
    }

    /**
     * Make an HTTP call. Defaults to curl; in tests an injected callable
     * returns canned responses. Body is JSON-encoded if an array is
     * passed, otherwise sent as-is.
     *
     * @param array<string, string> $headers
     * @param string|array<string, mixed>|null $body
     * @return array{status: int, body: string}
     */
    protected function request(string $method, string $url, array $headers = [], string|array|null $body = null): array
    {
        $bodyStr = is_array($body)
            ? json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            : $body;

        if ($this->httpClient !== null) {
            return ($this->httpClient)($method, $url, $headers, $bodyStr);
        }

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = $k . ': ' . $v;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        if ($bodyStr !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $bodyStr);
        }
        $resp = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if (!is_string($resp)) {
            throw new RuntimeException('HTTP request failed: ' . $err);
        }
        return ['status' => $code, 'body' => $resp];
    }

    /**
     * @return array<string, mixed>
     */
    protected function decodeJson(string $body): array
    {
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Expected JSON response, got: ' . substr($body, 0, 200));
        }
        return $decoded;
    }
}
