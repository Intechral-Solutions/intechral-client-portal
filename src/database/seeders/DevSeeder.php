<?php

namespace Database\Seeders;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Seeder;
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
        // The two customers hold the built-in `user` role and nothing else, so they resolve to the
        // Focused shell. `customerOther` is a second, unrelated customer: seeded now, used from the
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
        $operationalMember->givePermissionTo('projects.manage');

        // A fixed, idempotent fixture ticket (EPIC-011E WP7, §21): the task page's move to
        // React leaves the embedded Blade `x-time-tracker` with no remaining project/task host
        // to browser-test against, but tickets have no delete route and this seeder creates
        // none, so a per-run fixture would be undeletable (against the EPIC-011D hygiene
        // rules). `firstOrCreate` on a fixed ticket number keeps this call safe to run
        // repeatedly; the E2E suite only starts and stops timers against it and cleans those
        // up, never the ticket itself.
        Ticket::firstOrCreate(
            ['ticket_number' => 'TKT-E2E1'],
            [
                'user_id' => $operator->id,
                'title' => 'E2E fixture: embedded time tracker',
                'description' => 'Seeded fixture for the Blade-embedded time tracker browser test.',
                'category' => 'General',
                'priority' => 'low',
                'status' => 'open',
                'sla_due_at' => now()->addDays(30),
            ]
        );

        // A fixed, idempotent request that is waiting on `e2e-customer`, so the customer Home has a
        // deterministic "Waiting on you" row to render in the browser. Its title deliberately does not
        // start with `E2E-`: that prefix is reserved for tickets a run creates, which the Helpdesk
        // package's cleanup command removes. This one is owned by the seeder and never removed.
        Ticket::firstOrCreate(
            ['ticket_number' => 'TKT-E2E2'],
            [
                'user_id' => $customer->id,
                'title' => 'E2E fixture: waiting on the customer',
                'description' => 'Seeded fixture so the customer Home has a request waiting on its owner.',
                'category' => 'General',
                'priority' => 'low',
                'status' => 'pending_user',
                'sla_due_at' => now()->addDays(30),
            ]
        );

        $this->command->info('Dev accounts seeded:');
        $this->command->line('  operator@intechral.test / password  (operator)');
        $this->command->line('  user@intechral.test / password       (user)');
        $this->command->line('  e2e-login-flow@intechral.test / password       (user, E2E fixture)');
        $this->command->line('  e2e-profile-mutation@intechral.test / password (user, E2E fixture)');
        $this->command->line('  e2e-signout@intechral.test / password          (operator, E2E fixture)');
        $this->command->line('  e2e-customer@intechral.test / password         (user, Focused shell, E2E fixture)');
        $this->command->line('  e2e-customer-other@intechral.test / password   (user, Focused shell, E2E fixture)');
        $this->command->line('  e2e-operational-member@intechral.test / password (user + projects.manage, Operational shell, E2E fixture)');
    }
}
