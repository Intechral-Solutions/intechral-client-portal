<?php

namespace App\Console\Commands;

use App\Support\Database\DatabaseProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('database:verify {--json : Print the report as JSON}')]
#[Description('Read-only check that the database matches the supported profile: InnoDB tables, UTC strict utf8mb4 session, collations and the expected foreign keys (Release 0.9, WP3). Run after `migrate`; writes nothing and fails closed')]
class VerifyDatabaseProfile extends Command
{
    public function handle(): int
    {
        /** @var Connection $db */
        $db = DB::connection();
        $pending = $this->pendingMigrations($db);

        // The foreign-key manifest describes the schema after EVERY migration. With migrations pending (or the migration
        // state unreadable) it cannot be compared, and an unverifiable check is a failure, never a pass.
        $violations = DatabaseProfile::safely($db, foreignKeys: $pending === 0);

        if ($pending !== 0) {
            $violations[] = $pending === null
                ? 'the migration state could not be read, so the foreign keys were not verified'
                : "{$pending} migration(s) are pending, so the foreign keys were not verified (run `php artisan migrate` first)";
        }

        $passed = $violations === [];

        if ($this->option('json')) {
            $this->line(json_encode(['passed' => $passed, 'violations' => $violations], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($violations as $violation) {
            $this->components->error($violation);
        }

        if ($passed) {
            $this->components->info('The database matches the supported profile. Nothing was written.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }

    /** The number of migrations that have not run, or null when that cannot be determined. */
    private function pendingMigrations(Connection $db): ?int
    {
        try {
            $migrator = app('migrator');
            $files = array_keys($migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')])));
            $ran = $db->getSchemaBuilder()->hasTable(config('database.migrations.table', 'migrations'))
                ? $db->table(config('database.migrations.table', 'migrations'))->pluck('migration')->all()
                : [];

            return count(array_diff($files, $ran));
        } catch (Throwable) {
            return null;
        }
    }
}
