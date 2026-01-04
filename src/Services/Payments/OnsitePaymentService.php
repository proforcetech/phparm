<?php

namespace App\Services\Payments;

use App\Database\Connection;
use App\Services\Payment\PaymentGatewayFactory;
use InvalidArgumentException;
use RuntimeException;

class OnsitePaymentService
{
    private Connection $connection;
    private PaymentGatewayFactory $gatewayFactory;

    public function __construct(Connection $connection, PaymentGatewayFactory $gatewayFactory)
    {
        $this->connection = $connection;
        $this->gatewayFactory = $gatewayFactory;
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createCharge(array $payload, int $actorId): array
    {
        $provider = (string) ($payload['provider'] ?? 'stripe');
        $amount = (float) ($payload['amount'] ?? 0);
        if ($amount <= 0) {
            throw new InvalidArgumentException('amount must be greater than 0');
        }

        $currency = (string) ($payload['currency'] ?? 'USD');
        $referenceType = isset($payload['reference_type']) ? (string) $payload['reference_type'] : null;
        $referenceId = isset($payload['reference_id']) ? (int) $payload['reference_id'] : null;

        $paymentData = $payload;
        $paymentData['amount'] = $amount;
        $paymentData['currency'] = $currency;
        $paymentData['description'] = $payload['description'] ?? 'On-site card charge';
        $paymentData['metadata'] = array_merge(
            $payload['metadata'] ?? [],
            [
                'actor_id' => $actorId,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
            ]
        );

        $gateway = $this->gatewayFactory->create($provider);

        try {
            $result = $gateway->processPayment($paymentData);
        } catch (RuntimeException $exception) {
            throw $exception;
        }

        $transactionId = $result['transaction_id'] ?? null;
        if ($transactionId === null) {
            throw new RuntimeException('Payment gateway did not return a transaction id.');
        }

        $this->storeTransaction([
            'provider' => $provider,
            'external_id' => $transactionId,
            'amount' => $result['amount'] ?? $amount,
            'currency' => $result['currency'] ?? $currency,
            'status' => $result['status'] ?? 'pending',
            'payment_method' => $payload['payment_method'] ?? $payload['source_id'] ?? null,
            'reference_type' => $referenceType,
            'reference_id' => $referenceId,
            'metadata' => [
                'actor_id' => $actorId,
                'gateway_response' => $result,
            ],
        ]);

        return [
            'provider' => $provider,
            'transaction_id' => $transactionId,
            'status' => $result['status'] ?? 'pending',
            'amount' => $result['amount'] ?? $amount,
            'currency' => $result['currency'] ?? $currency,
            'gateway' => $result,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function storeTransaction(array $payload): void
    {
        $stmt = $this->connection->pdo()->prepare(
            'INSERT INTO payment_transactions
                (provider, external_id, amount, currency, status, payment_method, reference_type, reference_id, metadata, created_at)
             VALUES
                (:provider, :external_id, :amount, :currency, :status, :payment_method, :reference_type, :reference_id, :metadata, NOW())'
        );

        $stmt->execute([
            'provider' => $payload['provider'],
            'external_id' => $payload['external_id'],
            'amount' => $payload['amount'],
            'currency' => $payload['currency'],
            'status' => $payload['status'],
            'payment_method' => $payload['payment_method'],
            'reference_type' => $payload['reference_type'],
            'reference_id' => $payload['reference_id'],
            'metadata' => json_encode($payload['metadata'], JSON_THROW_ON_ERROR),
        ]);
    }
}
