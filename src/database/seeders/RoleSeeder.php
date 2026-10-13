<?php

namespace Database\Seeders;

use App\Services\BuiltInRoleSynchronizer;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Disposable environments only (Release 0.9, decision H4). It resets the built-in roles to the code's definitions,
 * revocations included, with no preview, which is right for a local or test database and wrong for any data that matters.
 * Anywhere else it refuses: use `php artisan roles:sync` to preview a plan and apply it with an explicit approval.
 */
class RoleSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new RuntimeException('RoleSeeder resets the built-in roles without a preview and is only for local and testing '
                .'databases. Run `php artisan roles:sync` to preview the change, then apply the plan you reviewed.');
        }

        $synchronizer = app(BuiltInRoleSynchronizer::class);
        $synchronizer->apply($synchronizer->plan()->fingerprint(), allowRevocations: true, audit: false);

        $this->command?->info('Built-in roles seeded: operator, user.');
    }
}
