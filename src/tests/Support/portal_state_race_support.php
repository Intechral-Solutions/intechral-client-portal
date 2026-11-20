<?php

/*
 * Release 1.0 (ORG-1): the harness for OrganizationPortalStateConcurrencyTest. The METHOD is R1-3A's (OrganizationInvitationConcurrencyTest, whose own file is
 * left exactly as reviewed, which is why these helpers are a separate, prefixed copy rather than a move): two independent PHP processes, two real MariaDB
 * connections; worker FIRST runs one operation and FREEZES after a named statement has executed (its locks are held, its transaction open); the test starts
 * worker SECOND, which announces just before it sends its conflicting statement; the test checks it is STILL RUNNING (waiting on a lock, with a 15 second
 * ceiling so a hang is an error and not a pass), then releases FIRST. FIRST commits, SECOND proceeds on the committed state. Every pair runs in BOTH orders.
 *
 * Loaded with require_once; the file name does not end in "Test" so Pest does not run it. Function names carry the `psr` prefix (Pest loads every test file into
 * one process). The worker is tests/Support/organization_invitation_worker.php, extended in ORG-1 with the lifecycle operations.
 */

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Services\OrganizationRelationships;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Process\Process;

require_once __DIR__.'/committed_installation.php';

// The statements each side freezes after (as FIRST) or announces before (as SECOND). Regexes over the SQL text the worker saw.
const PSR_USER_SHARE = '/from `users` where `id` = \? limit 1 lock in share mode/';
const PSR_USER_EXCL = '/from `users` where `users`\.`id` = \? limit 1 for update/';
const PSR_ORG_EXCL = '/from `organizations` where `id` = \? limit 1 for update/';
const PSR_ORGS_ASC = '/from `organizations` where `id` in \(.*\) order by `id` asc for update/';
const PSR_INV_SWEEP = '/from `organization_invitations` where `id` in \(.*\) order by `id` asc for update/';

/** @return array<string, string> */
function psrWorkerEnv(array $extra = []): array
{
    $connection = config('database.connections.'.config('database.default'));

    return array_merge([
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => config('database.default'),
        'DB_HOST' => $connection['host'],
        'DB_PORT' => $connection['port'],
        'DB_DATABASE' => $connection['database'],
        'DB_USERNAME' => $connection['username'],
        'DB_PASSWORD' => $connection['password'],
        'DB_URL' => '',
        'CACHE_STORE' => 'array',
        'SESSION_DRIVER' => 'database',
        'QUEUE_CONNECTION' => 'database',
        'MAIL_MAILER' => 'array',
        'BCRYPT_ROUNDS' => '4',
    ], $extra);
}

function psrStart(array $operation, array $env = []): Process
{
    $process = new Process([PHP_BINARY, base_path('tests/Support/organization_invitation_worker.php'), json_encode($operation)], base_path(), psrWorkerEnv($env));
    $process->setTimeout(90);
    $process->start();

    return $process;
}

/** @return array<string, mixed> */
function psrResult(Process $process): array
{
    $process->wait();
    $decoded = json_decode(trim($process->getOutput()), true);

    return is_array($decoded) ? $decoded : ['outcome' => 'worker failed: '.$process->getErrorOutput().$process->getOutput(), 'statements' => []];
}

/**
 * Laravel RETRIES a transaction that was a deadlock victim, so a correct final outcome can hide one. Every operation here is exactly one top-level
 * transaction: more than one beginning means it was aborted and re-run, and a rolled-back transaction behind an `ok` outcome means the same.
 *
 * @param  array<string, mixed>  $result
 */
function psrAssertNoVictim(string $which, array $result): void
{
    expect($result['transactions'] ?? 0)->toBeLessThanOrEqual(1, "the {$which} worker's transaction began ".($result['transactions'] ?? '?').' times: it was a deadlock or lock-wait victim and was silently retried');

    if (($result['outcome'] ?? '') === 'ok') {
        expect($result['rollbacks'] ?? 0)->toBe(0, "the {$which} worker reported ok but rolled a transaction back");
    }

    expect($result['outcome'])->not->toContain('1213', "the {$which} worker was a deadlock victim")
        ->not->toContain('1205', "the {$which} worker hit a lock wait timeout")
        ->not->toContain('Deadlock', "the {$which} worker was a deadlock victim")
        ->not->toContain('error:', "the {$which} worker failed unexpectedly: ".($result['detail'] ?? ''))
        ->not->toContain('worker failed');
}

/**
 * Run two operations as two real connections: $first frozen after $freezeAfter, $second announcing before $blockAt. Returns both results.
 *
 * @param  array<string, mixed>  $first
 * @param  array<string, mixed>  $second
 * @return array{first: array<string, mixed>, second: array<string, mixed>, blocked: bool}
 */
function psrInterleave(array $first, string $freezeAfter, array $second, string $blockAt): array
{
    $dir = sys_get_temp_dir().'/psr-'.bin2hex(random_bytes(6));
    mkdir($dir);

    try {
        $a = psrStart($first + ['hold' => ['pattern' => $freezeAfter, 'dir' => $dir, 'tag' => 'first']]);

        $deadline = microtime(true) + 40;
        while (! is_file($dir.'/first.held') && microtime(true) < $deadline && $a->isRunning()) {
            usleep(10_000);
        }
        if (! is_file($dir.'/first.held')) {
            $a->stop(2);
            throw new RuntimeException('the first worker never reached its freeze point: '.$a->getOutput().$a->getErrorOutput());
        }

        $b = psrStart($second + ['signal' => ['pattern' => $blockAt, 'dir' => $dir, 'tag' => 'second']]);

        $startedAt = microtime(true);
        while (! is_file($dir.'/second.requesting') && $b->isRunning() && microtime(true) - $startedAt < 6.0) {
            usleep(10_000);
        }

        usleep(1_200_000);
        $blocked = $b->isRunning();

        $releasedAt = microtime(true);
        touch($dir.'/first.release');

        $firstResult = psrResult($a);
        $secondResult = psrResult($b);
    } finally {
        array_map('unlink', glob($dir.'/*') ?: []);
        @rmdir($dir);
    }

    expect($blocked)->toBeTrue('the second transaction was not waiting on the first at its conflicting statement');
    expect($secondResult['finishedAt'])->toBeGreaterThan($releasedAt, 'the second finished before the first was released');

    foreach (['first' => $firstResult, 'second' => $secondResult] as $which => $result) {
        psrAssertNoVictim($which, $result);
    }

    return ['first' => $firstResult, 'second' => $secondResult, 'blocked' => $blocked];
}

/**
 * A committed synchronized installation, a handful of accounts, and the Organizations the races need. Everything is removed by psrTeardown().
 *
 * @return array<string, mixed>
 */
function psrFixture(): array
{
    $cleanup = committedSynchronizedInstallation();
    $make = fn (string $name): User => User::factory()->create(['email' => $name.'@psr.test', 'name' => ucfirst($name), 'password' => 'x']);

    $admin = $make('admin');
    $admin->assignRole('operator'); // users.manage: the actor of suspensions and role changes, and a built-in operator
    $actor = $make('actor');
    $actor->givePermissionTo(['portal.operator', 'crm.manage']);   // the operator who disables
    $issuer = $make('issuer');
    $issuer->givePermissionTo(['portal.operator', 'crm.manage']);
    $other = $make('other');
    $other->givePermissionTo(['portal.operator', 'crm.manage']);   // the second operator of a pair

    $role = Role::create(['name' => 'psr-directory', 'guard_name' => 'web']);
    $role->givePermissionTo(['portal.operator', 'crm.manage']);
    $r1 = $make('r1');
    $r1->assignRole($role);
    $r2 = $make('r2');
    $r2->assignRole($role);

    $member = $make('member');
    $member->assignRole('user');
    $madmin = $make('madmin');
    $madmin->assignRole('user');
    $newcomer = $make('newcomer');
    $newcomer->assignRole('user');
    $pmember = $make('pmember');
    $pmember->assignRole('user');

    $org = Organization::factory()->create(['name' => 'Race Org', 'portal_enabled_by' => $admin->id]);
    $partner = Organization::factory()->create(['name' => 'Race Partner', 'portal_enabled_by' => $admin->id]);
    $cust = Organization::factory()->create(['name' => 'Race Customer', 'portal_enabled_by' => $admin->id]);
    $org2 = Organization::factory()->create(['name' => 'Race Candidate', 'portal_enabled_by' => $admin->id]);
    $companies = [];
    foreach ([$partner, $cust, $org2] as $paired) {
        $companies[] = CrmCompany::factory()->create(['name' => $paired->name, 'organization_id' => $paired->id, 'created_by' => $admin->id])->id;
    }

    $stamp = ['created_at' => now(), 'updated_at' => now()];
    DB::table('organization_members')->insert([
        ['organization_id' => $org->id, 'user_id' => $member->id, 'role' => 'member'] + $stamp,
        ['organization_id' => $org->id, 'user_id' => $madmin->id, 'role' => 'admin'] + $stamp,
        ['organization_id' => $partner->id, 'user_id' => $pmember->id, 'role' => 'member'] + $stamp,
    ]);

    // The partner is designated through the service, as in production (it needs its single company, created above).
    app(OrganizationRelationships::class)->designatePartner($partner, $admin);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return compact('cleanup', 'admin', 'actor', 'issuer', 'other', 'role', 'r1', 'r2', 'member', 'madmin', 'newcomer', 'pmember', 'org', 'partner', 'cust', 'org2', 'companies')
        + ['orgIds' => [$org->id, $partner->id, $cust->id, $org2->id]];
}

function psrTeardown(array $f): void
{
    DB::table('organization_invitations')->delete();
    DB::table('organizations')->whereIn('id', $f['orgIds'])->update(['managed_by_organization_id' => null]);
    DB::table('organization_members')->whereIn('organization_id', $f['orgIds'])->delete();
    DB::table('crm_companies')->whereIn('id', $f['companies'])->delete();
    DB::table('activity_log')->delete();
    DB::table('jobs')->delete();
    DB::table('sessions')->delete();
    DB::table('organizations')->whereIn('id', $f['orgIds'])->delete();

    $ids = DB::table('users')->where('email', 'like', '%@psr.test')->pluck('id')->all();
    DB::table('model_has_roles')->whereIn('model_id', $ids)->delete();
    DB::table('model_has_permissions')->whereIn('model_id', $ids)->delete();
    $f['cleanup']($ids);
}

/** Write an open invitation (committed) and return [id, token]. @return array{0: int, 1: string} */
function psrInvite(Organization $org, User $issuer, string $email, array $state = []): array
{
    $token = Str::random(64);
    $row = OrganizationInvitation::factory()->withToken($token)->forAddress($email)->state(['organization_id' => $org->id, 'invited_by' => $issuer->id] + $state)->create();

    return [$row->id, $token];
}

function psrInvitation(int $id): ?object
{
    return DB::table('organization_invitations')->find($id);
}

function psrOrganization(int $id): object
{
    return DB::table('organizations')->where('id', $id)->first();
}

/**
 * The locking statements a worker ran inside its transaction, in order, as 'S users' / 'X organizations' / 'X organization_invitations', with every write
 * marked 'W <table>' in its place, so a lock taken AFTER a write shows up as one.
 *
 * @return list<string>
 */
function psrLocks(array $result): array
{
    return collect($result['statements'])->filter(fn ($s) => $s['transactional'])->map(function ($s) {
        $table = preg_match('/(?:from|into|update)\s+`(\w+)`/i', $s['sql'], $m) === 1 ? $m[1] : '?';

        return match (true) {
            str_contains($s['sql'], 'lock in share mode') => 'S '.$table,
            str_contains($s['sql'], 'for update') => 'X '.$table,
            preg_match('/^\s*(insert|update|delete)\b/i', $s['sql']) === 1 => 'W '.$table,
            default => null,
        };
    })->filter()->values()->all();
}
