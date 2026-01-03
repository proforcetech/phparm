<?php

namespace App\Support\Notifications;

use RuntimeException;

class TwilioSmsDriver implements SmsDriverInterface
{
    private string $sid;
    private string $token;

    public function __construct(array $config)
    {
        $this->sid = (string) ($config['sid'] ?? '');
        $this->token = (string) ($config['token'] ?? '');
    }

    public function send(string $to, string $message, ?string $fromNumber = null): void
    {
        if ($this->sid === '' || $this->token === '') {
            throw new RuntimeException('Twilio credentials are not configured.');
        }

        if ($fromNumber === null) {
            throw new RuntimeException('A from number is required for Twilio SMS messages.');
        }

        $endpoint = sprintf('https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json', $this->sid);

        $payload = http_build_query([
            'From' => $fromNumber,
            'To' => $to,
            'Body' => $message,
        ]);

        $ch = curl_init($endpoint);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
        curl_setopt($ch, CURLOPT_USERPWD, $this->sid . ':' . $this->token);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false || $httpCode >= 400) {
            throw new RuntimeException('Twilio SMS send failed: ' . ($error !== '' ? $error : $response));
        }
    }
}
