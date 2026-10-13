<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
use App\Rules\AllowedTicketAttachment;
use App\Services\TicketService;
use Illuminate\Http\Request;

class TicketReplyController extends Controller
{
    public function store(Request $request, Ticket $ticket, TicketService $service)
    {
        // First, before validation, file handling, persistence or notification (EPIC-010D H1).
        $this->authorize('reply', $ticket);

        $validated = $request->validate([
            'body' => ['required', 'string'],
            'is_internal' => ['boolean'],
            ...AllowedTicketAttachment::rules(),
        ]);

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
        );

        // An Inertia reply is the requester's React page, which announces "Reply sent." and focuses the new
        // entry itself; the shared flash would announce the same reply a second time (and cannot announce
        // a repeat, because it dedupes identical text). A plain form post (the operator's Blade page) keeps it.
        if ($request->header('X-Inertia')) {
            return back();
        }

        return back()->with('status', 'Reply added.');
    }
}
