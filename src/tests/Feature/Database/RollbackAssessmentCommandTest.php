<?php

use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationMembership;
use App\Support\Database\RollbackCompatibility;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
 * Release 1.0 (RE-C1): `database:rollback-assessment` against a real MariaDB schema. The pure rules and the declaration are in
 * tests/Unit/Database/RollbackCompatibilityTest.php; this proves the command reads the live database, stays read-only, never claims the
 * older verifier passes, never claims to have verified the whole schema, and fails closed on real drift. Tests that change the schema
 * (DDL commits implicitly) put it back exactly in a finally block, like ManagedOrganizationMigrationTest.
 *
 * The test environment's queue is `sync`; the assessment requires the database queue the cron worker drains, so every test starts on it.
 */

beforeEach(function () {
    config(['queue.default' => 'database']);
    $this->target = raTargetDirectory();
});

afterEach(function () {
    raRemove($this->target);
});

/** A throwaway directory shaped like an unpacked Release 0.9 build: the three facts the assessment checks about the rollback target. */
function raTargetDirectory(string $commit = '32ec62f5c034b6394950203941e170d830b4669c'): string
{
    $declaration = RollbackCompatibility::declaration('0.9');
    $dir = sys_get_temp_dir().'/ra-target-'.bin2hex(random_bytes(6));
    mkdir($dir.'/database/profile', 0700, true);
    mkdir($dir.'/database/migrations', 0700, true);
    file_put_contents($dir.'/RELEASE', "release=20261022225141-32ec62f\ncommit={$commit}\n");
    copy(base_path($declaration['target']['foreign_key_manifest']), $dir.'/database/profile/foreign-keys.json');
    touch($dir.'/database/migrations/'.$declaration['target']['last_migration'].'.php');

    return $dir;
}

function raRemove(string $dir): void
{
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($dir);
}

/** @return array<string, mixed> */
function raAssess(string $target, array $options = []): array
{
    Artisan::call('database:rollback-assessment', ['--target-dir' => $target, '--json' => true, ...$options]);

    return json_decode(Artisan::output(), true);
}

function raJob(string $display, ?string $command = null, ?int $reservedAt = null): void
{
    DB::table('jobs')->insert([
        'queue' => 'default',
        'payload' => json_encode(['displayName' => $display, 'job' => 'Illuminate\Queue\CallQueuedHandler@call', 'data' => ['commandName' => $command ?? 'Illuminate\Notifications\SendQueuedNotifications', 'command' => 'x']]),
        'attempts' => 0, 'reserved_at' => $reservedAt, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp,
    ]);
}

const RA_EXPECTED = [
    'unexpected foreign key: organization_members.admin_approved_by->users.id (RESTRICT/RESTRICT)',
    'unexpected foreign key: organizations.managed_by_organization_id->organizations.id (RESTRICT/RESTRICT)',
    'unexpected foreign key: ticket_due_date_changes.ticket_id,reply_id->ticket_replies.ticket_id,id (CASCADE/RESTRICT)',
    'unexpected foreign key: ticket_due_date_changes.ticket_id->tickets.id (CASCADE/RESTRICT)',
    'unexpected foreign key: ticket_due_date_changes.user_id->users.id (SET NULL/RESTRICT)',
];

it('passes on the migrated schema, predicts the five lines the Release 0.9 verifier will print, and claims neither that verifier passes nor a whole-schema verification', function () {
    $this->artisan("database:rollback-assessment --target-dir={$this->target}")
        ->expectsOutputToContain('Assessment passed for release 0.9 (32ec62f5c034b6394950203941e170d830b4669c)')
        ->expectsOutputToContain('a narrow contract, not a verification of the whole schema')
        ->expectsOutputToContain('is EXPECTED to report exactly these 5 line(s)')
        ->expectsOutputToContain(RA_EXPECTED[0])
        ->expectsOutputToContain(RA_EXPECTED[1])
        ->expectsOutputToContain('it will still exit non-zero')
        ->assertSuccessful();
});

it('prints only the expected lines with --expected, ready to be compared exactly with the older verifier output', function () {
    Artisan::call('database:rollback-assessment', ['--expected' => true, '--target-dir' => $this->target]);

    expect(trim(Artisan::output()))->toBe(implode("\n", RA_EXPECTED));
});

it('reports the facts as JSON, including the relationship counts, and is read-only', function () {
    User::factory()->create(['disabled_at' => now()]);
    $partner = Organization::factory()->create();
    $managed = Organization::factory()->create();
    DB::table('organizations')->where('id', $partner->id)->update(['can_manage_customers' => 1]);
    DB::table('organizations')->where('id', $managed->id)->update(['managed_by_organization_id' => $partner->id]);

    $tables = ['users', 'activity_log', 'jobs', 'migrations', 'organizations', 'failed_jobs'];
    $count = fn () => array_map(fn (string $table) => DB::table($table)->count(), $tables);
    $before = $count();

    $report = raAssess($this->target);

    expect($report['passed'])->toBeTrue()
        ->and($report['problems'])->toBe([])
        ->and($report['target'])->toBe(['release' => '0.9', 'commit' => '32ec62f5c034b6394950203941e170d830b4669c'])
        ->and($report['expected_target_verifier_output'])->toBe(RA_EXPECTED)
        ->and($report['expected_sha256'])->toBe(hash('sha256', implode("\n", RA_EXPECTED)))
        ->and($report['facts'])->toBe(['ran_migrations' => DB::table('migrations')->count(), 'queue_connection' => 'database', 'queued_jobs' => 0, 'reserved_jobs' => 0, 'failed_jobs' => 0, 'managed_organizations' => 1, 'partner_organizations' => 1, 'approvals_complete' => 0, 'approvals_incomplete' => 0])
        ->and(implode("\n", $report['warnings']))->toContain('company deletion must stay frozen for the whole rollback window')
        ->and($report['note'])->toContain('does not make the target release')->and($report['note'])->toContain('does not verify every column')
        ->and($count())->toBe($before);
});

it('refuses the rollback while ANY administrator approval value is present, complete or half-written, on any membership, and passes once none is', function () {
    $organization = Organization::factory()->create();
    $operator = User::factory()->create();
    $member = User::factory()->create();
    $stamp = now()->startOfSecond();

    $scenarios = [
        'a complete approval on an administrator' => ['admin', $stamp, $operator->id, '1 complete, 0 incomplete'],
        'a complete approval left on a member row' => ['member', $stamp, $operator->id, '1 complete, 0 incomplete'],
        'an approval time without an approver' => ['admin', $stamp, null, '0 complete, 1 incomplete'],
        'an approver without an approval time' => ['member', null, $operator->id, '0 complete, 1 incomplete'],
    ];

    foreach ($scenarios as $name => [$role, $at, $by, $counts]) {
        DB::table('organization_members')->insert([
            'organization_id' => $organization->id, 'user_id' => $member->id, 'role' => $role,
            'admin_approved_at' => $at, 'admin_approved_by' => $by, 'created_at' => $stamp, 'updated_at' => $stamp,
        ]);

        $report = raAssess($this->target);
        expect($report['passed'])->toBeFalse($name)
            ->and(implode('
', $report['problems']))->toContain("rollback blocked: 1 organization membership(s) carry an administrator-approval value ({$counts})")
            ->and(implode('
', $report['problems']))->toContain("organization:user {$organization->id}:{$member->id}")
            ->and($report['expected_target_verifier_output'])->toBe([], "{$name}: nothing is predicted for a refused assessment")
            ->and($report['expected_sha256'])->toBeNull();

        // `--expected` fails and prints no lines to compare, so nothing can be read as clearance.
        $exit = Artisan::call('database:rollback-assessment', ['--expected' => true, '--target-dir' => $this->target]);
        expect($exit)->toBe(1)->and(Artisan::output())->not->toContain('unexpected foreign key');
        $this->artisan("database:rollback-assessment --target-dir={$this->target}")->expectsOutputToContain('rollback blocked: 1 organization membership(s)')->assertFailed();

        DB::table('organization_members')->where('user_id', $member->id)->delete();
        expect(raAssess($this->target)['passed'])->toBeTrue("{$name}: with the row gone the assessment passes again");
    }

    // The same with no row at all, and with a plain membership that carries no approval value: both pass.
    DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'admin', 'created_at' => $stamp, 'updated_at' => $stamp]);
    $clean = raAssess($this->target);
    expect($clean['passed'])->toBeTrue()->and($clean['facts']['approvals_complete'])->toBe(0)->and($clean['facts']['approvals_incomplete'])->toBe(0);
});

it('passes again after the operator revokes the approval through the service: the documented way out', function () {
    $this->seedRolesAndPermissions();
    $operator = User::factory()->create()->assignRole('operator');
    $admin = User::factory()->create()->assignRole('user');
    $organization = Organization::factory()->create(['owner_id' => $operator->id]);
    $organization->members()->attach($admin, ['role' => 'admin']);

    app(OrganizationMembership::class)->approve($organization, $admin, $operator);

    $blocked = raAssess($this->target);
    expect($blocked['passed'])->toBeFalse()->and($blocked['facts']['approvals_complete'])->toBe(1);

    app(OrganizationMembership::class)->revokeApproval($organization, $admin, $operator, confirmNoAdministrator: true);

    $cleared = raAssess($this->target);
    expect($cleared['passed'])->toBeTrue()->and($cleared['facts']['approvals_complete'])->toBe(0)
        ->and($cleared['expected_target_verifier_output'])->toBe(RA_EXPECTED);
});

it('fails closed without the rollback target directory, and on a directory that is not the reviewed release', function () {
    $this->artisan('database:rollback-assessment')->expectsOutputToContain('--target-dir was not given')->assertFailed();

    $other = raTargetDirectory('8f5fe5a000000000000000000000000000000000');

    try {
        $this->artisan("database:rollback-assessment --target-dir={$other}")
            ->expectsOutputToContain('does not record the reviewed release 0.9 commit 32ec62f5c034b6394950203941e170d830b4669c')
            ->assertFailed();
    } finally {
        raRemove($other);
    }
});

it('refuses a queued job of a class the older release does not have, and accepts the classes it does', function () {
    raJob('App\Notifications\TicketRepliedNotification');
    $this->artisan("database:rollback-assessment --target-dir={$this->target}")->assertSuccessful();

    raJob('App\Notifications\FutureInvitationNotification');
    raJob('App\Notifications\FutureInvitationNotification');
    $this->artisan("database:rollback-assessment --target-dir={$this->target}")
        ->expectsOutputToContain('2 queued job(s) of App\Notifications\FutureInvitationNotification are waiting, and release 0.9 has no such class')
        ->assertFailed();
});

it('prints nothing it predicts with --expected when the assessment fails, and says why', function () {
    raJob('App\Notifications\FutureInvitationNotification');

    $exit = Artisan::call('database:rollback-assessment', ['--expected' => true, '--target-dir' => $this->target]);
    $output = Artisan::output();

    expect($exit)->toBe(1);
    expect($output)->toContain('queued job(s) of App\Notifications\FutureInvitationNotification are waiting');
    expect($output)->not->toContain('unexpected foreign key');
    expect($output)->not->toContain('(RESTRICT/RESTRICT)');
});

it('does not trust a known display name over the command it runs, and refuses a framework-queued closure or listener', function () {
    raJob('App\Jobs\RecordRuntimeObservation', 'App\Jobs\FutureJob');
    raJob('App\Listeners\Future@handle', 'Illuminate\Events\CallQueuedListener');

    $report = raAssess($this->target);

    expect($report['passed'])->toBeFalse()
        ->and(implode("\n", $report['problems']))->toContain('App\Jobs\RecordRuntimeObservation (command App\Jobs\FutureJob)')
        ->and(implode("\n", $report['problems']))->toContain('App\Listeners\Future@handle (command Illuminate\Events\CallQueuedListener)');
});

it('refuses a job that is being processed, and a queue that is not the database queue', function () {
    raJob('App\Notifications\TicketRepliedNotification', null, now()->timestamp);

    expect(implode("\n", raAssess($this->target)['problems']))->toContain('1 job(s) are reserved');

    DB::table('jobs')->delete();
    config(['queue.default' => 'redis']);
    $problems = implode("\n", raAssess($this->target)['problems']);

    expect($problems)->toContain("default queue connection is 'redis'");
});

it('reports failed jobs of an unknown class as ones that must never be retried, without blocking the rollback', function () {
    $failed = fn (string $payload) => DB::table('failed_jobs')->insert(['uuid' => (string) Str::uuid(), 'connection' => 'database', 'queue' => 'default', 'payload' => $payload, 'exception' => 'x', 'failed_at' => now()]);
    $failed(json_encode(['displayName' => 'App\Notifications\FutureInvitationNotification', 'data' => ['commandName' => 'Illuminate\Notifications\SendQueuedNotifications']]));
    $failed('not json');
    $failed(json_encode(['displayName' => 'App\Notifications\TicketStatusNotification', 'data' => ['commandName' => 'Illuminate\Notifications\SendQueuedNotifications']]));

    $report = raAssess($this->target);

    expect($report['passed'])->toBeTrue()
        ->and($report['facts']['failed_jobs'])->toBe(3)
        ->and(implode("\n", $report['warnings']))->toContain('1 x (unreadable payload)')
        ->and(implode("\n", $report['warnings']))->toContain('1 x App\Notifications\FutureInvitationNotification')
        ->and(implode("\n", $report['warnings']))->toContain('Do not run queue:retry for them (nor queue:retry all)')
        ->and(implode("\n", $report['warnings']))->not->toContain('TicketStatusNotification');
});

it('fails closed on an unknown target and on an unreadable payload', function () {
    $this->artisan("database:rollback-assessment --target=9.9 --target-dir={$this->target}")->expectsOutputToContain('could not be assessed')->assertFailed();
    $this->artisan("database:rollback-assessment --target=../0.9 --target-dir={$this->target}")->expectsOutputToContain('could not be assessed')->assertFailed();

    DB::table('jobs')->insert(['queue' => 'default', 'payload' => 'not json', 'attempts' => 0, 'reserved_at' => null, 'available_at' => now()->timestamp, 'created_at' => now()->timestamp]);
    $this->artisan("database:rollback-assessment --target-dir={$this->target}")->expectsOutputToContain('(unreadable payload)')->assertFailed();
});

it('refuses a pending migration and a migration row this build does not know', function () {
    $last = DB::table('migrations')->orderByDesc('id')->first();

    DB::table('migrations')->where('id', $last->id)->delete();
    $problems = implode("\n", raAssess($this->target)['problems']);

    expect($problems)->toContain("migration {$last->migration} is pending")
        ->and($problems)->toContain("this build's database verifier reports: 1 migration(s) are pending");

    DB::table('migrations')->insert(['migration' => $last->migration, 'batch' => $last->batch]);
    expect(raAssess($this->target)['passed'])->toBeTrue();

    DB::table('migrations')->insert(['migration' => '2026_12_01_000000_unknown_to_this_build', 'batch' => $last->batch]);
    expect(implode("\n", raAssess($this->target)['problems']))->toContain('2026_12_01_000000_unknown_to_this_build, which is not a migration file of this build');
});

it('rejects real schema drift: an extra foreign key, and a missing reviewed one', function () {
    try {
        DB::statement('create table rollback_probe (id bigint unsigned primary key, user_id bigint unsigned not null, constraint rollback_probe_user_foreign foreign key (user_id) references users (id) on delete cascade) engine = InnoDB default charset = utf8mb4 collate = utf8mb4_unicode_ci');

        $this->artisan("database:rollback-assessment --target-dir={$this->target}")
            ->expectsOutputToContain("the live database is not this build's reviewed schema: unexpected foreign key: rollback_probe.user_id->users.id (CASCADE/RESTRICT)")
            ->expectsOutputToContain('would also report an unreviewed line: unexpected foreign key: rollback_probe.user_id->users.id')
            ->assertFailed();
    } finally {
        DB::statement('drop table if exists rollback_probe');
    }

    try {
        DB::statement('alter table ticket_due_date_changes drop foreign key ticket_due_date_changes_user_id_foreign');

        $this->artisan("database:rollback-assessment --target-dir={$this->target}")
            ->expectsOutputToContain('foreign key missing: ticket_due_date_changes.user_id->users.id (SET NULL/RESTRICT)')
            ->assertFailed();
    } finally {
        if (collect(Schema::getForeignKeys('ticket_due_date_changes'))->doesntContain(fn ($fk) => $fk['name'] === 'ticket_due_date_changes_user_id_foreign')) {
            DB::statement('alter table ticket_due_date_changes add constraint ticket_due_date_changes_user_id_foreign foreign key (user_id) references users (id) on delete set null');
        }
    }

    $this->artisan("database:rollback-assessment --target-dir={$this->target}")->assertSuccessful();
});

it('rejects a declared column that became NOT NULL and a CHECK constraint that nobody reviewed', function () {
    try {
        DB::statement('alter table tickets modify due_override_at timestamp not null');

        $problems = implode("\n", raAssess($this->target)['problems']);

        expect($problems)->toContain('column tickets.due_override_at is NOT NULL with no default in the live database but the declaration says nullable');
    } finally {
        DB::statement('alter table tickets modify due_override_at timestamp null default null');
    }

    try {
        DB::statement('alter table tickets add constraint rc_probe_check check (user_id > 0)');

        expect(implode("\n", raAssess($this->target)['problems']))->toContain('unreviewed CHECK constraint tickets.rc_probe_check');
    } finally {
        DB::statement('alter table tickets drop constraint if exists rc_probe_check');
    }

    try {
        DB::statement('alter table organizations drop constraint organizations_partner_not_managed_check');

        expect(implode("\n", raAssess($this->target)['problems']))->toContain('CHECK constraint organizations.organizations_partner_not_managed_check is expected but absent');
    } finally {
        $checks = collect(DB::select("select constraint_name from information_schema.table_constraints where constraint_schema = database() and table_name = 'organizations' and constraint_type = 'CHECK'"))->pluck('constraint_name');
        if ($checks->doesntContain('organizations_partner_not_managed_check')) {
            DB::statement('alter table organizations add constraint organizations_partner_not_managed_check check (can_manage_customers = 0 or managed_by_organization_id is null)');
        }
    }

    expect(raAssess($this->target)['passed'])->toBeTrue();
});
