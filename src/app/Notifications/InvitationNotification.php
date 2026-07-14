<?php

namespace App\Notifications;

use App\Models\Invitation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Invitation $invitation) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invitation.show', ['token' => $this->invitation->token]);
        $inviterName = $this->invitation->invitedBy?->name ?? config('app.name');
        $expiresIn = '48 hours';

        return (new MailMessage)
            ->subject("You've been invited to ".config('app.name'))
            ->greeting('Hello!')
            ->line("{$inviterName} has invited you to join ".config('app.name').'.')
            ->action('Accept Invitation', $url)
            ->line("This invitation expires in {$expiresIn}. If you did not expect this invitation, you may ignore this email.");
    }
}
