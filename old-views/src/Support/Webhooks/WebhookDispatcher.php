<?php

namespace App\Support\Webhooks;

use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use RuntimeException;

class WebhookDispatcher
{
    /**
     * @var array<int, string>
     */
    private array $endpoints;
    private string $secret;
    private int $timeout;
    private ?AuditLogger $audit;

    /**
     * @param array<int, string> $endpoints
     */
    public function __construct(array $endpoints = [], string $secret = '', int $timeout = 5, ?AuditLogger $audit = null)
    {
        $this->endpoints = array_values(array_filter($endpoints, static fn ($value) => is_string($value) && $value !== ''));
        $this->secret = $secret;
        $this->timeout = $timeout;
        $this->audit = $audit;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload): void
    {
        if (empty($this->endpoints)) {
            return;
        }

        $body = json_encode([
            'event' => $event,
            'payload' => $payload,
            'sent_at' => date('c'),
        ], JSON_THROW_ON_ERROR);

        foreach ($this->endpoints as $endpoint) {
            try {
                $this->post($endpoint, $body);
                $this->audit('webhook.sent', $event, $endpoint, ['status' => 'sent']);
            } catch (RuntimeException $exception) {
                $this->audit('webhook.failed', $event, $endpoint, [
                    'status' => 'failed',
                    'error' => $exception->getMessage(),
                ]);
            }
        }
    }

    private function post(string $url, string $body): void
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Unable to initialize webhook request');
        }

        $headers = ['Content-Type: application/json'];
        if ($this->secret !== '') {
            $signature = hash_hmac('sha256', $body, $this->secret);
            $headers[] = 'X-Webhook-Signature: ' . $signature;
        }

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $status >= 400) {
            $message = $error ?: 'HTTP ' . $status;
            throw new RuntimeException('Webhook dispatch failed: ' . $message);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private function audit(string $event, string $topic, string $endpoint, array $context): void
    {
        if ($this->audit === null) {
            return;
        }

        $this->audit->log(new AuditEntry($event, 'webhook', $topic . '->' . $endpoint, null, $context));
    }
}
