<?php

/*
 * Release 1.0 (MO-2A1): the membership rules against a REAL second database connection (MariaDB, InnoDB). "The last active approved
 * administrator stays" spans rows, and a transaction on one connection cannot protect it from a write on another unless the
 * organization row, the one serialization point of an organization's membership, is locked first. These tests do not trust query
 * order: they run the actual service, and at the exact moment it holds the organization lock they run the CONFLICTING operation, the
 * same service on a second connection, then assert what happened.
 *
 *   C1. demote two approved administrators    | the second waits (lock wait), then is refused as the last one
 *   C2. remove two approved administrators    | the second waits, then is refused as the last one
 *   C3. approve | demote, and demote | approve  | the loser waits, then sees the committed state; no approved MEMBER row can result
 *   C4. revoke one approval | remove the other | the removal waits, then is refused (the revocation made it the last one)
 *
 * Same technique and constraints as OrganizationRelationshipsConcurrencyTest: the second connection acts from inside a DB::listen
 * callback on the first operation's own queries (deterministic, no timing), sets a one-second InnoDB lock wait, and everything is
 * committed (DB::commit()) and removed again by id in a finally block. Nothing is seeded: the two permissions the policy reads are
 * created here and removed after.
 */

use App\Exceptions\OrganizationMembershipException;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationAuthority;
use App\Services\OrganizationMembership;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Tests\Support\CommittedUsers;

beforeEach(function () {
    config(['database.connections.mem_race_b' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    DB::purge('mem_race_b');
});

/** Run $callback with the second connection as the default one, so the service under test uses it. */
function memRaceOnSecond(callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('mem_race_b');

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * Committed rows: an operator-by-capability, an organization, and two approved customer administrators (and a third plain member).
 *
 * @return array{actor: User, org: Organization, a: User, b: User, member: User, users: list<int>, permissions: list<int>}
 */
function memRaceFixture(): array
{
    DB::commit();

    $permissions = collect(['portal.operator', 'crm.manage'])->map(fn ($name) => Permission::create(['name' => $name, 'guard_name' => 'web']));
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions->all());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $a = User::factory()->create();
    $b = User::factory()->create();
    $member = User::factory()->create();
    $org = Organization::factory()->create(['name' => 'Membership Race', 'owner_id' => $actor->id]);

    foreach ([[$a, 'admin', true], [$b, 'admin', true], [$member, 'member', false]] as [$user, $role, $approved]) {
        DB::table('organization_members')->insert([
            'organization_id' => $org->id, 'user_id' => $user->id, 'role' => $role,
            'admin_approved_at' => $approved ? now() : null, 'admin_approved_by' => $approved ? $actor->id : null,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    DB::connection('mem_race_b')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    return [
        'actor' => $actor, 'org' => $org, 'a' => $a, 'b' => $b, 'member' => $member,
        'users' => [$actor->id, $a->id, $b->id, $member->id],
        'permissions' => $permissions->pluck('id')->all(),
    ];
}

function memRaceCleanup(array $fixture): void
{
    DB::table('activity_log')->where('subject_type', Organization::class)->where('subject_id', $fixture['org']->id)->delete();
    DB::table('activity_log')->whereIn('causer_id', $fixture['users'])->delete();
    // Approvals reference the approving operator (RESTRICT): the memberships go first.
    DB::table('organization_members')->where('organization_id', $fixture['org']->id)->delete();
    DB::table('organizations')->where('id', $fixture['org']->id)->delete();
    DB::table('model_has_permissions')->whereIn('model_id', $fixture['users'])->where('model_type', User::class)->delete();
    CommittedUsers::delete($fixture['users']);
    Permission::whereIn('id', $fixture['permissions'])->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function memRaceCode(Throwable $e): ?int
{
    return $e instanceof QueryException ? (int) ($e->errorInfo[1] ?? 0) : null;
}

/** Run $operation; when it has just taken the organization row lock, run $conflict once and record what it did. */
function memRaceDuring(callable $operation, callable $conflict): mixed
{
    $outcome = null;
    $fired = false;

    DB::listen(function ($query) use (&$outcome, &$fired, $conflict) {
        if ($fired || $query->connectionName === 'mem_race_b' || ! str_contains($query->sql, 'from `organizations`') || ! str_contains($query->sql, 'for update')) {
            return;
        }

        $fired = true;

        try {
            $conflict();
            $outcome = 'succeeded';
        } catch (Throwable $e) {
            $outcome = $e;
        }
    });

    $operation();

    expect($fired)->toBeTrue('the conflicting operation was attempted while the first held the organization lock');

    return $outcome;
}

function memRaceRow(array $f, User $user): ?object
{
    return DB::table('organization_members')->where('organization_id', $f['org']->id)->where('user_id', $user->id)->first();
}

function memRaceRefusal(callable $action): ?string
{
    try {
        $action();
    } catch (OrganizationMembershipException $e) {
        return $e->reason;
    }

    return null;
}

it('C1. two approved administrators cannot both be demoted: the second waits, then is refused as the last one', function () {
    $f = memRaceFixture();
    $service = app(OrganizationMembership::class);

    try {
        $during = memRaceDuring(
            fn () => expect($service->demote($f['org'], $f['a'], $f['actor']))->toBeTrue(),
            fn () => memRaceOnSecond(fn () => app(OrganizationMembership::class)->demote($f['org'], $f['b'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)
            ->and(memRaceCode($during))->toBe(1205, 'the second demotion waited on the organization row and gave up; it did not slip in')
            ->and(memRaceRow($f, $f['b'])->role)->toBe('admin');

        // Once the first has committed, the same demotion is re-validated against the new state and refused.
        expect(memRaceRefusal(fn () => memRaceOnSecond(fn () => app(OrganizationMembership::class)->demote($f['org'], $f['b'], $f['actor']))))->toBe(OrganizationMembershipException::LAST_APPROVED_ADMIN)
            ->and(memRaceRow($f, $f['a']))->toMatchObject(['role' => 'member', 'admin_approved_at' => null, 'admin_approved_by' => null])
            ->and(memRaceRow($f, $f['b'])->role)->toBe('admin')
            ->and(OrganizationAuthority::approvedAdminIds($f['org']->id)->all())->toBe([$f['b']->id]);
    } finally {
        memRaceCleanup($f);
    }
});

it('C2. two approved administrators cannot both be removed: the second waits, then is refused as the last one', function () {
    $f = memRaceFixture();
    $service = app(OrganizationMembership::class);

    try {
        $during = memRaceDuring(
            fn () => $service->remove($f['org'], $f['a'], $f['actor']),
            fn () => memRaceOnSecond(fn () => app(OrganizationMembership::class)->remove($f['org'], $f['b'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)->and(memRaceCode($during))->toBe(1205)
            ->and(memRaceRow($f, $f['b']))->not->toBeNull();

        expect(memRaceRefusal(fn () => memRaceOnSecond(fn () => app(OrganizationMembership::class)->remove($f['org'], $f['b'], $f['actor']))))->toBe(OrganizationMembershipException::LAST_APPROVED_ADMIN)
            ->and(memRaceRow($f, $f['a']))->toBeNull()
            ->and(memRaceRow($f, $f['b'])->role)->toBe('admin')
            ->and(OrganizationAuthority::approvedAdminIds($f['org']->id)->all())->toBe([$f['b']->id]);
    } finally {
        memRaceCleanup($f);
    }
});

it('C3a. an approval and a demotion of the same member cannot interleave: the demotion waits, then clears what was approved', function () {
    $f = memRaceFixture();
    $service = app(OrganizationMembership::class);
    $service->promote($f['org'], $f['member'], $f['actor']);   // an unapproved administrator: the subject of the race

    try {
        $during = memRaceDuring(
            fn () => expect($service->approve($f['org'], $f['member'], $f['actor']))->toBeTrue(),
            fn () => memRaceOnSecond(fn () => app(OrganizationMembership::class)->demote($f['org'], $f['member'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)->and(memRaceCode($during))->toBe(1205);

        // The demotion, run after the approval committed, sees the approved row and clears the approval with the role.
        memRaceOnSecond(fn () => app(OrganizationMembership::class)->demote($f['org'], $f['member'], $f['actor']));

        expect(memRaceRow($f, $f['member']))->toMatchObject(['role' => 'member', 'admin_approved_at' => null, 'admin_approved_by' => null]);
    } finally {
        memRaceCleanup($f);
    }
});

it('C3b. a demotion and an approval of the same administrator cannot interleave: the approval waits, then is refused, so no approved member row results', function () {
    $f = memRaceFixture();
    $service = app(OrganizationMembership::class);

    try {
        $during = memRaceDuring(
            fn () => expect($service->demote($f['org'], $f['a'], $f['actor']))->toBeTrue(),
            fn () => memRaceOnSecond(fn () => app(OrganizationMembership::class)->approve($f['org'], $f['a'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)->and(memRaceCode($during))->toBe(1205);

        expect(memRaceRefusal(fn () => memRaceOnSecond(fn () => app(OrganizationMembership::class)->approve($f['org'], $f['a'], $f['actor']))))->toBe(OrganizationMembershipException::NOT_ADMIN_ROLE)
            ->and(memRaceRow($f, $f['a']))->toMatchObject(['role' => 'member', 'admin_approved_at' => null, 'admin_approved_by' => null]);
    } finally {
        memRaceCleanup($f);
    }
});

it('C4. a revocation and a removal of the two approved administrators cannot both succeed: the removal waits, then is refused', function () {
    $f = memRaceFixture();
    $service = app(OrganizationMembership::class);

    try {
        $during = memRaceDuring(
            fn () => expect($service->revokeApproval($f['org'], $f['a'], $f['actor']))->toBeTrue(),
            fn () => memRaceOnSecond(fn () => app(OrganizationMembership::class)->remove($f['org'], $f['b'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)->and(memRaceCode($during))->toBe(1205);

        // Run after the revocation committed: B is now the only approved administrator, so the ordinary removal is refused.
        expect(memRaceRefusal(fn () => memRaceOnSecond(fn () => app(OrganizationMembership::class)->remove($f['org'], $f['b'], $f['actor']))))->toBe(OrganizationMembershipException::LAST_APPROVED_ADMIN)
            ->and(memRaceRow($f, $f['a']))->toMatchObject(['role' => 'admin', 'admin_approved_at' => null])
            ->and(OrganizationAuthority::approvedAdminIds($f['org']->id)->all())->toBe([$f['b']->id]);
    } finally {
        memRaceCleanup($f);
    }
});
