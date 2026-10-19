<?php

namespace App\Support\Database;

use Illuminate\Database\Events\MigrationsEvent;

/**
 * Production migration-time guard (R0.9 WP3): refuses to create or extend tables on a connection that does not match the
 * database profile, and reports tables that already violate it once the migration has run.
 *
 * It listens to the migrator's `MigrationsStarted` and `MigrationsEnded` for an `up` run, on the connection actually being
 * migrated, and only when `APP_ENV=production`. Development, CI and the disposable rehearsal databases are unaffected.
 * It checks the session and the engines and collations (invariants that hold for any prefix of the migrations); the
 * foreign-key manifest needs every migration to have run, so it belongs to `database:verify` after `migrate`, not here.
 * `--pretend` is covered too: nothing is written, but the answer is the same.
 */
final class MigrationProfileGuard
{
    public function handle(MigrationsEvent $event): void
    {
        if ($event->method !== 'up' || ! app()->environment('production')) {
            return;
        }

        $migrator = app('migrator');
        $connection = $migrator->resolveConnection($migrator->getConnection());

        $violations = [...DatabaseProfile::configuration(), ...DatabaseProfile::safely($connection, foreignKeys: false)];

        if ($violations !== []) {
            throw new DatabaseProfileViolation(array_values(array_unique($violations)));
        }
    }
}
