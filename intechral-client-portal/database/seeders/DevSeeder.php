<?php

namespace Database\Seeders;

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
                'name'              => 'Dev Operator',
                'password'          => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $operator->assignRole('operator');

        // Platform user account
        $user = User::firstOrCreate(
            ['email' => 'user@intechral.test'],
            [
                'name'              => 'Dev User',
                'password'          => Hash::make('password'),
                'email_verified_at' => now(),
            ]
        );
        $user->assignRole('user');

        $this->command->info('Dev accounts seeded:');
        $this->command->line('  operator@intechral.test / password  (operator)');
        $this->command->line('  user@intechral.test / password       (user)');
    }
}
