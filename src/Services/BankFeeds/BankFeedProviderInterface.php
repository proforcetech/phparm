<?php

namespace App\Services\BankFeeds;

interface BankFeedProviderInterface
{
    public function providerKey(): string;

    /**
     * @param array<string, mixed> $options
     * @return array<int, array<string, mixed>>
     */
    public function fetchTransactions(array $options = []): array;
}
