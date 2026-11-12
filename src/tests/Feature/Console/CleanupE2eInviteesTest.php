<?php

/*
 * Release 1.0 (R1-3C) — `e2e:cleanup-invitees`, the narrow cleanup of what the browser suite's invitation journey creates.
 *
 * The command deletes accounts, so these tests pin what it must NOT touch as hard as what it removes: any account that is not on the
 * reserved address, that was not created by an accepted invitation into one of the suite's organizations, or that holds anything beyond
 * the built-in `user` role (a ticket, a project membership, a permission, an extra organization, operator eligibility). Such an account
 * is REFUSED (reported, exit non-zero, nothing of it deleted), never removed to be helpful. Every account is created the way the
 * browser suite creates it: a real invitation, really accepted through the service. The isolated testing database (RefreshDatabase) is
 * the only database these tests can reach.
 */

use App\Console\Commands\CleanupE2eInvitees;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\InvitationAcceptance;
use App\Services\OrganizationInvitations;
use App\Support\InvitationIssueThrottle;
use Database\Seeders\DevSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Auth/InvitationTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = regInvIssuer();
    $this->suite = Organization::factory()->create(['slug' => CleanupE2eInvitees::ORGANIZATION_SLUGS[0], 'name' => 'E2E Invitations Org', 'owner_id' => $this->operator->id]);
    $this->other = Organization::factory()->create(['slug' => 'acme-real-customer', 'name' => 'Acme', 'owner_id' => $this->operator->id]);
});

/** @return PendingCommand */
function inviteeCleanup()
{
    return test()->artisan('e2e:cleanup-invitees');
}

/** An invitee exactly as the browser suite makes one: a real invitation into the organization, accepted with a password. */
function inviteeAccepted(string $email, ?Organization $organization = null, ?User $issuer = null): User
{
    $organization ??= test()->suite;
    [, $token] = regInvOpen($email, $organization, $issuer ?? test()->operator);

    return app(OrganizationInvitations::class)->accept($token, InvitationAcceptance::withPassword('E2E Invitee', Hash::make('Str0ng!Password99')));
}

function inviteeAddress(string $run = 'run1'): string
{
    return "e2e-invitee+{$run}@example.test";
}

/** Every row the cleanup could delete or must not, as a comparable snapshot. */
function inviteeState(): array
{
    return [
        'users' => DB::table('users')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'members' => DB::table('organization_members')->orderBy('organization_id')->orderBy('user_id')->get()->map(fn ($row) => (array) $row)->all(),
        'invitations' => DB::table('organization_invitations')->orderBy('id')->get()->map(fn ($row) => (array) $row)->all(),
        'roles' => DB::table('model_has_roles')->orderBy('model_id')->get()->map(fn ($row) => (array) $row)->all(),
        'histories' => DB::table('password_histories')->count(),
        'tickets' => DB::table('tickets')->count(),
        'activity' => DB::table('activity_log')->count(),
        'organizations' => DB::table('organizations')->count(),
    ];
}

// ── Environment ──────────────────────────────────────────────────────────────

it('refuses production before reading or changing anything', function () {
    inviteeAccepted(inviteeAddress());
    $before = inviteeState();
    app()->detectEnvironment(fn () => 'production');
    DB::flushQueryLog();
    DB::enableQueryLog();

    inviteeCleanup()->assertFailed()->expectsOutputToContain('Refusing to run in the "production" environment');

    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    app()->detectEnvironment(fn () => 'testing');
    expect(inviteeState())->toBe($before);
});

it('refuses an environment outside local and testing', function (string $environment) {
    inviteeAccepted(inviteeAddress());
    $before = inviteeState();
    app()->detectEnvironment(fn () => $environment);

    inviteeCleanup()->assertFailed()->expectsOutputToContain("Refusing to run in the \"{$environment}\" environment");

    app()->detectEnvironment(fn () => 'testing');
    expect(inviteeState())->toBe($before);
})->with(['staging', 'prod', 'development', 'ci']);

it('runs in both permitted environments', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    inviteeCleanup()->assertSuccessful();
})->with(['local', 'testing']);

it('refuses when the configured environment is not local or testing, whatever the runtime environment says', function (string $configured) {
    inviteeAccepted(inviteeAddress());
    $before = inviteeState();
    config(['app.env' => $configured]);
    app()->detectEnvironment(fn () => 'local');
    DB::flushQueryLog();
    DB::enableQueryLog();

    inviteeCleanup()->assertFailed()->expectsOutputToContain("Refusing to run in the \"{$configured}\" environment");

    expect(DB::getQueryLog())->toBe([]);
    DB::disableQueryLog();
    config(['app.env' => 'testing']);
    app()->detectEnvironment(fn () => 'testing');
    expect(inviteeState())->toBe($before);
})->with(['production', 'staging']);

/** @return array{0: int, 1: string} `php artisan e2e:cleanup-invitees` in a child process whose database host is unreachable (a refusal comes before any query) */
function inviteeCleanupProcess(string $appEnv, array $arguments = []): array
{
    $process = new Process([PHP_BINARY, 'artisan', 'e2e:cleanup-invitees', '--no-interaction', ...$arguments], base_path(), [
        'APP_ENV' => $appEnv, 'DB_CONNECTION' => 'mysql', 'DB_HOST' => '127.0.0.1', 'DB_PORT' => '1', 'DB_URL' => '',
        'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array', 'QUEUE_CONNECTION' => 'sync',
    ]);
    $process->setTimeout(60);
    $process->run();

    return [$process->getExitCode(), $process->getOutput().$process->getErrorOutput()];
}

it('is not authorised by the global --env option: APP_ENV=production with --env=local still refuses, before any query', function () {
    [$code, $output] = inviteeCleanupProcess('production', ['--env=local']);

    expect($code)->toBe(1)->and($output)->toContain('Refusing to run in the "production" environment')
        ->and($output)->not->toContain('SQLSTATE')->and($output)->not->toContain('Eligible E2E invitee');
});

it('does reach the database when the configured environment is local (the control for the --env test)', function () {
    [$code, $output] = inviteeCleanupProcess('local');

    expect($code)->not->toBe(0)->and($output)->not->toContain('Refusing to run')->and($output)->toContain('SQLSTATE');
});

it('declares no argument and no option, so nothing can select an account, an address, an organization or an id', function () {
    $definition = Artisan::all()['e2e:cleanup-invitees']->getDefinition();

    expect($definition->getArguments())->toBe([])->and($definition->getOptions())->toBe([]);

    $output = new BufferedOutput;
    Artisan::call('e2e:cleanup-invitees', [], $output);
    expect($output->fetch())->not->toContain('@example.test');
});

// ── What it removes ──────────────────────────────────────────────────────────

it('removes an invitee and everything that account created, in dependency order, and nothing else', function () {
    $stranger = User::factory()->create(['email' => 'stranger@example.com'])->assignRole('user');
    $this->other->members()->attach($stranger->id, ['role' => 'member']);
    $kept = inviteeAccepted('real.person@example.com', $this->other);      // an ordinary invitation into a REAL organization
    $invitee = inviteeAccepted(inviteeAddress());
    DB::table('sessions')->insert(['id' => 'sess-'.Str::random(10), 'user_id' => $invitee->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'x', 'payload' => 'x', 'last_activity' => time()]);
    DB::table('social_accounts')->insert(['user_id' => $invitee->id, 'provider' => 'google', 'provider_id' => 'g-1', 'created_at' => now(), 'updated_at' => now()]);
    $activity = DB::table('activity_log')->count();

    expect(DB::table('password_histories')->where('user_id', $invitee->id)->exists())->toBeTrue();

    inviteeCleanup()->assertSuccessful()
        ->expectsOutputToContain('Eligible E2E invitee accounts: 1')
        ->expectsOutputToContain('Accounts deleted: 1')
        ->expectsOutputToContain('Memberships deleted: 1')
        ->expectsOutputToContain('Invitations deleted: 1')
        ->expectsOutputToContain('E2E invitee cleanup complete.');

    foreach (['users' => 'id', 'organization_members' => 'user_id', 'password_histories' => 'user_id', 'social_accounts' => 'user_id', 'sessions' => 'user_id', 'organization_invitations' => 'accepted_user_id'] as $table => $column) {
        expect(DB::table($table)->where($column, $invitee->id)->exists())->toBeFalse("{$table} still has the account's rows");
    }

    expect(DB::table('model_has_roles')->where('model_id', $invitee->id)->exists())->toBeFalse();

    // Everything else is untouched: the stranger, the real organization's member and invitation, both organizations, the operator.
    expect(User::whereKey([$stranger->id, $kept->id, $this->operator->id])->count())->toBe(3)
        ->and(DB::table('organization_members')->where('user_id', $kept->id)->where('organization_id', $this->other->id)->exists())->toBeTrue()
        ->and(DB::table('organization_invitations')->where('accepted_user_id', $kept->id)->exists())->toBeTrue()
        ->and(DB::table('organization_members')->where('user_id', $stranger->id)->exists())->toBeTrue()
        ->and(Organization::whereKey([$this->suite->id, $this->other->id])->count())->toBe(2)
        // The audit trail is never touched: its rows keep naming the removed account by id.
        ->and(DB::table('activity_log')->count())->toBe($activity);
});

it('removes pending, expired, revoked and superseded invitations on the reserved address in the suite organization, and no other', function () {
    $open = regInvOpen(inviteeAddress('a'), $this->suite, $this->operator)[0];
    $expired = regInvOpen(inviteeAddress('b'), $this->suite, $this->operator, ['expires_at' => now()->subDay()])[0];
    $revoked = regInvOpen(inviteeAddress('c'), $this->suite, $this->operator, ['live_email' => null, 'revoked_at' => now(), 'revoked_reason' => 'operator'])[0];
    $real = regInvOpen('someone@example.com', $this->suite, $this->operator)[0];           // not the reserved address
    $upper = regInvOpen('shout@example.com', $this->suite, $this->operator)[0];
    // Not lower case: the normalizer never writes this, so it is set directly. The collation matches it; the pattern does not.
    DB::table('organization_invitations')->where('id', $upper->id)->update(['email' => 'E2E-INVITEE+shout@EXAMPLE.TEST', 'live_email' => 'E2E-INVITEE+shout@EXAMPLE.TEST']);
    $wrongDomain = regInvOpen('e2e-invitee+x@example.com', $this->suite, $this->operator)[0];

    inviteeCleanup()->assertSuccessful()->expectsOutputToContain('Eligible E2E invitation addresses without an account: 3')->expectsOutputToContain('Invitations deleted: 3');

    expect(DB::table('organization_invitations')->whereIn('id', [$open->id, $expired->id, $revoked->id])->count())->toBe(0)
        ->and(DB::table('organization_invitations')->whereIn('id', [$real->id, $upper->id, $wrongDomain->id])->count())->toBe(3);
});

it('does not delete an invitation that was accepted after the run planned it and before the run locked it', function () {
    // Planned as a pending invitation on the reserved address (no account yet): eligible when the run begins.
    [$racing, $token] = regInvOpen(inviteeAddress('racing'), $this->suite, $this->operator);
    $accepted = (object) ['user' => null, 'fired' => 0];

    // The first statement that takes the organization row FOR UPDATE belongs to the invitation removal, which is the first thing the run
    // locks (there is no account to remove). Just before it runs, the recipient accepts the invitation, exactly as the service does it.
    DB::beforeExecuting(function (string $sql) use ($accepted, $token) {
        if ($accepted->fired === 0 && str_contains($sql, 'from `organizations`') && str_contains($sql, 'for update')) {
            $accepted->fired++;
            $accepted->user = app(OrganizationInvitations::class)->accept($token, InvitationAcceptance::withPassword('E2E Invitee', Hash::make('Str0ng!Password99')));
        }
    });

    inviteeCleanup()->assertSuccessful()->expectsOutputToContain('Eligible E2E invitation addresses without an account: 1');

    // The row was in the plan, and the lock-time check saw it accepted: it is NOT deleted, the account it created is untouched.
    expect($accepted->fired)->toBe(1)->and($accepted->user)->toBeInstanceOf(User::class);
    $row = DB::table('organization_invitations')->where('id', $racing->id)->first();
    expect($row)->not->toBeNull()->and($row->accepted_at)->not->toBeNull()->and((int) $row->accepted_user_id)->toBe($accepted->user->id)
        ->and(User::whereKey($accepted->user->id)->exists())->toBeTrue()
        ->and(DB::table('organization_members')->where('user_id', $accepted->user->id)->exists())->toBeTrue();

    // The next run sees an ordinary invitee (one accepted suite invitation) and removes the account with its invitation.
    inviteeCleanup()->assertSuccessful();
    expect(DB::table('organization_invitations')->where('id', $racing->id)->exists())->toBeFalse()
        ->and(User::whereKey($accepted->user->id)->exists())->toBeFalse();
});

it('clears the seeded E2E operator\'s issuance counter, and only that account\'s, so repeated local runs stay under the production limit', function () {
    $seeded = User::factory()->create(['email' => CleanupE2eInvitees::E2E_OPERATOR_ADDRESS]);
    $seeded->givePermissionTo(['portal.operator', 'crm.manage']);
    $bystander = regInvIssuer();
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $exhaust = function (User $actor) {
        for ($i = 0; $i < InvitationIssueThrottle::ATTEMPTS_PER_HOUR; $i++) {
            test()->actingAs($actor)->post(route('organizations.invitations.store', test()->suite), ['email' => 'not-an-address'])->assertRedirect();
        }
    };
    $exhaust($seeded);
    $exhaust($bystander);

    $this->actingAs($seeded)->post(route('organizations.invitations.store', $this->suite), ['email' => 'a@example.test'])->assertStatus(429);

    inviteeCleanup()->assertSuccessful()->expectsOutputToContain('Invitation issuance counter cleared for the seeded E2E operator.');

    // The seeded operator can issue again; another operator's counter is untouched; the production limit itself is unchanged.
    $this->actingAs($seeded)->post(route('organizations.invitations.store', $this->suite), ['email' => 'a@example.test'])->assertRedirect(route('organizations.show', $this->suite));
    $this->actingAs($bystander)->post(route('organizations.invitations.store', $this->suite), ['email' => 'b@example.test'])->assertStatus(429);
    expect(InvitationIssueThrottle::ATTEMPTS_PER_HOUR)->toBe(30);

    // Idempotent, and a missing seeded account is not an error.
    inviteeCleanup()->assertSuccessful();
    DB::table('users')->where('id', $seeded->id)->update(['email' => 'renamed@example.test']);
    inviteeCleanup()->assertSuccessful()->doesntExpectOutputToContain('counter cleared');
});

it('does not clear the counter in a refused environment', function () {
    $seeded = User::factory()->create(['email' => CleanupE2eInvitees::E2E_OPERATOR_ADDRESS]);
    $seeded->givePermissionTo(['portal.operator', 'crm.manage']);
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    for ($i = 0; $i < InvitationIssueThrottle::ATTEMPTS_PER_HOUR; $i++) {
        $this->actingAs($seeded)->post(route('organizations.invitations.store', $this->suite), ['email' => 'not-an-address'])->assertRedirect();
    }

    app()['env'] = 'production';
    config(['app.env' => 'production']);
    inviteeCleanup()->assertFailed();
    app()['env'] = 'testing';
    config(['app.env' => 'testing']);

    $this->actingAs($seeded)->post(route('organizations.invitations.store', $this->suite), ['email' => 'a@example.test'])->assertStatus(429);
});

it('is idempotent: a second run finds nothing and succeeds', function () {
    inviteeAccepted(inviteeAddress());
    regInvOpen(inviteeAddress('pending'), $this->suite, $this->operator);

    inviteeCleanup()->assertSuccessful()->expectsOutputToContain('Accounts deleted: 1');
    $after = inviteeState();

    inviteeCleanup()->assertSuccessful()
        ->expectsOutputToContain('Eligible E2E invitee accounts: 0')->expectsOutputToContain('Accounts deleted: 0')->expectsOutputToContain('Invitations deleted: 0');

    expect(inviteeState())->toBe($after);
});

it('is a no-op without the suite organization: nothing is eligible, so nothing is touched', function () {
    DB::table('organizations')->where('id', $this->suite->id)->update(['slug' => 'something-else']);
    inviteeAccepted(inviteeAddress());
    $before = inviteeState();

    // The account has the reserved address but its invitation is not into an E2E organization: ambiguous, so refused, not deleted.
    inviteeCleanup()->assertFailed()->expectsOutputToContain('Eligible E2E invitee accounts: 0')->expectsOutputToContain('REFUSED');

    expect(inviteeState())->toBe($before);
});

// ── What it refuses ──────────────────────────────────────────────────────────

/** Fixtures for each account an invitee must never be removed with, built after the real acceptance. */
function inviteeRefusalCases(): array
{
    return [
        'a ticket it raised' => fn (User $u, $t) => Ticket::factory()->create(['user_id' => $u->id, 'organization_id' => $t->suite->id, 'submitted_by_id' => $u->id]),
        'a project membership' => function (User $u, $t) {
            $project = Project::factory()->create(['created_by' => $t->operator->id]);
            DB::table('project_members')->insert(['project_id' => $project->id, 'user_id' => $u->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);
        },
        'a role other than the built-in user role' => fn (User $u, $t) => $u->assignRole('operator'),
        'a custom role' => function (User $u, $t) {
            $u->assignRole(Role::create(['name' => 'e2e-extra', 'guard_name' => 'web']));
        },
        'a direct permission' => fn (User $u, $t) => $u->givePermissionTo('projects.manage'),
        'operator eligibility through the user role itself' => fn (User $u, $t) => Role::findByName('user')->givePermissionTo('portal.operator'),
        'a membership of a real organization' => fn (User $u, $t) => $t->other->members()->attach($u->id, ['role' => 'member']),
        'an invitation it issued' => function (User $u, $t) {
            // `organization_invitations.invited_by` references the account: the cleanup never deletes another account's invitations.
            regInvOpen('someone-else@example.com', $t->suite, $u);
        },
    ];
}

it('refuses an account that has anything beyond the invitee shape, deleting nothing of it, and still removes the others', function (string $case) {
    $refused = inviteeAccepted(inviteeAddress('refused'));
    $fine = inviteeAccepted(inviteeAddress('fine'));
    (inviteeRefusalCases()[$case])($refused, test());
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $before = [
        'user' => (array) DB::table('users')->find($refused->id),
        'members' => DB::table('organization_members')->where('user_id', $refused->id)->count(),
        'invitation' => (array) DB::table('organization_invitations')->where('accepted_user_id', $refused->id)->first(),
        'histories' => DB::table('password_histories')->where('user_id', $refused->id)->count(),
        'roles' => DB::table('model_has_roles')->where('model_id', $refused->id)->count(),
    ];

    // Making the built-in `user` role itself an operator role turns EVERY invitee into one, so both accounts are refused.
    $bothRefused = $case === 'operator eligibility through the user role itself';

    inviteeCleanup()->assertFailed()->expectsOutputToContain("account #{$refused->id}:")->expectsOutputToContain('REFUSED or NOT removed')
        ->expectsOutputToContain('Accounts deleted: '.($bothRefused ? '0' : '1'));

    expect((array) DB::table('users')->find($refused->id))->toBe($before['user'])
        ->and(DB::table('organization_members')->where('user_id', $refused->id)->count())->toBe($before['members'])
        ->and((array) DB::table('organization_invitations')->where('accepted_user_id', $refused->id)->first())->toBe($before['invitation'])
        ->and(DB::table('password_histories')->where('user_id', $refused->id)->count())->toBe($before['histories'])
        ->and(DB::table('model_has_roles')->where('model_id', $refused->id)->count())->toBe($before['roles'])
        // The other, ordinary invitee is gone: one refusal does not stop the run.
        ->and(User::whereKey($fine->id)->exists())->toBe($bothRefused);
})->with(array_keys(inviteeRefusalCases()));

it('refuses an account on the reserved address that no accepted suite invitation created, and one whose address only looks reserved', function () {
    $operatorLike = User::factory()->create(['email' => inviteeAddress('operator')]);
    $operatorLike->givePermissionTo(['portal.operator', 'crm.manage']);
    $plain = User::factory()->create(['email' => inviteeAddress('plain')])->assignRole('user');
    $upper = User::factory()->create(['email' => 'E2E-Invitee+Upper@Example.test'])->assignRole('user');
    $wrongDomain = User::factory()->create(['email' => 'e2e-invitee+run@example.org'])->assignRole('user');
    $before = inviteeState();

    inviteeCleanup()->assertFailed()->expectsOutputToContain("account #{$operatorLike->id}:")->expectsOutputToContain("account #{$plain->id}:")
        ->expectsOutputToContain('Accounts deleted: 0');

    // The two whose address is not the reserved one are not even candidates (and are not reported); nothing at all changed.
    expect(inviteeState())->toBe($before)->and(User::whereKey([$upper->id, $wrongDomain->id])->count())->toBe(2);
});

it('refuses a pending invitation on the reserved address in an organization that is not an E2E organization', function () {
    [$stray] = regInvOpen(inviteeAddress('stray'), $this->other, $this->operator);
    $before = inviteeState();

    inviteeCleanup()->assertFailed()->expectsOutputToContain("invitation #{$stray->id}: it has the reserved address but belongs to an organization that is not an E2E organization");

    expect(inviteeState())->toBe($before);
});

it('refuses an invitation accepted into the suite organization by an account that is not on the reserved address', function () {
    $person = inviteeAccepted('real.person@example.com');
    DB::table('organization_invitations')->where('accepted_user_id', $person->id)->update(['email' => inviteeAddress('mismatch')]);
    $before = inviteeState();

    inviteeCleanup()->assertFailed()->expectsOutputToContain('whose address is not the reserved one');

    expect(inviteeState())->toBe($before);
});

it('discovers the tables that reference an account from the schema, so a table added later refuses it', function () {
    $invitee = inviteeAccepted(inviteeAddress());
    DB::statement('create table e2e_future_notes (id bigint unsigned auto_increment primary key, author_id bigint unsigned not null, constraint e2e_future_notes_author foreign key (author_id) references users (id))');

    try {
        DB::table('e2e_future_notes')->insert(['author_id' => $invitee->id]);

        inviteeCleanup()->assertFailed()->expectsOutputToContain('1 row(s) of e2e_future_notes.author_id reference it');

        expect(User::whereKey($invitee->id)->exists())->toBeTrue();
    } finally {
        DB::statement('drop table e2e_future_notes');
    }
});

// ── Atomicity, locking and the failure policy ────────────────────────────────

it('leaves an account whole when its removal fails part-way, reports it without SQL, and still removes the others', function () {
    $broken = inviteeAccepted(inviteeAddress('broken'));
    $fine = inviteeAccepted(inviteeAddress('fine'));
    DB::beforeExecuting(function (string $sql, array $bindings) use ($broken) {
        if (str_starts_with(ltrim($sql), 'delete from `users`') && in_array($broken->id, $bindings, true)) {
            throw new RuntimeException('forced failure after the dependent rows went');
        }
    });

    $output = new BufferedOutput;
    $code = Artisan::call('e2e:cleanup-invitees', [], $output);
    $text = $output->fetch();

    expect($code)->toBe(1)->and($text)->toContain("account #{$broken->id}: not removed (RuntimeException)")->and($text)->not->toContain('delete from')
        // The transaction rolled back: every dependent row of the broken account is still there.
        ->and(User::whereKey($broken->id)->exists())->toBeTrue()
        ->and(DB::table('organization_members')->where('user_id', $broken->id)->exists())->toBeTrue()
        ->and(DB::table('organization_invitations')->where('accepted_user_id', $broken->id)->exists())->toBeTrue()
        ->and(DB::table('password_histories')->where('user_id', $broken->id)->exists())->toBeTrue()
        ->and(DB::table('model_has_roles')->where('model_id', $broken->id)->exists())->toBeTrue()
        ->and(User::whereKey($fine->id)->exists())->toBeFalse();
});

it('takes the account row, then the organization rows, then the invitation rows, before it deletes anything', function () {
    $invitee = inviteeAccepted(inviteeAddress());
    DB::flushQueryLog();
    DB::enableQueryLog();

    inviteeCleanup()->assertSuccessful();

    $log = collect(DB::getQueryLog())->pluck('query')->map(fn (string $sql) => strtolower($sql));
    DB::disableQueryLog();

    $firstDelete = $log->search(fn (string $sql) => str_starts_with($sql, 'delete from'));
    $user = $log->search(fn (string $sql) => str_contains($sql, 'from `users`') && str_contains($sql, 'for update'));
    $organization = $log->search(fn (string $sql) => str_contains($sql, 'from `organizations`') && str_contains($sql, 'for update'));
    $invitation = $log->search(fn (string $sql) => str_contains($sql, 'from `organization_invitations`') && str_contains($sql, 'for update'));

    expect($user)->not->toBeFalse()->and($organization)->not->toBeFalse()->and($invitation)->not->toBeFalse()
        ->and($user)->toBeLessThan($organization)->and($organization)->toBeLessThan($invitation)->and($invitation)->toBeLessThan($firstDelete);

    // Dependency order of the deletes themselves: the invitation row, the membership, then the account.
    $deletes = $log->filter(fn (string $sql) => str_starts_with($sql, 'delete from'))->map(fn (string $sql) => preg_match('/delete from `([a-z_]+)`/', $sql, $m) ? $m[1] : '')->values()->all();
    expect($deletes[0])->toBe('organization_invitations')->and($deletes[1])->toBe('organization_members')->and(end($deletes))->toBe('users')
        ->and($invitee->exists)->toBeTrue();
});

it('reads no secret: the report names ids and counts, never an address, a token or a hash', function () {
    inviteeAccepted(inviteeAddress('secret-run'));
    [$stray, $token] = regInvOpen(inviteeAddress('stray'), $this->other, $this->operator);

    $output = new BufferedOutput;
    Artisan::call('e2e:cleanup-invitees', [], $output);
    $text = $output->fetch();

    expect($text)->not->toContain('@example.test')->and($text)->not->toContain('secret-run')->and($text)->not->toContain($token)->and($text)->not->toContain($stray->token_hash);
});

// ── It is the seeded organization's cleanup, and only that ───────────────────

it('names the organization DevSeeder seeds, and that organization is empty after seeding', function () {
    $this->seed(DevSeeder::class);

    $suite = Organization::withoutGlobalScopes()->where('slug', CleanupE2eInvitees::ORGANIZATION_SLUGS[0])->sole();

    expect($suite->name)->toBe('E2E Invitations Org')->and(DB::table('organization_members')->where('organization_id', $suite->id)->count())->toBe(0)
        ->and(CleanupE2eInvitees::ORGANIZATION_SLUGS)->toBe(['e2e-invitations-org']);

    // Re-seeding is idempotent.
    $this->seed(DevSeeder::class);
    expect(Organization::withoutGlobalScopes()->where('slug', 'e2e-invitations-org')->count())->toBe(1);
});

it('keeps the reserved-address pattern strict', function (string $address, bool $matches) {
    expect(preg_match(CleanupE2eInvitees::ADDRESS_PATTERN, $address))->toBe($matches ? 1 : 0);
})->with([
    ['e2e-invitee+run1@example.test', true],
    ['e2e-invitee+a-b-1@example.test', true],
    ['e2e-invitee+@example.test', false],
    ['e2e-invitee@example.test', false],
    ['E2E-invitee+run@example.test', false],
    ['e2e-invitee+run@example.com', false],
    ['x-e2e-invitee+run@example.test', false],
    ['e2e-invitee+run@example.test.evil.com', false],
    ["e2e-invitee+run@example.test\n", false],
    ['e2e-invitee+ru n@example.test', false],
]);
