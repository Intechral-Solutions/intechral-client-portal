<?php

namespace App\Queries;

use App\Models\Ticket;
use App\Models\User;
use App\Services\PartnerSupportAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * The partner support list (Release 1.0, R1-4): the Tickets of the customer organizations the actor supports RIGHT NOW, nothing else.
 *
 * The universe is a subselect of {@see PartnerSupportAccess::supportedOrganizations()}, the same rule the policy asks, evaluated in the same
 * query as the page, so a revocation takes effect on the next request and no organization id is ever taken from the request. Search covers
 * number, title, description and PUBLIC reply bodies only (internal notes never steer it); the filters are the requester list's, normalized
 * by {@see RequesterTicketQuery::filters()}. Rows carry the organization's and the requester's NAMES, selected in this one query, and no ids.
 */
final class PartnerSupportQuery
{
    public const PER_PAGE = 20;

    /** @param  array{search: string|null, status: string|null}  $filters */
    public static function paginate(User $actor, array $filters): LengthAwarePaginator
    {
        $supported = PartnerSupportAccess::supportedOrganizations((int) $actor->getKey())->select('c.id');

        return Ticket::query()
            ->whereIn('tickets.organization_id', $supported)
            ->addSelect([
                'organization_name' => DB::table('organizations')->select('name')->whereColumn('organizations.id', 'tickets.organization_id')->limit(1),
                'requester_name' => DB::table('users')->select('name')->whereColumn('users.id', 'tickets.user_id')->limit(1),
            ])
            ->when($filters['search'], fn ($query, string $term) => $query->search($term, false))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->appends(array_filter($filters, fn ($value) => $value !== null));
    }
}
