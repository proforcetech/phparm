<?php

namespace App\Support\Auth;

use InvalidArgumentException;

class PasswordPolicy
{
    private const CATEGORY_MAP = [
        'lower' => 26,
        'upper' => 26,
        'digit' => 10,
        'symbol' => 32,
    ];

    /**
     * @var array<string, mixed>
     */
    private array $config;

    /**
     * @param array<string, mixed> $config
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    public function assertPasswordStrength(string $password): void
    {
        $minLength = (int) ($this->config['passwords']['min_length'] ?? 12);
        $minEntropy = (int) ($this->config['passwords']['min_entropy'] ?? 50);
        $minCategories = (int) ($this->config['passwords']['min_categories'] ?? 3);

        if (strlen($password) < $minLength) {
            throw new InvalidArgumentException('Password must be at least ' . $minLength . ' characters long.');
        }

        $categories = $this->detectCategories($password);
        if ($categories < $minCategories) {
            throw new InvalidArgumentException('Password must include at least ' . $minCategories . ' character types.');
        }

        $entropy = $this->estimateEntropy($password);
        if ($entropy < $minEntropy) {
            throw new InvalidArgumentException('Password is too weak. Use a longer passphrase with mixed character types.');
        }
    }

    private function detectCategories(string $password): int
    {
        $count = 0;
        if (preg_match('/[a-z]/', $password)) {
            $count++;
        }
        if (preg_match('/[A-Z]/', $password)) {
            $count++;
        }
        if (preg_match('/\d/', $password)) {
            $count++;
        }
        if (preg_match('/[^a-zA-Z\d]/', $password)) {
            $count++;
        }

        return $count;
    }

    private function estimateEntropy(string $password): float
    {
        $poolSize = 0;
        if (preg_match('/[a-z]/', $password)) {
            $poolSize += self::CATEGORY_MAP['lower'];
        }
        if (preg_match('/[A-Z]/', $password)) {
            $poolSize += self::CATEGORY_MAP['upper'];
        }
        if (preg_match('/\d/', $password)) {
            $poolSize += self::CATEGORY_MAP['digit'];
        }
        if (preg_match('/[^a-zA-Z\d]/', $password)) {
            $poolSize += self::CATEGORY_MAP['symbol'];
        }

        if ($poolSize === 0) {
            return 0.0;
        }

        $length = strlen($password);
        return $length * (log($poolSize) / log(2));
    }
}
