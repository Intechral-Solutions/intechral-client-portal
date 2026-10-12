<?php

namespace App\Console\Commands;

use App\Support\Rehearsal\MigrationSet;
use App\Support\Rehearsal\RehearsalInterrupted;
use App\Support\Rehearsal\RehearsalRunner;
use App\Support\Rehearsal\UnsafeRehearsalTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Empties the two rehearsal databases and deletes the rehearsal files and backups. It acts only on
 * resources the rehearsal harness can prove it created (reserved database names carrying its marker, and
 * directories carrying its marker beneath storage/rehearsal). Every target is checked before the first one is
 * touched, so a refusal (exit 2) changes nothing; a failure after cleaning began exits 1 and says what was done.
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
            // Raised only before the first change: a refusal.
            $this->components->error($e->getMessage());
            $this->line('  Refused before cleaning started. Nothing was changed.');

            return 2;
        } catch (RehearsalInterrupted $e) {
            // A cleanup that stopped part-way is reported with what was already done, never swallowed.
            $this->components->error($e->getMessage());

            return self::FAILURE;
        } catch (\Throwable $e) {
            $this->components->error('Cleanup did not complete: '.strtok($e->getMessage(), "\n").' Part of it may have run; run rehearsal:clean again.');

            return self::FAILURE;
        }

        $this->components->info('Cleaned: '.implode(', ', $cleaned));

        return self::SUCCESS;
    }
}
