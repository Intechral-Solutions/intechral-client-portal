<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Support\Str;

/**
 * A queued mail about a ticket (or reply) that no longer exists has nothing left to say, so the job is
 * discarded instead of failing into `failed_jobs` with a ModelNotFoundException (EPIC-017 WP2 G3-A; Laravel 13
 * honours the attribute for queued notifications). It covers only a missing model: any other failure,
 * a mail transport error included, still fails and retries as before.
 */
#[DeleteWhenMissingModels]
class TicketRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $preview = Str::limit($this->reply->body, 200);

        return (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] New reply: {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A new reply has been added to ticket **{$this->ticket->ticket_number}**.")
            ->line("**{$this->reply->user->name}** wrote:")
            ->line($preview)
            ->action('View Ticket', url("/tickets/{$this->ticket->id}"));
    }
}
