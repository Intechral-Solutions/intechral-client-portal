<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupSet;
use App\Support\Backup\SetVerifier;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Verifies a backup set without a database (Release 0.9 WP-B1). Fails closed.
 *
 *  - A SEALED set (as written by backup:create): layout, permissions, COMPLETE, SHA256SUMS and every encrypted
 *    artifact. That is integrity only; the contents are not read.
 *  - An OPENED set (decrypted by scripts/backup/open-backup-set.sh): additionally the decrypted files against the
 *    sealed checksums, the dump (complete, one database, expected statements only) against the manifest, and the
 *    attachment archive against the dump's attachment rows, file by file.
 *
 * Neither proves the set restores; `backup:drill` does. Exit codes: 0 valid; 1 invalid.
 */
#[Signature('backup:verify {set : The set directory, sealed or opened} {--expect-digest= : The set digest recorded when the set was made}')]
#[Description('Verify a backup set (sealed: integrity; opened: contents) without restoring it')]
class BackupVerify extends Command
{
    public function handle(): int
    {
        $dir = rtrim((string) $this->argument('set'), '/');
        $digest = $this->option('expect-digest') !== null ? (string) $this->option('expect-digest') : null;

        if (is_file($dir.'/'.BackupSet::DATABASE.'.gpg')) {
            $violations = BackupSet::sealedViolations($dir, $digest);
            $kind = 'sealed';
        } else {
            $result = SetVerifier::verifyOpened($dir, $digest);
            $violations = $result['violations'];
            $kind = 'opened';
        }

        if ($violations !== []) {
            $this->components->error("The {$kind} set is NOT valid:");
            foreach ($violations as $violation) {
                $this->line("  - {$violation}");
            }

            return self::FAILURE;
        }

        if ($kind === 'sealed') {
            $this->components->info('The sealed set is complete and intact.'.($digest === null ? ' (No --expect-digest: not authenticated.)' : ''));
            $this->line('  Its contents were not read (they are encrypted). Open it on the owner\'s machine and run backup:verify and backup:drill.');
        } else {
            $this->components->info('The opened set is valid: complete, unaltered, and its dump, manifest and attachments agree.'.($digest === null ? ' (No --expect-digest: not authenticated.)' : ''));
            $this->line('  This does not prove it restores: run backup:drill.');
        }

        return self::SUCCESS;
    }
}
