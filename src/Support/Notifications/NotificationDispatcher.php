<?php

namespace App\Support\Notifications;

use InvalidArgumentException;

class NotificationDispatcher
{
    private array $config;
    private TemplateEngine $templates;
    private NotificationLogRepository $logs;

    public function __construct(array $config, TemplateEngine $templates, NotificationLogRepository $logs)
    {
        $this->config = $config;
        $this->templates = $templates;
        $this->logs = $logs;
    }

    public function sendMail(string $templateKey, string $to, array $data, ?string $subject = null): void
    {
        $driver = $this->resolveMailDriver();
        $subject ??= $templateKey;
        $body = $this->renderTemplate($templateKey, $data);

        $driver->send($to, $subject, $body, $this->config['mail']['from_name'] ?? null, $this->config['mail']['from_address'] ?? null);
    }

    public function sendSms(string $templateKey, string $to, array $data): void
    {
        $driver = $this->resolveSmsDriver();
        $message = $this->renderTemplate($templateKey, $data);

        $driver->send($to, $message, $this->config['sms']['from_number'] ?? null);
    }

    private function renderTemplate(string $key, array $data): string
    {
        $template = $this->config['templates'][$key] ?? null;
        if ($template === null) {
            throw new InvalidArgumentException("Notification template [{$key}] is not defined.");
        }

        return $this->templates->render($template, $data);
    }

    private function resolveMailDriver(): MailDriverInterface
    {
        $driverName = $this->config['mail']['default'] ?? 'log';

        return match ($driverName) {
            'log' => new LogMailDriver($this->logs),
            default => throw new InvalidArgumentException("Unsupported mail driver: {$driverName}"),
        };
    }

    private function resolveSmsDriver(): SmsDriverInterface
    {
        $driverName = $this->config['sms']['default'] ?? 'log';

        return match ($driverName) {
            'log' => new LogSmsDriver($this->logs),
            default => throw new InvalidArgumentException("Unsupported SMS driver: {$driverName}"),
        };
    }
}
