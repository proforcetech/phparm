<?php

namespace App\Services\Tickets;

use App\Models\TicketResolutionCode;

class TicketResolutionCodeRepository extends TicketCodeCatalogRepository
{
    protected function table(): string
    {
        return 'ticket_resolution_codes';
    }

    protected function modelClass(): string
    {
        return TicketResolutionCode::class;
    }
}
