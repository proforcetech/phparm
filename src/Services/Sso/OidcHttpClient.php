<?php

namespace App\Services\Sso;

use RuntimeException;

/**
 * Tiny curl wrapper around the two HTTP exchanges OIDC needs:
 * POST to the token endpoint and GET to the userinfo endpoint.
 *
 * Kept as a thin class (not a callable in SsoService) so tests can
 * substitute an in-memory implementation that returns fixture
 * payloads without touching the network. The default implementation
 * uses curl directly to avoid pulling in a Guzzle/PSR-7 dependency
 * for two HTTP calls.
 */
class OidcHttpClient
{
    /**
     * Execute a token exchange — `POST {token_endpoint}` with
     * application/x-www-form-urlencoded body. Returns decoded JSON.
     *
     * @param array<string, string> $form
     * @return array<string, mixed>
     */
    public function postForm(string $url, array $form, ?string $basicAuth = null): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }
        $headers = ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'];
        if ($basicAuth !== null) {
            $headers[] = 'Authorization: Basic ' . base64_encode($basicAuth);
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($form),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            throw new RuntimeException('Token endpoint returned no body.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Token endpoint returned non-JSON: HTTP ' . $code);
        }
        if ($code >= 400) {
            $err = $decoded['error_description'] ?? $decoded['error'] ?? 'HTTP ' . $code;
            throw new RuntimeException('Token endpoint error: ' . $err);
        }
        return $decoded;
    }

    /**
     * GET a JSON resource with bearer auth — used for userinfo.
     *
     * @return array<string, mixed>
     */
    public function getJson(string $url, string $bearerToken): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init failed.');
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Authorization: Bearer ' . $bearerToken,
            ],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if (!is_string($body)) {
            throw new RuntimeException('Userinfo endpoint returned no body.');
        }
        $decoded = json_decode($body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Userinfo endpoint returned non-JSON: HTTP ' . $code);
        }
        if ($code >= 400) {
            $err = $decoded['error_description'] ?? $decoded['error'] ?? 'HTTP ' . $code;
            throw new RuntimeException('Userinfo endpoint error: ' . $err);
        }
        return $decoded;
    }
}
