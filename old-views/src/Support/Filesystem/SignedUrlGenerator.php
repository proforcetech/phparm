<?php

namespace App\Support\Filesystem;

class SignedUrlGenerator
{
    private string $secret;

    public function __construct(string $secret)
    {
        $this->secret = $secret;
    }

    public function generate(string $baseUrl, string $path, string $disk, int $ttlSeconds = 900): string
    {
        $expires = time() + $ttlSeconds;
        $signature = $this->sign($path, $disk, $expires);

        $query = http_build_query([
            'path' => $path,
            'disk' => $disk,
            'expires' => $expires,
            'signature' => $signature,
        ]);

        return rtrim($baseUrl, '/') . '?' . $query;
    }

    public function validate(string $path, string $disk, int $expires, string $signature): bool
    {
        if ($expires < time()) {
            return false;
        }

        return hash_equals($this->sign($path, $disk, $expires), $signature);
    }

    private function sign(string $path, string $disk, int $expires): string
    {
        $payload = implode('|', [$path, $disk, $expires]);

        return hash_hmac('sha256', $payload, $this->secret);
    }
}
