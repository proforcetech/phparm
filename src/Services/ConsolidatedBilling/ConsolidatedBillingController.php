<?php

namespace App\Services\ConsolidatedBilling;

use App\Models\ConsolidatedStatement;
use App\Models\User;
use App\Support\Auth\AccessGate;
use InvalidArgumentException;

/**
 * HTTP controller for /api/consolidated-statements — Phase 17 / M11 of
 * docs/woms-expansion-plan.md.
 *
 * Permissions:
 *   consolidated_billing.view    — list and view statements
 *   consolidated_billing.manage  — generate, mark-sent, cancel, detach invoices,
 *                                  run the monthly batch
 */
class ConsolidatedBillingController
{
    public function __construct(
        private readonly ConsolidatedBillingService $service,
        private readonly ConsolidatedStatementRepository $statements,
        private readonly AccessGate $gate,
    ) {
    }

    /**
     * @param array<string, mixed> $filters
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, int>}
     */
    public function index(User $user, array $filters, int $page, int $perPage): array
    {
        $this->gate->assert($user, 'consolidated_billing.view');

        $page = max(1, $page);
        $perPage = max(1, min(200, $perPage));
        $offset = ($page - 1) * $perPage;

        $rows = $this->statements->list($filters, $perPage, $offset);
        $total = $this->statements->count($filters);

        return [
            'items' => array_map(static fn (ConsolidatedStatement $s) => self::statementToArray($s), $rows),
            'pagination' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $total,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function show(User $user, int $statementId): array
    {
        $this->gate->assert($user, 'consolidated_billing.view');

        $statement = $this->statements->findById($statementId);
        if ($statement === null) {
            throw new InvalidArgumentException("statement {$statementId} not found");
        }
        return [
            'statement' => self::statementToArray($statement),
            'invoices' => $this->statements->listInvoiceRows($statementId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function generate(
        User $user,
        int $customerId,
        string $periodStart,
        string $periodEnd,
        ?string $notes,
    ): array {
        $this->gate->assert($user, 'consolidated_billing.manage');

        $result = $this->service->generateForCustomer(
            $customerId,
            $periodStart,
            $periodEnd,
            (int) $user->id,
            $notes,
        );
        return [
            'statement' => self::statementToArray($result['statement']),
            'attached' => $result['attached'],
            'eligible_invoice_count' => count($result['eligible_invoice_ids']),
        ];
    }

    /**
     * @return array{processed: int, failures: array<int, array{customer_id: int, error: string}>}
     */
    public function runBatch(User $user, string $periodStart, string $periodEnd): array
    {
        $this->gate->assert($user, 'consolidated_billing.manage');

        return $this->service->runMonthlyBatch($periodStart, $periodEnd, (int) $user->id);
    }

    /**
     * @return array<string, mixed>
     */
    public function markSent(User $user, int $statementId): array
    {
        $this->gate->assert($user, 'consolidated_billing.manage');

        $statement = $this->service->markSent($statementId, (int) $user->id);
        return ['statement' => self::statementToArray($statement)];
    }

    /**
     * @return array<string, mixed>
     */
    public function cancel(User $user, int $statementId): array
    {
        $this->gate->assert($user, 'consolidated_billing.manage');

        $statement = $this->service->cancel($statementId, (int) $user->id);
        return ['statement' => self::statementToArray($statement)];
    }

    /**
     * @return array<string, mixed>
     */
    public function detachInvoice(User $user, int $statementId, int $invoiceId): array
    {
        $this->gate->assert($user, 'consolidated_billing.manage');

        $statement = $this->service->detachInvoice($statementId, $invoiceId, (int) $user->id);
        return ['statement' => self::statementToArray($statement)];
    }

    /**
     * @return array<string, mixed>
     */
    public static function statementToArray(ConsolidatedStatement $s): array
    {
        return [
            'id' => $s->id,
            'number' => $s->number,
            'customer_id' => $s->customer_id,
            'period_start' => $s->period_start,
            'period_end' => $s->period_end,
            'status' => $s->status,
            'subtotal' => $s->subtotal,
            'tax' => $s->tax,
            'total' => $s->total,
            'amount_paid' => $s->amount_paid,
            'balance_due' => $s->balance_due,
            'invoice_count' => $s->invoice_count,
            'notes' => $s->notes,
            'sent_at' => $s->sent_at,
            'paid_at' => $s->paid_at,
            'cancelled_at' => $s->cancelled_at,
            'generated_by_user_id' => $s->generated_by_user_id,
            'created_at' => $s->created_at,
            'updated_at' => $s->updated_at,
        ];
    }
}
