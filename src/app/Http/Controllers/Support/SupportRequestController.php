<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Presenters\PartnerSupportPresenter;
use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\User;
use App\Queries\PartnerSupportQuery;
use App\Queries\RequesterTicketQuery;
use App\Rules\AllowedTicketAttachment;
use App\Services\PartnerSupportAccess;
use App\Services\TicketService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Partner support (Release 1.0, R1-4; docs/product/release-1.0-r1-4-partner-support.md): designated staff of a partner organization read and publicly
 * reply to the requests of the partner's managed customers. These routes are the ONLY place that authority is exercised, and they are deliberately a
 * separate, small surface: no status, assignment, bulk, report, export, queue, internal note, time or due-date action exists here.
 *
 * Every refusal is a 404, the same answer a request that does not exist gives, so nothing here confirms that a ticket exists or that an organization
 * is supported. Authority is {@see PartnerSupportAccess}, read from the tables on every request (no cache, no tenant scope); the reply re-decides it
 * under locks inside its transaction.
 */
class SupportRequestController extends Controller
{
    public function index(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(PartnerSupportAccess::holdsGrant($user), 404);

        $filters = RequesterTicketQuery::filters($request);
        $tickets = PartnerSupportQuery::paginate($user, $filters);

        return Inertia::render('support/index', [
            'tickets' => $tickets->through(fn (Ticket $ticket) => PartnerSupportPresenter::row($ticket)),
            'filters' => $filters,
            'statusOptions' => PartnerSupportPresenter::statusOptions(),
        ]);
    }

    public function show(Request $request, Ticket $ticket): Response
    {
        $this->authorizeSupport($request, $ticket);

        $ticket->load([
            'user:id,name',
            'assignee:id,name',
            'organization:id,name',
            'attachments',
            'statusHistories' => fn ($query) => $query->orderBy('id'),
            // Public replies only: an internal note is never loaded, let alone serialized.
            'replies' => fn ($query) => $query->where('is_internal', false)->orderBy('id')->with(['user:id,name', 'attachments']),
        ]);

        return Inertia::render('tickets/show', [
            ...PartnerSupportPresenter::detail($ticket),
            'abilities' => ['create' => false],
        ]);
    }

    public function reply(Request $request, Ticket $ticket, TicketService $service)
    {
        // First, before validation, file handling, persistence or notification. Nothing below the policy is reachable by someone without authority.
        $this->authorizeSupport($request, $ticket);

        // Only a body and attachments are read. A due date, an internal flag or a status in the payload is not validated, not read and not honoured.
        $validated = $request->validate([
            'body' => ['required', 'string'],
            ...AllowedTicketAttachment::rules(),
        ]);

        $service->addPartnerSupportReply($ticket, $request->user(), $validated['body'], $request->file('attachments', []));

        // An Inertia reply is the React page, which announces it itself (see the requester reply route).
        if ($request->header('X-Inertia')) {
            return back();
        }

        return back()->with('status', 'Reply added.');
    }

    public function downloadAttachment(Request $request, Ticket $ticket, string $attachment)
    {
        $this->authorizeSupport($request, $ticket);

        $attachment = TicketAttachment::where('ticket_id', $ticket->id)->findOrFail($attachment);

        abort_unless(Gate::forUser($request->user())->allows('downloadAttachmentAsPartnerSupport', [$ticket, $attachment]), 404);
        abort_unless(Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->filename);
    }

    private function authorizeSupport(Request $request, Ticket $ticket): void
    {
        abort_unless(Gate::forUser($request->user())->allows('viewAsPartnerSupport', $ticket), 404);
    }
}
