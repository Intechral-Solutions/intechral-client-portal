<?php

use App\Support\Rehearsal\MigrationSet;
use Tests\Support\RehearsalSandbox;

/*
 * R0.9-1A: which migrations form the rehearsal baseline and which may be applied as candidates. Pure file
 * rules: no database is touched.
 */

const CANDIDATE = '2026_10_12_000001_rehearsal_probe';

beforeEach(fn () => RehearsalSandbox::use());

function selectionOf(array $names, array $candidates = [CANDIDATE => ['up' => '']]): array
{
    return RehearsalSandbox::migrationSet($candidates)->select($names);
}

it('ships an empty candidate manifest at R0.9-1A, so the baseline is every committed migration', function () {
    $set = MigrationSet::repository();

    expect($set->candidateNames())->toBe([])
        ->and($set->baselineNames())->toBe($set->names())
        ->and($set->select([]))->toBe([]);
});

it('builds the baseline from every migration the manifest does not name', function () {
    $set = RehearsalSandbox::migrationSet([CANDIDATE => ['up' => '']]);

    expect($set->baselineNames())->not->toContain(CANDIDATE)
        ->and($set->baselineNames())->toBe(array_values(array_diff($set->names(), [CANDIDATE])))
        ->and($set->names())->toContain(CANDIDATE);
});

it('selects an approved candidate by name, returning its declared changes and an absolute path', function () {
    $selected = selectionOf([CANDIDATE], [CANDIDATE => ['up' => '', 'schema' => ['column+:users.example'], 'data' => ['users']]]);

    expect($selected)->toHaveCount(1)
        ->and($selected[0]['name'])->toBe(CANDIDATE)
        ->and($selected[0]['schema'])->toBe(['column+:users.example'])
        ->and($selected[0]['data'])->toBe(['users'])
        ->and($selected[0]['path'])->toStartWith('/')->toEndWith(CANDIDATE.'.php');
});

it('refuses a selection that is not a plain approved migration name', function (string $name, string $reason) {
    expect(fn () => selectionOf([$name]))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'all' => ['all', 'not a migration name'],
    'wildcard' => ['2026_10_12_*', 'not a migration name'],
    'traversal' => ['../../etc/passwd', 'not a migration name'],
    'absolute path' => ['/etc/passwd', 'not a migration name'],
    'a path with the extension' => ['database/migrations/'.CANDIDATE.'.php', 'not a migration name'],
    'the extension' => [CANDIDATE.'.php', 'not a migration name'],
    'empty' => ['', 'not a migration name'],
    'a baseline migration (not a candidate)' => ['0001_01_01_000000_create_users_table', 'not an approved candidate'],
    'an unknown migration' => ['2026_10_12_000009_never_reviewed', 'not an approved candidate'],
]);

it('refuses a candidate selected twice', function () {
    expect(fn () => selectionOf([CANDIDATE, CANDIDATE]))->toThrow(InvalidArgumentException::class, 'selected twice');
});

it('refuses a candidate whose file no longer matches the reviewed hash', function () {
    $candidates = [CANDIDATE => ['up' => '', 'sha256' => str_repeat('a', 64)]];

    expect(fn () => selectionOf([CANDIDATE], $candidates))->toThrow(InvalidArgumentException::class, 'sha256 mismatch');
});

it('refuses, even with nothing selected, a manifest that is stale or malformed', function () {
    $set = new MigrationSet(database_path('migrations'), [
        '2026_10_12_000002_missing_file' => ['sha256' => str_repeat('a', 64)],
        'not a migration' => ['sha256' => str_repeat('a', 64)],
        '2026_10_12_000003_bad_hash' => ['sha256' => 'xyz'],
    ]);

    expect(fn () => $set->select([]))->toThrow(InvalidArgumentException::class);

    try {
        $set->select([]);
    } catch (InvalidArgumentException $e) {
        expect($e->getMessage())->toContain('has no file', 'is not a migration name', 'no valid sha256');
    }
});

it('reports every problem in one refusal rather than the first only', function () {
    try {
        selectionOf(['all', '../x', '2026_10_12_000009_never_reviewed']);
        $this->fail('expected a refusal');
    } catch (InvalidArgumentException $e) {
        expect(substr_count($e->getMessage(), "\n  - "))->toBe(3);
    }
});

it('never selects a file outside the migrations directory, even if the manifest names one', function () {
    $path = RehearsalSandbox::databasePath([CANDIDATE => ['up' => '']]);
    $outside = RehearsalSandbox::root().'/outside';
    mkdir($outside);
    rename($path.'/migrations/'.CANDIDATE.'.php', $outside.'/'.CANDIDATE.'.php');
    symlink($outside.'/'.CANDIDATE.'.php', $path.'/migrations/'.CANDIDATE.'.php');

    $set = new MigrationSet($path.'/migrations', require $path.'/rehearsal/candidates.php');

    expect(fn () => $set->select([CANDIDATE]))->toThrow(InvalidArgumentException::class, 'not a file directly inside the migrations directory');
});
