<?php

namespace App\Notifications;

use App\Models\Ticket;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

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
