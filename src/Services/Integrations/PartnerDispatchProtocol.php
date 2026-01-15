<?php

namespace App\Services\Integrations;

final class PartnerDispatchProtocol
{
    public const SWIFT = 'swift';
    public const DIGITAL_DISPATCH = 'digital_dispatch';

    private const ALIASES = [
        'swift' => self::SWIFT,
        'swift_dispatch' => self::SWIFT,
        'digital_dispatch' => self::DIGITAL_DISPATCH,
        'digital dispatch' => self::DIGITAL_DISPATCH,
        'digitaldispatch' => self::DIGITAL_DISPATCH,
        'dd' => self::DIGITAL_DISPATCH,
        'ddp' => self::DIGITAL_DISPATCH,
    ];

    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $key = strtolower(trim($value));
        if ($key === '') {
            return null;
        }

        return self::ALIASES[$key] ?? $key;
    }
}
