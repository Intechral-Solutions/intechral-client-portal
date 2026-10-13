<?php

use App\Support\Rehearsal\MigrationSet;
use Tests\Support\RehearsalSandbox;

/*
 * R0.9-1A/1B: which migrations form the rehearsal baseline and which may be applied as candidates. The baseline
 * is a PINNED list (database/rehearsal/baseline.php); the migrations directory must be exactly that list plus
 * the approved candidates. Pure file rules: no database is touched.
 */

const CANDIDATE = '2026_12_31_000001_rehearsal_probe';

beforeEach(fn () => RehearsalSandbox::use());

function selectionOf(array $names, array $candidates = [CANDIDATE => ['up' => '']]): array
{
    return RehearsalSandbox::migrationSet($candidates)->select($names);
}

/** The refusal text for a set built from a sandbox, optionally altered after it was built. */
function refusalFor(callable $arrange, array $names = []): string
{
    $path = RehearsalSandbox::databasePath([CANDIDATE => ['up' => '']]);
    $baseline = require $path.'/rehearsal/baseline.php';
    $candidates = require $path.'/rehearsal/candidates.php';
    $altered = $arrange($path, $baseline, $candidates);
    if (is_array($altered)) {
        [$baseline, $candidates] = $altered;
    }

    try {
        (new MigrationSet($path.'/migrations', $candidates, $baseline))->select($names);
    } catch (InvalidArgumentException $e) {
        return $e->getMessage();
    }

    return '';
}

describe('the committed sets', function () {
    it('pin every committed migration: nothing is undeclared and nothing has drifted', function () {
        $set = MigrationSet::repository();

        expect($set->manifestViolations())->toBe([])
            ->and($set->select([]))->toBe([])
            ->and($set->names())->toBe([...$set->baselineNames(), ...$set->candidateNames()]);
    });

    it('keeps the baseline in migration order and every candidate after it', function () {
        $set = MigrationSet::repository();
        $baseline = $set->baselineNames();
        $sorted = $baseline;
        sort($sorted);

        expect($baseline)->toBe($sorted)
            ->and(count($baseline))->toBeGreaterThanOrEqual(45);

        foreach ($set->candidateNames() as $candidate) {
            expect(strcmp($candidate, max($baseline)))->toBeGreaterThan(0);
        }
    });

    it('builds the baseline from the pinned list only, in pinned order, as absolute paths', function () {
        $set = MigrationSet::repository();
        $paths = $set->baselinePaths();

        expect($paths)->toHaveCount(count($set->baselineNames()));

        foreach ($set->baselineNames() as $i => $name) {
            expect($paths[$i])->toStartWith('/')->toEndWith('/'.$name.'.php');
        }

        foreach ($set->candidateNames() as $candidate) {
            expect(implode("\n", $paths))->not->toContain($candidate);
        }
    });
});

describe('baseline pinning', function () {
    it('treats an approved candidate as a candidate, not as part of the baseline', function () {
        $set = RehearsalSandbox::migrationSet([CANDIDATE => ['up' => '']]);

        expect($set->baselineNames())->not->toContain(CANDIDATE)
            ->and($set->baselineNames())->toBe(array_keys(RehearsalSandbox::pinnedBaseline()))
            ->and($set->names())->toContain(CANDIDATE)
            ->and($set->candidateNames())->toBe([CANDIDATE]);
    });

    it('refuses an undeclared migration file, naming it, instead of adding it to the baseline', function () {
        $message = refusalFor(fn (string $path) => file_put_contents($path.'/migrations/2026_12_31_000009_surprise_migration.php', '<?php return null;'));

        expect($message)->toContain('Migration set refused', 'undeclared migration file "2026_12_31_000009_surprise_migration"', 'candidates.php');
    });

    it('refuses an undeclared migration even when no candidate is selected, and never lists it in the baseline', function () {
        $path = RehearsalSandbox::databasePath();
        file_put_contents($path.'/migrations/2026_12_31_000009_surprise_migration.php', '<?php return null;');
        $set = new MigrationSet($path.'/migrations', [], require $path.'/rehearsal/baseline.php');

        expect(fn () => $set->select([]))->toThrow(InvalidArgumentException::class, 'undeclared migration file')
            ->and(fn () => $set->baselinePaths())->toThrow(InvalidArgumentException::class, 'undeclared migration file')
            ->and($set->baselineNames())->not->toContain('2026_12_31_000009_surprise_migration');
    });

    it('reports a missing baseline file by name', function () {
        $message = refusalFor(function (string $path, array $baseline) {
            $name = array_key_first($baseline);
            unlink($path.'/migrations/'.$name.'.php');
        });

        expect($message)->toContain('is pinned but missing from the migrations directory', array_key_first(RehearsalSandbox::pinnedBaseline()));
    });

    it('reports a baseline file that no longer matches its pin, with both hashes', function () {
        $name = array_key_first(RehearsalSandbox::pinnedBaseline());
        $message = refusalFor(function (string $path, array $baseline, array $candidates) use ($name) {
            $baseline[$name] = str_repeat('0', 64);

            return [$baseline, $candidates];
        });

        expect($message)->toContain("baseline migration \"{$name}\" has changed since it was pinned", str_repeat('0', 64), RehearsalSandbox::pinnedBaseline()[$name]);
    });

    it('refuses a pinned baseline that is out of order, has a bad hash, or a bad name', function (string $label, Closure $break, string $expected) {
        $message = refusalFor(function (string $path, array $baseline, array $candidates) use ($break) {
            return [$break($baseline), $candidates];
        });

        expect($message)->toContain($expected);
    })->with([
        'out of order' => ['order', function (array $b) {
            $keys = array_keys($b);
            [$b[$keys[0]], $b[$keys[1]]] = [$b[$keys[1]], $b[$keys[0]]];

            return array_combine(array_reverse($keys), array_reverse(array_values($b)));
        }, 'not in migration (ascending name) order'],
        'a bad hash' => ['hash', fn (array $b) => [...$b, '2026_12_30_000001_x' => 'nothex'], 'has no valid sha256'],
        'a bad name' => ['name', fn (array $b) => [...$b, 'not a migration' => str_repeat('a', 64)], 'is not a migration name'],
    ]);

    it('refuses a name that is both pinned and a candidate', function () {
        $name = array_key_first(RehearsalSandbox::pinnedBaseline());
        $message = refusalFor(function (string $path, array $baseline, array $candidates) use ($name) {
            $candidates[$name] = ['sha256' => $baseline[$name]];

            return [$baseline, $candidates];
        });

        expect($message)->toContain("\"{$name}\" is listed as both a baseline migration and a candidate");
    });

    it('refuses a candidate that sorts before the last baseline migration, so it cannot run ahead of the fixtures', function () {
        $early = '2026_01_01_000001_runs_too_early';
        $path = RehearsalSandbox::databasePath([$early => ['up' => '']]);
        $set = new MigrationSet($path.'/migrations', require $path.'/rehearsal/candidates.php', require $path.'/rehearsal/baseline.php');

        expect(fn () => $set->select([$early]))->toThrow(InvalidArgumentException::class, 'sorts before the baseline migration');
    });

    it('reports every piece of drift in one refusal', function () {
        $name = array_key_first(RehearsalSandbox::pinnedBaseline());
        $message = refusalFor(function (string $path, array $baseline, array $candidates) use ($name) {
            file_put_contents($path.'/migrations/2026_12_31_000009_surprise_migration.php', '<?php return null;');
            unlink($path.'/migrations/'.$name.'.php');
        });

        expect($message)->toContain('undeclared migration file', 'pinned but missing');
    });
});

describe('candidate selection', function () {
    it('selects an approved candidate by name, returning its declared changes and an absolute path', function () {
        $selected = selectionOf([CANDIDATE], [CANDIDATE => ['up' => '', 'schema' => ['column+:users.example'], 'data' => ['users']]]);

        expect($selected)->toHaveCount(1)
            ->and($selected[0]['name'])->toBe(CANDIDATE)
            ->and($selected[0]['schema'])->toBe(['column+:users.example'])
            ->and($selected[0]['data'])->toBe(['users'])
            ->and($selected[0]['path'])->toStartWith('/')->toEndWith(CANDIDATE.'.php');
    });

    it('selects nothing by default: an empty selection is a baseline-only run', function () {
        expect(selectionOf([]))->toBe([]);
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
        'an unknown migration' => ['2026_12_31_000009_never_reviewed', 'not an approved candidate'],
    ]);

    it('refuses a candidate selected twice', function () {
        expect(fn () => selectionOf([CANDIDATE, CANDIDATE]))->toThrow(InvalidArgumentException::class, 'selected twice');
    });

    it('refuses a candidate whose file no longer matches the reviewed hash', function () {
        $candidates = [CANDIDATE => ['up' => '', 'sha256' => str_repeat('a', 64)]];

        expect(fn () => selectionOf([CANDIDATE], $candidates))->toThrow(InvalidArgumentException::class, 'sha256 mismatch');
    });

    it('refuses, even with nothing selected, a manifest that is stale or malformed', function () {
        $path = RehearsalSandbox::databasePath();
        $set = new MigrationSet($path.'/migrations', [
            '2026_12_31_000002_missing_file' => ['sha256' => str_repeat('a', 64)],
            'not a migration' => ['sha256' => str_repeat('a', 64)],
            '2026_12_31_000003_bad_hash' => ['sha256' => 'xyz'],
        ], require $path.'/rehearsal/baseline.php');

        expect(fn () => $set->select([]))->toThrow(InvalidArgumentException::class);

        try {
            $set->select([]);
        } catch (InvalidArgumentException $e) {
            expect($e->getMessage())->toContain('has no file', 'is not a migration name', 'no valid sha256');
        }
    });

    it('reports every problem in one refusal rather than the first only', function () {
        try {
            selectionOf(['all', '../x', '2026_12_31_000009_never_reviewed']);
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

        $set = new MigrationSet($path.'/migrations', require $path.'/rehearsal/candidates.php', require $path.'/rehearsal/baseline.php');

        expect(fn () => $set->select([CANDIDATE]))->toThrow(InvalidArgumentException::class, 'not a file directly inside the migrations directory');
    });
});
