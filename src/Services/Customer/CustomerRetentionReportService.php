<?php

namespace App\Services\Customer;

use App\Support\Webhooks\WebhookDispatcher;
use DateTimeImmutable;

class CustomerRetentionReportService
{
    private CustomerRepository $customers;
    private ?WebhookDispatcher $webhooks;

    public function __construct(CustomerRepository $customers, ?WebhookDispatcher $webhooks = null)
    {
        $this->customers = $customers;
        $this->webhooks = $webhooks;
    }

    /**
     * @return array{data: array<int, array<string, mixed>>, total: int}
     */
    public function list(int $months, int $limit, int $offset = 0, ?string $query = null): array
    {
        $result = $this->customers->findInactiveCustomers($months, $query, $limit, $offset);

        return [
            'data' => $this->mapRows($result['data']),
            'total' => $result['total'],
        ];
    }

    public function export(int $months, string $format = 'csv', ?string $query = null): string
    {
        $result = $this->customers->findInactiveCustomers($months, $query, null, 0);
        $rows = $this->mapRows($result['data']);

        if ($format === 'json') {
            return json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '[]';
        }

        return $this->toCsv($rows);
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function dispatchCampaign(array $payload): array
    {
        $months = isset($payload['months']) ? max(1, (int) $payload['months']) : 6;
        $query = isset($payload['query']) ? (string) $payload['query'] : null;
        $campaignName = (string) ($payload['campaign_name'] ?? 'Customer Retention');
        $limit = isset($payload['limit']) ? max(1, (int) $payload['limit']) : 250;

        $result = $this->customers->findInactiveCustomers($months, $query, $limit, 0);
        $rows = $this->mapRows($result['data']);

        $hookPayload = [
            'campaign_name' => $campaignName,
            'months' => $months,
            'query' => $query,
            'recipient_count' => count($rows),
            'recipients' => array_map(static fn (array $row) => $row['campaign_payload'], $rows),
            'generated_at' => (new DateTimeImmutable())->format(DATE_ATOM),
        ];

        if ($this->webhooks !== null) {
            $this->webhooks->dispatch('customer.retention.campaign', $hookPayload);
        }

        return [
            'dispatched' => $this->webhooks !== null,
            'payload' => $hookPayload,
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function mapRows(array $rows): array
    {
        $now = new DateTimeImmutable();

        return array_map(function (array $row) use ($now) {
            $lastWorkorderAt = $row['last_workorder_at'] ?? null;
            $lastWorkorderDate = $lastWorkorderAt ? new DateTimeImmutable($lastWorkorderAt) : null;
            $diff = $lastWorkorderDate ? $lastWorkorderDate->diff($now) : null;
            $monthsSince = $diff ? ($diff->y * 12) + $diff->m : null;
            $daysSince = $diff ? $diff->days : null;

            $fullName = trim(($row['first_name'] ?? '') . ' ' . ($row['last_name'] ?? ''));
            $name = $fullName !== '' ? $fullName : trim((string) ($row['business_name'] ?? ''));

            $preferredChannel = $row['preferred_channel'] ?? 'none';
            $isSubscribed = (bool) ($row['reminder_active'] ?? false);
            $contactEmail = $row['reminder_email'] ?? $row['email'] ?? null;
            $contactPhone = $row['reminder_phone'] ?? $row['phone'] ?? null;

            $campaignPayload = [
                'customer_id' => (int) $row['id'],
                'name' => $name,
                'email' => $contactEmail,
                'phone' => $contactPhone,
                'preferred_channel' => $preferredChannel,
                'is_subscribed' => $isSubscribed,
                'last_workorder_at' => $lastWorkorderAt,
                'months_since_workorder' => $monthsSince,
            ];

            return [
                'id' => (int) $row['id'],
                'first_name' => $row['first_name'] ?? null,
                'last_name' => $row['last_name'] ?? null,
                'business_name' => $row['business_name'] ?? null,
                'name' => $name,
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'last_workorder_at' => $lastWorkorderAt,
                'months_since_workorder' => $monthsSince,
                'days_since_workorder' => $daysSince,
                'messaging' => [
                    'preferred_channel' => $preferredChannel,
                    'is_subscribed' => $isSubscribed,
                    'email' => $contactEmail,
                    'phone' => $contactPhone,
                ],
                'campaign_payload' => $campaignPayload,
            ];
        }, $rows);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private function toCsv(array $rows): string
    {
        if (count($rows) === 0) {
            return '';
        }

        $header = [
            'customer_id',
            'name',
            'business_name',
            'email',
            'phone',
            'last_workorder_at',
            'months_since_workorder',
            'preferred_channel',
            'is_subscribed',
            'campaign_email',
            'campaign_phone',
        ];

        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $header);

        foreach ($rows as $row) {
            fputcsv($handle, [
                $row['id'],
                $row['name'],
                $row['business_name'],
                $row['email'],
                $row['phone'],
                $row['last_workorder_at'],
                $row['months_since_workorder'],
                $row['messaging']['preferred_channel'] ?? 'none',
                $row['messaging']['is_subscribed'] ? 'yes' : 'no',
                $row['messaging']['email'],
                $row['messaging']['phone'],
            ]);
        }

        rewind($handle);
        $csv = stream_get_contents($handle) ?: '';
        fclose($handle);

        return $csv;
    }
}
