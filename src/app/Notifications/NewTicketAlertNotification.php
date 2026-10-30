<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Support\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;

/**
 * The operator new-ticket alert (EPIC-017 O3, option c; closes G-E1: nobody was told when a request arrived).
 *
 * It goes to ONE configured destination, `HELPDESK_NOTIFY_ADDRESS`, as an on-demand (address) notification: never to the
 * requester, never to the `tickets.assign` holders, and never to an account, so the suspended-recipient listener has nothing to
 * decide here. What it says is deliberately small: the number, the title, the category and priority, who raised it, a short
 * preview of the description and a link to the operator page. No attachment names, no reply, no internal note, no requester address.
 * Every piece of user-authored text is escaped for the Markdown template (F-3, {@see MailText}).
 *
 * Queued; the dispatcher (TicketService::create) hands it over only after the ticket is committed. R0.9 WP1 retry policy: three
 * attempts, 60 s then 180 s apart. A ticket that no longer exists has nothing left to announce and is discarded, not failed.
 */
#[Tries(3)]
#[Backoff(60, 180)]
#[DeleteWhenMissingModels]
class NewTicketAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Characters of the description shown to the operator; the full text is one click away. */
    public const PREVIEW_LIMIT = 200;

    public function __construct(public readonly Ticket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket;
        $requester = MailText::escape($ticket->user?->name ?: 'a customer');

        return (new MailMessage)
            ->subject("[{$ticket->ticket_number}] New request: ".MailText::plain($ticket->title, 150))
            ->greeting('New support request')
            ->line(MailText::line("**{$ticket->ticket_number}** was raised by ", $requester, '.'))
            ->line(MailText::line('**Subject:** ', MailText::escape($ticket->title, 255)))
            ->line(MailText::line('**Category:** ', MailText::escape($ticket->category, 100)))
            ->line(MailText::line('**Priority:** ', MailText::escape(ucfirst((string) $ticket->priority), 40)))
            ->line(MailText::line('**Preview:** ', MailText::escape($ticket->description, self::PREVIEW_LIMIT)))
            ->action('Open in the portal', route('operator.tickets.show', $ticket));
    }
}
