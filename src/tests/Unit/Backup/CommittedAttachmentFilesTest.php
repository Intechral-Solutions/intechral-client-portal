<?php

use Illuminate\Support\Facades\Artisan;

/*
 * WP-B1 / Release 0.9 §7.1.4: the backup copies the files a database snapshot references AFTER the snapshot, which is
 * sufficient only while a committed attachment file is never deleted or overwritten. This guards that precondition
 * in the code, so a change that breaks it fails here instead of silently weakening every backup:
 *
 *  - the only code that changes the private disk is TicketService (it stores new files, and deletes ONLY the files
 *    the failing request itself wrote, before their rows ever committed: EPIC-010D F-7) and `e2e:cleanup-tickets`;
 *  - `e2e:cleanup-tickets` refuses outside local and testing, so it cannot run on staging or production.
 *
 * The backup also DETECTS a violation (BackupCreationTest: a missing, resized, rewritten or replaced file fails it).
 */

/** Every call in app/ that writes, moves or deletes through a filesystem, by file. */
function privateDiskMutations(): array
{
    $patterns = '/(->(delete|deleteDirectory|deleteDirectories|move|copy|put|putFile|putFileAs|append|prepend|writeStream|store|storeAs|storePublicly|storePubliclyAs|makeDirectory)\s*\()|\b(unlink|rename|rmdir|file_put_contents|fopen|copy|move_uploaded_file)\s*\(/';
    $found = [];

    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $relative = substr($file->getPathname(), strlen(base_path()) + 1);
        // The backup and rehearsal tools write only their own private directories, never the application's disk.
        if (! str_ends_with($relative, '.php') || str_starts_with($relative, 'app/Support/Backup/') || str_starts_with($relative, 'app/Support/Rehearsal/')) {
            continue;
        }
        $source = (string) file_get_contents($file->getPathname());
        if (! preg_match('/Storage::|Illuminate\\\\Support\\\\Facades\\\\Storage|storage_path\(|UploadedFile/', $source)) {
            continue;
        }
        preg_match_all($patterns, $source, $m);
        $calls = array_values(array_unique(array_map(fn ($c) => preg_replace('/\s*\($/', '', ltrim($c, '->')), $m[0])));
        sort($calls);
        if ($calls !== []) {
            $found[$relative] = $calls;
        }
    }

    ksort($found);

    return $found;
}

it('changes the private disk only where the precondition has been reviewed', function () {
    $mutations = privateDiskMutations();

    // A new entry here must be reviewed against the backup precondition: it may ADD new files, but must never delete
    // or overwrite a file whose attachment row has committed. Update docs/operations/backup-and-recovery.md with it.
    expect($mutations)->toBe([
        // deleteDirectory for tickets/{id}, and the ticket row's delete; local and testing only (below).
        'app/Console/Commands/CleanupE2eTickets.php' => ['delete', 'deleteDirectory'],
        'app/Services/TicketService.php' => ['delete', 'store'],
    ]);
});

it('deletes files in TicketService only on the failing request\'s own rollback path', function () {
    $source = (string) file_get_contents(app_path('Services/TicketService.php'));

    expect(substr_count($source, "Storage::disk('local')->delete("))->toBe(1)
        ->and($source)->toMatch('/catch \(Throwable \$e\) \{\s*if \(\$stored !== \[\]\) \{\s*Storage::disk\(\'local\'\)->delete\(\$stored\);/');
});

it('has no route that deletes an attachment or a ticket', function () {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->filter(fn ($r) => in_array('DELETE', $r->methods(), true) && preg_match('/ticket|attachment/i', $r->uri()))
        ->map(fn ($r) => $r->uri())->values()->all();

    expect($routes)->toBe([]);
});

it('refuses the e2e cleanup on a staging or production installation, before touching anything', function (string $environment) {
    config(['app.env' => $environment]);

    expect(Artisan::call('e2e:cleanup-tickets'))->toBe(1)
        ->and(Artisan::output())->toContain("Refusing to run in the \"{$environment}\" environment");
})->with(['staging', 'production']);
