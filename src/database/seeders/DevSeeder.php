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

        $this->command->info('Dev accounts seeded:');
        $this->command->line('  operator@intechral.test / password  (operator)');
        $this->command->line('  user@intechral.test / password       (user)');
        $this->command->line('  e2e-login-flow@intechral.test / password       (user, E2E fixture)');
        $this->command->line('  e2e-profile-mutation@intechral.test / password (user, E2E fixture)');
        $this->command->line('  e2e-signout@intechral.test / password          (operator, E2E fixture)');
    }
}
