<?php

namespace App\Services\BankFeeds;

use InvalidArgumentException;

class BankFeedProviderFactory
{
    /**
     * @var array<string, BankFeedProviderInterface>
     */
    private array $providers;

    /**
     * @param array<int, BankFeedProviderInterface> $providers
     */
    public function __construct(array $providers = [])
    {
        $defaultProviders = [
            new DemoBankFeedProvider(),
        ];

        $registered = [];
        foreach (array_merge($defaultProviders, $providers) as $provider) {
            $registered[$provider->providerKey()] = $provider;
        }

        $this->providers = $registered;
    }

    public function create(string $providerKey): BankFeedProviderInterface
    {
        if (!isset($this->providers[$providerKey])) {
            throw new InvalidArgumentException('Unknown bank feed provider: ' . $providerKey);
        }

        return $this->providers[$providerKey];
    }
}
