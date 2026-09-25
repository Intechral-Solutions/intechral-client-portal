<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketBulkController extends Controller
{
    public function update(Request $request, TicketService $service)
    {
        $validated = $request->validate([
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
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

            $tickets = Ticket::whereIn('id', $validated['ticket_ids'])->get();

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
