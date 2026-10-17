<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;

/**
 * A queued mail about a ticket (or reply) that no longer exists has nothing left to say, so the job is
 * discarded instead of failing into `failed_jobs` with a ModelNotFoundException (EPIC-017 WP2 G3-A; Laravel 13
 * honours the attribute for queued notifications). It covers only a missing model: any other failure,
 * a mail transport error included, still fails and retries. R0.9 WP1: three attempts, 60 s then 180 s apart.
 */
#[Tries(3)]
#[Backoff(60, 180)]
#[DeleteWhenMissingModels]
class TicketCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Ticket $ticket) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] Ticket received: {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("Your support ticket **{$this->ticket->ticket_number}** has been received.")
            ->line("**Subject:** {$this->ticket->title}")
            ->line('**Priority:** '.ucfirst($this->ticket->priority))
            ->action('View Ticket', url("/tickets/{$this->ticket->id}"))
            ->line("We'll get back to you as soon as possible.");
    }
}
