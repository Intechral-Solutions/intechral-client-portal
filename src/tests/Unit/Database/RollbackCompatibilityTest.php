<?php

use App\Support\Database\DatabaseProfile;
use App\Support\Database\RollbackCompatibility;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;

/** A fixture only: the queued-class scan must see a class queued through ShouldQueueAfterCommit, which a text match on ShouldQueue misses. */
final class RcAfterCommitFixture implements ShouldQueueAfterCommit {}

/*
 * Release 1.0 (RE-C1): the reviewed rollback declaration for Release 0.9 and the fail-closed assessment built on it.
 *
 * The facts being pinned were measured against the REAL Release 0.9 build (commit 32ec62f) on a disposable database on 2026-11-06:
 * after this build's three migrations its `database:verify` reports exactly the four `unexpected foreign key` lines recorded in the
 * declaration, and its `runtime:preflight` reports the same four (so it never issues an A2 challenge). The older build cannot be
 * changed, and tolerating the foreign keys in THIS build's manifest could not make it pass. These tests therefore check the
 * assessment, never a tolerance: every refusal is a negative control that unexpected schema drift is rejected.
 *
 * Pure: no database. The live schema comes in as an array. The same command against a real database is in
 * tests/Feature/Database/RollbackAssessmentCommandTest.php.
 */

function rcDeclaration(): array
{
    return RollbackCompatibility::declaration('0.9');
}

/** @return array<string, string> */
function rcTarget(): array
{
    return RollbackCompatibility::targetManifest(rcDeclaration());
}

/** @return array<string, string> this build's manifest */
function rcCurrent(): array
{
    return DatabaseProfile::expectedForeignKeys();
}

function rcProblems(?array $declaration = null, ?array $target = null, ?array $current = null, ?array $live = null): array
{
    $current ??= rcCurrent();

    return RollbackCompatibility::problems($declaration ?? rcDeclaration(), $target ?? rcTarget(), $current, $live ?? $current);
}

/** @return array<string, array{schema: list<string>}> the rehearsal candidates that sort after the target's last migration */
function rcMigrationsAfterTarget(): array
{
    $last = rcDeclaration()['target']['last_migration'];

    return array_filter(require base_path('database/rehearsal/candidates.php'), fn (string $name) => strcmp($name, $last) > 0, ARRAY_FILTER_USE_KEY);
}

// ── What the reviewed declaration says, against three independent sources ────

it('pins the Release 0.9 manifest byte for byte, and refuses a tampered copy', function () {
    $declaration = rcDeclaration();

    expect(hash('sha256', file_get_contents(base_path($declaration['target']['foreign_key_manifest']))))->toBe($declaration['target']['foreign_key_manifest_sha256'])
        ->and(rcTarget())->not->toBeEmpty()
        ->and($declaration['target']['commit'])->toMatch('/^[0-9a-f]{40}$/');

    $declaration['target']['foreign_key_manifest_sha256'] = str_repeat('0', 64);

    expect(fn () => RollbackCompatibility::targetManifest($declaration))->toThrow(RuntimeException::class, 'SHA-256 mismatch');
});

it('has exactly the additions the three sources agree on: the manifest difference, the rehearsed migrations and the declaration', function () {
    $declared = rcDeclaration()['additive_foreign_keys'];
    $fromManifests = array_diff_key(rcCurrent(), rcTarget());

    $fromCandidates = [];
    foreach (rcMigrationsAfterTarget() as $candidate) {
        foreach ($candidate['schema'] as $change) {
            if (str_starts_with($change, 'fk+:')) {
                $body = substr($change, 4);
                $fromCandidates[substr($body, 0, (int) strrpos($body, ':'))] = substr($body, (int) strrpos($body, ':') + 1);
            }
        }
    }

    ksort($declared);
    ksort($fromManifests);
    ksort($fromCandidates);

    expect($fromManifests)->toBe($declared)
        ->and($fromCandidates)->toBe($declared)
        ->and(array_diff_key(rcTarget(), rcCurrent()))->toBe([], 'no foreign key of Release 0.9 was removed')
        ->and(array_filter(rcTarget(), fn (string $rules, string $key) => (rcCurrent()[$key] ?? null) !== $rules, ARRAY_FILTER_USE_BOTH))->toBe([], 'and none changed its actions');
});

it('reproduces what the real Release 0.9 verifier reported: four unexpected foreign key lines and nothing else', function () {
    $expected = RollbackCompatibility::expectedTargetVerifierOutput(rcTarget(), rcCurrent());

    // Captured from the real build, not computed: this is the evidence the computation must keep reproducing.
    expect($expected)->toBe(rcDeclaration()['expected_target_verifier_output'])
        ->and($expected)->toHaveCount(4)
        ->and(collect($expected)->every(fn (string $line) => str_starts_with($line, 'unexpected foreign key: ')))->toBeTrue();
});

it('lists as migrations after the target exactly the migration files that follow it, each one rehearsed', function () {
    $last = rcDeclaration()['target']['last_migration'];
    $files = collect(glob(base_path('database/migrations/*.php')))->map(fn (string $path) => basename($path, '.php'))
        ->filter(fn (string $name) => strcmp($name, $last) > 0)->sort()->values()->all();

    expect($files)->toBe(rcDeclaration()['migrations_after_target'])
        ->and(array_keys(rcMigrationsAfterTarget()))->toBe($files, 'each has a pinned, rehearsed candidate entry');
});

it('has no data-rewriting migration among the rollback candidates: a rewrite of existing rows is not reversible by a code-only rollback', function () {
    $candidates = rcMigrationsAfterTarget();

    expect($candidates)->not->toBeEmpty('a vacuous pass: no candidates were found after the target');

    foreach ($candidates as $name => $candidate) {
        expect($candidate['data'] ?? null)->toBe([], "{$name} declares a data change; a code-only rollback to the older release needs a separate review");
    }
});

it('is rollback-compatible by construction: the rehearsed changes after the target are additive, and every tightening is reviewed', function () {
    $candidates = rcMigrationsAfterTarget();
    $createdTables = [];
    foreach ($candidates as $candidate) {
        foreach ($candidate['schema'] as $change) {
            if (str_starts_with($change, 'table+:')) {
                $createdTables[] = substr($change, 7);
            }
        }
    }

    $tightenings = [];
    foreach ($candidates as $name => $candidate) {
        foreach ($candidate['schema'] as $change) {
            $kind = substr($change, 0, (int) strpos($change, ':'));

            // Anything that removes or alters something the older release uses needs a person, not a green test.
            expect(in_array($kind, ['table+', 'column+', 'index+', 'unique+', 'fk+', 'index-'], true))->toBeTrue("{$name}: '{$change}' is not an additive change");

            if ($kind === 'unique+' && ! in_array(explode('(', substr($change, 8))[0], $createdTables, true)) {
                $tightenings[] = $change;
            }

            if ($kind === 'index-') {
                // Only the plain index a foreign key used, replaced by a unique key on the same columns in the same migration.
                expect(in_array('unique+:'.substr($change, 7), $candidate['schema'], true))->toBeTrue("{$name}: '{$change}' is not replaced by a unique key");
            }
        }
    }

    sort($tightenings);
    $declared = collect(rcDeclaration()['tightenings'])->pluck('object')->sort()->values()->all();

    expect($tightenings)->toBe($declared)
        ->and(collect(rcDeclaration()['tightenings'])->every(fn (array $t) => strlen($t['reason']) > 20))->toBeTrue('each tightening says why the older code cannot violate it');
});

/** @return list<string> every instantiable class under app/ that Laravel would queue: ShouldQueue and everything that extends it, such as ShouldQueueAfterCommit */
function rcQueuedClasses(): array
{
    $found = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $class = 'App\\'.str_replace(['/', '.php'], ['\\', ''], substr($file->getPathname(), strlen(app_path()) + 1));

        if (class_exists($class) && rcIsQueued($class)) {
            $found[] = $class;
        }
    }

    sort($found);

    return $found;
}

function rcIsQueued(string $class): bool
{
    $reflection = new ReflectionClass($class);

    return $reflection->isInstantiable() && $reflection->implementsInterface(ShouldQueue::class);
}

it('knows every queued class the application has, so a new one cannot reach a rollback unreviewed', function () {
    $declared = [...rcDeclaration()['target']['queued_classes'], ...rcDeclaration()['queued_classes_added_since_target']];
    sort($declared);

    expect(rcQueuedClasses())->toBe($declared, 'a queued class added since Release 0.9 must be listed in queued_classes_added_since_target, and its jobs drained before a rollback');
});

it('detects a class queued through ShouldQueueAfterCommit, which a text match on ShouldQueue misses', function () {
    expect(rcIsQueued(RcAfterCommitFixture::class))->toBeTrue()
        ->and(rcIsQueued(RollbackCompatibility::class))->toBeFalse()
        ->and(rcQueuedClasses())->not->toContain(RcAfterCommitFixture::class);
});

it('declares exactly the columns the rehearsed migrations add, and every one is nullable or has a default', function () {
    $fromCandidates = [];
    foreach (rcMigrationsAfterTarget() as $candidate) {
        foreach ($candidate['schema'] as $change) {
            if (str_starts_with($change, 'column+:')) {
                $fromCandidates[] = substr($change, 8);
            }
            // A changed or dropped column is not additive: it needs a person and a new kind of declaration.
            expect(str_starts_with($change, 'column~:') || str_starts_with($change, 'column-:'))->toBeFalse("'{$change}' alters or drops a column");
        }
    }

    $declared = array_keys(rcDeclaration()['additive_columns']);
    sort($fromCandidates);
    sort($declared);

    expect($declared)->toBe($fromCandidates)
        ->and(RollbackCompatibility::declaredColumnProblems(rcDeclaration()))->toBe([]);
});

it('declares exactly the CHECK constraints the migrations after the target create', function () {
    $count = 0;
    foreach (rcDeclaration()['migrations_after_target'] as $name) {
        $count += preg_match_all('/\bcheck\s*\(/i', (string) file_get_contents(base_path("database/migrations/{$name}.php")));
    }

    expect($count)->toBe(count(rcDeclaration()['additive_check_constraints']), 'a CHECK in a migration after the target must be declared, with the reason the older code cannot violate it')
        ->and(collect(rcDeclaration()['additive_check_constraints'])->every(fn (string $reason) => strlen($reason) > 20))->toBeTrue();
});

// ── Negative controls: unexpected drift is rejected ──────────────────────────

it('accepts exactly the reviewed schema', function () {
    expect(rcProblems())->toBe([]);
});

it('rejects an unexpected foreign key in the live database', function () {
    $live = rcCurrent() + ['rollback_probe.user_id->users.id' => 'CASCADE/RESTRICT'];

    $problems = implode("\n", rcProblems(live: $live));

    expect($problems)->toContain("the live database is not this build's reviewed schema: unexpected foreign key: rollback_probe.user_id->users.id")
        ->and($problems)->toContain('would also report an unreviewed line: unexpected foreign key: rollback_probe.user_id->users.id');
});

it('rejects a missing reviewed additive foreign key', function () {
    $live = rcCurrent();
    unset($live['ticket_due_date_changes.user_id->users.id']);

    expect(implode("\n", rcProblems(live: $live)))->toContain('foreign key missing: ticket_due_date_changes.user_id->users.id')
        ->and(implode("\n", rcProblems(live: $live)))->toContain('verifier line is not reproduced: unexpected foreign key: ticket_due_date_changes.user_id->users.id');
});

it('rejects a changed action on a reviewed additive foreign key', function () {
    $live = rcCurrent();
    $live['ticket_due_date_changes.ticket_id->tickets.id'] = 'RESTRICT/RESTRICT';

    expect(implode("\n", rcProblems(live: $live)))->toContain('foreign key rules changed: ticket_due_date_changes.ticket_id->tickets.id');
});

it('rejects a missing or changed foreign key of the older release itself', function () {
    $key = array_key_first(rcTarget());

    $missing = rcCurrent();
    unset($missing[$key]);
    $changed = rcCurrent();
    $changed[$key] = 'CASCADE/CASCADE';

    expect(implode("\n", rcProblems(live: $missing)))->toContain("foreign key missing: {$key}")
        ->and(implode("\n", rcProblems(live: $changed)))->toContain("foreign key rules changed: {$key}")
        ->and(implode("\n", rcProblems(live: $changed)))->toContain('would also report an unreviewed line: foreign key rules changed');
});

it('rejects a build that removed or changed a foreign key the older release has: the rollback is blocked', function () {
    $key = array_key_first(rcTarget());
    $without = rcCurrent();
    unset($without[$key]);
    $changed = rcCurrent();
    $changed[$key] = 'CASCADE/CASCADE';

    expect(implode("\n", rcProblems(current: $without, live: $without)))->toContain("rollback blocked: foreign key {$key}")
        ->and(implode("\n", rcProblems(current: $changed, live: $changed)))->toContain("rollback blocked: foreign key {$key} is CASCADE/CASCADE in this build");
});

it('rejects a new foreign key that nobody has reviewed, even when this build and the live database agree on it', function () {
    // An invented key, deliberately not any real or pending migration's: this control must stay meaningful whatever merges later.
    $current = rcCurrent() + ['invented_unreviewed.owner_id->users.id' => 'RESTRICT/RESTRICT'];
    ksort($current);

    $problems = implode("\n", rcProblems(current: $current, live: $current));

    expect($problems)->toContain('unreviewed additive foreign key: invented_unreviewed.owner_id->users.id (RESTRICT/RESTRICT) is not in the rollback declaration')
        ->and($problems)->toContain('would also report an unreviewed line');
});

it('rejects a declaration that lists something this build does not add, that has the wrong action, or that tries a wildcard', function () {
    $extra = rcDeclaration();
    $extra['additive_foreign_keys']['invented.column->users.id'] = 'CASCADE/RESTRICT';

    $wrongRule = rcDeclaration();
    $wrongRule['additive_foreign_keys']['organizations.managed_by_organization_id->organizations.id'] = 'CASCADE/RESTRICT';

    $wildcard = rcDeclaration();
    $wildcard['additive_foreign_keys'] = ['*' => '*'];

    $alreadyInTarget = rcDeclaration();
    $alreadyInTarget['additive_foreign_keys'][array_key_first(rcTarget())] = rcTarget()[array_key_first(rcTarget())];

    expect(implode("\n", rcProblems(declaration: $extra)))->toContain('declared additive foreign key invented.column->users.id (CASCADE/RESTRICT) is not an addition')
        ->and(implode("\n", rcProblems(declaration: $wrongRule)))->toContain('but the declaration says CASCADE/RESTRICT')
        ->and(implode("\n", rcProblems(declaration: $wildcard)))->toContain('unreviewed additive foreign key: organizations.managed_by_organization_id')
        ->and(implode("\n", rcProblems(declaration: $wildcard)))->toContain('declared additive foreign key * (*) is not an addition')
        ->and(implode("\n", rcProblems(declaration: $alreadyInTarget)))->toContain('is not an addition of this build');
});

it('rejects a recorded older-verifier output that this build no longer reproduces', function () {
    $stale = rcDeclaration();
    $stale['expected_target_verifier_output'][] = 'unexpected foreign key: invented.column->users.id (CASCADE/RESTRICT)';

    $short = rcDeclaration();
    array_pop($short['expected_target_verifier_output']);

    expect(implode("\n", rcProblems(declaration: $stale)))->toContain('verifier line is not reproduced: unexpected foreign key: invented.column')
        ->and(implode("\n", rcProblems(declaration: $short)))->toContain('would also report an unreviewed line');
});

it('refuses queued jobs the older release does not have, and accepts the ones it does', function () {
    $declaration = rcDeclaration();

    expect(RollbackCompatibility::queueProblems($declaration, ['App\Notifications\TicketRepliedNotification' => 3, 'App\Jobs\RecordRuntimeObservation' => 1]))->toBe([])
        ->and(RollbackCompatibility::queueProblems($declaration, ['App\Notifications\FutureInvitationNotification' => 2, 'App\Notifications\TicketStatusNotification' => 1]))
        ->toBe(['2 queued job(s) of App\Notifications\FutureInvitationNotification are waiting, and release 0.9 has no such class: drain or delete them before the rollback'])
        ->and(RollbackCompatibility::queueProblems($declaration, ['(unreadable payload)' => 1]))->not->toBe([]);
});

it('reads a job as the class it will run, and never trusts a display name over its command', function () {
    $payload = fn (string $display, string $command) => json_encode(['displayName' => $display, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => ['commandName' => $command, 'command' => 'x']]);
    $notification = 'Illuminate\Notifications\SendQueuedNotifications';

    expect(RollbackCompatibility::jobIdentity($payload('App\Notifications\TicketStatusNotification', $notification)))->toBe('App\Notifications\TicketStatusNotification')
        ->and(RollbackCompatibility::jobIdentity($payload('App\Jobs\RecordRuntimeObservation', 'App\Jobs\RecordRuntimeObservation')))->toBe('App\Jobs\RecordRuntimeObservation')
        // A known display name over a command the older release does not have must NOT read as the known class.
        ->and(RollbackCompatibility::jobIdentity($payload('App\Jobs\RecordRuntimeObservation', 'App\Jobs\FutureJob')))->toBe('App\Jobs\RecordRuntimeObservation (command App\Jobs\FutureJob)')
        // A queued listener, mailable or closure is run by a framework class and is not one of the six.
        ->and(RollbackCompatibility::jobIdentity($payload('App\Listeners\Future@handle', 'Illuminate\Events\CallQueuedListener')))->toBe('App\Listeners\Future@handle (command Illuminate\Events\CallQueuedListener)')
        ->and(RollbackCompatibility::jobIdentity('not json'))->toBe('(unreadable payload)')
        ->and(RollbackCompatibility::jobIdentity(json_encode(['displayName' => 'App\Jobs\RecordRuntimeObservation'])))->toBe('(unreadable payload)')
        ->and(RollbackCompatibility::jobIdentity(json_encode(['data' => ['commandName' => 'X']])))->toBe('(unreadable payload)');

    $mismatch = RollbackCompatibility::jobIdentity($payload('App\Jobs\RecordRuntimeObservation', 'App\Jobs\FutureJob'));

    expect(RollbackCompatibility::queueProblems(rcDeclaration(), [$mismatch => 1]))->not->toBe([]);
});

it('requires the database queue, an unreserved queue and the jobs table', function () {
    expect(RollbackCompatibility::queueConfigProblems('database', 'database', 'jobs', 0))->toBe([])
        ->and(implode("\n", RollbackCompatibility::queueConfigProblems('redis', 'redis', 'jobs', 0)))->toContain("default queue connection is 'redis'")
        ->and(implode("\n", RollbackCompatibility::queueConfigProblems('database', 'sync', 'jobs', 0)))->toContain('not the database queue')
        ->and(implode("\n", RollbackCompatibility::queueConfigProblems('database', 'database', 'queue_jobs', 0)))->toContain("not 'jobs'")
        ->and(implode("\n", RollbackCompatibility::queueConfigProblems('database', 'database', 'jobs', 2)))->toContain('2 job(s) are reserved');
});

it('lists the failed jobs of a class the older release lacks as ones that must never be retried', function () {
    $declaration = rcDeclaration();

    expect(RollbackCompatibility::unretryableFailedJobs($declaration, ['App\Notifications\TicketStatusNotification' => 4]))->toBe([])
        ->and(RollbackCompatibility::unretryableFailedJobs($declaration, ['App\Notifications\TicketStatusNotification' => 4, 'App\Notifications\FutureInvitationNotification' => 2, '(unreadable payload)' => 1]))
        ->toBe(['App\Notifications\FutureInvitationNotification' => 2, '(unreadable payload)' => 1]);
});

// ── The ran-migration set, added columns and CHECK constraints ───────────────

function rcFiles(): array
{
    return array_map(fn (string $path) => basename($path, '.php'), glob(base_path('database/migrations/*.php')));
}

it('accepts exactly the ran-migration set of this build', function () {
    expect(RollbackCompatibility::migrationProblems(rcDeclaration(), rcFiles(), rcFiles()))->toBe([]);
});

it('rejects a pending migration, an unknown ran migration, a new migration the declaration does not cover and a declared one that is gone', function () {
    $files = rcFiles();
    $declaration = rcDeclaration();
    $gone = array_values(array_diff($files, [$declaration['migrations_after_target'][0]]));

    expect(implode("\n", RollbackCompatibility::migrationProblems($declaration, $files, array_slice($files, 0, -1))))->toContain('is pending')
        ->and(implode("\n", RollbackCompatibility::migrationProblems($declaration, $files, [...$files, '2026_12_01_000000_unknown_to_this_build'])))->toContain('2026_12_01_000000_unknown_to_this_build, which is not a migration file of this build')
        ->and(implode("\n", RollbackCompatibility::migrationProblems($declaration, [...$files, '2026_12_01_000000_new_after_declaration'], [...$files, '2026_12_01_000000_new_after_declaration'])))->toContain('not covered by the rollback declaration')
        ->and(implode("\n", RollbackCompatibility::migrationProblems($declaration, $gone, $gone)))->toContain('is not a migration file of this build');
});

function rcLiveColumns(): array
{
    return collect(rcDeclaration()['additive_columns'])->map(fn (array $shape) => ['nullable' => $shape['nullable'], 'default' => $shape['default']])->all();
}

function rcLiveChecks(): array
{
    return [...rcDeclaration()['target']['check_constraints'], ...array_keys(rcDeclaration()['additive_check_constraints'])];
}

it('accepts the declared columns and CHECK constraints, and rejects drift in either', function () {
    $declaration = rcDeclaration();
    $notNull = rcLiveColumns();
    $notNull['tickets.due_override_at'] = ['nullable' => false, 'default' => null];
    $missing = rcLiveColumns();
    unset($missing['organizations.can_manage_customers']);

    // A migration that adds a NOT NULL column with no default, declared honestly, is itself refused: the older code could not insert.
    $declaredNotNull = $declaration;
    $declaredNotNull['additive_columns']['tickets.invented'] = ['nullable' => false, 'default' => null];

    expect(RollbackCompatibility::schemaProblems($declaration, rcLiveColumns(), rcLiveChecks()))->toBe([])
        ->and(implode("\n", RollbackCompatibility::schemaProblems($declaration, $notNull, rcLiveChecks())))->toContain('column tickets.due_override_at is NOT NULL with no default in the live database but the declaration says nullable')
        ->and(implode("\n", RollbackCompatibility::schemaProblems($declaration, $missing, rcLiveChecks())))->toContain('declared column organizations.can_manage_customers does not exist')
        ->and(implode("\n", RollbackCompatibility::schemaProblems($declaredNotNull, [...rcLiveColumns(), 'tickets.invented' => ['nullable' => false, 'default' => null]], rcLiveChecks())))->toContain('declared column tickets.invented is NOT NULL without a default')
        ->and(implode("\n", RollbackCompatibility::schemaProblems($declaration, rcLiveColumns(), [...rcLiveChecks(), 'tickets.extra_check'])))->toContain('unreviewed CHECK constraint tickets.extra_check')
        ->and(implode("\n", RollbackCompatibility::schemaProblems($declaration, rcLiveColumns(), array_slice(rcLiveChecks(), 1))))->toContain('is expected but absent');
});

// ── Target identity ──────────────────────────────────────────────────────────

/** @return string a throwaway directory shaped like an unpacked Release 0.9 build: RELEASE, the manifest and the migration files */
function rcTargetDirectory(array $override = []): string
{
    $declaration = rcDeclaration();
    $dir = sys_get_temp_dir().'/rc-target-'.bin2hex(random_bytes(6));
    mkdir($dir.'/database/profile', 0700, true);
    mkdir($dir.'/database/migrations', 0700, true);

    file_put_contents($dir.'/RELEASE', $override['release'] ?? "release=20261022225141-32ec62f\ncommit={$declaration['target']['commit']}\nbuilt_utc=x\n");
    copy(base_path($declaration['target']['foreign_key_manifest']), $dir.'/database/profile/foreign-keys.json');

    if (isset($override['manifest'])) {
        file_put_contents($dir.'/database/profile/foreign-keys.json', $override['manifest']);
    }

    foreach ($override['migrations'] ?? ['2026_10_12_000002_restrict_user_business_record_deletes', $declaration['target']['last_migration']] as $name) {
        touch($dir.'/database/migrations/'.$name.'.php');
    }

    return $dir;
}

function rcRemove(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

it('accepts only the reviewed release directory: the RELEASE commit, the manifest and the newest migration must all match', function () {
    $declaration = rcDeclaration();
    $dirs = [
        'ok' => rcTargetDirectory(),
        'other commit' => rcTargetDirectory(['release' => "release=20261030145420-8f5fe5a\ncommit=8f5fe5a000000000000000000000000000000000\n"]),
        'no commit' => rcTargetDirectory(['release' => "release=x\n"]),
        'two commits' => rcTargetDirectory(['release' => "commit={$declaration['target']['commit']}\ncommit={$declaration['target']['commit']}\n"]),
        'other manifest' => rcTargetDirectory(['manifest' => '{"foreign_keys":{}}']),
        'later migration' => rcTargetDirectory(['migrations' => [$declaration['target']['last_migration'], '2026_11_04_120000_add_ticket_due_date_override']]),
    ];

    try {
        expect(RollbackCompatibility::targetDirectoryProblems($declaration, $dirs['ok']))->toBe([])
            ->and(implode("\n", RollbackCompatibility::targetDirectoryProblems($declaration, $dirs['other commit'])))->toContain('does not record the reviewed release 0.9 commit')
            ->and(implode("\n", RollbackCompatibility::targetDirectoryProblems($declaration, $dirs['no commit'])))->toContain('found: no commit line')
            ->and(implode("\n", RollbackCompatibility::targetDirectoryProblems($declaration, $dirs['two commits'])))->toContain('does not record the reviewed release 0.9 commit')
            ->and(implode("\n", RollbackCompatibility::targetDirectoryProblems($declaration, $dirs['other manifest'])))->toContain('SHA-256 mismatch')
            ->and(implode("\n", RollbackCompatibility::targetDirectoryProblems($declaration, $dirs['later migration'])))->toContain('newest migration is not')
            ->and(RollbackCompatibility::targetDirectoryProblems($declaration, sys_get_temp_dir().'/rc-does-not-exist'))->not->toBe([])
            ->and(implode("\n", RollbackCompatibility::targetDirectoryProblems($declaration, base_path())))->toContain('this build itself');
    } finally {
        array_map('rcRemove', $dirs);
    }
});

it('accepts only a release such as "0.9" and a declaration that exists', function () {
    expect(fn () => RollbackCompatibility::declaration('../0.9'))->toThrow(RuntimeException::class, 'not a release')
        ->and(fn () => RollbackCompatibility::declaration('9.9'))->toThrow(RuntimeException::class, 'no reviewed rollback declaration');
});
