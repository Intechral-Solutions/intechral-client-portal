<?php

use App\Support\Rehearsal\RehearsalTarget;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HistoricalSchema;

/*
 * Release 1.0 (ORG-2, CP1): the infrastructure itself. A historical migration test is only worth anything if it provably runs on the disposable database,
 * on exactly the schema it asks for, and cannot leave the next test on anything else.
 */

it('runs the application on the disposable rehearsal database, never on the testing database', function () {
    $connection = (string) config('database.default');

    expect($connection)->toBe(RehearsalTarget::primary()->connectionName())
        ->and(DB::selectOne('select database() as d')->d)->toBe(RehearsalTarget::DATABASES[RehearsalTarget::PRIMARY])
        ->and(DB::selectOne('select database() as d')->d)->not->toBe('intechral_client_portal_testing')
        ->and(config("database.connections.{$connection}.host"))->toBe('db');
});

it('builds exactly the schema before the named migration, from the real migration files', function () {
    HistoricalSchema::use(HistoricalSchema::ORG2);

    expect(Schema::hasTable('crm_companies'))->toBeTrue()
        ->and(Schema::hasTable('project_company'))->toBeTrue()
        ->and(Schema::hasColumn('organizations', 'portal_enabled_at'))->toBeTrue()
        ->and(Schema::hasColumn('organizations', 'created_by'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', HistoricalSchema::ORG2)->exists())->toBeFalse()
        ->and(DB::table('migrations')->where('migration', HistoricalSchema::ORG1)->exists())->toBeTrue();

    HistoricalSchema::use(HistoricalSchema::ORG1);

    expect(Schema::hasColumn('organizations', 'owner_id'))->toBeTrue()
        ->and(Schema::hasColumn('organizations', 'portal_enabled_at'))->toBeFalse()
        ->and(DB::table('migrations')->where('migration', HistoricalSchema::ORG1)->exists())->toBeFalse();

    HistoricalSchema::use(null);

    expect(Schema::hasTable('crm_companies'))->toBeFalse()
        ->and(Schema::hasTable('project_organization'))->toBeTrue()
        ->and(DB::table('migrations')->where('migration', HistoricalSchema::ORG2)->exists())->toBeTrue();
});

it('refuses a point that is not a migration', function () {
    expect(fn () => HistoricalSchema::use('2000_01_01_000000_not_a_migration'))->toThrow(RuntimeException::class, 'There is no migration');
});

it('empties what a previous test left, and keeps the schema', function () {
    HistoricalSchema::use(HistoricalSchema::ORG2);
    DB::table('users')->insert(['name' => 'Left behind', 'email' => 'left-behind@example.test', 'password' => 'x', 'created_at' => now(), 'updated_at' => now()]);
    expect(DB::table('users')->count())->toBe(1);

    HistoricalSchema::use(HistoricalSchema::ORG2);

    expect(DB::table('users')->count())->toBe(0)
        ->and(Schema::hasTable('crm_companies'))->toBeTrue();
});

it('rebuilds, never repairs, a schema that a failed or interrupted test left changed', function () {
    HistoricalSchema::use(HistoricalSchema::ORG2);
    $target = RehearsalTarget::primary();
    $clean = HistoricalSchema::fingerprint(DB::connection(), $target);

    // What an interrupted DDL test leaves: a rolled-back-halfway schema (here the ORG-1 migration undone), then a stray column.
    DB::statement('alter table crm_companies add column stray varchar(10) null');
    DB::statement('drop table project_company');
    expect(HistoricalSchema::fingerprint(DB::connection(), $target))->not->toBe($clean);

    HistoricalSchema::use(HistoricalSchema::ORG2);

    expect(HistoricalSchema::fingerprint(DB::connection(), $target))->toBe($clean)
        ->and(Schema::hasColumn('crm_companies', 'stray'))->toBeFalse()
        ->and(Schema::hasTable('project_company'))->toBeTrue();
});

it('moves from one point to another by rebuilding, and a different point is never mistaken for the same one', function () {
    HistoricalSchema::use(null);
    $full = HistoricalSchema::fingerprint(DB::connection(), RehearsalTarget::primary());

    HistoricalSchema::use(HistoricalSchema::ORG2);

    expect(HistoricalSchema::fingerprint(DB::connection(), RehearsalTarget::primary()))->not->toBe($full);
});

// ── The reuse key depends on the CONTENT of the migrations that build the schema (CP1 review, B-4) ──

it('hashes the content of the migration files: an edit changes it, a changed timestamp or location does not', function () {
    $dir = sys_get_temp_dir().'/hist-hash-'.bin2hex(random_bytes(4));
    $copy = sys_get_temp_dir().'/hist-hash-copy-'.bin2hex(random_bytes(4));
    mkdir($dir);
    mkdir($copy);

    try {
        file_put_contents("{$dir}/2026_01_01_000001_a.php", "<?php // a\n");
        file_put_contents("{$dir}/2026_01_01_000002_b.php", "<?php // b\n");
        $paths = ["{$dir}/2026_01_01_000001_a.php", "{$dir}/2026_01_01_000002_b.php"];
        $before = HistoricalSchema::contentHash($paths);

        // Timestamps alone change nothing; neither does the order the paths are given in, nor the directory they live in.
        touch($paths[0], time() + 3600);
        copy($paths[0], "{$copy}/2026_01_01_000001_a.php");
        copy($paths[1], "{$copy}/2026_01_01_000002_b.php");

        expect(HistoricalSchema::contentHash($paths))->toBe($before)
            ->and(HistoricalSchema::contentHash(array_reverse($paths)))->toBe($before)
            ->and(HistoricalSchema::contentHash(["{$copy}/2026_01_01_000001_a.php", "{$copy}/2026_01_01_000002_b.php"]))->toBe($before);

        // One byte of one migration does.
        file_put_contents($paths[1], "<?php // b!\n");
        expect(HistoricalSchema::contentHash($paths))->not->toBe($before);

        // A migration added to the point does, and so does one that is renamed.
        file_put_contents("{$dir}/2026_01_01_000003_c.php", "<?php // c\n");
        expect(HistoricalSchema::contentHash([...$paths, "{$dir}/2026_01_01_000003_c.php"]))->not->toBe(HistoricalSchema::contentHash($paths));
    } finally {
        array_map('unlink', [...glob("{$dir}/*"), ...glob("{$copy}/*")]);
        rmdir($dir);
        rmdir($copy);
    }
});

it('keys a point by its migrations: the real reuse key moves when the point does, and carries the content hash', function () {
    $org2 = HistoricalSchema::reuseKey(HistoricalSchema::ORG2);

    expect($org2)->toContain('before:'.HistoricalSchema::ORG2.'|'.HistoricalSchema::contentHash(HistoricalSchema::paths(HistoricalSchema::ORG2)))
        ->and(HistoricalSchema::reuseKey(HistoricalSchema::ORG1))->not->toBe($org2)
        ->and(HistoricalSchema::reuseKey(null))->not->toBe($org2);
});

it('reuses a database built from the same migration content, and REBUILDS one built from different content even when its schema is unchanged', function () {
    HistoricalSchema::use(HistoricalSchema::ORG2);
    // A marker that is not part of the schema fingerprint (the harness's own state table is outside it) and that only survives a REUSE.
    DB::statement('alter table historical_state add column reuse_probe int null');

    HistoricalSchema::use(HistoricalSchema::ORG2);
    expect(Schema::hasColumn('historical_state', 'reuse_probe'))->toBeTrue('the control failed: an identical build was not reused, so this test could not tell a rebuild from a reuse');

    // The database now claims it was built from other migration code. The schema is byte-for-byte what the current files would build.
    $hash = HistoricalSchema::contentHash(HistoricalSchema::paths(HistoricalSchema::ORG2));
    DB::table('historical_state')->update(['state' => DB::raw("replace(state, '{$hash}', 'built-from-older-migration-code')")]);
    DB::commit();   // use() leaves a transaction open, which the next use() rolls back: the claim about the old build must be durable
    expect(DB::table('historical_state')->value('state'))->toContain('built-from-older-migration-code');

    HistoricalSchema::use(HistoricalSchema::ORG2);

    expect(Schema::hasColumn('historical_state', 'reuse_probe'))->toBeFalse()
        ->and(DB::table('historical_state')->value('state'))->toContain($hash)
        ->and(Schema::hasTable('crm_companies'))->toBeTrue();
});
