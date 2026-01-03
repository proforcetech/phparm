<?php

namespace App\Support\Notifications;

interface SmsDriverInterface
{
    public function send(string $to, string $message, ?string $fromNumber = null): void;
}
