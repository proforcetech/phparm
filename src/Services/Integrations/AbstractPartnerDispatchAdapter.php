<?php

namespace App\Services\Integrations;

abstract class AbstractPartnerDispatchAdapter implements PartnerDispatchAdapterInterface
{
    protected function value(array $payload, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->getValue($payload, $key);
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
            if (is_numeric($value)) {
                return (string) $value;
            }
        }

        return null;
    }

    protected function intValue(array $payload, array $keys): ?int
    {
        foreach ($keys as $key) {
            $value = $this->getValue($payload, $key);
            if (is_numeric($value)) {
                return (int) $value;
            }
            if (is_string($value) && trim($value) !== '') {
                $filtered = preg_replace('/[^0-9]/', '', $value);
                if ($filtered !== '') {
                    return (int) $filtered;
                }
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function metadata(array $payload, array $keys): array
    {
        $meta = [];
        foreach ($keys as $key) {
            $value = $this->getValue($payload, $key);
            if ($value !== null && $value !== '') {
                $metaKey = str_replace('.', '_', $key);
                $meta[$metaKey] = $value;
            }
        }

        return $meta;
    }

    protected function getValue(array $payload, string $path): mixed
    {
        $segments = explode('.', $path);
        $current = $payload;
        foreach ($segments as $segment) {
            if (!is_array($current) || !array_key_exists($segment, $current)) {
                return null;
            }
            $current = $current[$segment];
        }

        return $current;
    }

    protected function detectProtocol(array $payload, array $keys = []): ?string
    {
        $protocolValue = $keys !== [] ? $this->value($payload, $keys) : null;
        $normalized = PartnerDispatchProtocol::normalize($protocolValue);
        if ($normalized !== null) {
            return $normalized;
        }

        $swiftPayload = $this->getValue($payload, 'swift')
            ?? $this->getValue($payload, 'swiftDispatch')
            ?? $this->getValue($payload, 'swift_payload');
        if (is_array($swiftPayload)) {
            return PartnerDispatchProtocol::SWIFT;
        }

        $digitalPayload = $this->getValue($payload, 'digitalDispatch')
            ?? $this->getValue($payload, 'digital_dispatch')
            ?? $this->getValue($payload, 'digital_payload');
        if (is_array($digitalPayload)) {
            return PartnerDispatchProtocol::DIGITAL_DISPATCH;
        }

        return null;
    }

    /**
     * @param array<string, mixed> $dispatch
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    protected function baseStatusPayload(array $dispatch, array $context = []): array
    {
        return [
            'partner' => $dispatch['partner'] ?? null,
            'protocol' => $dispatch['protocol'] ?? null,
            'dispatch_reference' => $dispatch['dispatch_reference'] ?? null,
            'external_reference' => $dispatch['external_reference'] ?? null,
            'status' => $context['status'] ?? null,
            'occurred_at' => $context['occurred_at'] ?? date(DATE_ATOM),
            'notes' => $context['notes'] ?? null,
            'provider' => $context['provider'] ?? null,
        ];
    }

    protected function normalizeStatus(string $status): string
    {
        $normalized = strtolower(trim($status));
        return match ($normalized) {
            'enroute', 'en_route', 'en route', 'in_progress' => 'en_route',
            'arrived' => 'arrived',
            'hooked' => 'hooked',
            'completed', 'complete' => 'completed',
            'cancelled', 'canceled' => 'cancelled',
            'accepted', 'accept' => 'accepted',
            'declined', 'decline', 'rejected', 'reject' => 'declined',
            default => $normalized,
        };
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    protected function pruneNulls(array $payload): array
    {
        return array_filter($payload, static fn ($value) => $value !== null);
    }
}
