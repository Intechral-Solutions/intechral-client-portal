<?php

namespace App\Services;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only report on the organization, company and membership data the managed-organization work (Release 1.0, MO-1A, and the
 * packages after it) will meet. It exists to be run against a copy or a rehearsal of real data BEFORE the migrations, and
 * again after. It never writes, repairs, merges or deletes anything, and it reads the tables directly: it must not populate
 * the permission cache, and no tenant scope may filter what it counts.
 *
 * `failures` are conditions that block the work or mean the data is already inconsistent (a duplicate company-to-organization
 * link stops the UNIQUE migration; a relationship that breaks a rule). `warnings` are facts a person must decide about
 * (existing organization admin rows, which MO-2 would turn from inert into authority; operator-held rows; users in several
 * organizations; tickets and their organization attribution).
 */
final class OrganizationPreflight
{
    /** @return array<string, mixed> */
    public function report(): array
    {
        $failures = [];
        $warnings = [];

        $duplicates = DB::table('crm_companies')
            ->select('organization_id', DB::raw('group_concat(id order by id) as company_ids'))
            ->whereNotNull('organization_id')->groupBy('organization_id')->havingRaw('count(*) > 1')
            ->orderBy('organization_id')->get()
            ->map(fn ($row) => ['organization_id' => (int) $row->organization_id, 'company_ids' => array_map('intval', explode(',', $row->company_ids))])->all();

        if ($duplicates !== []) {
            $failures[] = count($duplicates).' organization(s) are linked to more than one CRM company. The unique-link migration will refuse to run, and no relationship can involve them. Decide for each which company keeps the organization; nothing was changed.';
        }

        $withoutCompany = DB::table('organizations')
            ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('crm_companies')->whereColumn('crm_companies.organization_id', 'organizations.id'))
            ->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();

        if ($withoutCompany !== []) {
            $warnings[] = count($withoutCompany).' organization(s) have no CRM company. They cannot be designated as partners or linked as customers until they have exactly one.';
        }

        $companiesWithoutOrganization = DB::table('crm_companies')->whereNull('organization_id')->count();

        $operators = OrganizationAuthority::operatorIds();
        $admins = DB::table('organization_members')
            ->join('users', 'users.id', '=', 'organization_members.user_id')
            ->where('organization_members.role', 'admin')
            ->orderBy('organization_members.organization_id')->orderBy('users.id')
            ->get(['organization_members.organization_id', 'users.id as user_id', 'users.email', 'users.disabled_at'])
            ->map(fn ($row) => [
                'organization_id' => (int) $row->organization_id,
                'user_id' => (int) $row->user_id,
                'email' => $row->email,
                'active' => $row->disabled_at === null,
                'operator' => in_array((int) $row->user_id, $operators, true),
            ])->all();

        $operatorHeld = array_values(array_filter($admins, fn (array $admin) => $admin['operator']));

        if ($admins !== []) {
            $warnings[] = count($admins).' organization admin membership(s) exist ('.count($operatorHeld).' held by an operator). The role grants nothing today; it must be reviewed before anything starts to honour it.';
        }

        $multiOrganization = DB::table('organization_members')
            ->join('users', 'users.id', '=', 'organization_members.user_id')
            ->groupBy('users.id', 'users.email')->havingRaw('count(*) > 1')->orderBy('users.id')
            ->get(['users.id', 'users.email', DB::raw('count(*) as organizations')])
            ->map(fn ($row) => ['user_id' => (int) $row->id, 'email' => $row->email, 'organizations' => (int) $row->organizations])->all();

        if ($multiOrganization !== []) {
            $warnings[] = count($multiOrganization).' user(s) belong to more than one organization.';
        }

        $attributed = $this->ticketAttribution($warnings);

        $relationships = $this->relationships($failures);
        $adminApproval = $this->adminApproval($failures, $warnings, $operators);
        $invitations = $this->invitations($failures, $warnings);

        return [
            'duplicateLinks' => $duplicates,
            'organizationsWithoutCompany' => $withoutCompany,
            'companiesWithoutOrganization' => $companiesWithoutOrganization,
            'organizations' => DB::table('organizations')->count(),
            'companies' => DB::table('crm_companies')->count(),
            'adminMemberships' => $admins,
            'operatorAdminMemberships' => $operatorHeld,
            'multiOrganizationUsers' => $multiOrganization,
            'ticketAttribution' => $attributed,
            'relationships' => $relationships,
            'adminApproval' => $adminApproval,
            'invitations' => $invitations,
            'failures' => $failures,
            'warnings' => $warnings,
        ];
    }

    /**
     * Tickets and their organization (Release 1.0, R1-2). Reads whichever shape the database has, so it can be run before the attribution
     * migration (the legacy `company_id` link, which nothing ever wrote) and after it (the durable organization, and how many requesters
     * have since left it; information only, because a requester keeps access to their own tickets).
     *
     * @param  list<string>  $warnings
     * @return array<string, mixed>
     */
    private function ticketAttribution(array &$warnings): array
    {
        if (! Schema::hasColumn('tickets', 'organization_id')) {
            $companyLinked = Schema::hasColumn('tickets', 'company_id') ? DB::table('tickets')->whereNotNull('company_id')->count() : 0;
            $total = DB::table('tickets')->count();

            if ($total > 0) {
                $warnings[] = "{$total} ticket(s) exist and none can be attributed to an organization: the attribution migration will refuse until they are removed or the database is rebuilt.";
            }

            return ['attributed' => false, 'tickets' => $total, 'companyLinked' => $companyLinked];
        }

        $perOrganization = DB::table('tickets')->select('organization_id', DB::raw('count(*) as n'))->groupBy('organization_id')->orderBy('organization_id')
            ->get()->map(fn ($row) => ['organization_id' => (int) $row->organization_id, 'tickets' => (int) $row->n])->all();

        $requesterLeft = (int) DB::table('tickets')->whereNotExists(
            fn ($query) => $query->selectRaw('1')->from('organization_members')
                ->whereColumn('organization_members.organization_id', 'tickets.organization_id')->whereColumn('organization_members.user_id', 'tickets.user_id')
        )->count();

        if ($requesterLeft > 0) {
            $warnings[] = "{$requesterLeft} ticket(s) have a requester who is no longer a member of the ticket's organization. They keep access to their own tickets until suspended.";
        }

        return [
            'attributed' => true,
            'tickets' => (int) DB::table('tickets')->count(),
            'perOrganization' => $perOrganization,
            'requesterNoLongerMember' => $requesterLeft,
        ];
    }

    /**
     * The managed relationships, or null before the MO-1A migration. Every rule the service holds, checked against the data.
     *
     * @param  list<string>  $failures
     * @return array<string, mixed>|null
     */
    private function relationships(array &$failures): ?array
    {
        if (! Schema::hasColumn('organizations', 'managed_by_organization_id') || ! Schema::hasColumn('organizations', 'can_manage_customers')) {
            return null;
        }

        $violations = [
            'managedByNonPartner' => $this->ids('select o.id from organizations o join organizations m on m.id = o.managed_by_organization_id where m.can_manage_customers = 0'),
            'partnerThatIsManaged' => $this->ids('select id from organizations where can_manage_customers = 1 and managed_by_organization_id is not null'),
            'managesItself' => $this->ids('select id from organizations where managed_by_organization_id = id'),
            'withoutSingleCompany' => $this->ids(
                'select o.id from organizations o where (o.can_manage_customers = 1 or o.managed_by_organization_id is not null or exists (select 1 from organizations c where c.managed_by_organization_id = o.id))
                 and (select count(*) from crm_companies k where k.organization_id = o.id) <> 1'
            ),
        ];

        foreach ($violations as $rule => $ids) {
            if ($ids !== []) {
                $failures[] = "Managed-organization rule broken ({$rule}): organization(s) ".implode(', ', $ids).'.';
            }
        }

        return [
            'partners' => DB::table('organizations')->where('can_manage_customers', true)->count(),
            'managed' => DB::table('organizations')->whereNotNull('managed_by_organization_id')->count(),
            'violations' => $violations,
        ];
    }

    /**
     * The administrator-approval inventory (MO-2A1), or null before the migration. Ids only: no email appears here, so the section is
     * safe to keep as evidence. It changes nothing and approves nobody; approval is the operator's explicit, per-membership act.
     *
     * "Counted" is the definition in {@see OrganizationAuthority}: role admin, approved, active account, not an operator.
     *
     * @param  list<string>  $failures
     * @param  list<string>  $warnings
     * @param  list<int>  $operators
     * @return array<string, mixed>|null
     */
    private function adminApproval(array &$failures, array &$warnings, array $operators): ?array
    {
        if (! Schema::hasColumn('organization_members', 'admin_approved_at') || ! Schema::hasColumn('organization_members', 'admin_approved_by')) {
            return null;
        }

        $rows = DB::table('organization_members as m')
            ->join('users as u', 'u.id', '=', 'm.user_id')
            ->orderBy('m.organization_id')->orderBy('m.user_id')
            ->get(['m.organization_id as org', 'm.user_id as uid', 'm.role', 'm.updated_at as updated', 'm.admin_approved_at as at', 'm.admin_approved_by as by', 'u.disabled_at as disabled']);

        $pair = fn (object $row): array => ['organization_id' => (int) $row->org, 'user_id' => (int) $row->uid];
        $isOperator = fn (object $row): bool => in_array((int) $row->uid, $operators, true);
        $isAdmin = fn (object $row): bool => $row->role === 'admin';
        $record = fn (object $row): string => OrganizationAuthority::approvalRecord($row->at, $row->by);
        $approved = fn (object $row): bool => $record($row) === OrganizationAuthority::APPROVAL_COMPLETE;
        $hasApproval = fn (object $row): bool => $record($row) !== OrganizationAuthority::APPROVAL_NONE;
        $active = fn (object $row): bool => $row->disabled === null;

        $of = fn (callable $test): array => $rows->filter($test)->map($pair)->values()->all();

        $counted = $rows->filter(fn ($row) => $isAdmin($row) && $approved($row) && $active($row) && ! $isOperator($row));
        $organizationsWithCounted = $counted->pluck('org')->map(fn ($id) => (int) $id)->unique();

        $report = [
            'unapprovedAdministrators' => $of(fn ($row) => $isAdmin($row) && ! $approved($row)),
            'approvedActiveCustomerAdministrators' => $counted->map($pair)->values()->all(),
            'approvedSuspendedAdministrators' => $of(fn ($row) => $isAdmin($row) && $approved($row) && ! $active($row)),
            'operatorHeldAdministrators' => $of(fn ($row) => $isAdmin($row) && $isOperator($row)),
            'approvedButNowOperator' => $of(fn ($row) => $approved($row) && $isOperator($row)),
            'approvedButNotAdministrator' => $of(fn ($row) => $hasApproval($row) && ! $isAdmin($row)),
            'incompleteApprovals' => $of(fn ($row) => $record($row) === OrganizationAuthority::APPROVAL_INCOMPLETE),
            // DIAGNOSTIC, not proof. MO-2A1 stamps `admin_approved_at` and `updated_at` with one instant and every later membership
            // write either keeps the role or clears the approval, so a membership row changed AFTER its approval means the previous
            // release (which cannot write either column) touched it: for instance a demote then re-promote that revived the approval.
            // The reverse proves nothing: an untouched or deliberately restored row looks clean.
            'changedAfterApproval' => $of(fn ($row) => $row->at !== null && $row->updated !== null && Carbon::parse($row->updated)->gt(Carbon::parse($row->at))),
            'organizationsWithoutApprovedAdministrator' => DB::table('organizations')->orderBy('id')->pluck('id')
                ->map(fn ($id) => (int) $id)->reject(fn (int $id) => $organizationsWithCounted->contains($id))->values()->all(),
            'multiOrganizationAdministrators' => $rows->filter($isAdmin)->groupBy('uid')->filter(fn ($group) => $group->count() > 1)
                ->map(fn ($group, $uid) => ['user_id' => (int) $uid, 'organizations' => $group->count()])->values()->all(),
        ];

        if ($report['incompleteApprovals'] !== []) {
            $failures[] = count($report['incompleteApprovals']).' membership(s) have an approval time without an approver, or the reverse. They count as no approval. The application never writes one column without the other, so investigate how it happened, then revoke each one before relying on approvals.';
        }

        if ($report['approvedButNotAdministrator'] !== []) {
            $failures[] = count($report['approvedButNotAdministrator']).' membership(s) carry an approval record but are not administrators (older code can demote without clearing it, and a later promotion would then revive the approval). They do not count. Revoke each approval before delegation is ever enabled.';
        }

        if ($report['changedAfterApproval'] !== []) {
            $failures[] = count($report['changedAfterApproval']).' membership(s) were changed after they were approved (membership updated_at is later than admin_approved_at), which this release never does: older code probably touched them, and may have revived a cleared approval. Diagnostic evidence only, not proof either way (a clean result proves nothing). Revoke each approval and approve again only after review.';
        }

        if ($report['unapprovedAdministrators'] !== []) {
            $warnings[] = count($report['unapprovedAdministrators']).' administrator membership(s) are not approved and hold no delegated authority. Approve only the exact memberships the owner has reviewed; nothing approves them automatically.';
        }

        if ($report['approvedButNowOperator'] !== []) {
            $warnings[] = count($report['approvedButNowOperator']).' approved membership(s) belong to an account that is now an operator. They do not count; revoke the approval.';
        }

        if ($report['approvedSuspendedAdministrators'] !== []) {
            $warnings[] = count($report['approvedSuspendedAdministrators']).' approved administrator(s) are suspended. They do not count while suspended and count again, with their approval, on reactivation.';
        }

        return $report;
    }

    /**
     * Organization invitations (Release 1.0, R1-3), or null before the table exists. IDS AND COUNTS ONLY: no address, token or hash appears,
     * so the section is safe to keep as evidence (an invitee's address is operator-only data). It reads the tables directly, takes no
     * lock and never touches the permission cache; the authority questions use the table-based predicate, exactly as acceptance does.
     *
     * STATE IS DERIVED (accepted, then revoked, then expired by the clock, otherwise open). Failures are rows the service can never write
     * (an open row that holds no slot, a slot that is not the address, a terminal row that still holds the slot or two terminal states at
     * once, an acceptance without its account or the reverse, a revocation detail without a revocation). A revoked or expired row that
     * KEEPS its slot is legitimate (the system revocations and time never write it; issuance releases it, O4/R1) and is not reported.
     * Warnings are facts for a person: an open invitation that cannot be accepted (the address is now an account, or the issuer is no
     * longer a membership manager through a path the revocation hooks do not cover) and revocation codes this release does not know.
     *
     * @param  list<string>  $failures
     * @param  list<string>  $warnings
     * @return array<string, mixed>|null
     */
    private function invitations(array &$failures, array &$warnings): ?array
    {
        if (! Schema::hasTable('organization_invitations')) {
            return null;
        }

        $now = now();
        $rows = DB::table('organization_invitations as i')
            ->orderBy('i.id')
            ->get(['i.id', 'i.organization_id', 'i.invited_by', 'i.expires_at', 'i.accepted_at', 'i.accepted_user_id', 'i.revoked_at', 'i.revoked_by', 'i.revoked_reason', 'i.live_email', 'i.email']);

        $state = function (object $row) use ($now): string {
            return match (true) {
                $row->accepted_at !== null => 'accepted',
                $row->revoked_at !== null => 'revoked',
                Carbon::parse($row->expires_at)->lte($now) => 'expired',
                default => 'open',
            };
        };

        $byState = $rows->groupBy($state);
        $open = $byState->get('open', collect());
        $ids = fn ($collection): array => $collection->pluck('id')->map(fn ($id) => (int) $id)->values()->all();

        $managers = OrganizationAuthority::membershipManagerIds($open->pluck('invited_by')->all());
        $withAccount = $open->isEmpty() ? [] : DB::table('organization_invitations as i')
            ->whereIn('i.id', $ids($open))
            ->whereExists(fn ($query) => $query->selectRaw('1')->from('users')->whereColumn('users.email', 'i.email'))
            ->orderBy('i.id')->pluck('i.id')->map(fn ($id) => (int) $id)->all();

        $violations = [
            'openWithoutSlot' => $ids($open->filter(fn ($row) => $row->live_email === null)),
            'slotIsNotTheAddress' => $ids($rows->filter(fn ($row) => $row->live_email !== null && $row->live_email !== $row->email)),
            'acceptedStillHoldingSlot' => $ids($rows->filter(fn ($row) => $row->accepted_at !== null && $row->live_email !== null)),
            'acceptedAndRevoked' => $ids($rows->filter(fn ($row) => $row->accepted_at !== null && $row->revoked_at !== null)),
            'acceptanceHalfRecorded' => $ids($rows->filter(fn ($row) => ($row->accepted_at === null) !== ($row->accepted_user_id === null))),
            'revocationDetailWithoutRevocation' => $ids($rows->filter(fn ($row) => $row->revoked_at === null && ($row->revoked_reason !== null || $row->revoked_by !== null))),
        ];

        foreach ($violations as $rule => $found) {
            if ($found !== []) {
                $failures[] = 'Organization invitation rule broken ('.$rule.'): invitation(s) '.implode(', ', $found).'. The application never writes such a row; investigate how it happened. Nothing was changed.';
            }
        }

        $issuerNotManager = $ids($open->filter(fn ($row) => ! in_array((int) $row->invited_by, $managers, true)));

        if ($issuerNotManager !== []) {
            $warnings[] = count($issuerNotManager).' open invitation(s) were issued by an account that is no longer a membership manager: acceptance refuses them and the mail is not sent. Revoke them on the organization page.';
        }

        if ($withAccount !== []) {
            $warnings[] = count($withAccount).' open invitation(s) are for an address that already has an account: acceptance refuses them. Revoke them on the organization page.';
        }

        $unknownReason = $ids($rows->filter(fn ($row) => $row->revoked_at !== null && ! in_array($row->revoked_reason, OrganizationInvitations::REASONS, true)));

        if ($unknownReason !== []) {
            $warnings[] = count($unknownReason).' revoked invitation(s) carry no known revocation reason. They are read as revoked; the reason is descriptive only.';
        }

        return [
            'total' => $rows->count(),
            'open' => count($ids($open)),
            'accepted' => $byState->get('accepted', collect())->count(),
            'revoked' => $byState->get('revoked', collect())->count(),
            'expired' => $byState->get('expired', collect())->count(),
            'openIds' => $ids($open),
            'openPerOrganization' => $open->groupBy('organization_id')->map(fn ($group, $organization) => ['organization_id' => (int) $organization, 'open' => $group->count()])->values()->all(),
            'openIssuerNotMembershipManager' => $issuerNotManager,
            'openAddressAlreadyAnAccount' => $withAccount,
            'revokedWithUnknownReason' => $unknownReason,
            'violations' => $violations,
        ];
    }

    /** @return list<int> */
    private function ids(string $sql): array
    {
        return array_map(fn ($row) => (int) $row->id, DB::select($sql));
    }
}
