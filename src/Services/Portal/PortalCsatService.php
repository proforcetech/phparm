<?php

namespace App\Services\Portal;

use App\Models\PortalAccount;
use App\Models\PortalCsatResponse;
use App\Models\User;
use App\Models\Workorder;
use App\Services\Customer\CustomerRepository;
use App\Services\Workorder\WorkorderRepository;
use App\Support\Audit\AuditEntry;
use App\Support\Audit\AuditLogger;
use App\Support\Auth\UnauthorizedException;
use InvalidArgumentException;

/**
 * Phase 2f — customer-satisfaction surface.
 *
 * Two entry paths:
 *   1. Authenticated portal user: dashboard surfaces "rate this work order"
 *      cards for closed work orders that don't yet have a CSAT row, the
 *      user picks a rating, we insert+answer in one shot.
 *   2. Public token link (e.g. emailed survey): the portal_csat_responses
 *      row is pre-seeded with a public_token; a separate route exchanges
 *      the token for a no-auth response. The public path stays narrow —
 *      only the row for that token can be touched, no surrounding data.
 *
 * Visibility rule (mirrors PortalWorkorderService): workorders.customer_id
 * must belong to a customer in portal_account.company_id. We re-resolve
 * via CustomerRepository on every request so a workorder reassigned to a
 * different customer drops out of the prompt list immediately.
 *
 * No auto-provisioning of "send the survey" — staff or a cron job must
 * trigger the request. This service is the rating surface, not a job
 * scheduler.
 */
class PortalCsatService
{
    private const PROMPT_LOOKBACK_DAYS = 30;

    public function __construct(
        private readonly PortalCsatRepository $csat,
        private readonly WorkorderRepository $workorders,
        private readonly CustomerRepository $customers,
        private readonly AuditLogger $audit,
    ) {
    }

    /**
     * Closed work orders within the lookback window that the account hasn't
     * yet rated. Used by the dashboard "please rate" surface.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listPending(User $user, PortalAccount $account): array
    {
        $this->assertUsable($account);
        $customerIds = $this->customers->listIdsForCompany($account->company_id);
        if ($customerIds === []) {
            return [];
        }
        $cutoff = date('Y-m-d H:i:s', strtotime('-' . self::PROMPT_LOOKBACK_DAYS . ' days'));

        $closed = [];
        foreach ($customerIds as $customerId) {
            foreach ($this->workorders->list(
                ['customer_id' => $customerId, 'status' => [Workorder::STATUS_COMPLETED]],
                100,
                0,
            ) as $wo) {
                if ($wo->completed_at !== null && $wo->completed_at >= $cutoff) {
                    $closed[] = $wo;
                }
            }
        }

        $pending = [];
        foreach ($closed as $wo) {
            $existing = $this->csat->findByAccountAndWorkorder($account->id, $wo->id);
            if ($existing !== null && $existing->isAnswered()) {
                continue;
            }
            $pending[] = [
                'workorder_id' => $wo->id,
                'workorder_number' => $wo->number,
                'completed_at' => $wo->completed_at,
                'csat_id' => $existing?->id,
            ];
        }
        return $pending;
    }

    /**
     * Already-answered ratings for the account (history view).
     *
     * @return array<int, array<string, mixed>>
     */
    public function listHistory(User $user, PortalAccount $account): array
    {
        $this->assertUsable($account);
        return array_map(
            fn(PortalCsatResponse $r) => $this->serialize($r),
            $this->csat->listForAccount($account->id, answeredOnly: true)
        );
    }

    /**
     * Submit a 1-5 rating + optional comment from an authenticated portal
     * user. Idempotent: re-submitting to the same workorder updates the
     * existing row in-place (so a user correcting a typo doesn't strand
     * the original, and we don't multi-count their rating).
     */
    public function submit(
        User $user,
        PortalAccount $account,
        int $workorderId,
        int $rating,
        ?string $comment
    ): PortalCsatResponse {
        $this->assertUsable($account);
        $this->assertWorkorderInScope($account, $workorderId);
        $rating = $this->normalizeRating($rating);
        $comment = $this->normalizeComment($comment);

        $existing = $this->csat->findByAccountAndWorkorder($account->id, $workorderId);
        if ($existing === null) {
            $existing = $this->csat->request($account->id, $workorderId);
        }
        $updated = $this->csat->recordResponse($existing->id, $rating, $comment);

        $this->audit->log(new AuditEntry(
            'portal.csat.submitted',
            'portal_csat_response',
            $updated->id,
            $user->id,
            [
                'portal_account_id' => $account->id,
                'workorder_id' => $workorderId,
                'rating' => $rating,
                'has_comment' => $comment !== null,
            ]
        ));

        return $updated;
    }

    /**
     * Public-link variant — no authenticated portal user, just a token. The
     * token row carries portal_account_id + workorder_id so we don't need
     * extra params; we only verify the token resolves and isn't already
     * answered.
     */
    public function submitByPublicToken(string $token, int $rating, ?string $comment): PortalCsatResponse
    {
        $row = $this->csat->findByPublicToken($token);
        if ($row === null) {
            throw new InvalidArgumentException('CSAT token not found');
        }
        if ($row->isAnswered()) {
            throw new InvalidArgumentException('CSAT token has already been used');
        }
        $rating = $this->normalizeRating($rating);
        $comment = $this->normalizeComment($comment);
        $updated = $this->csat->recordResponse($row->id, $rating, $comment);

        $this->audit->log(new AuditEntry(
            'portal.csat.submitted_public',
            'portal_csat_response',
            $updated->id,
            null,
            [
                'portal_account_id' => $row->portal_account_id,
                'workorder_id' => $row->workorder_id,
                'rating' => $rating,
                'has_comment' => $comment !== null,
            ]
        ));

        return $updated;
    }

    private function assertUsable(PortalAccount $account): void
    {
        if (!$account->isUsable()) {
            throw new UnauthorizedException('portal_account is not usable');
        }
    }

    private function assertWorkorderInScope(PortalAccount $account, int $workorderId): void
    {
        if ($workorderId <= 0) {
            throw new InvalidArgumentException('workorder_id is required');
        }
        $wo = $this->workorders->find($workorderId);
        if ($wo === null) {
            throw new InvalidArgumentException("workorder {$workorderId} not found");
        }
        $customer = $this->customers->find($wo->customer_id);
        if ($customer === null
            || (int) ($customer->company_id ?? 0) !== $account->company_id
        ) {
            throw new UnauthorizedException('workorder belongs to a different company');
        }
    }

    private function normalizeRating(int $rating): int
    {
        if ($rating < 1 || $rating > 5) {
            throw new InvalidArgumentException('rating must be 1..5');
        }
        return $rating;
    }

    private function normalizeComment(?string $comment): ?string
    {
        if ($comment === null) {
            return null;
        }
        $trimmed = trim($comment);
        if ($trimmed === '') {
            return null;
        }
        if (mb_strlen($trimmed) > 2000) {
            throw new InvalidArgumentException('comment is too long (max 2000 chars)');
        }
        return $trimmed;
    }

    /**
     * @return array<string, mixed>
     */
    public function serialize(PortalCsatResponse $r): array
    {
        return [
            'id' => $r->id,
            'workorder_id' => $r->workorder_id,
            'rating' => $r->rating,
            'comment' => $r->comment,
            'requested_at' => $r->requested_at,
            'responded_at' => $r->responded_at,
        ];
    }
}
