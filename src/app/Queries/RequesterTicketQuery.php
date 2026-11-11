<?php

namespace App\Queries;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The requester's own-ticket list (EPIC-017 §12, §19.1): the `/tickets` universe a customer
 * sees, and an operator's own requests.
 *
 * - Universe: Ticket::forUser, the same set TicketPolicy::view grants a requester (EPIC-010D D1).
 *   Organization or company membership adds nothing.
 * - Search: number, title, description and PUBLIC reply bodies only. Internal-note text never
 *   affects membership, count or pagination, for any actor, an operator who is the requester
 *   included: this is the requester view (P2), never the operator universe.
 * - Malformed input never errors: the term is trimmed and capped, an unknown or non-string status
 *   is ignored, and the paginator carries only the normalized filters.
 * - Order: created_at DESC, then id DESC, so tied timestamps page deterministically.
 */
final class RequesterTicketQuery
{
    public const PER_PAGE = 20;

    public const SEARCH_LIMIT = 200;

    /** @return array{search: string|null, status: string|null} */
    public static function filters(Request $request): array
    {
        $search = $request->query('search');
        $search = is_string($search) ? trim(mb_substr(trim($search), 0, self::SEARCH_LIMIT)) : '';
        $status = $request->query('status');

        return [
            'search' => $search === '' ? null : $search,
            'status' => is_string($status) && in_array($status, Ticket::STATUSES, true) ? $status : null,
        ];
    }

    /** @param  array{search: string|null, status: string|null}  $filters */
    public static function paginate(User $user, array $filters): LengthAwarePaginator
    {
        // R1-2B: each row also carries its organization's NAME, read by a subselect in this same query. An eager load would add a query to
        // every page and break the pinned query budget (RequesterTicketPropsTest); the name is all the list needs.
        return Ticket::forUser($user)
            ->addSelect(['organization_name' => DB::table('organizations')->select('name')->whereColumn('organizations.id', 'tickets.organization_id')->limit(1)])
            ->when($filters['search'], fn ($query, string $term) => $query->search($term, false))
            ->when($filters['status'], fn ($query, string $status) => $query->where('status', $status))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE)
            ->appends(array_filter($filters, fn ($value) => $value !== null));
    }

    /**
     * Whether the requester has raised any ticket at all, filters aside: it separates the truly
     * empty list ("You haven't raised any requests yet.") from the filtered-empty one ("No
     * requests match these filters.").
     */
    public static function hasRequests(User $user): bool
    {
        return Ticket::forUser($user)->exists();
    }
}
