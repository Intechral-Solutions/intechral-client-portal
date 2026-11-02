<?php

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
| Pest PHP configuration for the Intechral Client Portal.
| All tests use the Laravel Pest plugin for database refresh helpers.
*/

use App\Models\Ticket;
use App\Models\TicketAttachment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/** The nested attachment download URL (C6-R): addressed by the ticket's public reference, never the numeric key. */
function ticketAttachmentUrl(TicketAttachment $attachment): string
{
    return route('tickets.attachment.download', [$attachment->ticket, $attachment]);
}

/** The time-attribution context for a ticket (C6-R): the internal id travels with the ticket's public reference. */
function ticketTimeContext(Ticket $ticket): array
{
    return ['ticket_id' => $ticket->id, 'ticket_reference' => $ticket->ticket_number];
}
