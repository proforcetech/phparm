<?php

namespace App\Support\Notifications;

class LogSmsDriver implements SmsDriverInterface
{
    public function send(string $to, string $message, ?string $fromNumber = null): void
    {
        $log = sprintf(
            'SMS log -> to: %s | from: %s | message: %s',
            $to,
            $fromNumber ?? '',
            $message
        );

        error_log($log);
    }
}
