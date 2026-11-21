<?php

require_once dirname(__DIR__, 2).'/Support/source_scan.php';

/*
 * Release 1.0 (ORG-2; ORG-0 section 12, owner ruling 5): ONE production path deletes an Organization row, and it is App\Services\OrganizationDirectory::delete(), which
 * authorizes it twice (the Gate, then the tables under the actor's lock), refuses anything that was ever enabled, and refuses while a project link, member,
 * invitation, request or relationship refers to it. There is no database trigger and no enablement-history table, so THIS is what keeps a second, unguarded
 * deletion path from appearing: a `delete()` of a model, a query-builder delete, a raw `delete from`, a `truncate`, a `destroy` or a `forceDelete`.
 *
 * WHAT IT SCANS: every PHP file under app/, comments removed (prose never counts), for the forms above aimed at the table by name, at the model by class,
 * or at a variable named like an Organization. WHAT IT CANNOT SEE, stated so nobody relies on it as proof: a delete that reaches the table through a
 * variable table name or an alias of the model it cannot recognize; a foreign-key CASCADE (there is none on an Organization: every reference is RESTRICT or SET
 * NULL, which tests/Feature/Database/PreservationSchemaTest.php pins); and anything outside app/ (a migration or a test: not application paths). The database's
 * RESTRICT keys are the backstop for what slips through.
 */

/** @return list<string> the patterns that mean "this deletes an Organization row" */
function orgDeletionPatterns(): array
{
    return [
        'raw delete' => '/\bdelete\s+from\s+`?organizations`?\b/i',
        'truncate' => '/\btruncate\s+(?:table\s+)?`?organizations`?\b/i',
        'table delete' => '/table\(\s*[\'"]organizations(?: as \w+)?[\'"]\s*\)[^;]*?->(?:delete|forceDelete|truncate)\(/s',
        'model static delete' => '/\bOrganization::(?:destroy|truncate)\(|\bOrganization::[^;]*?->(?:delete|forceDelete)\(/s',
        'model instance delete' => '/\$(?:organization|org|customer|partner|tenant)\w*->(?:delete|forceDelete)\(/',
        'relation delete' => '/->organizations?\(\)[^;]*?->(?:delete|forceDelete)\(/s',
    ];
}

/** @return array<string, list<string>> file relative to app/ => the pattern names it matches */
function orgDeletionFound(): array
{
    $found = [];
    $root = app_path();

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = guardStrip(file_get_contents($file->getPathname()));
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));

        foreach (orgDeletionPatterns() as $name => $pattern) {
            if (preg_match($pattern, $code) === 1) {
                $found[$relative][] = $name;
            }
        }
    }

    ksort($found);

    return $found;
}

it('deletes an Organization row in exactly one place: OrganizationDirectory::delete()', function () {
    $found = orgDeletionFound();

    expect(array_keys($found))->toBe(['Services/OrganizationDirectory.php']);

    $directory = file_get_contents(app_path('Services/OrganizationDirectory.php'));
    $methods = guardMethods($directory);
    $deleters = array_keys(array_filter($methods, fn (string $body) => orgDeletionMatches($body) !== []));

    expect($deleters)->toBe(['delete']);
});

it('has no other reader of the forms it scans for: a new deletion path fails here', function () {
    // The patterns are real: each one catches the form it names (so a pass above is not a scan that sees nothing).
    $samples = [
        'raw delete' => 'DB::statement("delete from organizations where id = 1");',
        'truncate' => 'DB::statement("truncate table `organizations`");',
        'table delete' => 'DB::table(\'organizations\')->where(\'id\', 1)->delete();',
        'model static delete' => 'Organization::whereKey(1)->delete();',
        'model instance delete' => '$organization->delete();',
        'relation delete' => '$user->organizations()->delete();',
    ];

    foreach ($samples as $name => $code) {
        expect(orgDeletionMatches($code))->toContain($name);
    }

    // And ordinary reads, writes and unrelated deletes are not matched.
    foreach ([
        'DB::table(\'organizations\')->where(\'id\', 1)->update([\'name\' => \'x\']);',
        '$organization->update([\'name\' => \'x\']);',
        'DB::table(\'organization_members\')->where(\'organization_id\', 1)->delete();',
        '$invitation->delete();',
        'Organization::query()->count();',
    ] as $code) {
        expect(orgDeletionMatches($code))->toBe([]);
    }
});

it('authorizes the deletion twice and refuses anything that ever had portal access, in the one service that performs it', function () {
    $body = guardMethods(file_get_contents(app_path('Services/OrganizationDirectory.php')))['delete'];

    expect($body)->toContain("authorize('manageDirectory'")
        ->and($body)->toContain('requireDirectoryManager')
        ->and($body)->toContain('isNotEnabled()')
        ->and($body)->toContain('lockOrganization')
        ->and($body)->toContain('hasProjects');
});

/** @return list<string> */
function orgDeletionMatches(string $code): array
{
    $code = guardStrip("<?php\n".$code);

    return array_keys(array_filter(orgDeletionPatterns(), fn (string $pattern) => preg_match($pattern, $code) === 1));
}
