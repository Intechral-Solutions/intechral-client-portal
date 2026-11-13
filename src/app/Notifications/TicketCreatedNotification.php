<?php

namespace App\Notifications;

use App\Models\Ticket;
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
 * Release 1.0 (R1-6): a request that partner support raised for a managed customer's contact (`submitted_by_id` differs from `user_id`, R1-5)
 * has two people to tell, so this one class has two audiences, chosen by the dispatcher and carried with the job:
 *
 *  - {@see FOR_REQUESTER}, the receipt a requester always gets. When someone else raised the request it also names them and the organization,
 *    so the contact is never told a request exists without being told who made it. A request raised by the requester themselves is
 *    byte-for-byte what it was before and is not re-authorised at send time (it discloses only what they typed themselves);
 *  - {@see FOR_SUBMITTER}, the partner staff member's own confirmation, with a link to the SUPPORT page (never the requester's). It carries
 *    the title and the contact's name, so it is a disclosure and is re-authorised when the job runs: the recipient must still be the
 *    submitter and still hold effective partner support authority over the ticket (`viewAsPartnerSupport`, read from the tables). A designation
 *    revoked, a suspension, a relationship moved or an organization disabled between queueing and sending sends nothing.
 *
 * A database error inside {@see shouldSend()} is not caught: the attempt fails and the queue retries.
 */
#[Tries(3)]
#[Backoff(60, 180)]
#[DeleteWhenMissingModels]
class TicketCreatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public const FOR_REQUESTER = 'requester';

    public const FOR_SUBMITTER = 'submitter';

    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $audience = self::FOR_REQUESTER,
    ) {
        if (! in_array($audience, [self::FOR_REQUESTER, self::FOR_SUBMITTER], true)) {
            throw new InvalidArgumentException("Unknown audience '{$audience}'.");
        }
    }

    /** A job serialized before R1-6 has no `audience` in its payload: it is the requester's receipt it always was. */
    private function audience(): string
    {
        return $this->audience ?? self::FOR_REQUESTER;
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $ticket = Ticket::query()->find($this->ticket->getKey());

        if ($ticket === null || ! $notifiable instanceof Authenticatable) {
            return false;
        }

        $raisedByAnother = $ticket->raisedByAnother();

        if ($this->audience() === self::FOR_SUBMITTER) {
            return $raisedByAnother
                && $ticket->submittedBy()->whereKey($notifiable->getAuthIdentifier())->exists()
                && Gate::forUser($notifiable)->allows('viewAsPartnerSupport', $ticket);
        }

        // Unchanged: a requester's receipt for a request they raised themselves is not re-decided here.
        if (! $raisedByAnother) {
            return true;
        }

        return (int) $notifiable->getAuthIdentifier() === (int) $ticket->user_id && Gate::forUser($notifiable)->allows('view', $ticket);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket;

        if ($this->audience() === self::FOR_SUBMITTER) {
            return (new MailMessage)
                ->subject("[{$ticket->ticket_number}] Request raised: ".MailText::plain($ticket->title))
                ->greeting(MailText::line('Hello ', MailText::escape($notifiable->name), ','))
                ->line(MailText::line("You raised request **{$ticket->ticket_number}** for **", MailText::escape($ticket->user?->name ?: 'a contact', 255), '** at **', MailText::escape($ticket->organization?->name ?: 'the organization', 255), '**.'))
                ->line(MailText::line('**Subject:** ', MailText::escape($ticket->title, 255)))
                ->line('**Priority:** '.ucfirst($ticket->priority))
                ->action('View request', route('support.show', $ticket))
                ->line('You will be emailed when the requester or Intechral replies.');
        }

        $message = (new MailMessage)
            ->subject("[{$ticket->ticket_number}] Request received: ".MailText::plain($ticket->title))
            ->greeting(MailText::line('Hello ', MailText::escape($notifiable->name), ','))
            ->line("Your service request **{$ticket->ticket_number}** has been received.");

        if ($ticket->raisedByAnother()) {
            $message->line(MailText::line('**', MailText::escape($ticket->submittedBy?->name ?: 'Partner support', 255), '** raised this request for you at **', MailText::escape($ticket->organization?->name ?: 'your organization', 255), '**.'));
        }

        return $message
            ->line(MailText::line('**Subject:** ', MailText::escape($ticket->title)))
            ->line('**Priority:** '.ucfirst($ticket->priority))
            ->action('View request', route('tickets.show', $ticket))
            ->line("We'll get back to you as soon as possible.");
    }
}
