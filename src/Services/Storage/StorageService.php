<?php

namespace App\Services\Storage;

use DateTimeImmutable;
use DateTimeInterface;

class StorageService
{
    private const DAY_IN_SECONDS = 86400;

    /**
     * @var array<string, array<string, float|int>>
     */
    private array $stateRules = [
        'CA' => [
            'max_daily_rate' => 75.00,
            'grace_period_hours' => 2,
            'gate_fee_multiplier' => 1.10,
            'after_hours_multiplier' => 1.20,
            'max_billable_days' => 30,
            'lien_notice_days' => 15,
        ],
        'TX' => [
            'max_daily_rate' => 35.00,
            'grace_period_hours' => 0,
            'gate_fee_multiplier' => 1.00,
            'after_hours_multiplier' => 1.15,
            'max_billable_days' => 45,
            'lien_notice_days' => 20,
        ],
        'FL' => [
            'max_daily_rate' => 60.00,
            'grace_period_hours' => 1,
            'gate_fee_multiplier' => 1.05,
            'after_hours_multiplier' => 1.10,
            'max_billable_days' => 35,
            'lien_notice_days' => 25,
        ],
    ];

    /**
     * @return array{billable_days:int, daily_rate:float, amount:float, notes:array<int, string>}
     */
    public function calculateDailyAccruals(
        DateTimeInterface $start,
        DateTimeInterface $end,
        float $dailyRate,
        ?string $stateCode = null
    ): array {
        $notes = [];
        $rule = $this->getStateRule($stateCode);
        $graceHours = (int) ($rule['grace_period_hours'] ?? 0);

        $seconds = max(0, $end->getTimestamp() - $start->getTimestamp());
        $graceSeconds = $graceHours * 3600;
        if ($graceSeconds > 0 && $seconds > 0) {
            $seconds = max(0, $seconds - $graceSeconds);
            $notes[] = sprintf('Applied %d hour grace period.', $graceHours);
        }

        $billableDays = (int) ceil($seconds / self::DAY_IN_SECONDS);

        if (!empty($rule['max_billable_days'])) {
            $maxBillableDays = (int) $rule['max_billable_days'];
            if ($billableDays > $maxBillableDays) {
                $billableDays = $maxBillableDays;
                $notes[] = sprintf('Capped billable days at %d per state rule.', $maxBillableDays);
            }
        }

        if (!empty($rule['max_daily_rate']) && $dailyRate > (float) $rule['max_daily_rate']) {
            $dailyRate = (float) $rule['max_daily_rate'];
            $notes[] = sprintf('Daily rate capped at $%0.2f per state rule.', $dailyRate);
        }

        $amount = round($billableDays * $dailyRate, 2);

        return [
            'billable_days' => $billableDays,
            'daily_rate' => $dailyRate,
            'amount' => $amount,
            'notes' => $notes,
        ];
    }

    public function calculateGateFee(float $baseGateFee, bool $afterHours, ?string $stateCode = null): float
    {
        $rule = $this->getStateRule($stateCode);
        $multiplier = (float) ($rule['gate_fee_multiplier'] ?? 1.00);
        $afterHoursMultiplier = (float) ($rule['after_hours_multiplier'] ?? 1.00);

        $fee = $baseGateFee * $multiplier;
        if ($afterHours) {
            $fee *= $afterHoursMultiplier;
        }

        return round($fee, 2);
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $rate
     * @return array<string, mixed>
     */
    public function calculateAccruedFees(array $case, array $rate, ?DateTimeInterface $asOf = null): array
    {
        $asOf = $asOf ?: new DateTimeImmutable('now');
        $stateCode = $case['state_code'] ?? null;

        $impoundDate = new DateTimeImmutable($case['impound_date'] ?? 'now');
        $releaseDate = !empty($case['released_at'])
            ? new DateTimeImmutable($case['released_at'])
            : $asOf;

        $dailyRate = (float) ($rate['daily_rate'] ?? ($case['daily_rate'] ?? 0));
        $dailyAccrual = $this->calculateDailyAccruals($impoundDate, $releaseDate, $dailyRate, $stateCode);
        $gateFee = $this->calculateGateFee(
            (float) ($rate['gate_fee'] ?? ($case['gate_fee'] ?? 0)),
            !empty($case['after_hours_gate']),
            $stateCode
        );

        $fees = [
            'billable_days' => $dailyAccrual['billable_days'],
            'daily_rate' => $dailyAccrual['daily_rate'],
            'daily_amount' => $dailyAccrual['amount'],
            'gate_fee' => $gateFee,
            'subtotal' => round($dailyAccrual['amount'] + $gateFee, 2),
            'notes' => $dailyAccrual['notes'],
        ];

        return $this->applyStateRules($stateCode, $fees, [
            'impound_date' => $impoundDate,
            'release_date' => $releaseDate,
        ]);
    }

    /**
     * @param array<string, mixed> $fees
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function applyStateRules(?string $stateCode, array $fees, array $context = []): array
    {
        $rule = $this->getStateRule($stateCode);
        $notes = $fees['notes'] ?? [];

        if (!empty($rule['lien_notice_days']) && isset($context['impound_date'], $context['release_date'])) {
            $daysHeld = (int) floor(
                ($context['release_date']->getTimestamp() - $context['impound_date']->getTimestamp())
                / self::DAY_IN_SECONDS
            );
            $lienNoticeDays = (int) $rule['lien_notice_days'];
            if ($daysHeld >= $lienNoticeDays) {
                $notes[] = sprintf('Lien notice threshold reached (%d days).', $lienNoticeDays);
            }
        }

        $fees['notes'] = $notes;
        $fees['total'] = $fees['subtotal'];

        return $fees;
    }

    /**
     * @return array<string, float|int>
     */
    private function getStateRule(?string $stateCode): array
    {
        if ($stateCode && isset($this->stateRules[$stateCode])) {
            return $this->stateRules[$stateCode];
        }

        return [
            'max_daily_rate' => 0,
            'grace_period_hours' => 0,
            'gate_fee_multiplier' => 1.00,
            'after_hours_multiplier' => 1.00,
            'max_billable_days' => 0,
            'lien_notice_days' => 0,
        ];
    }
}
