<?php

namespace App\Services;

use App\Models\OrganizationInvitation;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * What the operator's Invitations card shows for one organization (Release 1.0, R1-3C; design record sections 11 and 20). A READ model:
 * it never writes (the only writer of `organization_invitations` is {@see OrganizationInvitations}), takes no lock and reads authority
 * from the tables ({@see OrganizationAuthority::membershipManagerIds()}), so it neither populates the permission cache nor depends on it.
 *
 * Nothing here can carry a credential: the plaintext token exists only in the queued mail, and `token_hash` is never selected.
 * The rows hold operator-only data (an invitee's address, the names of the inviter and of the person who accepted), so the card is built
 * only for an actor who may manage membership.
 *
 * STATE IS DERIVED, never stored ({@see OrganizationInvitation::state()}). One refinement is added for the operator and is PRESENTATION
 * ONLY, never a decision input: a row that is Pending by the clock but cannot be used (the address now belongs to an account, or the
 * issuer is no longer a membership manager through a path the revocation hooks do not cover) carries a `unusable` reason, so the operator
 * is not told a dead link is live (accepted risk R7 of the design record). Acceptance and the send-time check decide for themselves.
 */
final class OrganizationInvitationList
{
    /** The most recent rows shown; older history stays in the table. */
    public const LIMIT = 50;

    /**
     * What the operator is told for each revocation code: fixed labels for the closed set of C1, never free text. An unknown or missing
     * code is read as plainly Revoked (fail closed: `revoked_at` is the state, the code is descriptive).
     */
    public const REASON_LABELS = [
        OrganizationInvitations::REASON_OPERATOR => 'Revoked by an operator',
        OrganizationInvitations::REASON_SUPERSEDED => 'Replaced by a newer invitation',
        OrganizationInvitations::REASON_ISSUER_SUSPENDED => 'The inviting operator was suspended',
        OrganizationInvitations::REASON_ISSUER_AUTHORITY_LOST => 'The inviting operator lost the authority to invite',
        OrganizationInvitations::REASON_ISSUER_REINSTATED => 'The inviting operator\'s authority changed',
    ];

    public const UNUSABLE_ACCOUNT_EXISTS = 'The address already belongs to an account, so this link cannot be used.';

    public const UNUSABLE_ISSUER = 'The inviting operator can no longer invite, so this link cannot be used.';

    /**
     * @return array{rows: Collection<int, object>, total: int, truncated: bool}
     */
    public static function for(int $organizationId, ?CarbonInterface $now = null): array
    {
        $now ??= now();

        $invitations = OrganizationInvitation::query()
            ->join('users as inviter', 'inviter.id', '=', 'organization_invitations.invited_by')
            ->leftJoin('users as accepted', 'accepted.id', '=', 'organization_invitations.accepted_user_id')
            ->where('organization_invitations.organization_id', $organizationId)
            ->orderByDesc('organization_invitations.id')
            ->limit(self::LIMIT)
            ->get([
                'organization_invitations.id', 'organization_invitations.email', 'organization_invitations.invited_by',
                'organization_invitations.expires_at', 'organization_invitations.accepted_at', 'organization_invitations.accepted_user_id',
                'organization_invitations.revoked_at', 'organization_invitations.revoked_reason', 'organization_invitations.created_at',
                'inviter.name as inviter_name', 'accepted.name as accepted_name',
                // Decided by the database (collation), never by a PHP string comparison.
                DB::raw('exists (select 1 from users u where u.email = organization_invitations.email) as address_has_account'),
            ]);

        // Resend is offered on the NEWEST row of an address only: an older expired row must not supersede a newer open one.
        $latest = DB::table('organization_invitations')->where('organization_id', $organizationId)
            ->groupBy('email')->selectRaw('max(id) as id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        $pending = $invitations->filter(fn (OrganizationInvitation $row) => $row->isOpen($now));
        $managers = OrganizationAuthority::membershipManagerIds($pending->pluck('invited_by')->all());

        $rows = $invitations->map(function (OrganizationInvitation $row) use ($now, $latest, $managers): object {
            $state = $row->state($now);
            $unusable = null;

            if ($state === OrganizationInvitation::PENDING) {
                $unusable = match (true) {
                    (bool) $row->address_has_account => self::UNUSABLE_ACCOUNT_EXISTS,
                    ! in_array((int) $row->invited_by, $managers, true) => self::UNUSABLE_ISSUER,
                    default => null,
                };
            }

            return (object) [
                'id' => (int) $row->id,
                'email' => $row->email,
                'state' => $state,
                'reason' => $state === OrganizationInvitation::REVOKED ? (self::REASON_LABELS[$row->revoked_reason] ?? null) : null,
                'unusable' => $unusable,
                'invited_by_name' => $row->inviter_name,
                'sent_at' => $row->created_at,
                'expires_at' => $row->expires_at,
                'accepted_at' => $row->accepted_at,
                'accepted_user_id' => $row->accepted_user_id === null ? null : (int) $row->accepted_user_id,
                'accepted_name' => $row->accepted_name,
                'canRevoke' => $state === OrganizationInvitation::PENDING,
                'canResend' => in_array($state, [OrganizationInvitation::PENDING, OrganizationInvitation::EXPIRED], true) && in_array((int) $row->id, $latest, true),
            ];
        });

        $total = (int) DB::table('organization_invitations')->where('organization_id', $organizationId)->count();

        return ['rows' => $rows, 'total' => $total, 'truncated' => $total > $rows->count()];
    }
}
