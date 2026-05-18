<?php

namespace App\Support\Realtime;

class PusherBroadcaster
{
    private const MAX_CHANNELS_PER_REQUEST = 100;

    public function isConfigured(): bool
    {
        return $this->appId() !== ''
            && $this->key() !== ''
            && $this->secret() !== ''
            && $this->host() !== '';
    }

    /**
     * @param array<int, string> $channels
     * @param array<string, mixed> $payload
     */
    public function trigger(array $channels, string $event, array $payload): void
    {
        $channels = array_values(array_unique(array_filter(array_map('strval', $channels))));
        if ($channels === [] || $event === '' || !$this->isConfigured()) {
            return;
        }

        foreach (array_chunk($channels, self::MAX_CHANNELS_PER_REQUEST) as $chunk) {
            $this->sendEventChunk($chunk, $event, $payload);
        }
    }

    /**
     * @return array{auth: string}|null
     */
    public function authenticatePrivateChannel(string $socketId, string $channelName): ?array
    {
        if (!$this->isConfigured() || $socketId === '' || $channelName === '') {
            return null;
        }

        $signature = hash_hmac('sha256', "{$socketId}:{$channelName}", $this->secret());

        return [
            'auth' => $this->key() . ':' . $signature,
        ];
    }

    /**
     * @param array<int, string> $channels
     * @param array<string, mixed> $payload
     */
    private function sendEventChunk(array $channels, string $event, array $payload): void
    {
        try {
            $eventBody = [
                'name' => $event,
                'channels' => $channels,
                'data' => json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ];
            $body = json_encode($eventBody, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (\JsonException $exception) {
            error_log('Pusher payload error: ' . $exception->getMessage());
            return;
        }

        $path = '/apps/' . $this->appId() . '/events';
        $params = [
            'auth_key' => $this->key(),
            'auth_timestamp' => time(),
            'auth_version' => '1.0',
            'body_md5' => md5($body),
        ];
        ksort($params);

        $query = http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        $signature = hash_hmac('sha256', "POST\n{$path}\n{$query}", $this->secret());
        $url = sprintf(
            '%s://%s:%d%s?%s&auth_signature=%s',
            $this->scheme(),
            $this->host(),
            $this->port(),
            $path,
            $query,
            $signature
        );

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => $body,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            error_log(sprintf('Pusher broadcast failed (%s): %s', $httpCode, $error ?: 'request rejected'));
        }
    }

    private function appId(): string
    {
        return (string) env('PUSHER_APP_ID', '');
    }

    private function key(): string
    {
        return (string) env('PUSHER_KEY', '');
    }

    private function secret(): string
    {
        return (string) env('PUSHER_SECRET', '');
    }

    private function scheme(): string
    {
        return (string) env('PUSHER_SCHEME', 'https');
    }

    private function host(): string
    {
        $cluster = (string) env('PUSHER_CLUSTER', '');
        return (string) env('PUSHER_HOST', $cluster !== '' ? "api-{$cluster}.pusher.com" : '');
    }

    private function port(): int
    {
        return (int) env('PUSHER_PORT', $this->scheme() === 'https' ? 443 : 80);
    }
}
