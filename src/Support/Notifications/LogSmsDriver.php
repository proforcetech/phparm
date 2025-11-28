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
    private NotificationLogRepository $logs;

    public function __construct(NotificationLogRepository $logs)
    {
        $this->logs = $logs;
    }

    public function send(string $to, string $message, ?string $fromNumber = null): void
    {
        $this->logs->log(new NotificationLogEntry(
            'sms',
            $to,
            'sms',
            [
                'from_number' => $fromNumber,
                'message' => $message,
            ],
            'logged'
        ));
    }
}
