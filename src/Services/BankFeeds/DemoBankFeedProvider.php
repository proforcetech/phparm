<?php

namespace App\Services\BankFeeds;

class DemoBankFeedProvider implements BankFeedProviderInterface
{
    public function providerKey(): string
    {
        return 'demo';
    }

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function fetchTransactions(array $options = []): array
    {
        $today = new \DateTimeImmutable('today');
        $transactions = [];
        $descriptions = [
            'Auto parts wholesale',
            'Customer payment',
            'Fuel charge',
            'Shop supplies',
            'Insurance payout',
        ];

        foreach ($descriptions as $index => $description) {
            $date = $today->sub(new \DateInterval(sprintf('P%dD', $index)));
            $transactions[] = [
                'external_id' => sprintf('demo-%s-%d', $date->format('Ymd'), $index + 1),
                'account_name' => 'Operating Account',
                'amount' => $index % 2 === 0 ? 425.50 + ($index * 12.25) : -210.75 - ($index * 18.5),
                'currency' => 'USD',
                'description' => $description,
                'transaction_date' => $date->format('Y-m-d'),
                'posted_at' => $date->setTime(14, 30)->format('Y-m-d H:i:s'),
                'status' => 'posted',
                'raw_payload' => [
                    'demo' => true,
                    'source' => 'generated',
                ],
            ];
        }

        return $transactions;
    }
}
