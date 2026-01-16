<?php

namespace App\Services\BankFeeds;

use App\Support\SettingsRepository;
use InvalidArgumentException;
use RuntimeException;

class BankFeedService
{
    private SettingsRepository $settings;
    private BankFeedProviderFactory $providers;
    private BankFeedRepository $repository;

    public function __construct(
        SettingsRepository $settings,
        BankFeedProviderFactory $providers,
        BankFeedRepository $repository
    ) {
        $this->settings = $settings;
        $this->providers = $providers;
        $this->repository = $repository;
    }

    /**
     * @return array<string, mixed>
     */
    public function status(): array
    {
        return [
            'provider' => $this->settings->get('integrations.bank_feeds.provider', 'demo'),
            'status' => $this->settings->get('integrations.bank_feeds.status', 'disconnected'),
            'last_sync_at' => $this->settings->get('integrations.bank_feeds.last_sync_at'),
            'last_sync_status' => $this->settings->get('integrations.bank_feeds.last_sync_status'),
            'last_sync_count' => $this->settings->get('integrations.bank_feeds.last_sync_count', 0),
            'last_match_count' => $this->settings->get('integrations.bank_feeds.last_match_count', 0),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function authorize(array $payload, int $actorId): array
    {
        $provider = isset($payload['provider']) ? (string) $payload['provider'] : null;
        if (!$provider) {
            throw new InvalidArgumentException('provider is required');
        }

        if (isset($payload['access_token'])) {
            $this->settings->set('integrations.bank_feeds.access_token', (string) $payload['access_token']);
        }

        $this->settings->set('integrations.bank_feeds.provider', $provider);
        $this->settings->set('integrations.bank_feeds.status', 'connected');
        $this->settings->set('integrations.bank_feeds.last_sync_status', null);
        $this->settings->set('integrations.bank_feeds.last_sync_count', 0);
        $this->settings->set('integrations.bank_feeds.last_match_count', 0);

        return $this->status();
    }

    /**
     * @return array<string, mixed>
     */
    public function sync(int $actorId): array
    {
        $status = $this->settings->get('integrations.bank_feeds.status', 'disconnected');
        if ($status !== 'connected') {
            throw new RuntimeException('Bank feed is not connected.');
        }

        $providerKey = (string) $this->settings->get('integrations.bank_feeds.provider', 'demo');
        $provider = $this->providers->create($providerKey);

        $transactions = $provider->fetchTransactions([
            'access_token' => $this->settings->get('integrations.bank_feeds.access_token'),
        ]);

        $stored = $this->repository->upsertTransactions($provider->providerKey(), $transactions);
        $matched = $this->matchTransactions($stored, $actorId);

        $now = (new \DateTimeImmutable('now'))->format('Y-m-d H:i:s');
        $this->settings->set('integrations.bank_feeds.last_sync_at', $now);
        $this->settings->set('integrations.bank_feeds.last_sync_status', 'success');
        $this->settings->set('integrations.bank_feeds.last_sync_count', count($stored));
        $this->settings->set('integrations.bank_feeds.last_match_count', $matched);

        return [
            'synced' => count($stored),
            'matched' => $matched,
            'status' => $this->status(),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $transactions
     */
    private function matchTransactions(array $transactions, int $actorId): int
    {
        $matches = 0;

        foreach ($transactions as $transaction) {
            $paymentId = $this->repository->findPaymentMatch(
                (float) $transaction['amount'],
                (string) $transaction['transaction_date']
            );

            if ($paymentId === null) {
                continue;
            }

            $this->repository->createMatch(
                (int) $transaction['id'],
                'payment_transaction',
                $paymentId,
                'amount_date',
                $actorId
            );
            $matches += 1;
        }

        return $matches;
    }
}
