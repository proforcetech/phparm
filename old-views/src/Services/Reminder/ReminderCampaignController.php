<?php

namespace App\Services\Reminder;

use App\Models\ReminderCampaign;
use App\Models\ReminderLog;
use InvalidArgumentException;

class ReminderCampaignController
{
    private ReminderCampaignService $campaigns;
    private ReminderScheduler $scheduler;
    private ReminderLogService $logs;

    public function __construct(ReminderCampaignService $campaigns, ReminderScheduler $scheduler, ReminderLogService $logs)
    {
        $this->campaigns = $campaigns;
        $this->scheduler = $scheduler;
        $this->logs = $logs;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function index(): array
    {
        return array_map(static fn (ReminderCampaign $campaign) => $campaign->toArray(), $this->campaigns->list());
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function store(array $payload, int $actorId): ReminderCampaign
    {
        return $this->campaigns->create($payload, $actorId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function update(int $campaignId, array $payload, int $actorId): ?ReminderCampaign
    {
        return $this->campaigns->update($campaignId, $payload, $actorId);
    }

    public function pause(int $campaignId, int $actorId): ?ReminderCampaign
    {
        return $this->campaigns->update($campaignId, ['status' => 'paused'], $actorId);
    }

    public function activate(int $campaignId, int $actorId): ?ReminderCampaign
    {
        $campaign = $this->campaigns->activate($campaignId, $actorId);
        if ($campaign === null) {
            return null;
        }

        $this->scheduler->sendDueCampaigns($actorId);

        return $campaign;
    }

    /**
     * Force a one-off run for a campaign.
     */
    public function runNow(int $campaignId, int $actorId): int
    {
        $campaign = $this->campaigns->find($campaignId);
        if ($campaign === null) {
            throw new InvalidArgumentException('Campaign not found.');
        }

        return $this->scheduler->sendDueCampaigns($actorId);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function logs(int $campaignId, int $limit = 50): array
    {
        return array_map(static fn (ReminderLog $log) => $log->toArray(), $this->logs->forCampaign($campaignId, $limit));
    }
}
