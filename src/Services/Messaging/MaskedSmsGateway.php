<?php

namespace App\Services\Messaging;

use App\Support\Notifications\LogSmsDriver;
use App\Support\Notifications\SmsDriverInterface;
use App\Support\Notifications\TwilioSmsDriver;
use InvalidArgumentException;

class MaskedSmsGateway
{
    /** @var array<string, mixed> */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function send(string $to, string $message, ?string $fromNumber = null): void
    {
        $driver = $this->resolveSmsDriver();
        $from = $fromNumber ?? ($this->config['sms']['from_number'] ?? null);
        $driver->send($to, $message, $from);
    }

    private function resolveSmsDriver(): SmsDriverInterface
    {
        $driverName = $this->config['sms']['default'] ?? 'log';
        $driverConfig = $this->config['sms']['drivers'][$driverName] ?? [];

        return match ($driverName) {
            'log' => new LogSmsDriver(),
            'twilio' => new TwilioSmsDriver($driverConfig),
            default => throw new InvalidArgumentException("Unsupported SMS driver: {$driverName}"),
        };
    }
}
