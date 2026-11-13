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
use InvalidArgumentException;

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
 *
 * Release 1.0 (R1-6): a second audience, {@see FOR_PARTNER_SUPPORT}, for a partner support PARTICIPANT of the request (the staff member who
 * submitted it, or who has replied publicly). It is the same public reply, but it is authorised by the partner support rule instead:
 * `viewAsPartnerSupport` (effective authority read from the tables when the job runs, so a designation revoked, a suspension, a
 * relationship moved or an organization disabled while the mail was queued sends nothing), and the link goes to the SUPPORT page, which the
 * recipient may open, never to the requester's. The mail names the requester and the organization and carries only what a support user
 * may already read: the public reply's text. No attachment name, no internal note, and never a reply the reader could not open there.
 */
#[Tries(3)]
#[Backoff(60, 180)]
#[DeleteWhenMissingModels]
class TicketRepliedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** The requester or the assignee: authorised by the ticket policy's `view`. */
    public const FOR_PARTICIPANT = 'participant';

    /** A partner support participant: authorised by `viewAsPartnerSupport`. */
    public const FOR_PARTNER_SUPPORT = 'partner_support';

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
        public readonly string $audience = self::FOR_PARTICIPANT,
    ) {
        if (! in_array($audience, [self::FOR_PARTICIPANT, self::FOR_PARTNER_SUPPORT], true)) {
            throw new InvalidArgumentException("Unknown audience '{$audience}'.");
        }
    }

    /** A job serialized before R1-6 has no `audience` in its payload: it is the ordinary participant mail it always was. */
    private function audience(): string
    {
        return $this->audience ?? self::FOR_PARTICIPANT;
    }

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

        if (! $notifiable instanceof Authenticatable) {
            return false;
        }

        if ($this->audience() === self::FOR_PARTNER_SUPPORT) {
            // The author is never told about their own reply, even if the recipient list is ever built wrongly.
            return (int) $notifiable->getAuthIdentifier() !== (int) $reply->user_id && Gate::forUser($notifiable)->allows('viewAsPartnerSupport', $ticket);
        }

        return Gate::forUser($notifiable)->allows('view', $ticket);
    }

    public function toMail(object $notifiable): MailMessage
    {
        // F-3: the title, the author's name and the preview are user-authored text inside a Markdown template (App\Support\Mail\MailText).
        // The preview keeps its pinned shape: the first 200 characters, then `...` (the ellipsis is ours, so it is appended after escaping).
        $body = MailText::plain($this->reply->body);
        $preview = MailText::line(MailText::escape(rtrim(mb_substr($body, 0, 200))), mb_strlen($body) > 200 ? '...' : '');

        $message = (new MailMessage)
            ->subject("[{$this->ticket->ticket_number}] New reply: ".MailText::plain($this->ticket->title))
            ->greeting(MailText::line('Hello ', MailText::escape($notifiable->name), ','));

        if ($this->audience() === self::FOR_PARTNER_SUPPORT) {
            $message->line(MailText::line("A new reply has been added to request **{$this->ticket->ticket_number}** for **", MailText::escape($this->ticket->user?->name ?: 'a contact', 255), '** at **', MailText::escape($this->ticket->organization?->name ?: 'the organization', 255), '**.'));
        } else {
            $message->line("A new reply has been added to request **{$this->ticket->ticket_number}**.");
        }

        return $message
            ->line(MailText::line('**', MailText::escape($this->reply->user->name), '** wrote:'))
            ->line($preview)
            ->action($this->audience() === self::FOR_PARTNER_SUPPORT ? 'View request' : 'View request', route($this->audience() === self::FOR_PARTNER_SUPPORT ? 'support.show' : 'tickets.show', $this->ticket));
    }
}
