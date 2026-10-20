<?php

namespace App\Console\Commands;

use App\Support\Database\DatabaseProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

#[Signature('database:verify {--json : Print the report as JSON}')]
#[Description('Read-only check that the database matches the supported profile: InnoDB tables, UTC strict utf8mb4 session, collations and the expected foreign keys (Release 0.9, WP3). Run after `migrate`; writes nothing and fails closed')]
class VerifyDatabaseProfile extends Command
{
    public function handle(): int
    {
        // The shared rule (pending migrations make the foreign keys unverifiable, which is a failure): DatabaseProfile::safelyWithMigrations().
        $violations = DatabaseProfile::safelyWithMigrations(DB::connection());

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
}
