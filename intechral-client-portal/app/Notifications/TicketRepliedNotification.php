<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class TicketRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly Ticket      $ticket,
        public readonly TicketReply $reply,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $preview = \Illuminate\Support\Str::limit($this->reply->body, 200);

        return (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] New reply: {$this->ticket->title}")
            ->greeting("Hello {$notifiable->name},")
            ->line("A new reply has been added to ticket **{$this->ticket->ticket_number}**.")
            ->line("**{$this->reply->user->name}** wrote:")
            ->line($preview)
            ->action('View Ticket', url("/tickets/{$this->ticket->id}"));
    }
}
