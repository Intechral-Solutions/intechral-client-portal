<?php

namespace App\Console\Commands;

use App\Support\Database\DatabaseProfile;
use App\Support\Runtime\RuntimeProbes;
use App\Support\Runtime\RuntimeProfile;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

#[Signature('runtime:preflight {--json : Print the report as JSON}')]
#[Description('Production runtime readiness for the shared-host profile: cached production configuration, database cache/session/queue, a delivering SMTP transport, the database profile, and a disposable cache and lock probe (Release 0.9, WP3-A). Run after `migrate`; fails closed')]
class RuntimePreflight extends Command
{
    public function handle(): int
    {
        $violations = RuntimeProfile::configuration();
        $probed = false;

        try {
            $db = DB::connection();
            $database = DatabaseProfile::safelyWithMigrations($db);
            $violations = [...$violations, ...$database];

            // The live probes need a reachable, migrated database; when it cannot even be verified they would only repeat that.
            if (! collect($database)->contains(fn (string $v) => str_starts_with($v, 'the database could not be verified'))) {
                $probed = true;
                $violations = [...$violations, ...RuntimeProbes::run($db)];
            }
        } catch (Throwable $e) {
            $violations[] = 'the database connection could not be opened: '.DatabaseProfile::describe($e);
        }

        $violations = array_values(array_unique($violations));
        $passed = $violations === [];
        $diagnostics = RuntimeProfile::diagnostics();

        if ($this->option('json')) {
            $this->line(json_encode(['passed' => $passed, 'violations' => $violations, 'probes_ran' => $probed, 'diagnostics' => $diagnostics], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $passed ? self::SUCCESS : self::FAILURE;
        }

        foreach ($violations as $violation) {
            $this->components->error($violation);
        }

        $this->newLine();
        foreach ($diagnostics as $name => $value) {
            $this->line(sprintf('  %-22s %s', $name, is_bool($value) ? ($value ? 'yes' : 'no') : (string) $value));
        }
        $this->newLine();

        if ($passed) {
            $this->components->info('The runtime profile is satisfied. The probes created and removed one cache row and one cache lock row; nothing else was written.');
        } elseif (! $probed) {
            $this->components->warn('The live cache and lock probes did not run.');
        }

        return $passed ? self::SUCCESS : self::FAILURE;
    }
}
