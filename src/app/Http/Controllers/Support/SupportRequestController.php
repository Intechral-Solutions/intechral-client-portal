<?php

namespace App\Http\Controllers\Support;

use App\Http\Controllers\Controller;
use App\Http\Controllers\TicketController;
use App\Http\Presenters\PartnerSupportPresenter;
use App\Http\Presenters\RequesterTicketPresenter;
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

    /**
     * Release 1.0 (R1-5): raise a request for a contact at a managed customer. The options are the customers this actor supports RIGHT NOW and their
     * eligible contacts (`PartnerSupportAccess::creationOptions`), for display; the server decides again, under locks, when the request arrives.
     */
    public function create(Request $request): Response
    {
        /** @var User $user */
        $user = $request->user();

        abort_unless(Gate::forUser($user)->allows('createAsPartnerSupport', Ticket::class), 404);

        return Inertia::render('support/create', [
            'customers' => PartnerSupportAccess::creationOptions((int) $user->getKey()),
            'categories' => TicketController::CATEGORIES,
            'priorities' => RequesterTicketPresenter::priorityOptions(),
            'defaultPriority' => 'medium',
            'attachmentPolicy' => RequesterTicketPresenter::attachmentPolicy(),
        ]);
    }

    /**
     * `organization_id` and `requester_id` are REQUESTS: integers, never `exists:` (that rule would tell the caller which organizations and accounts
     * exist). Whether the actor supports that organization, and whether that account is an eligible contact of it, is decided inside the creation
     * transaction. The submitter is the authenticated user and nothing in the payload; a `user_id`, `submitted_by_id`, status or assignee in the
     * payload is not validated, not read and not honoured.
     */
    public function store(Request $request, TicketService $service)
    {
        abort_unless(Gate::forUser($request->user())->allows('createAsPartnerSupport', Ticket::class), 404);

        $validated = $request->validate([
            ...TicketController::contentRules(),
            'organization_id' => ['required', 'integer', 'min:1'],
            'requester_id' => ['required', 'integer', 'min:1'],
        ]);

        $ticket = $service->createForCustomerContact(
            $request->user(),
            (int) $validated['organization_id'],
            (int) $validated['requester_id'],
            $validated,
            $request->file('attachments', []),
        );

        return redirect()->route('support.show', $ticket)
            ->with('status', "Request {$ticket->ticket_number} received.");
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
