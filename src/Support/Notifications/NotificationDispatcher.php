<?php

namespace App\Support\Notifications;

use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use InvalidArgumentException;
use Throwable;
use InvalidArgumentException;

class NotificationDispatcher
{
    private array $config;
    private TemplateEngine $templates;
    private NotificationLogRepository $logs;
    private ?AuditLogger $audits;

    public function __construct(array $config, TemplateEngine $templates, NotificationLogRepository $logs, ?AuditLogger $audits = null)

    public function __construct(array $config, TemplateEngine $templates, NotificationLogRepository $logs)
    {
        $this->config = $config;
        $this->templates = $templates;
        $this->logs = $logs;
        $this->audits = $audits;
    }

    public function sendMail(string $templateKey, string $to, array $data, ?string $subject = null): void
    {
        $driver = $this->resolveMailDriver();
        $subject ??= $templateKey;
        $body = $this->renderTemplate($templateKey, $data);

        $meta = [
            'subject' => $subject,
            'driver' => $this->mailDriverName(),
            'from_name' => $this->config['mail']['from_name'] ?? null,
            'from_address' => $this->config['mail']['from_address'] ?? null,
        ];

        try {
            $driver->send($to, $subject, $body, $meta['from_name'], $meta['from_address']);
            $this->logs->log(new NotificationLogEntry('mail', $to, $templateKey, $data, 'sent', array_merge($meta, ['body' => $body])));
            $this->auditSend('mail', $templateKey, $to, 'sent', $meta);
        } catch (Throwable $exception) {
            $this->logs->log(new NotificationLogEntry('mail', $to, $templateKey, $data, 'failed', $meta, $exception->getMessage()));
            $this->auditSend('mail', $templateKey, $to, 'failed', $meta, $exception->getMessage());

            throw $exception;
        }
        $driver->send($to, $subject, $body, $this->config['mail']['from_name'] ?? null, $this->config['mail']['from_address'] ?? null);
    }

    public function sendSms(string $templateKey, string $to, array $data): void
    {
        $driver = $this->resolveSmsDriver();
        $message = $this->renderTemplate($templateKey, $data);
        $meta = [
            'driver' => $this->smsDriverName(),
            'from_number' => $this->config['sms']['from_number'] ?? null,
        ];

        try {
            $driver->send($to, $message, $meta['from_number']);
            $this->logs->log(new NotificationLogEntry('sms', $to, $templateKey, $data, 'sent', $meta));
            $this->auditSend('sms', $templateKey, $to, 'sent', $meta);
        } catch (Throwable $exception) {
            $this->logs->log(new NotificationLogEntry('sms', $to, $templateKey, $data, 'failed', $meta, $exception->getMessage()));
            $this->auditSend('sms', $templateKey, $to, 'failed', $meta, $exception->getMessage());

            throw $exception;
        }

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
        $driverName = $this->mailDriverName();
        $driverConfig = $this->config['mail']['drivers'][$driverName] ?? [];

        return match ($driverName) {
            'log' => new LogMailDriver(),
            'smtp' => new SmtpMailDriver($driverConfig),
        $driverName = $this->config['mail']['default'] ?? 'log';

        return match ($driverName) {
            'log' => new LogMailDriver($this->logs),
            default => throw new InvalidArgumentException("Unsupported mail driver: {$driverName}"),
        };
    }

    private function resolveSmsDriver(): SmsDriverInterface
    {
        $driverName = $this->smsDriverName();
        $driverConfig = $this->config['sms']['drivers'][$driverName] ?? [];

        return match ($driverName) {
            'log' => new LogSmsDriver(),
            'twilio' => new TwilioSmsDriver($driverConfig),
            default => throw new InvalidArgumentException("Unsupported SMS driver: {$driverName}"),
        };
    }

    private function mailDriverName(): string
    {
        return $this->config['mail']['default'] ?? 'log';
    }

    private function smsDriverName(): string
    {
        return $this->config['sms']['default'] ?? 'log';
    }

    private function auditSend(string $channel, string $template, string $recipient, string $status, array $meta, ?string $error = null): void
    {
        if ($this->audits === null) {
            return;
        }

        $context = [
            'channel' => $channel,
            'template' => $template,
            'recipient' => $recipient,
            'status' => $status,
            'meta' => $meta,
        ];

        if ($error !== null) {
            $context['error'] = $error;
        }

        $this->audits->log(new AuditEntry('notification.' . $status, 'notification', $template . ':' . $recipient, null, $context));
    }
        $driverName = $this->config['sms']['default'] ?? 'log';

        return match ($driverName) {
            'log' => new LogSmsDriver($this->logs),
            default => throw new InvalidArgumentException("Unsupported SMS driver: {$driverName}"),
        };
    }
}
