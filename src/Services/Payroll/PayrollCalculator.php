<?php

namespace App\Services\Payroll;

use InvalidArgumentException;

class PayrollCalculator
{
    public const TYPE_FLAT_RATE = 'flat_rate';
    public const TYPE_PROFIT_COMMISSION = 'profit_commission';

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function calculateGrossPay(array $payload): array
    {
        $payType = $this->normalizeType($payload['pay_type'] ?? null);

        if ($payType === self::TYPE_FLAT_RATE) {
            return $this->calculateFlatRate($payload);
        }

        if ($payType === self::TYPE_PROFIT_COMMISSION) {
            return $this->calculateProfitCommission($payload);
        }

        throw new InvalidArgumentException('Unsupported pay type for gross pay calculation.');
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function calculateFlatRate(array $payload): array
    {
        $billedHours = $this->requireFloat($payload, 'billed_hours');
        $rate = $this->requireFloat($payload, 'rate');
        $minimumPay = $this->optionalFloat($payload, 'minimum_pay');

        $grossPay = $billedHours * $rate;
        if ($minimumPay !== null) {
            $grossPay = max($grossPay, $minimumPay);
        }

        $grossPay = $this->roundMoney($grossPay);

        return [
            'pay_type' => self::TYPE_FLAT_RATE,
            'gross_pay' => $grossPay,
            'breakdown' => [
                'inputs' => [
                    'billed_hours' => $billedHours,
                    'rate' => $rate,
                    'minimum_pay' => $minimumPay,
                ],
                'calculations' => [
                    'gross_pay' => $grossPay,
                ],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    private function calculateProfitCommission(array $payload): array
    {
        $grossProfit = $this->requireFloat($payload, 'gross_profit');
        $commissionRate = $this->requireFloat($payload, 'commission_rate');
        $baseDraw = $this->optionalFloat($payload, 'base_draw');

        if ($commissionRate > 1) {
            $commissionRate = $commissionRate / 100;
        }

        $commissionPay = $grossProfit * $commissionRate;
        $grossPay = $commissionPay + ($baseDraw ?? 0.0);

        $grossPay = $this->roundMoney($grossPay);

        return [
            'pay_type' => self::TYPE_PROFIT_COMMISSION,
            'gross_pay' => $grossPay,
            'breakdown' => [
                'inputs' => [
                    'gross_profit' => $grossProfit,
                    'commission_rate' => $commissionRate,
                    'base_draw' => $baseDraw,
                ],
                'calculations' => [
                    'commission_pay' => $this->roundMoney($commissionPay),
                    'gross_pay' => $grossPay,
                ],
            ],
        ];
    }

    /**
     * @param mixed $payType
     */
    private function normalizeType($payType): string
    {
        $value = strtolower(trim((string) $payType));
        if ($value === '') {
            throw new InvalidArgumentException('Pay type is required for gross pay calculation.');
        }

        return $value;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function requireFloat(array $payload, string $key): float
    {
        if (!array_key_exists($key, $payload)) {
            throw new InvalidArgumentException(sprintf('Missing required field: %s.', $key));
        }

        if (!is_numeric($payload[$key])) {
            throw new InvalidArgumentException(sprintf('Field %s must be numeric.', $key));
        }

        return (float) $payload[$key];
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function optionalFloat(array $payload, string $key): ?float
    {
        if (!array_key_exists($key, $payload) || $payload[$key] === null || $payload[$key] === '') {
            return null;
        }

        if (!is_numeric($payload[$key])) {
            throw new InvalidArgumentException(sprintf('Field %s must be numeric.', $key));
        }

        return (float) $payload[$key];
    }

    private function roundMoney(float $value): float
    {
        return round($value, 2);
    }
}
