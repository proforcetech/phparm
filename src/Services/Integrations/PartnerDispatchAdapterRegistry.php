<?php

namespace App\Services\Integrations;

class PartnerDispatchAdapterRegistry
{
    /**
     * @var PartnerDispatchAdapterInterface[]
     */
    private array $adapters;

    /**
     * @param PartnerDispatchAdapterInterface[] $adapters
     */
    public function __construct(array $adapters)
    {
        $this->adapters = $adapters;
    }

    public function adapterFor(string $partner): ?PartnerDispatchAdapterInterface
    {
        foreach ($this->adapters as $adapter) {
            if ($adapter->supports($partner)) {
                return $adapter;
            }
        }

        return null;
    }
}
