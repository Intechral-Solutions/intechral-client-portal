<?php

namespace App\Services;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Models\TicketStatusHistory;
use App\Models\User;
use App\Notifications\TicketCreatedNotification;
use App\Notifications\TicketRepliedNotification;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class TicketService
{
    public function create(User $user, array $data, array $files = []): Ticket
    {
        $priority = $data['priority'];
        $hours = Ticket::SLA_HOURS[$priority];

        // The database reserves the row identity first; the public number is derived from it
        // (EPIC-010D H8). Both writes commit together, so no temporary number is ever visible.
        $ticket = DB::transaction(function () use ($user, $data, $priority, $hours) {
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

            return $ticket;
        });

        foreach ($files as $file) {
            $this->storeAttachment($ticket, null, $user, $file);
        }

        $user->notify(new TicketCreatedNotification($ticket));

        return $ticket;
    }

    public function addReply(Ticket $ticket, User $user, string $body, bool $isInternal, array $files = []): TicketReply
    {
        $reply = $ticket->replies()->create([
            'user_id' => $user->id,
            'body' => $body,
            'is_internal' => $isInternal,
        ]);

        foreach ($files as $file) {
            $this->storeAttachment($ticket, $reply, $user, $file);
        }

        foreach ($this->replyRecipients($ticket, $user, $isInternal) as $recipient) {
            $recipient->notify(new TicketRepliedNotification($ticket, $reply));
        }

        return $reply;
    }

    public function transition(Ticket $ticket, User $actor, string $newStatus): void
    {
        if (! $ticket->canTransitionTo($newStatus)) {
            throw ValidationException::withMessages([
                'status' => "Cannot transition from \"{$ticket->status}\" to \"{$newStatus}\".",
            ]);
        }

        $old = $ticket->status;

        $ticket->status = $newStatus;

        if ($newStatus === 'resolved' && $ticket->resolved_at === null) {
            $ticket->resolved_at = now();
        }
        if ($newStatus === 'closed' && $ticket->closed_at === null) {
            $ticket->closed_at = now();
        }
        // Re-open clears resolved/closed timestamps
        if ($newStatus === 'open') {
            $ticket->resolved_at = null;
            $ticket->closed_at = null;
        }

        $ticket->save();

        TicketStatusHistory::create([
            'ticket_id' => $ticket->id,
            'user_id' => $actor->id,
            'old_status' => $old,
            'new_status' => $newStatus,
            'created_at' => now(),
        ]);
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

    public function autoCloseResolved(int $idleHours = 72): int
    {
        $cutoff = now()->subHours($idleHours);
        $tickets = Ticket::where('status', 'resolved')
            ->where('resolved_at', '<=', $cutoff)
            ->get();

        foreach ($tickets as $ticket) {
            $ticket->update(['status' => 'closed', 'closed_at' => now()]);

            TicketStatusHistory::create([
                'ticket_id' => $ticket->id,
                'user_id' => null,
                'old_status' => 'resolved',
                'new_status' => 'closed',
                'created_at' => now(),
            ]);
        }

        return $tickets->count();
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

    private function storeAttachment(Ticket $ticket, ?TicketReply $reply, User $user, UploadedFile $file): TicketAttachment
    {
        $path = $file->store("tickets/{$ticket->id}", 'local');

        return TicketAttachment::create([
            'ticket_id' => $ticket->id,
            'reply_id' => $reply?->id,
            'user_id' => $user->id,
            'filename' => $file->getClientOriginalName(),
            'path' => $path,
            'mime_type' => $file->getMimeType(),
            'size' => $file->getSize(),
        ]);
    }
}
