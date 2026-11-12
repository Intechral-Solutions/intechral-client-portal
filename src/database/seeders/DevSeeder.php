<?php

namespace Database\Seeders;

use App\Console\Commands\CleanupE2eInvitees;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds development-only test accounts.
 * NEVER runs in production (guarded by DatabaseSeeder).
 */
class DevSeeder extends Seeder
{
    public function run(): void
    {
        // Platform operator account
        $operator = User::firstOrCreate(
            ['email' => 'operator@intechral.test'],
            [
                'name' => 'Dev Operator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $operator->assignRole('operator');

        // Platform user account
        $user = User::firstOrCreate(
            ['email' => 'user@intechral.test'],
            [
                'name' => 'Dev User',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $user->assignRole('user');
        // R1-1 test-fixture capability: the built-in `user` role no longer carries billing.view or time.log, so this persona is granted
        // billing.view and time.log explicitly, as an operator would grant a direct customer (the legacy specs drive invoices and the
        // member's own time through it). Specific to this seeded persona; `givePermissionTo` is idempotent.
        $user->givePermissionTo(['billing.view', 'time.log']);

        // Dedicated identities for browser flows whose *subject* is real authentication
        // (POST-WP4 E2E hardening). Reusing `operator@intechral.test` for these would spend that
        // account's own Fortify limiter bucket (5 logins/minute per email+IP) on top of the one
        // real login each Playwright worker already performs to mint its own session — with
        // enough spec files needing the operator persona, that collides with the limiter on its
        // own, before any auth-subject test runs at all. Each identity below is real (never
        // forged) and used by exactly one spec file, so its own login volume never scales with
        // worker count. See tests/Browser/support/auth.ts and docs/testing/e2e-browser-suite.md.
        $loginFlow = User::firstOrCreate(
            ['email' => 'e2e-login-flow@intechral.test'],
            [
                'name' => 'E2E Login Flow',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $loginFlow->assignRole('user');

        $profileMutation = User::firstOrCreate(
            ['email' => 'e2e-profile-mutation@intechral.test'],
            [
                'name' => 'E2E Profile Mutation',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $profileMutation->assignRole('user');

        // Full permissions, like the operator: the sign-out test's assertion is that no
        // administration item leaks into the personal account menu even for the most-permissioned
        // actor, which holds regardless of which fully-permissioned account is used.
        $signOut = User::firstOrCreate(
            ['email' => 'e2e-signout@intechral.test'],
            [
                'name' => 'E2E Sign Out',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $signOut->assignRole('operator');

        // EPIC-017 WP1 personas for the Focused customer shell (docs/testing/e2e-browser-suite.md).
        // All three are real accounts that sign in through the login form, and all are `firstOrCreate`
        // by email, so re-seeding is idempotent and cannot collide with any other identity.
        //
        // The two customers hold the built-in `user` role (`e2e-customer` also has the explicit billing.view and
        // time.log grants above; `customerOther` is a plain customer), so they resolve to the Focused shell. `customerOther` is a second, unrelated customer: seeded now, used from the
        // next package by the Helpdesk isolation journey (it must be unable to see `customer`'s data).
        $customer = User::firstOrCreate(
            ['email' => 'e2e-customer@intechral.test'],
            [
                'name' => 'E2E Customer',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $customer->assignRole('user');
        // R1-1 test-fixture capability: the built-in `user` role no longer carries billing.view or time.log, so this persona is granted
        // billing.view and time.log explicitly, as an operator would grant a direct customer. Specific to this seeded persona; `givePermissionTo` is idempotent.
        $customer->givePermissionTo(['billing.view', 'time.log']);

        $customerOther = User::firstOrCreate(
            ['email' => 'e2e-customer-other@intechral.test'],
            [
                'name' => 'E2E Customer Other',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $customerOther->assignRole('user');

        // A customer-scoped actor that nevertheless gets the OPERATIONAL shell: the `user` role plus a
        // direct `projects.manage`, one of the operator-surface capabilities that selects it. It
        // exists for the specs whose subject is the rail, drawer, breadcrumb or timer pill and tray,
        // which a plain customer no longer sees. Nothing else about the account is privileged.
        $operationalMember = User::firstOrCreate(
            ['email' => 'e2e-operational-member@intechral.test'],
            [
                'name' => 'E2E Operational Member',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $operationalMember->assignRole('user');
        // R1-1 test-fixture capability: billing.view and time.log are explicit grants (see `user@` above), as the specs that use the
        // timer pill and tray need time.log and the legacy invoice specs need billing.view.
        $operationalMember->givePermissionTo(['projects.manage', 'billing.view', 'time.log']);

        // R1-2B: the two customers the organization-selection journeys need. `customerMulti` belongs to TWO organizations (a request must
        // name one) and `customerNoOrg` to none (the create page explains instead of offering a form). Both are plain customers: the
        // `user` role and nothing else, so what they can do is exactly what an invited customer can do.
        $customerMulti = User::firstOrCreate(
            ['email' => 'e2e-customer-multi@intechral.test'],
            [
                'name' => 'E2E Customer Multi',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $customerMulti->assignRole('user');

        $customerNoOrg = User::firstOrCreate(
            ['email' => 'e2e-customer-no-org@intechral.test'],
            [
                'name' => 'E2E Customer No Org',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $customerNoOrg->assignRole('user');

        // R1-2: a Ticket belongs to an organization, and a person may raise one only as a CURRENT MEMBER of it (operators included).
        // Each persona that files requests (through the form in the browser specs, or as the owner of a fixture below) gets
        // its own fixed organization, so re-seeding is idempotent and the isolation journeys still have two unrelated customers.
        // Memberships are plain `member` rows: the role and approval grant nothing.
        $customerOrg = $this->organization('e2e-customer-org', 'E2E Customer Org', $customer, [$customer]);
        $this->organization('e2e-other-org', 'E2E Other Org', $customerOther, [$customerOther]);
        $operatorOrg = $this->organization('e2e-operator-org', 'E2E Operator Org', $operator, [$operator]);
        $this->organization('e2e-multi-org-a', 'E2E Multi Org A', $customerMulti, [$customerMulti]);
        $this->organization('e2e-multi-org-b', 'E2E Multi Org B', $customerMulti, [$customerMulti]);
        // $customerNoOrg is deliberately in none.
        // R1-3C: the organization the invitation journey invites people INTO (organization-invitations.spec.ts). It has NO members: every
        // person it ever holds is an `e2e-invitee+<run>@example.test` account the spec created by accepting a real invitation, and
        // `e2e:cleanup-invitees` removes exactly those, only from this organization (CleanupE2eInvitees::ORGANIZATION_SLUGS).
        $this->organization(CleanupE2eInvitees::ORGANIZATION_SLUGS[0], 'E2E Invitations Org', $operator, []);

        // A fixed, idempotent fixture ticket (EPIC-011E WP7, §21): the task page's move to
        // React leaves the embedded Blade `x-time-tracker` with no remaining project/task host
        // to browser-test against, but tickets have no delete route and this seeder creates
        // none, so a per-run fixture would be undeletable (against the EPIC-011D hygiene
        // rules). `firstOrCreate` on a fixed ticket number keeps this call safe to run
        // repeatedly; the E2E suite only starts and stops timers against it and cleans those
        // up, never the ticket itself.
        $this->fixtureTicket('TKT-E2E1', 'TK-E2E00-00001', [
            'user_id' => $operator->id,
            'organization_id' => $operatorOrg->id,
            'submitted_by_id' => $operator->id,
            'title' => 'E2E fixture: embedded time tracker',
            'description' => 'Seeded fixture for the Blade-embedded time tracker browser test.',
            'category' => 'General',
            'priority' => 'low',
            'status' => 'open',
            'sla_due_at' => now()->addDays(30),
        ]);

        // A fixed, idempotent request that is waiting on `e2e-customer`, so the customer Home has a
        // deterministic "Waiting on you" row to render in the browser. Its title deliberately does not
        // start with `E2E-`: that prefix is reserved for tickets a run creates, which the Helpdesk
        // package's cleanup command removes. This one is owned by the seeder and never removed.
        $this->fixtureTicket('TKT-E2E2', 'TK-E2E00-00002', [
            'user_id' => $customer->id,
            'organization_id' => $customerOrg->id,
            'submitted_by_id' => $customer->id,
            'title' => 'E2E fixture: waiting on the customer',
            'description' => 'Seeded fixture so the customer Home has a request waiting on its owner.',
            'category' => 'General',
            'priority' => 'low',
            'status' => 'pending_user',
            'sla_due_at' => now()->addDays(30),
        ]);

        $this->command->info('Dev accounts seeded:');
        $this->command->line('  operator@intechral.test / password  (operator)');
        $this->command->line('  user@intechral.test / password       (user)');
        $this->command->line('  e2e-login-flow@intechral.test / password       (user, E2E fixture)');
        $this->command->line('  e2e-profile-mutation@intechral.test / password (user, E2E fixture)');
        $this->command->line('  e2e-signout@intechral.test / password          (operator, E2E fixture)');
        $this->command->line('  e2e-customer@intechral.test / password         (user, Focused shell, E2E fixture)');
        $this->command->line('  e2e-customer-other@intechral.test / password   (user, Focused shell, E2E fixture)');
        $this->command->line('  e2e-operational-member@intechral.test / password (user + projects.manage, Operational shell, E2E fixture)');
        $this->command->line('  e2e-customer-multi@intechral.test / password   (user, two organizations, E2E fixture)');
        $this->command->line('  e2e-customer-no-org@intechral.test / password  (user, no organization, E2E fixture)');
    }

    /**
     * A fixed fixture ticket under its fixed canonical reference (C6-R). A development database seeded before C6-R already holds the
     * fixture under its legacy number; it is TAKEN OVER, not duplicated, by moving it to the fixed reference inside the one
     * conversion window the model allows. Otherwise it is created if missing, as before.
     *
     * @param  array<string, mixed>  $attributes
     */
    private function fixtureTicket(string $legacyNumber, string $reference, array $attributes): void
    {
        if (Ticket::where('ticket_number', $reference)->exists()) {
            return;
        }

        $legacy = Ticket::where('ticket_number', $legacyNumber)->first();

        if ($legacy !== null) {
            Ticket::convertingLegacyReferences(fn () => $legacy->update(['ticket_number' => $reference]));

            return;
        }

        // Fixture provenance is written explicitly: the two attribution columns are not mass-assignable.
        $ticket = new Ticket(['ticket_number' => $reference, ...Arr::except($attributes, ['organization_id', 'submitted_by_id'])]);
        $ticket->organization_id = $attributes['organization_id'];
        $ticket->submitted_by_id = $attributes['submitted_by_id'];
        $ticket->save();
    }

    /**
     * A fixed organization (by slug) whose members are exactly the given users, idempotently. Memberships are written straight to the
     * table, as plain members: this is fixture data, not an operator act, and the role and approval grant nothing.
     *
     * @param  list<User>  $members
     */
    private function organization(string $slug, string $name, User $owner, array $members): Organization
    {
        $organization = Organization::withoutGlobalScopes()->firstOrCreate(['slug' => $slug], ['name' => $name, 'owner_id' => $owner->id]);

        foreach ($members as $member) {
            DB::table('organization_members')->insertOrIgnore([
                'organization_id' => $organization->id,
                'user_id' => $member->id,
                'role' => 'member',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        return $organization;
    }
}
