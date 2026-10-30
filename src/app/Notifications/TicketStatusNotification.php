<?php

namespace App\Notifications;

use App\Http\Presenters\RequesterTicketPresenter;
use App\Models\Ticket;
use App\Models\TicketStatusHistory;
use App\Services\TicketService;
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
 * The two customer status mails of EPIC-017 O3, option c: the request is now "Waiting on you" (`pending_user`) or "Resolved"
 * (closes G-E2: a customer was not told, and auto-close follows 72 hours after Resolved).
 *
 * Recipient: the requester only (never the assignee, never an operator because they hold the capability). The wording is the
 * customer vocabulary ({@see RequesterTicketPresenter::STATUS_LABELS}): no operator status names, no SLA, no internal text, no
 * name of the person who changed the status.
 *
 * The mail is queued, so it is re-decided when the worker runs it, against persisted state, in this order:
 *  - the ticket is gone: discarded ({@see DeleteWhenMissingModels});
 *  - the recipient is not this ticket's requester, or no longer passes the ticket policy's `view`: dropped, the rule every ticket
 *    screen uses (a suspended account is dropped earlier, by {@see SkipSuspendedRecipients});
 *  - the ticket is no longer in the status being announced, or a LATER status change exists: dropped. That is what keeps a customer
 *    from being told "Waiting on you" about a request an operator has since resolved, and from receiving two "Resolved" mails when an
 *    operator flips a ticket resolved, open, resolved inside the queue delay: only the mail for the newest change is sent.
 * A database error is NOT caught: it propagates, the attempt fails and the queue retries, so an outage never silently swallows a
 * mail the customer is still entitled to. R0.9 WP1 retry policy: three attempts, 60 s then 180 s apart.
 */
#[Tries(3)]
#[Backoff(60, 180)]
#[DeleteWhenMissingModels]
class TicketStatusNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /** The statuses that notify the requester. Every other transition, auto-close included, sends nothing. */
    public const STATUSES = ['pending_user', 'resolved'];

    /**
     * @param  int  $historyId  the `ticket_status_histories` row written by the transition being announced
     */
    public function __construct(
        public readonly Ticket $ticket,
        public readonly string $status,
        public readonly int $historyId,
    ) {
        if (! in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException("Status '{$status}' does not notify the requester.");
        }
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function shouldSend(object $notifiable, string $channel): bool
    {
        $ticket = Ticket::query()->find($this->ticket->getKey());

        if ($ticket === null || ! $notifiable instanceof Authenticatable || (int) $notifiable->getAuthIdentifier() !== (int) $ticket->user_id) {
            return false;
        }

        if ($ticket->status !== $this->status) {
            return false;
        }

        $latest = TicketStatusHistory::query()->where('ticket_id', $ticket->getKey())->max('id');

        if ($latest === null || (int) $latest !== $this->historyId) {
            return false;
        }

        return Gate::forUser($notifiable)->allows('view', $ticket);
    }

    public function toMail(object $notifiable): MailMessage
    {
        $ticket = $this->ticket;
        $label = RequesterTicketPresenter::STATUS_LABELS[$this->status];

        $message = (new MailMessage)
            ->subject("[{$ticket->ticket_number}] {$label}: ".MailText::plain($ticket->title, 150))
            ->greeting(MailText::line('Hello ', MailText::escape($notifiable->name ?? ''), ','));

        if ($this->status === 'pending_user') {
            $message
                ->line("Your request **{$ticket->ticket_number}** is **{$label}**: we need something from you to continue.")
                ->line(MailText::line('**Subject:** ', MailText::escape($ticket->title, 255)))
                ->line('Please open the request and reply so we can keep going.');
        } else {
            $hours = TicketService::AUTO_CLOSE_HOURS;

            $message
                ->line("Your request **{$ticket->ticket_number}** has been marked **{$label}**.")
                ->line(MailText::line('**Subject:** ', MailText::escape($ticket->title, 255)))
                ->line("If it is not fully sorted, reply to the request and it will be reopened. If we do not hear from you it will be closed automatically after {$hours} hours.");
        }

        return $message->action('View request', route('tickets.show', $ticket));
    }
}
