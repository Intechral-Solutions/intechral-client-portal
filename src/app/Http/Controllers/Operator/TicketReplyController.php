<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Rules\AllowedTicketAttachment;
use App\Services\TicketService;
use App\Support\Helpdesk\DueDateAdjustment;
use App\Support\Helpdesk\DueDateInput;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class TicketReplyController extends Controller
{
    public function store(Request $request, Ticket $ticket, TicketService $service)
    {
        // First, before validation, file handling, persistence or notification (EPIC-010D H1).
        $this->authorize('reply', $ticket);

        // Release 1.0: a due-date adjustment rides on this same form. Asking for one is authorized BEFORE validation, like the reply
        // itself, so a requester who sends the fields gets a 403 and nothing is read, validated or written. The reply route is shared
        // with requesters; being allowed to reply is not being allowed to move the deadline.
        $asksForDueDate = $request->input('due_action', 'keep') !== 'keep' || $request->filled('due_at');

        if ($asksForDueDate) {
            $this->authorize('adjustDueDate', $ticket);
        }

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'is_internal' => ['boolean'],
            'due_action' => ['nullable', Rule::in(['keep', 'set', 'restore'])],
            'due_at' => ['nullable', 'required_if:due_action,set', 'string'],
            ...AllowedTicketAttachment::rules(),
        ]);

        // A date typed while "keep" is selected would otherwise be dropped silently; make the operator choose.
        if (($validated['due_action'] ?? 'keep') !== 'set' && filled($validated['due_at'] ?? null)) {
            throw ValidationException::withMessages(['due_at' => 'Choose "Set a new due date" to use this date, or clear it.']);
        }

        $dueDate = $this->dueDateAdjustment($validated);

        $isInternal = (bool) ($validated['is_internal'] ?? false);

        // Actors without internal visibility may only post public replies
        if ($isInternal && $request->user()->cannot('viewInternal', $ticket)) {
            $isInternal = false;
        }

        $service->addReply(
            $ticket,
            auth()->user(),
            $validated['body'],
            $isInternal,
            $request->file('attachments', []),
            $dueDate,
        );

        // An Inertia reply is the requester's React page, which announces "Reply sent." and focuses the new
        // entry itself; the shared flash would announce the same reply a second time (and cannot announce
        // a repeat, because it dedupes identical text). A plain form post (the operator's Blade page) keeps it.
        if ($request->header('X-Inertia')) {
            return back();
        }

        return back()->with('status', $dueDate === null ? 'Reply added.' : 'Reply added and due date updated.');
    }

    /** @param  array<string, mixed>  $validated */
    private function dueDateAdjustment(array $validated): ?DueDateAdjustment
    {
        return match ($validated['due_action'] ?? 'keep') {
            'restore' => DueDateAdjustment::restore(),
            'set' => DueDateAdjustment::set(
                DueDateInput::parse($validated['due_at'] ?? null)
                    ?? throw ValidationException::withMessages(['due_at' => 'Enter the due date and time as a real date in '.DueDateInput::zoneLabel().'.'])
            ),
            default => null,
        };
    }
}
