<?php

namespace App\Support;

class Env
{
    private array $values = [];

    public function __construct(string $path)
    {
        if (!is_readable($path)) {
            return;
        }

        $this->values = $this->parse(file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES));
    }

    public function get(string $key, $default = null)
    {
        return $this->values[$key] ?? $_ENV[$key] ?? $_SERVER[$key] ?? $default;
    }

    private function parse(array $lines): array
    {
        $data = [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $data[trim($key)] = $this->stripQuotes(trim($value));
        }

        return $data;
    }

    private function stripQuotes(string $value): string
    {
        if ($value === '') {
            return $value;
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
