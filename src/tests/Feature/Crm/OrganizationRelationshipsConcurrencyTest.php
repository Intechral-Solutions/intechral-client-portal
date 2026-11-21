<?php

/*
 * Release 1.0 (MO-1A; ORG-2): the relationship rules and the Organization lifecycle guards against a REAL second database connection (MariaDB,
 * InnoDB). The rules span rows (a partner cannot be managed; a partner with customers cannot lose its designation; an Organization being deleted
 * or enabled is locked against everything else), and a transaction on one connection cannot protect them from a write on another unless
 * the rows are locked. These tests do not trust query order: they run the actual service, and at the exact moment it holds
 * its locks they run the CONFLICTING operation, the same service method, on a second connection, then assert what happened.
 *
 *   A. remove a partner designation  | link a customer to that partner      -> the link waits (lock wait), then is refused
 *   B. designate an organization     | link that organization as a customer -> the link waits, then is refused
 *   C. delete a Directory-only org   | designate it as a partner            -> the designation waits, then finds it gone
 *   D. enable portal access          | anything touching the organization   -> waits behind the enablement's lock
 *
 * (The full two-connection certification of every Organization lock pair, in both orders, is ORG-2 CP6.)
 *
 * The second connection acts from inside a DB::listen callback on the first operation's own queries, so the moment is
 * deterministic (no timing, no sleeps beyond InnoDB's lock wait, which the second connection sets to one second).
 *
 * Like TicketReplyRaceTest it must end RefreshDatabase's transaction so the second connection can see its rows
 * (DB::commit()), and it removes everything it committed in a finally block, scoped by the ids it created.
 */

use App\Exceptions\OrganizationDirectoryException;
use App\Exceptions\OrganizationRelationshipException;
use App\Models\Organization;
use App\Models\User;
use App\Services\OrganizationDirectory;
use App\Services\OrganizationRelationships;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedUsers;

beforeEach(function () {
    config(['database.connections.org_race_b' => config('database.connections.'.config('database.default'))]);
});

afterEach(function () {
    DB::purge('org_race_b');
});

/** Run $callback with the second connection as the default one, so the service under test uses it. */
function onSecondConnection(callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('org_race_b');

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/**
 * Committed rows: an operator-by-capability, a partner, and two paired organizations. Returns what the test and its cleanup need.
 *
 * @return array{actor: User, partner: Organization, a: Organization, b: Organization, permissions: list<int>}
 */
function orgRaceFixture(): array
{
    DB::commit();

    $permissions = collect(['portal.operator', 'crm.manage'])->map(fn ($name) => Permission::create(['name' => $name, 'guard_name' => 'web']));
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions->all());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $make = fn (string $name): Organization => Organization::factory()->create(['name' => $name, 'portal_enabled_by' => $actor->id]);

    $partner = $make('Race Partner');
    $a = $make('Race A');
    $b = $make('Race B');

    DB::connection('org_race_b')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    return [
        'actor' => $actor,
        'partner' => $partner,
        'a' => $a,
        'b' => $b,
        'permissions' => $permissions->pluck('id')->all(),
    ];
}

function orgRaceCleanup(array $fixture): void
{
    $actor = $fixture['actor'];
    $organizations = DB::table('organizations')->where('portal_enabled_by', $actor->id)->orWhere('created_by', $actor->id)->pluck('id')->all();

    DB::table('activity_log')->where('subject_type', Organization::class)->whereIn('subject_id', $organizations)->delete();
    DB::table('activity_log')->where('causer_id', $actor->id)->delete();
    DB::table('organizations')->whereIn('id', $organizations)->update(['managed_by_organization_id' => null]);
    DB::table('organization_members')->whereIn('organization_id', $organizations)->delete();
    DB::table('organizations')->whereIn('id', $organizations)->delete();
    DB::table('model_has_permissions')->where('model_id', $actor->id)->where('model_type', User::class)->delete();
    CommittedUsers::delete($actor->id);
    Permission::whereIn('id', $fixture['permissions'])->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

/** The MariaDB error number, whatever the wrapper (1205: lock wait timeout exceeded). */
function orgRaceCode(Throwable $e): ?int
{
    return $e instanceof QueryException ? (int) ($e->errorInfo[1] ?? 0) : null;
}

/** Run $operation; when it has just taken a locking read matching $sql, run $conflict once and record what it did. */
function orgRaceDuring(callable $operation, string $sql, callable $conflict): mixed
{
    $outcome = null;
    $fired = false;

    DB::listen(function ($query) use (&$outcome, &$fired, $sql, $conflict) {
        if ($fired || $query->connectionName === 'org_race_b' || ! str_contains($query->sql, $sql) || ! str_contains($query->sql, 'for update')) {
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

    expect($fired)->toBeTrue('the conflicting operation was attempted while the first held its locks');

    return $outcome;
}

it('A. cannot link a customer to a partner while that partner is having its designation removed: it waits, then is refused', function () {
    $f = orgRaceFixture();
    $service = app(OrganizationRelationships::class);
    $service->designatePartner($f['partner'], $f['actor']);

    try {
        $during = orgRaceDuring(
            fn () => expect($service->removePartner($f['partner'], $f['actor']))->toBeTrue(),
            'from `organizations`',
            fn () => onSecondConnection(fn () => app(OrganizationRelationships::class)->assignCustomer($f['a'], $f['partner'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)
            ->and(orgRaceCode($during))->toBe(1205, 'the link waited on the partner row and gave up; it did not slip in')
            ->and(DB::table('organizations')->where('id', $f['a']->id)->value('managed_by_organization_id'))->toBeNull();

        // Once the removal has committed the same link is re-validated against the new state and refused.
        $refusal = null;
        try {
            onSecondConnection(fn () => app(OrganizationRelationships::class)->assignCustomer($f['a'], $f['partner'], $f['actor']));
        } catch (OrganizationRelationshipException $e) {
            $refusal = $e;
        }

        expect($refusal?->reason)->toBe(OrganizationRelationshipException::NOT_PARTNER)
            ->and((bool) DB::table('organizations')->where('id', $f['partner']->id)->value('can_manage_customers'))->toBeFalse()
            ->and(DB::table('organizations')->where('managed_by_organization_id', $f['partner']->id)->count())->toBe(0);
    } finally {
        orgRaceCleanup($f);
    }
});

it('A2. a link committed first makes the removal refuse, and the customer stays linked', function () {
    $f = orgRaceFixture();
    $service = app(OrganizationRelationships::class);
    $service->designatePartner($f['partner'], $f['actor']);

    try {
        // The link commits on the second connection before the removal takes its lock: the removal sees it, whatever model it was given.
        onSecondConnection(fn () => app(OrganizationRelationships::class)->assignCustomer($f['a'], $f['partner'], $f['actor']));
        $stalePartner = Organization::withoutGlobalScopes()->find($f['partner']->id);

        expect(fn () => $service->removePartner($stalePartner, $f['actor']))->toThrow(OrganizationRelationshipException::class, 'still manages 1 customer')
            ->and((bool) DB::table('organizations')->where('id', $f['partner']->id)->value('can_manage_customers'))->toBeTrue()
            ->and(DB::table('organizations')->where('id', $f['a']->id)->value('managed_by_organization_id'))->toBe($f['partner']->id);
    } finally {
        orgRaceCleanup($f);
    }
});

it('B. cannot manage an organization that is being designated a partner: the link waits, then is refused', function () {
    $f = orgRaceFixture();
    $service = app(OrganizationRelationships::class);
    $service->designatePartner($f['partner'], $f['actor']);

    try {
        $during = orgRaceDuring(
            fn () => expect($service->designatePartner($f['a'], $f['actor']))->toBeTrue(),
            'from `organizations`',
            fn () => onSecondConnection(fn () => app(OrganizationRelationships::class)->assignCustomer($f['a'], $f['partner'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)->and(orgRaceCode($during))->toBe(1205);

        $refusal = null;
        try {
            onSecondConnection(fn () => app(OrganizationRelationships::class)->assignCustomer($f['a'], $f['partner'], $f['actor']));
        } catch (OrganizationRelationshipException $e) {
            $refusal = $e;
        }

        $row = DB::table('organizations')->where('id', $f['a']->id)->first();

        expect($refusal?->reason)->toBe(OrganizationRelationshipException::IS_PARTNER)
            ->and((bool) $row->can_manage_customers)->toBeTrue()
            ->and($row->managed_by_organization_id)->toBeNull('never both a partner and managed');
    } finally {
        orgRaceCleanup($f);
    }
});

it('C. cannot designate a Directory-only organization that is being deleted: the designation waits, then finds it gone', function () {
    $f = orgRaceFixture();
    $directoryOnly = Organization::factory()->directoryOnly()->create(['name' => 'Race Directory Only', 'created_by' => $f['actor']->id]);

    try {
        $during = orgRaceDuring(
            fn () => app(OrganizationDirectory::class)->delete($directoryOnly, $f['actor']),
            'from `organizations`',
            fn () => onSecondConnection(fn () => app(OrganizationRelationships::class)->designatePartner($directoryOnly, $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)->and(orgRaceCode($during))->toBe(1205, 'the designation waited on the row the deletion held')
            ->and(DB::table('organizations')->where('id', $directoryOnly->id)->exists())->toBeFalse('the deletion went ahead');

        $refusal = null;
        try {
            onSecondConnection(fn () => app(OrganizationRelationships::class)->designatePartner($directoryOnly, $f['actor']));
        } catch (Throwable $e) {
            $refusal = $e;
        }

        expect($refusal)->toBeInstanceOf(HttpException::class)->and($refusal->getStatusCode())->toBe(404);
    } finally {
        orgRaceCleanup($f);
    }
});

it('C2. an Organization that is a managed customer cannot be deleted, whatever order things committed in, and nothing changes', function () {
    $f = orgRaceFixture();
    $service = app(OrganizationRelationships::class);
    $service->designatePartner($f['partner'], $f['actor']);

    try {
        onSecondConnection(fn () => app(OrganizationRelationships::class)->assignCustomer($f['a'], $f['partner'], $f['actor']));

        expect(fn () => app(OrganizationDirectory::class)->delete($f['a'], $f['actor']))->toThrow(OrganizationDirectoryException::class, 'has (or had) portal access')
            ->and(DB::table('organizations')->where('id', $f['a']->id)->exists())->toBeTrue()
            ->and(DB::table('organizations')->where('id', $f['a']->id)->value('managed_by_organization_id'))->toBe($f['partner']->id);
    } finally {
        orgRaceCleanup($f);
    }
});

it('D. initial enablement holds the Organization row: a second session cannot read it for update or change it until the enablement commits', function () {
    $f = orgRaceFixture();
    $organization = Organization::factory()->directoryOnly()->create(['name' => 'Race Enablement', 'created_by' => $f['actor']->id]);
    $attempts = [];

    try {
        $outcome = orgRaceDuring(
            fn () => app(OrganizationDirectory::class)->enablePortalAccess($organization, $f['actor']),
            'from `organizations`',
            function () use ($organization, &$attempts) {
                $other = DB::connection('org_race_b');

                foreach ([
                    'lock' => fn () => $other->table('organizations')->where('id', $organization->id)->lockForUpdate()->first(),
                    'change' => fn () => $other->table('organizations')->where('id', $organization->id)->update(['notes' => 'raced']),
                ] as $name => $attempt) {
                    try {
                        $attempt();
                        $attempts[$name] = 'succeeded';
                    } catch (QueryException $e) {
                        $attempts[$name] = orgRaceCode($e);
                    }
                }
            }
        );

        $row = DB::table('organizations')->where('id', $organization->id)->first();

        expect($attempts)->toBe(['lock' => 1205, 'change' => 1205])
            ->and($outcome)->toBe('succeeded')
            ->and($row->portal_enabled_at)->not->toBeNull()
            ->and((int) $row->portal_enabled_by)->toBe($f['actor']->id)
            ->and($row->notes)->toBeNull('the raced write never landed')
            ->and(DB::table('organization_members')->where('organization_id', $organization->id)->count())->toBe(1);
    } finally {
        orgRaceCleanup($f);
    }
});
