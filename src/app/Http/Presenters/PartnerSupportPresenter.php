<?php

namespace App\Http\Presenters;

use App\Models\Ticket;
use App\Models\TicketAttachment;
use App\Services\TicketService;
use Illuminate\Support\Facades\Gate;

/**
 * What a partner support user is sent about a supported Ticket (Release 1.0, R1-4). It is the REQUESTER's public thread
 * ({@see RequesterTicketPresenter}, which never serializes internal notes, time, SLA, ids or status-history actors) with three differences, all
 * written out here: attachment links and the row link point at the `support.*` routes, the requester's NAME is added, and the reply area follows the
 * partner rule (no reopening, so a resolved or closed request has no composer). A new key is a reviewed change; PartnerSupportPropsTest pins the sets.
 */
final class PartnerSupportPresenter
{
    /**
     * The requester's vocabulary addresses the requester ("Waiting on you"); a partner reads it about someone else. Only that label differs.
     */
    public const STATUS_LABELS = [...RequesterTicketPresenter::STATUS_LABELS, 'pending_user' => 'Waiting on requester'];

    public static function statusLabel(string $status): string
    {
        return self::STATUS_LABELS[$status] ?? RequesterTicketPresenter::statusLabel($status);
    }

    /** @return list<array{value: string, label: string}> */
    public static function statusOptions(): array
    {
        return array_map(fn (string $value, string $label) => ['value' => $value, 'label' => $label], array_keys(self::STATUS_LABELS), self::STATUS_LABELS);
    }

    /**
     * @return array{number: string, title: string, organization: string, requester: string, status: string, statusLabel: string, priority: string, createdAt: string, createdAtHuman: string, href: string}
     */
    public static function row(Ticket $ticket): array
    {
        return [
            'number' => $ticket->ticket_number,
            'title' => $ticket->title,
            'organization' => (string) ($ticket->getAttribute('organization_name') ?? ''),
            'requester' => (string) ($ticket->getAttribute('requester_name') ?? ''),
            'status' => $ticket->status,
            'statusLabel' => self::statusLabel($ticket->status),
            'priority' => $ticket->priority,
            'createdAt' => $ticket->created_at->toIso8601String(),
            'createdAtHuman' => $ticket->created_at->diffForHumans(),
            'href' => route('support.show', $ticket),
        ];
    }

    /**
     * The ticket must be loaded as the requester page loads it (`user`, `assignee`, `organization`, `attachments`, `statusHistories`, and `replies`
     * restricted to public ones with `user` and `attachments`); the shared presenter filters again regardless.
     *
     * @return array<string, mixed>
     */
    public static function detail(Ticket $ticket): array
    {
        $detail = RequesterTicketPresenter::detail(
            $ticket,
            fn (TicketAttachment $attachment): string => route('support.attachment.download', [$ticket, $attachment]),
        );

        $detail['ticket']['statusLabel'] = self::statusLabel($ticket->status);
        $detail['history'] = $ticket->statusHistories->map(fn ($history) => [
            'statusLabel' => self::statusLabel($history->new_status),
            'at' => $history->created_at->toIso8601String(),
            'atHuman' => $history->created_at->diffForHumans(),
        ])->values()->all();
        $detail['ticket']['requester'] = $ticket->user?->name ?? 'Unknown';
        $detail['reply'] = self::replyState($ticket);
        $detail['backLink'] = ['href' => route('support.index'), 'label' => 'Supported requests'];
        $detail['replyUrl'] = route('support.replies.store', $ticket);

        return $detail;
    }

    /** @return array{allowed: bool, notice: null, closedMessage: string|null} */
    public static function replyState(Ticket $ticket): array
    {
        $allowed = Gate::allows('replyAsPartnerSupport', $ticket);

        return [
            'allowed' => $allowed,
            'notice' => null,
            'closedMessage' => $allowed ? null : TicketService::PARTNER_SUPPORT_CLOSED_MESSAGE,
        ];
    }
}
