<?php

namespace App\Console\Commands;

use App\Support\Backup\RestoreDrill;
use App\Support\Rehearsal\UnsafeRehearsalTarget;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Str;

/**
 * Restore drill: restores an OPENED backup set into the disposable rehearsal restore database and file root, and
 * proves the result (Release 0.9 WP-B1; {@see RestoreDrill}). Development machine only: the rehearsal target guard
 * refuses any environment but local and testing, and any database but the reserved rehearsal restore database.
 *
 * The escrowed APP_KEY is read from --app-key-file (or standard input with `-`), never from the command line.
 * Restored data is removed afterwards unless --keep is given.
 *
 * Exit codes: 0 the set restored and every check passed; 1 a check failed or the drill failed part-way (the
 * rehearsal restore target may be partly changed; `./dev rehearse:clean` empties it); 2 refused before anything
 * was changed.
 */
#[Signature('backup:drill {set : The opened set directory} {--app-key-file= : File holding the escrowed APP_KEY, or - for standard input} {--expect-digest= : The set digest recorded when the set was made} {--expect-legacy-references=0 : Exact number of non-canonical (pre-C6-R) ticket references the set is known to hold} {--keep : Keep the restored database and files for inspection} {--unauthenticated : Drill a set without its recorded digest (a format test only; it does not prove where the set came from)}')]
#[Description('Restore an opened backup set into the disposable rehearsal restore database and verify it')]
class BackupDrill extends Command
{
    public function handle(): int
    {
        $key = self::readKey((string) $this->option('app-key-file'), (string) config('app.cipher'));

        if (is_string($key)) {
            $this->components->error($key);

            return 2;
        }

        $digest = $this->option('expect-digest') !== null ? (string) $this->option('expect-digest') : null;
        if ($digest === null && ! $this->option('unauthenticated')) {
            $this->components->error('--expect-digest is required (the digest recorded when the set was made), or --unauthenticated to drill a set whose origin is not being proven.');

            return 2;
        }
        if ($digest !== null && ! preg_match('/\A[0-9a-f]{64}\z/', trim($digest))) {
            $this->components->error('--expect-digest must be 64 lowercase hexadecimal characters.');

            return 2;
        }

        $legacy = (string) $this->option('expect-legacy-references');
        if (! ctype_digit($legacy)) {
            $this->components->error('--expect-legacy-references must be a whole number.');

            return 2;
        }

        $drill = new RestoreDrill(fn (string $line) => $this->line("  · {$line}"));

        try {
            $report = $drill->run(
                (string) $this->argument('set'),
                $key,
                $digest,
                (int) $legacy,
                (bool) $this->option('keep'),
            );
        } catch (UnsafeRehearsalTarget $e) {
            $this->components->error($e->getMessage());
            $this->line('  Refused before the drill started. Nothing was changed.');

            return 2;
        }

        return BackupRestore::report($this, $report, $report['cleaned'] ? 'The restored data was removed.' : 'The restored data was KEPT in the rehearsal restore target; ./dev rehearse:clean removes it.');
    }

    /** The key in $file (an `APP_KEY=` line, `base64:…`, or the raw key), as an Encrypter, or why not. */
    public static function readKey(string $file, string $cipher): Encrypter|string
    {
        if ($file === '') {
            return '--app-key-file is required: the escrowed APP_KEY is part of what a drill proves.';
        }

        $text = $file === '-' ? (string) stream_get_contents(STDIN) : (string) @file_get_contents($file);
        $value = trim((string) preg_replace('/\AAPP_KEY=/', '', trim($text)), "\"' \n");
        $key = Str::startsWith($value, 'base64:') ? (string) base64_decode(substr($value, 7), true) : $value;

        if (! Encrypter::supported($key, $cipher)) {
            return 'The APP_KEY file does not hold a valid key for '.$cipher.'.';
        }

        return new Encrypter($key, $cipher);
    }
}
