<?php

namespace App\Console\Commands;

use App\Support\Backup\BackupCreator;
use App\Support\Backup\BackupRefused;
use App\Support\Backup\BackupSet;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;
use Throwable;

/**
 * Creates one encrypted backup set of this installation: a consistent database snapshot and exactly the private
 * attachment files it references (Release 0.9 WP-B1). See docs/operations/backup-and-recovery.md.
 *
 * It only reads the database and the private disk. It writes only inside --to, a private directory outside the
 * application, storage and public directories. Nothing is uploaded anywhere and nothing is ever deleted except its
 * own working files and the partial sets of interrupted runs.
 *
 * Exit codes: 0 a complete, verified set was written and its digest printed; 1 the backup failed after it began
 * (nothing complete was left behind); 2 it refused to start (unsafe directory, another backup running, wrong key).
 */
#[Signature('backup:create {--to= : Private directory (mode 0700) the set is written under} {--recipient-key= : The owner\'s exported public key file (ASCII armor)} {--recipient-fingerprint= : The fingerprint that key must have}')]
#[Description('Create an encrypted backup set: a consistent database snapshot plus exactly the attachment files it references')]
class BackupCreate extends Command
{
    public function handle(): int
    {
        foreach (['to', 'recipient-key', 'recipient-fingerprint'] as $option) {
            if (trim((string) $this->option($option)) === '') {
                $this->components->error("--{$option} is required.");

                return 2;
            }
        }

        $interrupted = function (int $signal): void {
            throw new RuntimeException("Interrupted by signal {$signal}.");
        };
        if (extension_loaded('pcntl')) {
            $this->trap([SIGINT, SIGTERM, SIGHUP], $interrupted);
        }

        $creator = new BackupCreator(
            (array) config('database.connections.'.config('database.default')),
            (string) config('filesystems.disks.local.root'),
            app('encrypter'),
            (string) config('app.cipher'),
            (string) config('app.env'),
            $this->release(),
            fn (string $line) => $this->line("  · {$line}"),
        );

        try {
            $result = $creator->create((string) $this->option('to'), (string) $this->option('recipient-key'), (string) $this->option('recipient-fingerprint'));
        } catch (BackupRefused $e) {
            $this->components->error($e->getMessage());
            $this->line('  Refused before the backup started. No set was written.');

            return 2;
        } catch (Throwable $e) {
            $this->components->error('Backup FAILED: '.$e->getMessage());
            $this->line('  No complete set was written; the partial set was removed.');

            return self::FAILURE;
        }

        $this->newLine();
        $this->components->info("Backup set complete: {$result['backup_id']}");
        $this->line("  Directory: {$result['directory']}");
        $this->line("  Attachments: {$result['attachments']} files");
        $this->line("  Set digest (record it; it authenticates the set): {$result['digest']}");
        $this->line('  The set is sealed and encrypted. Whether it restores is proven only by a restore drill (backup:drill).');

        return self::SUCCESS;
    }

    /** @return array{id: ?string, commit: ?string} the release this code was built as, if it was (RELEASE file) */
    private function release(): array
    {
        $values = is_file(base_path('RELEASE')) ? BackupSet::keyValues((string) file_get_contents(base_path('RELEASE'))) : [];

        return ['id' => $values['release'] ?? null, 'commit' => $values['commit'] ?? null];
    }
}
