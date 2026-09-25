<?php

namespace App\Http\Controllers\Operator;

use App\Http\Controllers\Controller;
use App\Models\Ticket;
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
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'],
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

        return back()->with('status', 'Reply added.');
    }
}
