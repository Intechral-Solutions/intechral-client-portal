<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Support\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Support\Facades\Gate;

/**
 * A queued mail about a ticket (or reply) that no longer exists has nothing left to say, so the job is
 * discarded instead of failing into `failed_jobs` with a ModelNotFoundException (EPIC-017 WP2 G3-A; Laravel 13
 * honours the attribute for queued notifications). It covers only a missing model: any other failure,
 * a mail transport error included, still fails and retries. R0.9 WP1: three attempts, 60 s then 180 s apart.
 *
 * The reply preview is a ticket disclosure, so it is re-authorised when the job runs, not only when it was queued
 * (TicketService::replyRecipients() decided at dispatch). A recipient who lost access in between (an assignee whose
 * `tickets.assign` was revoked, a restricted customer) gets nothing. The check is the ticket policy's `view`, the
 * rule every ticket screen uses; no access rule of its own. An internal note is never mailed, as at dispatch.
 */
#[Tries(3)]
#[Backoff(60, 180)]
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

    /**
     * Genuine ineligibility (ticket or reply gone, a reply that is not on this ticket, an internal note, a recipient who may no longer view the ticket)
     * returns false and the mail is dropped. A database error is NOT caught: it propagates, the attempt fails and the
     * queue retries, so a transient outage never silently swallows a reply a recipient is still entitled to.
     */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        $ticket = Ticket::query()->find($this->ticket->getKey());
        $reply = TicketReply::query()->find($this->reply->getKey());

        // The reply must belong to the very ticket being authorised: the view check below says nothing about a reply that
        // sits on a different ticket.
        if ($ticket === null || $reply === null || (int) $reply->ticket_id !== (int) $ticket->getKey() || $reply->is_internal) {
            return false;
        }

        return $notifiable instanceof Authenticatable && Gate::forUser($notifiable)->allows('view', $ticket);
    }

    public function toMail(object $notifiable): MailMessage
    {
        // F-3: the title, the author's name and the preview are user-authored text inside a Markdown template (App\Support\Mail\MailText).
        // The preview keeps its pinned shape: the first 200 characters, then `...` (the ellipsis is ours, so it is appended after escaping).
        $body = MailText::plain($this->reply->body);
        $preview = MailText::line(MailText::escape(rtrim(mb_substr($body, 0, 200))), mb_strlen($body) > 200 ? '...' : '');

        return (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] New reply: ".MailText::plain($this->ticket->title))
            ->greeting(MailText::line('Hello ', MailText::escape($notifiable->name), ','))
            ->line("A new reply has been added to ticket **{$this->ticket->ticket_number}**.")
            ->line(MailText::line('**', MailText::escape($this->reply->user->name), '** wrote:'))
            ->line($preview)
            ->action('View Ticket', url("/tickets/{$this->ticket->id}"));
    }
}
