<?php

namespace App\Services\Tickets;

use App\Models\Ticket;
use App\Models\TicketRoutingRule;
use App\Services\Assets\SiteAssetRepository;

/**
 * Evaluate routing rules against a ticket and apply the first match's
 * actions (Phase 3.3 of docs/expansion-plan.md).
 *
 * Contract:
 *   routeTicket(Ticket): ?RoutingResult
 *
 * A NULL result means no rule matched — the ticket retains whatever assignee
 * / queue / priority the caller provided. The returned result exposes the
 * rule that fired plus the mutations (queue_id, user_id, priority) to apply
 * on top of the base ticket. The caller (TicketController) is responsible
 * for persisting those mutations so we can keep this service side-effect-free.
 */
class TicketRoutingService
{
    public function __construct(
        private readonly TicketRoutingRuleRepository $rules,
        private readonly SiteAssetRepository $assets,
    ) {
    }

    /**
     * Evaluate active rules against the ticket in evaluation_order, return
     * the first match with the actions it prescribes.
     *
     * @return array{rule: TicketRoutingRule, actions: array<string, mixed>}|null
     */
    public function routeTicket(Ticket $ticket): ?array
    {
        $assetTypeId = null;
        if ($ticket->asset_id !== null) {
            $asset = $this->assets->findById($ticket->asset_id);
            if ($asset !== null) {
                $assetTypeId = $asset->asset_type_id;
            }
        }

        foreach ($this->rules->listAll(activeOnly: true) as $rule) {
            if (!$this->matches($rule, $ticket, $assetTypeId)) {
                continue;
            }
            $actions = [];
            if ($rule->action_assign_queue_id !== null) {
                $actions['queue_id'] = $rule->action_assign_queue_id;
            }
            if ($rule->action_assign_user_id !== null) {
                $actions['assigned_to_user_id'] = $rule->action_assign_user_id;
            }
            if ($rule->action_set_priority !== null) {
                $actions['priority'] = $rule->action_set_priority;
            }
            return ['rule' => $rule, 'actions' => $actions];
        }
        return null;
    }

    private function matches(TicketRoutingRule $rule, Ticket $ticket, ?int $assetTypeId): bool
    {
        if ($rule->match_division_id !== null && $rule->match_division_id !== $ticket->division_id) {
            return false;
        }
        if ($rule->match_company_id !== null && $rule->match_company_id !== $ticket->company_id) {
            return false;
        }
        if ($rule->match_site_id !== null && $rule->match_site_id !== $ticket->site_id) {
            return false;
        }
        if ($rule->match_category_id !== null && $rule->match_category_id !== $ticket->category_id) {
            return false;
        }
        if ($rule->match_subcategory_id !== null && $rule->match_subcategory_id !== $ticket->subcategory_id) {
            return false;
        }
        if ($rule->match_priority !== null && $rule->match_priority !== $ticket->priority) {
            return false;
        }
        if ($rule->match_source !== null && $rule->match_source !== $ticket->source) {
            return false;
        }
        if ($rule->match_asset_type_id !== null && $rule->match_asset_type_id !== $assetTypeId) {
            return false;
        }
        return true;
    }
}
