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
use Illuminate\Validation\ValidationException;

class TicketService
{
    public function create(User $user, array $data, array $files = []): Ticket
    {
        $priority = $data['priority'];
        $hours = Ticket::SLA_HOURS[$priority];

        $ticket = Ticket::create([
            'ticket_number' => $this->nextTicketNumber(),
            'user_id' => $user->id,
            'title' => $data['title'],
            'description' => $data['description'],
            'category' => $data['category'],
            'priority' => $priority,
            'status' => 'open',
            'sla_due_at' => now()->addHours($hours),
        ]);

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

        // Notify the ticket owner on public replies (not internal notes)
        if (! $isInternal && $ticket->user_id !== $user->id) {
            $ticket->user->notify(new TicketRepliedNotification($ticket, $reply));
        }

        // Notify the assignee if they didn't write the reply
        if ($ticket->assignee_id && $ticket->assignee_id !== $user->id && ! $isInternal) {
            $ticket->assignee->notify(new TicketRepliedNotification($ticket, $reply));
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

    // ── Private helpers ──────────────────────────────────────

    private function nextTicketNumber(): string
    {
        $last = Ticket::max('id') ?? 0;

        return 'TKT-'.str_pad($last + 1, 4, '0', STR_PAD_LEFT);
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
