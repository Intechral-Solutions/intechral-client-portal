<?php

namespace App\Notifications;

use App\Models\Ticket;
use App\Models\TicketReply;
use App\Support\Mail\MailText;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Queue\Attributes\Backoff;
use Illuminate\Queue\Attributes\DeleteWhenMissingModels;
use Illuminate\Queue\Attributes\Tries;

/**
 * Release 1.0 (R1-6): the operator alert for a PARTNER SUPPORT reply that nobody at Intechral would otherwise hear about (a request with no
 * assignee, or whose assignee cannot be mailed). Without it the reply sits unseen until someone happens to open the queue (R1-4 review,
 * finding 5).
 *
 * Same shape and destination as {@see NewTicketAlertNotification}: ONE configured address (`HELPDESK_NOTIFY_ADDRESS`), an on-demand
 * notification, never an account, never the `tickets.assign` holders. What it says is small: the number, the title, the organization,
 * the requester, who replied, a short preview of the reply and a link to the OPERATOR page. Public text only: the reply must still exist,
 * belong to this ticket and be public, or nothing is sent (an operator mail never carries a note, and this one never needs to). No
 * attachment names, no address. All user-authored text is escaped for the Markdown template ({@see MailText}).
 *
 * Queued; handed over only after the reply is committed. R0.9 WP1 retry policy: three attempts, 60 s then 180 s apart. A ticket or reply
 * that no longer exists is discarded, not failed.
 */
#[Tries(3)]
#[Backoff(60, 180)]
#[DeleteWhenMissingModels]
class PartnerReplyAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** Characters of the reply shown to the operator; the full text is one click away. */
    public const PREVIEW_LIMIT = 200;

    public function __construct(
        public readonly Ticket $ticket,
        public readonly TicketReply $reply,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    /** A reply that is gone, is not on this ticket or is an internal note is not announced. A database error propagates (the attempt is retried). */
    public function shouldSend(object $notifiable, string $channel): bool
    {
        // An operator mail with a reply preview goes to the configured address only, never to an account.
        if (! $notifiable instanceof AnonymousNotifiable) {
            return false;
        }

        $ticket = Ticket::query()->find($this->ticket->getKey());
        $reply = TicketReply::query()->find($this->reply->getKey());

        return $ticket !== null && $reply !== null && (int) $reply->ticket_id === (int) $ticket->getKey() && ! $reply->is_internal;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket;
        $body = MailText::plain($this->reply->body);
        $preview = MailText::line(MailText::escape(rtrim(mb_substr($body, 0, self::PREVIEW_LIMIT))), mb_strlen($body) > self::PREVIEW_LIMIT ? '...' : '');

        return (new MailMessage)
            ->subject("[{$ticket->ticket_number}] Partner support reply: ".MailText::plain($ticket->title, 150))
            ->greeting('Partner support replied')
            ->line(MailText::line("**{$ticket->ticket_number}** has a new reply from ", MailText::escape($this->reply->user?->name ?: 'partner support'), ' (partner support), and no assignee could be notified.'))
            ->line(MailText::line('**Organization:** ', MailText::escape($ticket->organization?->name ?: 'unknown', 255)))
            ->line(MailText::line('**Requester:** ', MailText::escape($ticket->user?->name ?: 'a customer', 255)))
            ->line(MailText::line('**Subject:** ', MailText::escape($ticket->title, 255)))
            ->line(MailText::line('**Reply:** ', $preview))
            ->action('Open in the portal', route('operator.tickets.show', $ticket));
    }
}
