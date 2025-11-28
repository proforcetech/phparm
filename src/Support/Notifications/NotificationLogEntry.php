<?php

namespace App\Support\Notifications;

class NotificationLogEntry
{
    public string $channel;
    public string $recipient;
    public string $template;
    public array $payload;
    public ?string $status;

    public function __construct(string $channel, string $recipient, string $template, array $payload, ?string $status = null)
    {
        $this->channel = $channel;
        $this->recipient = $recipient;
        $this->template = $template;
        $this->payload = $payload;
        $this->status = $status;
    }
}
