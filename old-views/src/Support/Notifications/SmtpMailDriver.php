<?php

namespace App\Support\Notifications;

use RuntimeException;

class SmtpMailDriver implements MailDriverInterface
{
    private array $config;

    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(string $to, string $subject, string $body, ?string $fromName = null, ?string $fromAddress = null): void
    {
        $fromLine = $fromAddress !== null
            ? ($fromName ? sprintf('%s <%s>', $fromName, $fromAddress) : $fromAddress)
            : null;

        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        if ($fromLine !== null) {
            $headers[] = 'From: ' . $fromLine;
        }

        $mailSent = mail($to, $subject, $body, implode("\r\n", $headers));

        if ($mailSent === false) {
            throw new RuntimeException('Mail send failed via PHP mail(). Check SMTP configuration.');
        }
    }
}
