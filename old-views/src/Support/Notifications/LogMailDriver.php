<?php

namespace App\Support\Notifications;

class LogMailDriver implements MailDriverInterface
{
    public function send(string $to, string $subject, string $body, ?string $fromName = null, ?string $fromAddress = null): void
    {
        $message = sprintf(
            'Mail log -> to: %s | subject: %s | from: %s <%s> | body: %s',
            $to,
            $subject,
            $fromName ?? '',
            $fromAddress ?? '',
            $body
        );

        error_log($message);
    private NotificationLogRepository $logs;

    public function __construct(NotificationLogRepository $logs)
    {
        $this->logs = $logs;
    }

    public function send(string $to, string $subject, string $body, ?string $fromName = null, ?string $fromAddress = null): void
    {
        $this->logs->log(new NotificationLogEntry(
            'mail',
            $to,
            $subject,
            [
                'from_name' => $fromName,
                'from_address' => $fromAddress,
                'body' => $body,
            ],
            'logged'
        ));
    }
}
