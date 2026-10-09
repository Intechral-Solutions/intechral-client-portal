<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use App\Rules\AllowedTicketAttachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class TicketService
{
    /**
     * The implicit transitions of a CUSTOMER reply (EPIC-017 O2). A reply on `open` or
     * `in_progress` changes nothing; a reply on `closed` is refused (CLOSED_REPLY_MESSAGE).
     */
    public const CUSTOMER_REPLY_TRANSITIONS = [
        'pending_user' => 'in_progress',
        'resolved' => 'open',
    ];

    public const CLOSED_REPLY_MESSAGE = 'This request is closed. Raise a new request if you need more help.';

    public function create(User $user, array $data, array $files = []): Ticket
    {
        $priority = $data['priority'];
        $hours = Ticket::SLA_HOURS[$priority];

        // The row, its number and every attachment row commit together, and a file is written
        // only inside that transaction; storeAttachments() deletes every written file when it
        // rolls back (F-7). The database reserves the row identity first and the public number is
        // derived from it (EPIC-010D H8), so no temporary number is ever visible.
        $ticket = $this->storeAttachments(function (array &$stored) use ($user, $data, $priority, $hours, $files) {
            $ticket = Ticket::create([
                'ticket_number' => 'TMP-'.bin2hex(random_bytes(6)),
                'user_id' => $user->id,
                'title' => $data['title'],
                'description' => $data['description'],
                'category' => $data['category'],
                'priority' => $priority,
                'status' => 'open',
                'sla_due_at' => now()->addHours($hours),
            ]);

            $ticket->update(['ticket_number' => $this->ticketNumberFor($ticket)]);

            foreach ($files as $file) {
                $this->storeAttachment($ticket, null, $user, $file, $stored);
            }

            return $ticket;
        });

        // After commit only: a mail must never describe a ticket that rolled back.
        $user->notify(new TicketCreatedNotification($ticket));

        return $ticket;
    }

    /**
     * A reply, with the O2 lifecycle for a customer author (EPIC-017 §13).
     *
     * "Customer" is a capability, never a route or the shell presentation: an author without
     * `tickets.assign`. Everything happens under a lock on the ticket row, against its committed
     * status, so a concurrent operator change or auto-close is seen, not overwritten:
     *
     *  - a customer reply on `closed` is refused BEFORE any write: no reply, attachment row, file,
     *    status change, history row or notification;
     *  - a customer reply on `pending_user` / `resolved` moves the ticket to `in_progress` / `open`
     *    through transition(), so the history row names the customer, in the same transaction;
     *  - an operator reply never changes status and is accepted on any status (unchanged).
     *
     * Files are written inside the transaction and deleted if it rolls back (F-7). Notifications
     * are sent only after commit.
     */
    public function addReply(Ticket $ticket, User $user, string $body, bool $isInternal, array $files = []): TicketReply
    {
        $isCustomer = ! $user->can('tickets.assign');

        [$reply, $current] = $this->storeAttachments(function (array &$stored) use ($ticket, $user, $body, $isInternal, $files, $isCustomer) {
            $current = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if ($isCustomer && $current->status === 'closed') {
                throw ValidationException::withMessages(['body' => self::CLOSED_REPLY_MESSAGE]);
            }

            $reply = $current->replies()->create([
                'user_id' => $user->id,
                'body' => $body,
                'is_internal' => $isInternal,
            ]);

            foreach ($files as $file) {
                $this->storeAttachment($current, $reply, $user, $file, $stored);
            }

            $next = $isCustomer ? (self::CUSTOMER_REPLY_TRANSITIONS[$current->status] ?? null) : null;

            if ($next !== null) {
                $this->transition($current, $user, $next);
            }

            return [$reply, $current];
        });

        $ticket->setRawAttributes($current->getAttributes(), true);

        foreach ($this->replyRecipients($current, $user, $isInternal) as $recipient) {
            $recipient->notify(new TicketRepliedNotification($current, $reply));
        }

        return $reply;
    }

    /**
     * Move a ticket to a new status, validated against its COMMITTED status read under a row lock,
     * so a stale caller (a page loaded before a customer reopened the ticket) can neither skip the
     * transition map nor record a history row whose old status never existed. The caller's model
     * is refreshed with the result.
     */
    public function transition(Ticket $ticket, User $actor, string $newStatus): void
    {
        DB::transaction(function () use ($ticket, $actor, $newStatus) {
            $current = Ticket::query()->lockForUpdate()->findOrFail($ticket->getKey());

            if (! $current->canTransitionTo($newStatus)) {
                throw ValidationException::withMessages([
                    'status' => "Cannot transition from \"{$current->status}\" to \"{$newStatus}\".",
                ]);
            }

            $old = $current->status;

            $current->status = $newStatus;

            if ($newStatus === 'resolved' && $current->resolved_at === null) {
                $current->resolved_at = now();
            }
            if ($newStatus === 'closed' && $current->closed_at === null) {
                $current->closed_at = now();
            }
            // Re-open clears resolved/closed timestamps
            if ($newStatus === 'open') {
                $current->resolved_at = null;
                $current->closed_at = null;
            }

            $current->save();

            TicketStatusHistory::create([
                'ticket_id' => $current->id,
                'user_id' => $actor->id,
                'old_status' => $old,
                'new_status' => $newStatus,
                'created_at' => now(),
            ]);

            $ticket->setRawAttributes($current->getAttributes(), true);
        });
    }

    public function assign(Ticket $ticket, ?int $assigneeId, User $actor): void
    {
        $this->assertAssignable($assigneeId);

        $ticket->update(['assignee_id' => $assigneeId]);

        activity()->causedBy($actor)
            ->performedOn($ticket)
            ->withProperties(['assignee_id' => $assigneeId])
            ->log('assigned ticket');
    }

    /**
     * Close tickets resolved at least $idleHours ago. Each close is a compare-and-set: it applies
     * only if the ticket is STILL resolved past the cutoff when it is written, and writes its
     * history row only when it did. A customer reply that reopened the ticket after the candidate
     * list was read (EPIC-017 O2) therefore wins, and no false "resolved → closed" row appears.
     * Returns the number of tickets actually closed.
     */
    public function autoCloseResolved(int $idleHours = 72): int
    {
        $cutoff = now()->subHours($idleHours);
        $candidates = Ticket::where('status', 'resolved')
            ->where('resolved_at', '<=', $cutoff)
            ->pluck('id');

        $closed = 0;

        foreach ($candidates as $id) {
            $closed += DB::transaction(function () use ($id, $cutoff): int {
                $now = now();
                $affected = Ticket::whereKey($id)
                    ->where('status', 'resolved')
                    ->where('resolved_at', '<=', $cutoff)
                    ->update(['status' => 'closed', 'closed_at' => $now]);

                if ($affected !== 1) {
                    return 0;
                }

                TicketStatusHistory::create([
                    'ticket_id' => $id,
                    'user_id' => null,
                    'old_status' => 'resolved',
                    'new_status' => 'closed',
                    'created_at' => $now,
                ]);

                return 1;
            });
        }

        return $closed;
    }

    /**
     * The one assignment-eligibility rule (EPIC-010D H5): a Ticket may be assigned only to a user
     * who currently holds `tickets.assign`. Unassigning (null) is always allowed. There is no
     * exemption for the current assignee, so a stale assignee who lost the capability cannot be
     * re-selected. Bulk callers run this before touching any Ticket.
     *
     * @throws ValidationException
     */
    public function assertAssignable(?int $assigneeId): void
    {
        if ($assigneeId === null) {
            return;
        }

        if (! User::find($assigneeId)?->can('tickets.assign')) {
            throw ValidationException::withMessages([
                'assignee_id' => 'The selected assignee cannot be assigned tickets.',
            ]);
        }
    }

    // ── Private helpers ──────────────────────────────────────

    private function ticketNumberFor(Ticket $ticket): string
    {
        return 'TKT-'.str_pad((string) $ticket->id, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Who is emailed about a reply: nobody for an internal note; otherwise the owner and the
     * assignee, except the author, only users who can currently view the Ticket (a stale assignee
     * who lost `tickets.assign` cannot, so receives no preview), and each user at most once.
     *
     * @return Collection<int, User>
     */
    private function replyRecipients(Ticket $ticket, User $author, bool $isInternal)
    {
        if ($isInternal) {
            return collect();
        }

        return collect([$ticket->user, $ticket->assignee])
            ->filter()
            ->reject(fn (User $recipient) => $recipient->is($author))
            ->unique('id')
            ->filter(fn (User $recipient) => Gate::forUser($recipient)->allows('view', $ticket))
            ->values();
    }

    /**
     * Run $work in a transaction, giving it a list in which storeAttachment() records every file
     * it writes. The database rolls back on any exception, but the filesystem does not, so the
     * recorded files are deleted before the exception continues (EPIC-010D F-7): no file outlives
     * a failed create or reply, and since a row is created only after its file was written, no
     * row is left without its file. Only a process killed mid-request can still strand a file.
     *
     * @template T
     *
     * @param  callable(array<int, string>&): T  $work
     * @return T
     */
    private function storeAttachments(callable $work): mixed
    {
        $stored = [];

        try {
            return DB::transaction(function () use ($work, &$stored) {
                return $work($stored);
            });
        } catch (Throwable $e) {
            if ($stored !== []) {
                Storage::disk('local')->delete($stored);
            }

            throw $e;
        }
    }

    /** @param  array<int, string>  $stored  every path written so far, for storeAttachments() */
    private function storeAttachment(Ticket $ticket, ?TicketReply $reply, User $user, UploadedFile $file, array &$stored): TicketAttachment
    {
        $path = $file->store("tickets/{$ticket->id}", 'local');

        if (! is_string($path) || $path === '') {
            throw new RuntimeException("The attachment {$file->getClientOriginalName()} could not be stored.");
        }

        $stored[] = $path;

        return TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'reply_id' => $reply?->id,
            'user_id' => $user->id,
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            // The type detected from the bytes (the one the attachment rule checked), not the
            // browser's claim.
            'mime_type' => AllowedTicketAttachment::detectedType($file) ?? 'application/octet-stream',
            'size' => $file->getSize(),
        ]);
    }
}
