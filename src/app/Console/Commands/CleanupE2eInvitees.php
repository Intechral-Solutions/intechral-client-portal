<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\OrganizationAuthority;
use App\Support\InvitationIssueThrottle;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Removes the accounts, memberships and invitations a browser (E2E) run created through the organization-invitation journey (Release 1.0,
 * R1-3C; design record sections 13 and 20). The browser spec accepts real invitations, so without this every run would leave an account,
 * a membership and an invitation behind: nothing else deletes a user (no route, no UI), and `tickets.user_id` and the other user keys are
 * RESTRICT. It is the companion of {@see CleanupE2eTickets} and, like it, deliberately NOT a general tool:
 *
 *  - it runs only in `local` and `testing`: BOTH the runtime environment and the configured `app.env` must be one of them, checked before
 *    it reads or changes anything (the global `--env` option changes only the runtime value);
 *  - it takes no argument and no option that selects an account, an address, an organization or an id;
 *  - it is bound to THREE markers at once. An account is eligible only when (1) its address is exactly `e2e-invitee+<run>@example.test`
 *    (lower case, a reserved test domain), AND (2) it was created by an ACCEPTED organization invitation, AND (3) that invitation belongs to
 *    one of the suite's own organizations (the fixed slugs below, seeded by DevSeeder). A pending, expired or revoked invitation is eligible
 *    only on the same address pattern AND the same organizations;
 *  - anything that matches only part of that is AMBIGUOUS and is refused, never deleted: the run reports it and exits non-zero. Nothing is
 *    deleted "to be helpful";
 *  - it refuses an account (and deletes NOTHING of it) that holds any role other than the built-in `user`, any direct permission, any
 *    operator eligibility or membership-management authority, a membership of an organization that is not the suite's, or ANY row anywhere
 *    that references it other than the four kinds it removes. The referencing tables are discovered from the schema's foreign keys at run
 *    time, so a table added later is refused until someone decides about it; a ticket, a time entry, a task, a comment or a project it
 *    created therefore blocks the account (the ticket cleanup, which never matches an invitee, is not extended to cover it);
 *  - the activity log is audit and is never touched: its rows keep naming the removed account by id.
 *
 * Each account is removed in ONE transaction that follows the global lock order (the account's own row first, then the organizations
 * ascending, then the invitations ascending), re-checks every rule under those locks, and deletes in dependency order: the account's
 * accepted invitation rows, its memberships, password histories, linked social accounts, sessions and role assignment, then the user.
 * Pending addresses are removed the same way, one organization at a time. A failure leaves that account untouched and the run goes on to
 * the rest, then exits non-zero; "cannot look" is never read as "already gone". It is idempotent: a second run finds nothing and succeeds.
 *
 * One more thing, and only one: it clears the invitation-ISSUANCE counter of the SEEDED E2E operator ({@see E2E_OPERATOR_ADDRESS}) so that
 * repeated local runs stay under the production limit of 30 an hour without that limit being changed ({@see InvitationIssueThrottle}). It
 * runs inside the same environment guard, names exactly that one seeded account (never an argument), touches nothing but that account's
 * throttle key, and is idempotent. There is no route and no option for it.
 *
 * It must run once, after a whole browser suite and AFTER {@see CleanupE2eTickets}, never from a test or a worker.
 */
#[Signature('e2e:cleanup-invitees')]
#[Description('Delete the accounts, memberships and invitations an E2E run created (address e2e-invitee+…@example.test, accepted into an E2E organization)')]
class CleanupE2eInvitees extends Command
{
    /** The same allowlist as {@see CleanupE2eTickets} and DatabaseSeeder's call to DevSeeder. */
    private const ENVIRONMENTS = ['local', 'testing'];

    /** The suite's own organizations (DevSeeder). Only invitations into these are ever eligible. */
    public const ORGANIZATION_SLUGS = ['e2e-invitations-org'];

    /** The reserved test address of an invitee: lower case, one run token, a domain that can never be a real mailbox. */
    public const ADDRESS_PATTERN = '/\Ae2e-invitee\+[a-z0-9-]{1,64}@example\.test\z/';

    /** A cheap SQL pre-filter for the pattern; the regular expression above is what decides (the collation is case-insensitive). */
    public const ADDRESS_LIKE = 'e2e-invitee+%@example.test';

    /** The seeded operator persona the browser suite signs in as (DevSeeder); the only account whose throttle counter this command clears. */
    public const E2E_OPERATOR_ADDRESS = 'operator@intechral.test';

    /** The only role an eligible account may hold. */
    private const ROLE = 'user';

    /** What the command removes for an account. Every other referencing row refuses the account. */
    private const REMOVED_REFERENCES = [
        'organization_invitations.accepted_user_id',
        'organization_members.user_id',
        'password_histories.user_id',
        'social_accounts.user_id',
    ];

    public function handle(): int
    {
        $refusedEnvironment = $this->disallowedEnvironment();

        if ($refusedEnvironment !== null) {
            $this->error(sprintf(
                'Refusing to run in the "%s" environment: this command only runs in %s, and both the runtime and the configured environment must be one of them.',
                $refusedEnvironment,
                implode(' and ', self::ENVIRONMENTS),
            ));

            return self::FAILURE;
        }

        $this->clearOperatorThrottle();

        $organizations = $this->suiteOrganizations();
        $plan = $this->plan($organizations);

        $this->line(sprintf('Eligible E2E invitee accounts: %d', count($plan['accounts'])));
        $this->line(sprintf('Eligible E2E invitation addresses without an account: %d', array_sum(array_map('count', $plan['addresses']))));

        $refusals = $plan['refusals'];
        $removed = ['accounts' => 0, 'invitations' => 0, 'memberships' => 0];

        foreach ($plan['accounts'] as $userId => $address) {
            try {
                $outcome = $this->removeAccount($userId, $organizations);
            } catch (Throwable $e) {
                $refusals[] = sprintf('account #%d: not removed (%s)', $userId, $this->describe($e));

                continue;
            }

            if (is_string($outcome)) {
                $refusals[] = sprintf('account #%d: %s', $userId, $outcome);

                continue;
            }

            $removed['accounts'] += $outcome['accounts'];
            $removed['invitations'] += $outcome['invitations'];
            $removed['memberships'] += $outcome['memberships'];
        }

        foreach ($plan['addresses'] as $organizationId => $ids) {
            try {
                $removed['invitations'] += $this->removeInvitations($organizationId, $ids, $organizations);
            } catch (Throwable $e) {
                $refusals[] = sprintf('invitations %s: not removed (%s)', implode(', ', $ids), $this->describe($e));
            }
        }

        $this->line(sprintf('Accounts deleted: %d', $removed['accounts']));
        $this->line(sprintf('Memberships deleted: %d', $removed['memberships']));
        $this->line(sprintf('Invitations deleted: %d', $removed['invitations']));

        if ($refusals !== []) {
            $this->error(sprintf('%d target(s) were REFUSED or NOT removed and stay as they are:', count($refusals)));

            foreach ($refusals as $refusal) {
                $this->line('  '.$refusal);
            }

            $this->line('Nothing else was changed for them. They belong to a person or need a decision, not to this command; fix the cause and run it again.');

            return self::FAILURE;
        }

        $this->info('E2E invitee cleanup complete.');

        return self::SUCCESS;
    }

    /** Forget the seeded E2E operator's issuance counter (a cache key; nothing in the database changes). A missing account is not an error. */
    private function clearOperatorThrottle(): void
    {
        $id = DB::table('users')->where('email', self::E2E_OPERATOR_ADDRESS)->value('id');

        if ($id !== null) {
            InvitationIssueThrottle::clearFor((int) $id);
            $this->line('Invitation issuance counter cleared for the seeded E2E operator.');
        }
    }

    /**
     * The first environment value that is not permitted, or null when both are (the same rule as the ticket cleanup: a command-line
     * `--env` changes only the runtime value, so it never authorises a run by itself).
     */
    private function disallowedEnvironment(): ?string
    {
        foreach ([(string) $this->laravel->environment(), (string) config('app.env')] as $environment) {
            if (! in_array($environment, self::ENVIRONMENTS, true)) {
                return $environment;
            }
        }

        return null;
    }

    /** @return array<int, string> the suite's organizations as [id => slug], read straight from the table */
    private function suiteOrganizations(): array
    {
        return DB::table('organizations')->whereIn('slug', self::ORGANIZATION_SLUGS)->orderBy('id')->pluck('slug', 'id')
            ->mapWithKeys(fn ($slug, $id) => [(int) $id => (string) $slug])->all();
    }

    /**
     * What the run will try, and what it already knows it must refuse. Decisions here are provisional (made without locks); each removal
     * re-checks under lock.
     *
     * @param  array<int, string>  $organizations
     * @return array{accounts: array<int, string>, addresses: array<int, list<int>>, refusals: list<string>}
     */
    private function plan(array $organizations): array
    {
        $accounts = [];
        $addresses = [];
        $refusals = [];

        // Every account on the reserved address, whatever else it is.
        $candidates = DB::table('users')->where('email', 'like', self::ADDRESS_LIKE)->orderBy('id')->get(['id', 'email']);

        foreach ($candidates as $user) {
            if (preg_match(self::ADDRESS_PATTERN, (string) $user->email) !== 1) {
                continue;
            }

            $accepted = DB::table('organization_invitations')->where('accepted_user_id', $user->id)->get(['organization_id']);

            if ($accepted->count() === 1 && array_key_exists((int) $accepted->first()->organization_id, $organizations)) {
                $accounts[(int) $user->id] = (string) $user->email;
            } else {
                $refusals[] = sprintf('account #%d: it has the reserved address but was not created by exactly one accepted invitation into an E2E organization', $user->id);
            }
        }

        // An accepted invitation into a suite organization whose account does NOT have the reserved address is ambiguous too.
        $mismatched = DB::table('organization_invitations as i')->join('users as u', 'u.id', '=', 'i.accepted_user_id')
            ->whereIn('i.organization_id', array_keys($organizations))->where('i.email', 'like', self::ADDRESS_LIKE)
            ->get(['i.id', 'u.id as user_id', 'u.email']);

        foreach ($mismatched as $row) {
            if (! isset($accounts[(int) $row->user_id]) && preg_match(self::ADDRESS_PATTERN, (string) $row->email) !== 1) {
                $refusals[] = sprintf('invitation #%d: it was accepted by account #%d, whose address is not the reserved one', $row->id, $row->user_id);
            }
        }

        // Invitations on the reserved address that no account came from.
        $open = DB::table('organization_invitations')->whereNull('accepted_at')->where('email', 'like', self::ADDRESS_LIKE)->orderBy('id')->get(['id', 'organization_id', 'email']);

        foreach ($open as $invitation) {
            if (preg_match(self::ADDRESS_PATTERN, (string) $invitation->email) !== 1) {
                continue;
            }

            if (array_key_exists((int) $invitation->organization_id, $organizations)) {
                $addresses[(int) $invitation->organization_id][] = (int) $invitation->id;
            } else {
                $refusals[] = sprintf('invitation #%d: it has the reserved address but belongs to an organization that is not an E2E organization', $invitation->id);
            }
        }

        return ['accounts' => $accounts, 'addresses' => $addresses, 'refusals' => $refusals];
    }

    /**
     * Remove one account in one transaction, or return the reason it was refused (nothing changed). Throws when anything cannot be
     * established or done.
     *
     * @param  array<int, string>  $organizations
     * @return array{accounts: int, invitations: int, memberships: int}|string
     */
    private function removeAccount(int $userId, array $organizations): array|string
    {
        return DB::transaction(function () use ($userId, $organizations): array|string {
            // The global lock order: the account's own row first (exclusively: it is about to go), then the organizations ascending,
            // then the account's invitations ascending.
            $user = DB::table('users')->where('id', $userId)->lockForUpdate()->first();

            // A row already gone (a repeated run, or removed by someone else) is simply done.
            if ($user === null) {
                return ['accounts' => 0, 'invitations' => 0, 'memberships' => 0];
            }

            $memberOf = DB::table('organization_members')->where('user_id', $userId)->pluck('organization_id')->map(fn ($id) => (int) $id)->all();
            $lockIds = array_values(array_unique([...array_keys($organizations), ...$memberOf]));
            sort($lockIds);

            if ($lockIds !== []) {
                DB::table('organizations')->whereIn('id', $lockIds)->orderBy('id')->lockForUpdate()->pluck('id');
            }

            $invitations = DB::table('organization_invitations')->where('accepted_user_id', $userId)->orderBy('id')->lockForUpdate()->get(['id', 'organization_id']);

            // What was eligible when the run began may not be now; only the locked, current rows count.
            $refusal = $this->refusal($user, $invitations->pluck('organization_id')->map(fn ($id) => (int) $id)->all(), $memberOf, $organizations);

            if ($refusal !== null) {
                return $refusal;
            }

            $removed = ['accounts' => 0, 'invitations' => 0, 'memberships' => 0];
            $removed['invitations'] = DB::table('organization_invitations')->where('accepted_user_id', $userId)->delete();
            $removed['memberships'] = DB::table('organization_members')->where('user_id', $userId)->delete();
            DB::table('password_histories')->where('user_id', $userId)->delete();
            DB::table('social_accounts')->where('user_id', $userId)->delete();
            DB::table('sessions')->where('user_id', $userId)->delete();
            DB::table(config('permission.table_names.model_has_roles'))->where(config('permission.column_names.model_morph_key'), $userId)
                ->where('model_type', (new User)->getMorphClass())->delete();
            $removed['accounts'] = DB::table('users')->where('id', $userId)->delete();

            return $removed;
        });
    }

    /**
     * Why this account must NOT be removed, or null when it may. Run under the locks.
     *
     * @param  list<int>  $acceptedOrganizations  the organizations of the invitations that created it
     * @param  list<int>  $memberOf
     * @param  array<int, string>  $organizations
     */
    private function refusal(object $user, array $acceptedOrganizations, array $memberOf, array $organizations): ?string
    {
        if (preg_match(self::ADDRESS_PATTERN, (string) $user->email) !== 1) {
            return 'its address is not the reserved one';
        }

        if (count($acceptedOrganizations) !== 1 || ! array_key_exists($acceptedOrganizations[0], $organizations)) {
            return 'it was not created by exactly one accepted invitation into an E2E organization';
        }

        foreach ($memberOf as $organizationId) {
            if (! array_key_exists($organizationId, $organizations)) {
                return 'it is a member of an organization that is not an E2E organization';
            }
        }

        $morph = (new User)->getMorphClass();
        $roles = DB::table(config('permission.table_names.model_has_roles').' as mhr')
            ->join(config('permission.table_names.roles').' as r', 'r.id', '=', 'mhr.role_id')
            ->where('mhr.'.config('permission.column_names.model_morph_key'), $user->id)->where('mhr.model_type', $morph)
            ->pluck('r.name')->all();

        if ($roles !== [self::ROLE] && $roles !== []) {
            return 'it holds a role other than the built-in "'.self::ROLE.'" role';
        }

        if (DB::table(config('permission.table_names.model_has_permissions'))->where(config('permission.column_names.model_morph_key'), $user->id)->where('model_type', $morph)->exists()) {
            return 'it holds a permission directly';
        }

        if (OrganizationAuthority::isOperator((int) $user->id) || OrganizationAuthority::isMembershipManager((int) $user->id)) {
            return 'it has operator eligibility or membership-management authority';
        }

        foreach ($this->referencesTo((int) $user->id) as $reference => $count) {
            if (! in_array($reference, self::REMOVED_REFERENCES, true)) {
                return sprintf('%d row(s) of %s reference it (this command never deletes them)', $count, $reference);
            }
        }

        return null;
    }

    /**
     * Every row that references the account, as "table.column" => count, for every foreign key to `users.id` the schema has TODAY. Read
     * from information_schema, not from a list, so a table added later is counted (and so refuses the account) without anyone remembering
     * to update this command.
     *
     * @return array<string, int>
     */
    private function referencesTo(int $userId): array
    {
        $keys = DB::select(
            'select table_name as t, column_name as c from information_schema.key_column_usage
             where table_schema = database() and referenced_table_schema = database() and referenced_table_name = ? and referenced_column_name = ?',
            ['users', 'id']
        );

        $found = [];

        foreach ($keys as $key) {
            $count = DB::table($key->t)->where($key->c, $userId)->count();

            if ($count > 0) {
                $found[$key->t.'.'.$key->c] = $count;
            }
        }

        return $found;
    }

    /**
     * Remove pending, expired or revoked invitations of the reserved address in ONE organization (no account came from them).
     *
     * @param  list<int>  $ids
     * @param  array<int, string>  $organizations
     */
    private function removeInvitations(int $organizationId, array $ids, array $organizations): int
    {
        return DB::transaction(function () use ($organizationId, $ids, $organizations): int {
            if (! array_key_exists($organizationId, $organizations)) {
                return 0;
            }

            DB::table('organizations')->where('id', $organizationId)->lockForUpdate()->pluck('id');
            $rows = DB::table('organization_invitations')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'organization_id', 'email', 'accepted_at']);

            $deletable = $rows->filter(fn ($row) => (int) $row->organization_id === $organizationId
                && $row->accepted_at === null
                && preg_match(self::ADDRESS_PATTERN, (string) $row->email) === 1)->pluck('id')->all();

            return $deletable === [] ? 0 : DB::table('organization_invitations')->whereIn('id', $deletable)->delete();
        });
    }

    /** A failure described without SQL or bindings. */
    private function describe(Throwable $e): string
    {
        if ($e instanceof QueryException) {
            return 'database error, SQLSTATE '.($e->errorInfo[0] ?? 'unknown');
        }

        return $e::class;
    }
}
