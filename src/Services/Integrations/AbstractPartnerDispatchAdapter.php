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

    private function getValue(array $payload, string $path): mixed
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
}
