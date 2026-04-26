<?php

namespace App\Services\Tickets;

use App\Models\TicketFailureCode;

class TicketFailureCodeRepository extends TicketCodeCatalogRepository
{
    protected function table(): string
    {
        return 'ticket_failure_codes';
    }

    protected function modelClass(): string
    {
        return TicketFailureCode::class;
    }
}
