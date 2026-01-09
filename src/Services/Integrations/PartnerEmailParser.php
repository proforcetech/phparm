<?php

namespace App\Services\Integrations;

class PartnerEmailParser
{
    private const CANCELLATION_KEYWORDS = [
        'cancel',
        'cancelled',
        'canceled',
        'cancellation',
        'void',
        'voided',
        'abort',
    ];

    /**
     * @return array{payload: array<string, mixed>, metadata: array<string, mixed>}
     */
    public function parse(string $rawEmail): array
    {
        $headers = [];
        $body = $rawEmail;

        if (str_contains($rawEmail, "\n\n")) {
            [$rawHeaders, $body] = preg_split("/\R\R/", $rawEmail, 2) ?: [$rawEmail, ''];
            $headers = $this->parseHeaders($rawHeaders ?? '');
        }

        $body = trim((string) $body);
        $payload = $this->parseBodyPayload($body);

        $metadata = array_filter([
            'subject' => $headers['subject'] ?? null,
            'from' => $headers['from'] ?? null,
            'to' => $headers['to'] ?? null,
        ], static fn ($value) => $value !== null && $value !== '');

        $cancellation = $this->detectCancellationSignals($headers, $body, $payload);
        if ($cancellation['detected']) {
            $metadata['cancellation_detected'] = true;
            $metadata['cancellation_signals'] = $cancellation['signals'];
        }

        return [
            'payload' => $payload,
            'metadata' => $metadata,
        ];
    }

    /**
     * @return array<string, string>
     */
    private function parseHeaders(string $rawHeaders): array
    {
        $headers = [];
        $lines = preg_split('/\R/', $rawHeaders) ?: [];
        $currentKey = null;

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }

            if (str_starts_with($line, ' ') || str_starts_with($line, "\t")) {
                if ($currentKey !== null) {
                    $headers[$currentKey] .= ' ' . trim($line);
                }
                continue;
            }

            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $currentKey = strtolower(trim($parts[0]));
                $headers[$currentKey] = trim($parts[1]);
            }
        }

        return $headers;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseBodyPayload(string $body): array
    {
        if ($body === '') {
            return [];
        }

        $trimmed = ltrim($body);
        if (str_starts_with($trimmed, '{') || str_starts_with($trimmed, '[')) {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        $payload = [];
        $lines = preg_split('/\R/', $body) ?: [];
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $key = strtolower(trim($parts[0]));
            $value = trim($parts[1]);
            if ($key !== '') {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }

    /**
     * @param array<string, string> $headers
     * @param array<string, mixed> $payload
     * @return array{detected: bool, signals: array<int, string>}
     */
    private function detectCancellationSignals(array $headers, string $body, array $payload): array
    {
        $signals = [];
        $subject = strtolower($headers['subject'] ?? '');
        $bodyLower = strtolower($body);

        foreach (self::CANCELLATION_KEYWORDS as $keyword) {
            if ($subject !== '' && str_contains($subject, $keyword)) {
                $signals[] = sprintf('subject:%s', $keyword);
            }
            if ($bodyLower !== '' && str_contains($bodyLower, $keyword)) {
                $signals[] = sprintf('body:%s', $keyword);
            }
        }

        foreach ($payload as $key => $value) {
            $keyName = strtolower((string) $key);
            if (str_contains($keyName, 'cancel')) {
                $signals[] = sprintf('payload_key:%s', $key);
                continue;
            }
            if (!is_string($value)) {
                continue;
            }
            $valueLower = strtolower($value);
            foreach (self::CANCELLATION_KEYWORDS as $keyword) {
                if (str_contains($valueLower, $keyword)) {
                    $signals[] = sprintf('payload_value:%s:%s', $key, $keyword);
                }
            }
        }

        $signals = array_values(array_unique($signals));

        return [
            'detected' => !empty($signals),
            'signals' => $signals,
        ];
    }
}
