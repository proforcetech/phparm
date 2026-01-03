<?php

namespace App\Services\Estimate;

use App\Support\Notifications\NotificationDispatcher;

class EstimateMailer
{
    private EstimateRepository $estimates;
    private NotificationDispatcher $notifications;
    private EstimatePublicLinkService $links;

    public function __construct(
        EstimateRepository $estimates,
        NotificationDispatcher $notifications,
        EstimatePublicLinkService $links
    ) {
        $this->estimates = $estimates;
        $this->notifications = $notifications;
        $this->links = $links;
    }

    public function sendEstimate(int $estimateId, string $recipientEmail, string $baseUrl): void
    {
        $estimate = $this->estimates->find($estimateId);
        if ($estimate === null) {
            return;
        }

        $link = $this->links->issueLink($estimateId, $baseUrl, $estimate->expiration_date);

        $data = [
            'estimate_number' => $estimate->number,
            'expires_at' => $estimate->expiration_date,
            'total' => $estimate->grand_total,
            'secure_link' => $link['secure_url'],
        ];

        $this->notifications->sendMail('estimate.sent', $recipientEmail, $data, 'Your estimate is ready');
    }

    public function sendReminder(int $estimateId, string $recipientEmail, string $baseUrl): void
    {
        $estimate = $this->estimates->find($estimateId);
        if ($estimate === null) {
            return;
        }

        $link = $this->links->issueLink($estimateId, $baseUrl, $estimate->expiration_date);
        $data = [
            'estimate_number' => $estimate->number,
            'expires_at' => $estimate->expiration_date,
            'total' => $estimate->grand_total,
            'secure_link' => $link['secure_url'],
        ];

        $this->notifications->sendMail('estimate.reminder', $recipientEmail, $data, 'Reminder: estimate awaiting approval');
    }
}
