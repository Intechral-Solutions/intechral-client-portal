<?php

namespace App\Notifications;

use App\Models\Invitation;
use App\Support\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;

/**
 * An invitation is mailed only while it is still the live one (R0.9 WP1). The mail is queued, so by the time the
 * worker runs it the invitation may have been re-issued (InvitationService::invite() expires every earlier pending
 * invitation for the address), accepted, expired or deleted; mailing the link then would hand out a dead or already
 * consumed credential. `shouldSend()` re-reads the persisted row at send time and applies the same rule the
 * accept-link uses (Invitation::isPending()). A deleted invitation is discarded rather than failed, as for tickets.
 *
 * Three attempts, 60 s then 180 s apart: a transient SMTP failure is retried; a permanent one lands in `failed_jobs`.
 */
#[Tries(3)]
#[Backoff(60, 180)]
#[DeleteWhenMissingModels]
class InvitationNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public readonly Invitation $invitation) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /**
     * Genuine ineligibility (no such row, or not pending any more) returns false and the mail is dropped. A database
     * error is NOT caught: it propagates, the attempt fails and the queue retries, so a transient outage never
     * silently swallows a valid invitation.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        return Invitation::query()->find($this->invitation->getKey())?->isPending() === true;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $url = route('invitation.show', ['token' => $this->invitation->token]);
        // The inviter's name is user-authored text inside a Markdown template (EPIC-017 F-3, App\Support\Mail\MailText).
        $inviter = MailText::escape($this->invitation->invitedBy?->name ?? config('app.name'));
        $expiresIn = '48 hours';

        return (new MailMessage)
            ->subject("You've been invited to ".config('app.name'))
            ->greeting('Hello!')
            ->line(MailText::line($inviter, ' has invited you to join '.config('app.name').'.'))
            ->action('Accept Invitation', $url)
            ->line("This invitation expires in {$expiresIn}. If you did not expect this invitation, you may ignore this email.");
    }
}
