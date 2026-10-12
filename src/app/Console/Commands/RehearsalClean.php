<?php

namespace App\Console\Commands;

use App\Support\Rehearsal\MigrationSet;
use App\Support\Rehearsal\RehearsalRunner;
use App\Support\Rehearsal\UnsafeRehearsalTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Empties the two rehearsal databases and deletes the rehearsal files and backups. It acts only on
 * resources the rehearsal harness can prove it created (reserved database names carrying its marker, and
 * directories carrying its marker beneath storage/rehearsal), and refuses anything else untouched.
 */
#[Signature('rehearsal:clean')]
#[Description('Empty the rehearsal databases and delete rehearsal files and backups (rehearsal resources only)')]
class RehearsalClean extends Command
{
    public function handle(): int
    {
        try {
            $cleaned = (new RehearsalRunner(MigrationSet::repository()))->clean();
        } catch (UnsafeRehearsalTarget $e) {
            $this->components->error($e->getMessage());

            return 2;
        } catch (\Throwable $e) {
            // A cleanup that did not finish is reported, never swallowed: something may remain.
            $this->components->error('Cleanup did not complete: '.strtok($e->getMessage(), "\n"));

            return self::FAILURE;
        }

        $this->components->info('Cleaned: '.implode(', ', $cleaned));

        return self::SUCCESS;
    }
}
