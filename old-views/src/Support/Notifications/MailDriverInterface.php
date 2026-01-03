<?php

namespace App\Support\Notifications;

interface MailDriverInterface
{
    public function send(string $to, string $subject, string $body, ?string $fromName = null, ?string $fromAddress = null): void;
}
