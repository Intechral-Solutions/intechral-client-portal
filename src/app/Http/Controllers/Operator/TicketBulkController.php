<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketService;
use App\Support\Helpdesk\TicketReference;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class TicketBulkController extends Controller
{
    public function update(Request $request, TicketService $service)
    {
        $validated = $request->validate([
            // C6-R: tickets are named by their public reference, never by the numeric key. A form built before a recovery could carry a
            // number that now belongs to a different ticket; a reference that is no longer there is refused instead of re-pointed.
            'ticket_refs' => ['required', 'array', 'min:1'],
            'ticket_refs.*' => ['string', 'regex:'.TicketReference::PATTERN],
            'action' => ['required', 'in:assign,close,resolve,status'],
            // A real assignee is required to assign: a blank placeholder is invalid input, never an
            // implicit bulk unassign (EPIC-010D D4). Single-ticket unassign is a separate route.
            'assignee_id' => ['required_if:action,assign', 'nullable', 'integer', 'exists:users,id'],
            'status' => ['required_if:action,status', 'nullable', 'in:open,in_progress,pending_user,resolved,closed'],
        ]);

        $actor = auth()->user();
        $action = $validated['action'];

        // Everything that can reject the request runs before the first mutation, and the batch
        // commits or rolls back as one.
        $count = DB::transaction(function () use ($validated, $action, $service, $actor) {
            if ($action === 'assign') {
                $service->assertAssignable((int) $validated['assignee_id']);
            }

            $references = array_values(array_unique($validated['ticket_refs']));
            $tickets = Ticket::whereIn('ticket_number', $references)->get();

            if ($tickets->count() !== count($references)) {
                throw ValidationException::withMessages(['ticket_refs' => 'One or more selected tickets no longer exist.']);
            }

            foreach ($tickets as $ticket) {
                match ($action) {
                    'assign' => $service->assign($ticket, (int) $validated['assignee_id'], $actor),
                    'close' => $this->safeTransition($service, $ticket, $actor, 'closed'),
                    'resolve' => $this->safeTransition($service, $ticket, $actor, 'resolved'),
                    'status' => $this->safeTransition($service, $ticket, $actor, $validated['status']),
                };
            }

            return $tickets->count();
        });

        return back()->with('status', "Bulk action applied to {$count} ticket(s).");
    }

    private function safeTransition(TicketService $service, Ticket $ticket, $actor, string $newStatus): void
    {
        if ($ticket->canTransitionTo($newStatus)) {
            $service->transition($ticket, $actor, $newStatus);
        }
    }
}
