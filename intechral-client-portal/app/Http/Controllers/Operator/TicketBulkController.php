<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Services\TicketService;
use Illuminate\Http\Request;

class TicketBulkController extends Controller
{
    public function update(Request $request, TicketService $service)
    {
        $validated = $request->validate([
            'ticket_ids' => ['required', 'array', 'min:1'],
            'ticket_ids.*' => ['integer', 'exists:tickets,id'],
            'action' => ['required', 'in:assign,close,resolve,status'],
            'assignee_id' => ['nullable', 'exists:users,id'],
            'status' => ['nullable', 'in:open,in_progress,pending_user,resolved,closed'],
        ]);

        $tickets = Ticket::whereIn('id', $validated['ticket_ids'])->get();
        $actor = auth()->user();

        foreach ($tickets as $ticket) {
            match ($validated['action']) {
                'assign' => $service->assign($ticket, $validated['assignee_id'] ?: null, $actor),
                'close' => $this->safeTransition($service, $ticket, $actor, 'closed'),
                'resolve' => $this->safeTransition($service, $ticket, $actor, 'resolved'),
                'status' => $this->safeTransition($service, $ticket, $actor, $validated['status']),
            };
        }

        return back()->with('status', "Bulk action applied to {$tickets->count()} ticket(s).");
    }

    private function safeTransition(TicketService $service, Ticket $ticket, $actor, string $newStatus): void
    {
        if ($ticket->canTransitionTo($newStatus)) {
            $service->transition($ticket, $actor, $newStatus);
        }
    }
}
