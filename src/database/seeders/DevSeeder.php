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
    }
}
