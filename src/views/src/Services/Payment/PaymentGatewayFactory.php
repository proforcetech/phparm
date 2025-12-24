<?php

namespace App\Services\Payment;

use InvalidArgumentException;

/**
 * Payment Gateway Factory
 *
 * Creates and manages payment gateway instances
 */
class PaymentGatewayFactory
{
    /**
     * @var array<string, PaymentGatewayInterface>
     */
    private array $gateways = [];

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $config;

    /**
     * @param array<string, array<string, mixed>> $config Payment gateway configurations
     */
    public function __construct(array $config)
    {
        $this->config = $config;
    }

    /**
     * Get a payment gateway instance
     *
     * @param string $provider Gateway provider (stripe, square, paypal)
     * @return PaymentGatewayInterface
     * @throws InvalidArgumentException If provider is not supported
     */
    public function create(string $provider): PaymentGatewayInterface
    {
        $provider = strtolower($provider);

        // Return cached instance if exists
        if (isset($this->gateways[$provider])) {
            return $this->gateways[$provider];
        }

        // Create new instance
        $gateway = $this->createGateway($provider);

        // Cache and return
        $this->gateways[$provider] = $gateway;

        return $gateway;
    }

    /**
     * Get all configured and available gateways
     *
     * @return array<string, PaymentGatewayInterface>
     */
    public function getAvailableGateways(): array
    {
        $available = [];

        foreach (['stripe', 'square', 'paypal'] as $provider) {
            try {
                $gateway = $this->create($provider);
                if ($gateway->isConfigured()) {
                    $available[$provider] = $gateway;
                }
            } catch (\Exception $e) {
                // Skip if gateway cannot be created
                continue;
            }
        }

        return $available;
    }

    /**
     * Check if a specific gateway is configured and available
     *
     * @param string $provider Gateway provider
     * @return bool
     */
    public function isAvailable(string $provider): bool
    {
        try {
            $gateway = $this->create($provider);
            return $gateway->isConfigured();
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Get list of available gateway names
     *
     * @return array<string>
     */
    public function getAvailableGatewayNames(): array
    {
        return array_keys($this->getAvailableGateways());
    }

    /**
     * Get the default payment gateway
     *
     * @return PaymentGatewayInterface
     * @throws \RuntimeException If no gateways are configured
     */
    public function getDefault(): PaymentGatewayInterface
    {
        // Check if default is specified in config
        $default = $this->config['default'] ?? null;

        if ($default && $this->isAvailable($default)) {
            return $this->create($default);
        }

        // Otherwise, return first available gateway
        $available = $this->getAvailableGateways();

        if (empty($available)) {
            throw new \RuntimeException('No payment gateways are configured');
        }

        return reset($available);
    }

    /**
     * Create a gateway instance
     *
     * @param string $provider
     * @return PaymentGatewayInterface
     * @throws InvalidArgumentException
     */
    private function createGateway(string $provider): PaymentGatewayInterface
    {
        $gatewayConfig = $this->config[$provider] ?? [];

        return match ($provider) {
            'stripe' => new StripeGateway($gatewayConfig),
            'square' => new SquareGateway($gatewayConfig),
            'paypal' => new PayPalGateway($gatewayConfig),
            default => throw new InvalidArgumentException("Unsupported payment provider: {$provider}"),
        };
    }
}
