<?php

namespace App\Support\Security;

class RecaptchaVerifier
{
    private ?string $secretKey;
    private float $scoreThreshold;
    private bool $enabled;

    public function __construct(?string $secretKey, float $scoreThreshold = 0.5, bool $enabled = true)
    {
        $this->secretKey = $secretKey;
        $this->scoreThreshold = $scoreThreshold;
        $this->enabled = $enabled;
    }

    public function verify(?string $token): bool
    {
        if (!$this->enabled) {
            return true;
        }

        if ($token === null || $token === '') {
            return false;
        }

        if (!$this->secretKey) {
            return false;
        }

        $response = $this->sendVerificationRequest($token);

        if ($response === null || empty($response['success'])) {
            return false;
        }

        if (!isset($response['score'])) {
            return true;
        }

        return (float) $response['score'] >= $this->scoreThreshold;
    }

    private function sendVerificationRequest(string $token): ?array
    {
        $payload = http_build_query([
            'secret' => $this->secretKey,
            'response' => $token,
        ]);

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 5,
            ],
        ]);

        try {
            $result = file_get_contents('https://www.google.com/recaptcha/api/siteverify', false, $context);
        } catch (\Throwable $e) {
            return null;
        }

        if ($result === false) {
            return null;
        }

        /** @var array<string, mixed>|null $decoded */
        $decoded = json_decode($result, true);

        return is_array($decoded) ? $decoded : null;
    }
}
