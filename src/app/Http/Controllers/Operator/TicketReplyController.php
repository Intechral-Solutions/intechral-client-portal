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
        $validated = $request->validate([
            'body' => ['required', 'string'],
            'is_internal' => ['boolean'],
            'attachments' => ['nullable', 'array', 'max:10'],
            'attachments.*' => ['file', 'max:20480'],
        ]);

        $isInternal = (bool) ($validated['is_internal'] ?? false);

        // Non-operators may only post public replies
        if ($isInternal && ! auth()->user()->can('tickets.assign')) {
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
