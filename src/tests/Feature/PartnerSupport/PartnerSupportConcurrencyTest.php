<?php

/*
 * Release 1.0 (R1-4): partner support against a REAL second database connection (MariaDB, InnoDB). A reply re-decides authority under locks inside its
 * transaction; these tests run the real service and, at the exact moment it holds (or has just read) its locks, run the CONFLICTING operation, the real
 * service method, on a second connection. The moment is deterministic: the second connection acts from inside a DB::listen callback on the first
 * operation's own queries. Like OrganizationRelationshipsConcurrencyTest it ends RefreshDatabase's transaction (DB::commit()) so the second connection can see
 * the rows, and removes everything it committed in a finally block, scoped by the ids it created.
 *
 *   A. a revocation during a reply, after the reply holds its locks  -> the revocation WAITS (lock wait), the reply commits, the revocation then applies
 *   B. a revocation that commits after the reply started             -> the reply SEES it (a locking read returns the latest committed row) and is refused
 *   C. an operator re-submitting the customer link during a reply     -> the link WAITS (1205), it does not deadlock (1213): both rows are locked in ascending id order
 *   D. a suspension during a reply                                    -> the suspension WAITS on the user row, then clears the designation
 *
 * R1-5 (creating a request for a contact) re-decides the same authority, plus the CONTACT's eligibility, in the creation transaction:
 *   F. a revocation during a creation                                 -> the revocation WAITS (1205); the request commits; the next creation is refused (404, no grant)
 *   G. a revocation that commits after the creation started           -> the creation SEES it, is refused (422 on the customer) and writes nothing
 *   H. a contact suspension during a creation                         -> the suspension WAITS on the user row; once applied the next creation is a 422 on the contact
 *   I. a contact's membership removed after the creation started      -> the creation SEES it, is refused (422 on the contact) and writes nothing
 *   K. a contact suspended after the creation's first read but before its locks -> the creation SEES it on the LOCKED row (422 on the contact)
 *   L. the actor suspended after the same first read                -> the creation is refused as the ACCOUNT (404), nothing written
 *   J. the creation's locks: users together, then organizations together, each ascending, in that order
 */

use App\Models\CrmCompany;
use App\Models\Organization;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Services\OrganizationMembership;
use App\Services\OrganizationRelationships;
use App\Services\PartnerSupportAccess;
use App\Services\PartnerSupportGrants;
use App\Services\TicketService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CommittedUsers;

beforeEach(function () {
    config(['database.connections.ps_race_b' => config('database.connections.'.config('database.default'))]);
    Notification::fake();
});

afterEach(function () {
    DB::purge('ps_race_b');
});

function psRaceSecond(callable $callback): mixed
{
    $previous = DB::getDefaultConnection();
    DB::setDefaultConnection('ps_race_b');

    try {
        return $callback();
    } finally {
        DB::setDefaultConnection($previous);
    }
}

/** @return array{actor: User, partner: Organization, customer: Organization, staff: User, requester: User, ticket: Ticket, permissions: list<int>} */
function psRaceFixture(): array
{
    DB::commit();

    $permissions = collect(['portal.operator', 'crm.manage'])->map(fn ($name) => Permission::create(['name' => $name, 'guard_name' => 'web']));
    // R1-5: the contact (requester) must be able to raise and read a request.
    $customerPermissions = collect(['tickets.create', 'tickets.view'])->map(fn ($name) => Permission::create(['name' => $name, 'guard_name' => 'web']));
    $actor = User::factory()->create();
    $actor->givePermissionTo($permissions->all());
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $make = function (string $name) use ($actor): Organization {
        $organization = Organization::factory()->create(['name' => $name, 'owner_id' => $actor->id]);
        CrmCompany::factory()->create(['name' => $name, 'organization_id' => $organization->id, 'created_by' => $actor->id]);

        return $organization;
    };

    $partner = $make('Race Partner');
    $customer = $make('Race Customer');
    app(OrganizationRelationships::class)->designatePartner($partner, $actor);
    app(OrganizationRelationships::class)->assignCustomer($customer, $partner, $actor);

    $staff = User::factory()->create();
    $requester = User::factory()->create();
    $requester->givePermissionTo($customerPermissions->all());
    DB::table('organization_members')->insert([
        ['organization_id' => $partner->id, 'user_id' => $staff->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()],
        ['organization_id' => $customer->id, 'user_id' => $requester->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()],
    ]);
    app(PartnerSupportGrants::class)->grant($partner, $staff, $actor);
    $ticket = Ticket::factory()->open()->for($requester, 'user')->create(['organization_id' => $customer->id]);

    DB::connection('ps_race_b')->statement('SET SESSION innodb_lock_wait_timeout = 1');

    return compact('actor', 'partner', 'customer', 'staff', 'requester', 'ticket') + ['permissions' => $permissions->merge($customerPermissions)->pluck('id')->all()];
}

function psRaceCleanup(array $f): void
{
    $users = [$f['staff']->id, $f['requester']->id, $f['actor']->id];
    $organizations = DB::table('organizations')->where('owner_id', $f['actor']->id)->pluck('id')->all();

    DB::table('activity_log')->where('subject_type', Organization::class)->whereIn('subject_id', $organizations)->delete();
    DB::table('activity_log')->whereIn('causer_id', $users)->delete();
    DB::table('organizations')->whereIn('id', $organizations)->update(['managed_by_organization_id' => null]);
    DB::table('crm_companies')->where('created_by', $f['actor']->id)->delete();
    DB::table('organization_members')->whereIn('organization_id', $organizations)->delete();
    DB::table('organizations')->whereIn('id', $organizations)->update(['owner_id' => $f['actor']->id]);
    CommittedUsers::delete($users);
    DB::table('organizations')->whereIn('id', $organizations)->delete();
    DB::table('model_has_permissions')->whereIn('model_id', [$f['actor']->id, $f['requester']->id])->where('model_type', User::class)->delete();
    Permission::whereIn('id', $f['permissions'])->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function psRaceCode(Throwable $e): ?int
{
    return $e instanceof QueryException ? (int) ($e->errorInfo[1] ?? 0) : null;
}

/**
 * Run $operation; the first time one of ITS OWN queries contains every needle, run $conflict once and record what it did.
 *
 * @param  list<string>  $needles
 */
function psRaceDuring(callable $operation, array $needles, callable $conflict): mixed
{
    $outcome = null;
    $fired = false;

    DB::listen(function ($query) use (&$outcome, &$fired, $needles, $conflict) {
        if ($fired || $query->connectionName === 'ps_race_b') {
            return;
        }

        foreach ($needles as $needle) {
            if (! str_contains($query->sql, $needle)) {
                return;
            }
        }

        $fired = true;

        try {
            $outcome = $conflict() ?? 'succeeded';
        } catch (Throwable $e) {
            $outcome = $e;
        }
    });

    $operation();

    expect($fired)->toBeTrue('the conflicting operation was attempted at the intended moment');

    return $outcome;
}

it('A. a revocation during a reply waits for it, then applies', function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            fn () => app(TicketService::class)->addPartnerSupportReply($f['ticket'], $f['staff'], 'On it'),
            ['from `organization_members`', 'lock in share mode'],
            fn () => psRaceSecond(fn () => app(PartnerSupportGrants::class)->revoke($f['partner'], $f['staff'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)
            ->and(psRaceCode($during))->toBe(1205, 'the revocation waited on the membership row the reply holds')
            ->and(DB::table('ticket_replies')->where('ticket_id', $f['ticket']->id)->count())->toBe(1);

        // Now that the reply has committed the revocation applies, and the next reply is refused.
        expect(psRaceSecond(fn () => app(PartnerSupportGrants::class)->revoke($f['partner'], $f['staff'], $f['actor'])))->toBeTrue();
        expect(fn () => app(TicketService::class)->addPartnerSupportReply($f['ticket'], $f['staff'], 'Again'))->toThrow(HttpException::class)
            ->and(DB::table('ticket_replies')->where('ticket_id', $f['ticket']->id)->count())->toBe(1);
    } finally {
        psRaceCleanup($f);
    }
});

it('B. a revocation that commits after the reply started is seen by the reply, which is refused and writes nothing', function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            function () use ($f) {
                expect(fn () => app(TicketService::class)->addPartnerSupportReply($f['ticket'], $f['staff'], 'Too late'))->toThrow(HttpException::class);
            },
            // The nomination read: it runs before any organization or membership lock, so the revocation can commit in between.
            ['select `managed_by_organization_id` from `organizations`'],
            fn () => psRaceSecond(fn () => app(PartnerSupportGrants::class)->revoke($f['partner'], $f['staff'], $f['actor']))
        );

        expect($during)->toBeTrue('the revocation committed while the reply was in flight')
            ->and(DB::table('ticket_replies')->where('ticket_id', $f['ticket']->id)->count())->toBe(0)
            ->and(PartnerSupportAccess::holdsGrant($f['staff']))->toBeFalse();
    } finally {
        psRaceCleanup($f);
    }
});

it('C. an operator re-submitting the customer link during a reply waits (1205) and never deadlocks (1213)', function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            fn () => app(TicketService::class)->addPartnerSupportReply($f['ticket'], $f['staff'], 'Working'),
            ['from `organizations`', 'lock in share mode'],
            fn () => psRaceSecond(fn () => app(OrganizationRelationships::class)->assignCustomer($f['customer'], $f['partner'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)
            ->and(psRaceCode($during))->toBe(1205)
            ->and(DB::table('ticket_replies')->where('ticket_id', $f['ticket']->id)->count())->toBe(1);

        // And the other way round: the link is a no-op that commits, and the next reply still works.
        expect(psRaceSecond(fn () => app(OrganizationRelationships::class)->assignCustomer($f['customer'], $f['partner'], $f['actor'])))->toBeFalse();
        app(TicketService::class)->addPartnerSupportReply($f['ticket'], $f['staff'], 'Still working');
        expect(DB::table('ticket_replies')->where('ticket_id', $f['ticket']->id)->count())->toBe(2);
    } finally {
        psRaceCleanup($f);
    }
});

it('C2. the reply locks BOTH organization rows in ONE statement in ascending id order, and takes no organization lock before it', function () {
    // The two-connection test above cannot see a deadlock (the second connection runs inside the first's callback, so the first is paused and the second can
    // only time out). What prevents one is the ORDER, so pin the order itself: exactly one locking read of `organizations`, naming both rows with `order by id`.
    $f = psRaceFixture();

    try {
        $locking = [];
        DB::listen(function ($query) use (&$locking) {
            if ($query->connectionName !== 'ps_race_b' && str_contains($query->sql, 'from `organizations`') && str_contains($query->sql, 'lock in share mode')) {
                $locking[] = $query;
            }
        });

        app(TicketService::class)->addPartnerSupportReply($f['ticket'], $f['staff'], 'Ordered');

        expect($locking)->toHaveCount(1)
            ->and($locking[0]->sql)->toContain('in (?, ?)')->toContain('order by `id`')
            ->and($locking[0]->bindings)->toContain($f['partner']->id)->toContain($f['customer']->id);
    } finally {
        psRaceCleanup($f);
    }
});

it('E. a suspension during a designation waits for it on the user row (the user-first order), then clears it', function () {
    $f = psRaceFixture();

    try {
        // Take the designation away so the grant below has work to do, then grant while the account is being suspended on the second connection.
        app(PartnerSupportGrants::class)->revoke($f['partner'], $f['staff'], $f['actor']);

        $during = psRaceDuring(
            fn () => expect(app(PartnerSupportGrants::class)->grant($f['partner'], $f['staff'], $f['actor']))->toBeTrue(),
            ['from `organization_members`', 'for update'],
            fn () => psRaceSecond(fn () => app(AccountLifecycleService::class)->suspend($f['staff'], $f['actor'], 'race'))
        );

        expect($during)->toBeInstanceOf(QueryException::class)
            ->and(psRaceCode($during))->toBe(1205, 'the suspension waited on the user row the designation holds shared')
            ->and(DB::table('organization_members')->where('user_id', $f['staff']->id)->value('support_granted_at'))->not->toBeNull();

        // Suspended afterwards, the designation is cleared for good.
        expect(psRaceSecond(fn () => app(AccountLifecycleService::class)->suspend($f['staff'], $f['actor'], 'race')))->toBeTrue();
        expect(DB::table('organization_members')->where('user_id', $f['staff']->id)->value('support_granted_at'))->toBeNull();
    } finally {
        psRaceCleanup($f);
    }
});

it('D. a suspension during a reply waits on the user row, then clears the designation for good', function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            fn () => app(TicketService::class)->addPartnerSupportReply($f['ticket'], $f['staff'], 'Before I go'),
            ['from `organization_members`', 'lock in share mode'],
            fn () => psRaceSecond(fn () => app(AccountLifecycleService::class)->suspend($f['staff'], $f['actor'], 'race'))
        );

        expect($during)->toBeInstanceOf(QueryException::class)
            ->and(psRaceCode($during))->toBe(1205, 'the suspension waited on the user row the reply holds shared')
            ->and(DB::table('ticket_replies')->where('ticket_id', $f['ticket']->id)->count())->toBe(1);

        expect(psRaceSecond(fn () => app(AccountLifecycleService::class)->suspend($f['staff'], $f['actor'], 'race')))->toBeTrue();
        expect(DB::table('organization_members')->where('user_id', $f['staff']->id)->value('support_granted_at'))->toBeNull();
    } finally {
        psRaceCleanup($f);
    }
});

// ── R1-5: creating a request for a contact ─────────────────────────────

function psRaceCreate(array $f, ?User $actor = null): Ticket
{
    return app(TicketService::class)->createForCustomerContact(
        $actor ?? $f['staff'], $f['customer']->id, $f['requester']->id,
        ['title' => 'Race', 'description' => 'Raced.', 'category' => 'General', 'priority' => 'low'],
    );
}

function psRaceCreated(array $f): int
{
    return DB::table('tickets')->where('organization_id', $f['customer']->id)->where('title', 'Race')->count();
}

it('F. a revocation during a creation waits for it, the request commits with its provenance, and the next creation is refused', function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            fn () => psRaceCreate($f),
            ['from `organization_members`', 'lock in share mode'],
            fn () => psRaceSecond(fn () => app(PartnerSupportGrants::class)->revoke($f['partner'], $f['staff'], $f['actor']))
        );

        expect($during)->toBeInstanceOf(QueryException::class)
            ->and(psRaceCode($during))->toBe(1205, 'the revocation waited on the membership row the creation holds')
            ->and(psRaceCreated($f))->toBe(1);

        $row = DB::table('tickets')->where('title', 'Race')->first();
        expect([(int) $row->user_id, (int) $row->submitted_by_id, (int) $row->organization_id])->toBe([$f['requester']->id, $f['staff']->id, $f['customer']->id]);

        expect(psRaceSecond(fn () => app(PartnerSupportGrants::class)->revoke($f['partner'], $f['staff'], $f['actor'])))->toBeTrue();
        // Committed before the next creation starts: no grant at all, the door's 404 (mid-flight it is the 422 of G).
        expect(fn () => psRaceCreate($f))->toThrow(HttpException::class)
            ->and(psRaceCreated($f))->toBe(1);
    } finally {
        psRaceCleanup($f);
    }
});

it('G. a revocation that commits after the creation started is seen by it, which is refused and writes nothing', function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            function () use ($f) {
                try {
                    psRaceCreate($f);
                    $this->fail('a revoked designation must be refused');
                } catch (ValidationException $e) {
                    expect(array_keys($e->errors()))->toBe(['organization_id']);
                }
            },
            ['select `managed_by_organization_id` from `organizations`'],
            fn () => psRaceSecond(fn () => app(PartnerSupportGrants::class)->revoke($f['partner'], $f['staff'], $f['actor']))
        );

        expect($during)->toBeTrue('the revocation committed while the creation was in flight')
            ->and(psRaceCreated($f))->toBe(0);
    } finally {
        psRaceCleanup($f);
    }
});

it('H. a contact suspension during a creation waits on the user row; afterwards the contact is refused', function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            fn () => psRaceCreate($f),
            ['from `organization_members`', 'lock in share mode'],
            fn () => psRaceSecond(fn () => app(AccountLifecycleService::class)->suspend($f['requester'], $f['actor'], 'race'))
        );

        expect($during)->toBeInstanceOf(QueryException::class)
            ->and(psRaceCode($during))->toBe(1205, 'the suspension waited on the contact user row the creation holds shared')
            ->and(psRaceCreated($f))->toBe(1);

        expect(psRaceSecond(fn () => app(AccountLifecycleService::class)->suspend($f['requester'], $f['actor'], 'race')))->toBeTrue();

        try {
            psRaceCreate($f);
            $this->fail('a suspended contact must be refused');
        } catch (ValidationException $e) {
            expect(array_keys($e->errors()))->toBe(['requester_id']);
        }
        expect(psRaceCreated($f))->toBe(1);
    } finally {
        psRaceCleanup($f);
    }
});

it("I. a contact's membership removed after the creation started is seen by it, which is refused on the contact and writes nothing", function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            function () use ($f) {
                try {
                    psRaceCreate($f);
                    $this->fail('a contact who left the customer must be refused');
                } catch (ValidationException $e) {
                    expect(array_keys($e->errors()))->toBe(['requester_id']);
                }
            },
            ['select `managed_by_organization_id` from `organizations`'],
            fn () => psRaceSecond(fn () => app(OrganizationMembership::class)->remove($f['customer'], $f['requester'], $f['actor']))
        );

        expect($during)->toBe('succeeded', 'the removal ran to completion while the creation was in flight')
            ->and(psRaceCreated($f))->toBe(0);
    } finally {
        psRaceCleanup($f);
    }
});

it('J. the creation locks the users together, then the organizations together, each ascending and shared, and nothing earlier', function () {
    $f = psRaceFixture();

    try {
        $locking = [];
        DB::listen(function ($query) use (&$locking) {
            if ($query->connectionName !== 'ps_race_b' && str_contains($query->sql, 'lock in share mode')) {
                $locking[] = $query;
            }
        });

        psRaceCreate($f);

        $tables = array_map(fn ($q) => preg_match('/from `(\w+)`/', $q->sql, $m) ? $m[1] : '?', $locking);

        expect($tables)->toBe(['users', 'organizations', 'organization_members', 'organization_members'])
            ->and($locking[0]->sql)->toContain('in (?, ?)')->toContain('order by `id`')
            ->and($locking[0]->bindings)->toContain($f['staff']->id)->toContain($f['requester']->id)
            ->and($locking[1]->sql)->toContain('in (?, ?)')->toContain('order by `id`');
    } finally {
        psRaceCleanup($f);
    }
});

it('K. a contact suspended after the creation read its grant but before it took its locks is refused, from the locked row and not the snapshot', function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            function () use ($f) {
                try {
                    psRaceCreate($f);
                    $this->fail('a contact suspended before the locks must be refused');
                } catch (ValidationException $e) {
                    expect(array_keys($e->errors()))->toBe(['requester_id']);
                }
            },
            // The unlocked grant read (the door): it starts the transaction's read view, so the suspension below commits AFTER the snapshot.
            ['support_granted_at', 'select exists'],
            fn () => psRaceSecond(fn () => app(AccountLifecycleService::class)->suspend($f['requester'], $f['actor'], 'race'))
        );

        expect($during)->toBeTrue('the suspension committed while the creation was in flight')
            ->and(psRaceCreated($f))->toBe(0);
    } finally {
        psRaceCleanup($f);
    }
});

it('L. an actor suspended after the creation read its grant is refused as the account (404) and nothing is written', function () {
    $f = psRaceFixture();

    try {
        $during = psRaceDuring(
            function () use ($f) {
                expect(fn () => psRaceCreate($f))->toThrow(HttpException::class);
            },
            ['support_granted_at', 'select exists'],
            fn () => psRaceSecond(fn () => app(AccountLifecycleService::class)->suspend($f['staff'], $f['actor'], 'race'))
        );

        expect($during)->toBeTrue('the suspension committed while the creation was in flight')
            ->and(psRaceCreated($f))->toBe(0);
    } finally {
        psRaceCleanup($f);
    }
});

it('M. a contact id that does not exist takes no lock on the users index: only the actor row is locked', function () {
    $f = psRaceFixture();

    try {
        $locking = [];
        DB::listen(function ($query) use (&$locking) {
            if ($query->connectionName !== 'ps_race_b' && str_contains($query->sql, 'from `users`') && str_contains($query->sql, 'lock in share mode')) {
                $locking[] = $query;
            }
        });

        expect(fn () => app(TicketService::class)->createForCustomerContact($f['staff'], $f['customer']->id, 99999999, ['title' => 'Race', 'description' => 'Raced.', 'category' => 'General', 'priority' => 'low']))
            ->toThrow(ValidationException::class);

        expect($locking)->toHaveCount(1)
            ->and($locking[0]->bindings)->toBe([$f['staff']->id]);
    } finally {
        psRaceCleanup($f);
    }
});
