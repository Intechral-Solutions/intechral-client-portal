<?php

namespace App\Http\Presenters;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Models\TicketReply;
use App\Rules\AllowedTicketAttachment;
use App\Services\TicketService;
use Closure;

/**
 * The requester's view of a ticket (EPIC-017 §15, §19, §27): the ONLY place a customer-facing
 * Helpdesk page gets its data. Every key is written out here, so what a requester can ever
 * receive is exactly this file, and a new field is a reviewed change (RequesterTicketPropsTest
 * pins the key sets).
 *
 * "Requester" is the page, not the actor. An operator who is the requester gets the same shape:
 * this presenter never reads who is viewing, so it cannot widen for anyone.
 *
 * Never serialized, for any viewer: internal replies and their attachments, SLA and overdue, time
 * entries or totals or a running timer, status-history actors, the assignee's id or email, the
 * requester's or submitter's id, `organization_id` or `submitted_by_id`, or assignee candidates. Dropping a key from the output is the
 * guarantee; hiding it in the page is not.
 *
 * R1-2B (owner decision E4): the ticket's ORGANIZATION NAME is sent, as `organization`, on a row and on the detail: the name of the
 * organization the request was raised for, to the requester whom TicketPolicy already lets read that ticket. Never an id, and never a
 * list of organizations (the create page gets only the actor's own memberships, from ServiceRequestAccess).
 */
final class RequesterTicketPresenter
{
    /** The customer vocabulary (EPIC-017 §13). The operator labels are a separate mapping (P4). */
    public const STATUS_LABELS = [
        'open' => 'Open',
        'in_progress' => 'In progress',
        'pending_user' => 'Waiting on you',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ];

    public const PRIORITY_LABELS = [
        'low' => 'Low',
        'medium' => 'Medium',
        'high' => 'High',
        'critical' => 'Critical',
    ];

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? ucfirst(str_replace('_', ' ', $status));
    }

    /** @return list<array{value: string, label: string}> */
    public static function statusOptions(): array
    {
        return self::options(self::STATUS_LABELS);
    }

    /** @return list<array{value: string, label: string}> */
    public static function priorityOptions(): array
    {
        return self::options(self::PRIORITY_LABELS);
    }

    /**
     * One list row. Category, assignee and SLA are deliberately not here (§19.1).
     *
     * @return array{number: string, title: string, organization: string, status: string, statusLabel: string, priority: string, createdAt: string, createdAtHuman: string, href: string}
     */
    public static function row(Ticket $ticket): array
    {
        return [
            'number' => $ticket->ticket_number,
            'title' => $ticket->title,
            // The list query selects the name as `organization_name` (one query for the page); a ticket loaded any other way has the relation.
            'organization' => $ticket->getAttribute('organization_name') ?? $ticket->organization?->name ?? '',
            'status' => $ticket->status,
            'statusLabel' => self::statusLabel($ticket->status),
            'priority' => $ticket->priority,
            'createdAt' => $ticket->created_at->toIso8601String(),
            'createdAtHuman' => $ticket->created_at->diffForHumans(),
            'href' => route('tickets.show', $ticket),
        ];
    }

    /**
     * The detail props. The ticket must have `user`, `assignee`, `attachments` (its own, reply-less
     * ones), `statusHistories`, and `replies` already restricted to PUBLIC replies with `user` and
     * `attachments`. The presenter filters again regardless: an internal reply handed to it is
     * dropped, and so is every attachment that does not belong to the ticket or to a public reply.
     *
     * `$attachmentUrl` (R1-4) decides where an attachment link points; it defaults to the requester's download route. The partner support
     * presenter passes its own, so the same public-thread shape can be served from the `support.*` routes without widening any requester route.
     *
     * @param  (Closure(TicketAttachment): string)|null  $attachmentUrl
     * @return array<string, mixed>
     */
    public static function detail(Ticket $ticket, ?Closure $attachmentUrl = null): array
    {
        $public = $ticket->replies->reject(fn (TicketReply $reply) => $reply->is_internal)->values();

        $conversation = [
            [
                'key' => 'description',
                'kind' => 'description',
                'author' => ['name' => $ticket->user?->name ?? 'Unknown'],
                'body' => $ticket->description,
                'createdAt' => $ticket->created_at->toIso8601String(),
                'createdAtHuman' => $ticket->created_at->diffForHumans(),
                'attachments' => self::attachments($ticket, $ticket->attachments->whereNull('reply_id'), $attachmentUrl),
            ],
            ...$public->map(fn (TicketReply $reply) => [
                'key' => "reply-{$reply->id}",
                'kind' => 'reply',
                'author' => ['name' => $reply->user?->name ?? 'Unknown'],
                'body' => $reply->body,
                'createdAt' => $reply->created_at->toIso8601String(),
                'createdAtHuman' => $reply->created_at->diffForHumans(),
                'attachments' => self::attachments($ticket, $reply->attachments->where('reply_id', $reply->id), $attachmentUrl),
            ])->all(),
        ];

        return [
            'ticket' => [
                'number' => $ticket->ticket_number,
                'title' => $ticket->title,
                'organization' => $ticket->organization?->name ?? '',
                'status' => $ticket->status,
                'statusLabel' => self::statusLabel($ticket->status),
                'priority' => $ticket->priority,
                'category' => $ticket->category,
                'createdAt' => $ticket->created_at->toIso8601String(),
                'createdAtHuman' => $ticket->created_at->diffForHumans(),
                // P6: the name only. Never the assignee's id or email.
                'handledBy' => $ticket->assignee?->name,
            ],
            'conversation' => $conversation,
            'history' => $ticket->statusHistories->map(fn ($history) => [
                'statusLabel' => self::statusLabel($history->new_status),
                'at' => $history->created_at->toIso8601String(),
                'atHuman' => $history->created_at->diffForHumans(),
            ])->values()->all(),
            'reply' => self::replyState($ticket),
            'attachmentPolicy' => self::attachmentPolicy(),
        ];
    }

    /**
     * Whether the page offers a composer (O2, §19.3). `closed` has none: the server still refuses a
     * direct POST (422), this only decides what the page shows.
     *
     * @return array{allowed: bool, notice: 'returns'|'reopens'|null, closedMessage: string|null}
     */
    public static function replyState(Ticket $ticket): array
    {
        $closed = $ticket->status === 'closed';

        return [
            'allowed' => ! $closed,
            'notice' => match ($ticket->status) {
                'pending_user' => 'returns',
                'resolved' => 'reopens',
                default => null,
            },
            'closedMessage' => $closed ? TicketService::CLOSED_REPLY_MESSAGE : null,
        ];
    }

    /** @return array{maxFiles: int, maxSizeMb: int, extensions: list<string>} */
    public static function attachmentPolicy(): array
    {
        return [
            'maxFiles' => AllowedTicketAttachment::MAX_FILES,
            'maxSizeMb' => intdiv(AllowedTicketAttachment::MAX_KILOBYTES, 1024),
            'extensions' => array_keys(AllowedTicketAttachment::ALLOWED),
        ];
    }

    /**
     * @param  iterable<TicketAttachment>  $attachments
     * @param  (Closure(TicketAttachment): string)|null  $attachmentUrl
     * @return list<array{id: int, filename: string, size: string, href: string}>
     */
    private static function attachments(Ticket $ticket, iterable $attachments, ?Closure $attachmentUrl = null): array
    {
        $rows = [];

        foreach ($attachments as $attachment) {
            $rows[] = [
                'id' => $attachment->id,
                'filename' => $attachment->filename,
                'size' => $attachment->formattedSize(),
                'href' => $attachmentUrl !== null ? $attachmentUrl($attachment) : route('tickets.attachment.download', [$ticket, $attachment]),
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, string>  $labels
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $labels): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys($labels), $labels);
    }
}
