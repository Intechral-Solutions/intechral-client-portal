<?php

use App\Exceptions\LastAccountAdministratorException;
use App\Exceptions\RoleSyncRefused;
use App\Models\User;
use App\Services\AccessGrants;
use App\Services\AccountAdministrators;
use App\Services\BuiltInRoleSynchronizer;
use App\Services\RoleSyncPlan;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use App\Support\Permissions\PermissionCacheEpoch;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
 * Release 0.9 (R0.9-1D, decision H4): the built-in role synchronization. A plan is a pure read; applying it needs the
 * reviewed plan's fingerprint (and, for revocations, an explicit approval); it runs under the account-administrator
 * guard; it is idempotent; it never touches custom roles, role assignments or direct permissions.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->sync = app(BuiltInRoleSynchronizer::class);
});

/** Every row the synchronization could touch or that must not change, as one comparable value. */
function syncRelevantState(): array
{
    return collect(['roles', 'permissions', 'role_has_permissions', 'model_has_roles', 'model_has_permissions', 'activity_log', 'users'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->map(fn ($row) => json_encode($row))->sort()->values()->all()])
        ->all();
}

function grantNames(string $role): array
{
    return Role::findByName($role, 'web')->permissions()->pluck('name')->sort()->values()->all();
}

function sortedDefinition(string $role): array
{
    return collect(BuiltInRoles::definitions()[$role])->sort()->values()->all();
}

/** Clear every role and permission, as on a new installation before the first synchronization. */
function emptyPermissionTables(): void
{
    DB::table('role_has_permissions')->delete();
    DB::table('roles')->delete();
    DB::table('permissions')->delete();
    app(PermissionRegistrar::class)->forgetCachedPermissions();
}

function actionsOf(RoleSyncPlan $plan, string $type): array
{
    return collect($plan->actions)->where('action', $type)->map(fn ($a) => ($a['role'] ?? '').':'.($a['permission'] ?? ''))->values()->all();
}

describe('the plan', function () {
    it('is empty and stable when the stored roles already match the code', function () {
        $first = $this->sync->plan();
        $second = $this->sync->plan();

        expect($first->isEmpty())->toBeTrue()
            ->and($first->hasRevocations())->toBeFalse()
            ->and($first->stalePermissions)->toBe([])
            ->and($first->fingerprint())->toBe($second->fingerprint())
            ->and($first->toArray())->toBe($second->toArray());
    });

    it('lists additions and revocations separately, in a stable sorted order', function () {
        Role::findByName('user', 'web')->permissions()->detach(Permission::findByName('tickets.view')->id);
        Role::findByName('user', 'web')->givePermissionTo(['time.manage', 'billing.admin']);
        Role::findByName('operator', 'web')->permissions()->detach(Permission::findByName('cms.admin')->id);

        $plan = $this->sync->plan();

        expect(actionsOf($plan, 'grant'))->toBe(['operator:cms.admin', 'user:tickets.view'])
            ->and(actionsOf($plan, 'revoke'))->toBe(['user:billing.admin', 'user:time.manage'])
            ->and(actionsOf($plan, 'create_permission'))->toBe([])
            ->and($plan->hasRevocations())->toBeTrue()
            ->and(collect($plan->actions)->pluck('action')->unique()->values()->all())->toBe(['grant', 'revoke'], 'additions come before revocations')
            ->and($plan->describe($plan->actions[0]))->toBe('grant cms.admin to operator');
    });

    it('is a pure read: it writes no row, takes no lock and leaves the shared permission cache untouched', function () {
        Role::findByName('user', 'web')->givePermissionTo('billing.admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        // R0.9-1E: the live cache entry sits under the ACTIVE generation's key, so that is the key to look at (the old,
        // unsuffixed key is never used and would be absent whatever the plan did). The epoch row is part of the state.
        $epoch = app(PermissionCacheEpoch::class);
        $generation = $epoch->current();
        $liveKey = config('permission.cache.key').':'.$generation;
        $cache = Cache::store(config('permission.cache.store') === 'default' ? null : config('permission.cache.store'));
        $state = syncRelevantState();
        $statements = [];
        DB::listen(function ($query) use (&$statements) {
            $statements[] = $query->sql;
        });

        $plan = $this->sync->plan();

        expect($plan->hasRevocations())->toBeTrue()
            ->and(collect($statements)->filter(fn ($sql) => ! str_starts_with(strtolower(ltrim($sql)), 'select') || str_contains(strtolower($sql), 'for update'))->all())->toBe([])
            ->and(syncRelevantState())->toBe($state)
            ->and($epoch->current())->toBe($generation, 'planning does not advance the generation')
            ->and($cache->has($liveKey))->toBeFalse('planning does not load the shared cache under the active generation')
            ->and($cache->has(config('permission.cache.key')))->toBeFalse();
    });

    it('reports permissions no built-in role defines without changing them', function () {
        Permission::create(['name' => 'legacy.thing', 'guard_name' => 'web']);

        $plan = $this->sync->plan();

        expect($plan->stalePermissions)->toBe(['legacy.thing'])
            ->and($plan->isEmpty())->toBeTrue()
            ->and($plan->fingerprint())->toBe($this->sync->plan()->fingerprint());
    });

    it('plans a new installation: every permission and both roles created, then granted, nothing revoked', function () {
        emptyPermissionTables();

        $plan = $this->sync->plan();

        expect(actionsOf($plan, 'create_permission'))->toHaveCount(count(PermissionCatalogue::all()))
            ->and(actionsOf($plan, 'create_role'))->toBe(['operator:', 'user:'])
            ->and(actionsOf($plan, 'grant'))->toHaveCount(count(PermissionCatalogue::all()) + count(PermissionCatalogue::userDefaults()))
            ->and($plan->hasRevocations())->toBeFalse()
            ->and($plan->administratorsBefore)->toBe([])
            ->and($plan->administratorsAfter)->toBe([]);
    });
});

describe('applying a plan', function () {
    it('builds a new installation from nothing, then reports in sync (idempotent)', function () {
        emptyPermissionTables();
        $plan = $this->sync->plan();

        $applied = $this->sync->apply($plan->fingerprint());

        expect($applied->fingerprint())->toBe($plan->fingerprint())
            ->and(Permission::count())->toBe(count(PermissionCatalogue::all()))
            ->and(grantNames('operator'))->toBe(sortedDefinition('operator'))
            ->and(grantNames('user'))->toBe(sortedDefinition('user'))
            ->and($this->sync->plan()->isEmpty())->toBeTrue();

        $afterFirst = syncRelevantState();
        $again = $this->sync->apply($this->sync->plan()->fingerprint());
        expect($again->isEmpty())->toBeTrue()
            ->and(syncRelevantState())->toBe($afterFirst, 'an empty plan writes nothing, not even an audit row');
    });

    it('applies additions without a revocation approval, and logs exactly one audit entry describing them', function () {
        Role::findByName('user', 'web')->permissions()->detach(Permission::findByName('tickets.view')->id);
        $plan = $this->sync->plan();

        $this->sync->apply($plan->fingerprint());

        $entry = Activity::where('description', 'synchronized built-in roles')->sole();
        expect(grantNames('user'))->toBe(sortedDefinition('user'))
            ->and($entry->properties['fingerprint'])->toBe($plan->fingerprint())
            ->and($entry->properties['actions'])->toBe(['grant tickets.view to user'])
            ->and($entry->properties['revocations'])->toBe(0);
    });

    it('refuses a plan with revocations until they are approved, and changes nothing meanwhile', function () {
        Role::findByName('user', 'web')->givePermissionTo('billing.admin');
        $plan = $this->sync->plan();
        $state = syncRelevantState();

        expect(fn () => $this->sync->apply($plan->fingerprint()))->toThrow(RoleSyncRefused::class, '--allow-revocations')
            ->and(syncRelevantState())->toBe($state)
            ->and(grantNames('user'))->toContain('billing.admin');

        $this->sync->apply($plan->fingerprint(), allowRevocations: true);

        expect(grantNames('user'))->toBe(sortedDefinition('user'))
            ->and(Activity::where('description', 'synchronized built-in roles')->sole()->properties['revocations'])->toBe(1);
    });

    it('refuses a stale approval: a plan reviewed before the stored state moved cannot apply', function () {
        Role::findByName('user', 'web')->givePermissionTo('billing.admin');
        $reviewed = $this->sync->plan();

        // Another change lands after the review.
        Role::findByName('operator', 'web')->permissions()->detach(Permission::findByName('cms.admin')->id);
        $state = syncRelevantState();

        expect(fn () => $this->sync->apply($reviewed->fingerprint(), allowRevocations: true))->toThrow(RoleSyncRefused::class, 'stale')
            ->and(syncRelevantState())->toBe($state);

        // The new plan, reviewed, applies.
        $this->sync->apply($this->sync->plan()->fingerprint(), allowRevocations: true);
        expect($this->sync->plan()->isEmpty())->toBeTrue();
    });

    it('does not hand a stale approval the fingerprint of the plan it should have reviewed', function () {
        Role::findByName('user', 'web')->givePermissionTo('billing.admin');
        $reviewed = $this->sync->plan();
        Role::findByName('operator', 'web')->permissions()->detach(Permission::findByName('cms.admin')->id);
        $replacement = $this->sync->plan()->fingerprint();

        expect($replacement)->not->toBe($reviewed->fingerprint());

        try {
            $this->sync->apply($reviewed->fingerprint(), allowRevocations: true);
            $this->fail('A stale approval must be refused.');
        } catch (RoleSyncRefused $refusal) {
            expect($refusal->getMessage())->toContain($reviewed->fingerprint())
                ->not->toContain($replacement)
                ->toContain('dry run');
        }
    });

    it('binds the predicted effective administrators: the same actions with different administrators need a new approval', function () {
        // One revocation, planned while a single account administers accounts.
        $first = User::factory()->create()->assignRole('operator');
        Role::findByName('user', 'web')->givePermissionTo('billing.admin');
        $reviewed = $this->sync->plan();

        // A role assignment changes who administers accounts. The role grants, and so the planned actions, are identical.
        $second = User::factory()->create()->assignRole('operator');
        $changed = $this->sync->plan();
        $state = syncRelevantState();

        expect($changed->actions)->toBe($reviewed->actions)
            ->and($reviewed->administratorsAfter)->toBe([$first->id])
            ->and($changed->administratorsAfter)->toBe([$first->id, $second->id])
            ->and($changed->fingerprint())->not->toBe($reviewed->fingerprint())
            ->and(fn () => $this->sync->apply($reviewed->fingerprint(), allowRevocations: true))->toThrow(RoleSyncRefused::class, 'stale')
            ->and(syncRelevantState())->toBe($state, 'the old approval changed nothing');

        $this->sync->apply($changed->fingerprint(), allowRevocations: true);
        expect(grantNames('user'))->toBe(sortedDefinition('user'));
    });

    it('refuses a fingerprint that was never a plan, and an empty one', function (string $fingerprint) {
        Role::findByName('user', 'web')->permissions()->detach(Permission::findByName('tickets.view')->id);
        $state = syncRelevantState();

        expect(fn () => $this->sync->apply($fingerprint, allowRevocations: true))->toThrow(RoleSyncRefused::class)
            ->and(syncRelevantState())->toBe($state);
    })->with(['0123456789abcdef', '']);

    it('leaves custom roles, their grants, role assignments and direct permissions exactly as they were', function () {
        Permission::create(['name' => 'legacy.thing', 'guard_name' => 'web']);
        $custom = Role::create(['name' => 'support-agent', 'guard_name' => 'web'])->givePermissionTo(['tickets.view', 'legacy.thing', 'users.manage']);
        $member = User::factory()->create()->assignRole('user', 'support-agent')->givePermissionTo('crm.view');
        Role::findByName('user', 'web')->givePermissionTo('billing.admin');
        Role::findByName('operator', 'web')->givePermissionTo('legacy.thing');

        $this->sync->apply($this->sync->plan()->fingerprint(), allowRevocations: true);

        expect(grantNames('support-agent'))->toBe(['legacy.thing', 'tickets.view', 'users.manage'])
            ->and(grantNames('operator'))->toBe(sortedDefinition('operator'), 'the built-in role is reset to the code')
            ->and($member->fresh()->getRoleNames()->sort()->values()->all())->toBe(['support-agent', 'user'])
            ->and($member->fresh()->getDirectPermissions()->pluck('name')->all())->toBe(['crm.view'])
            ->and(Permission::where('name', 'legacy.thing')->exists())->toBeTrue('a stale permission is reported, never deleted');
    });

    it('does not restore a removed privilege silently: a missing grant is a planned, reviewed addition', function () {
        Role::findByName('user', 'web')->permissions()->detach(Permission::findByName('tickets.view')->id);

        $plan = $this->sync->plan();

        expect($plan->isEmpty())->toBeFalse()
            ->and(actionsOf($plan, 'grant'))->toBe(['user:tickets.view'])
            ->and(grantNames('user'))->not->toContain('tickets.view'); // nothing is written until the plan is applied
    });
});

describe('refusing what it cannot trust', function () {
    it('fails visibly on malformed definitions and plans nothing', function (array $definitions, string $why) {
        $sync = new BuiltInRoleSynchronizer(app(AccountAdministrators::class), app(AccessGrants::class), $definitions);
        $state = syncRelevantState();

        expect(fn () => $sync->plan())->toThrow(RoleSyncRefused::class, $why)
            ->and(fn () => $sync->apply('0123456789abcdef', allowRevocations: true))->toThrow(RoleSyncRefused::class)
            ->and(syncRelevantState())->toBe($state);
    })->with(fn () => [
        'a permission the catalogue does not define' => [['operator' => PermissionCatalogue::all(), 'user' => ['tickets.view', 'not.defined']], 'not.defined'],
        'an operator that is not the whole catalogue' => [['operator' => array_values(array_diff(PermissionCatalogue::all(), ['cms.admin'])), 'user' => PermissionCatalogue::userDefaults()], 'every permission'],
        'an operator that cannot administer accounts' => [['operator' => array_values(array_diff(PermissionCatalogue::all(), ['users.manage'])), 'user' => PermissionCatalogue::userDefaults()], 'users.manage'],
        'a duplicated permission' => [['operator' => PermissionCatalogue::all(), 'user' => ['tickets.view', 'tickets.view']], 'more than once'],
        'a malformed permission name' => [['operator' => PermissionCatalogue::all(), 'user' => ['Tickets View']], 'malformed'],
        'a missing built-in role' => [['operator' => PermissionCatalogue::all()], 'exactly the built-in roles'],
        'an extra role' => [['operator' => PermissionCatalogue::all(), 'user' => [], 'support' => []], 'exactly the built-in roles'],
    ]);

    it('fails visibly when a built-in role exists for another guard', function () {
        Role::create(['name' => 'user', 'guard_name' => 'api']);
        $state = syncRelevantState();

        expect(fn () => $this->sync->plan())->toThrow(RoleSyncRefused::class, 'guard "api"')
            ->and(syncRelevantState())->toBe($state);
    });

    it('fails visibly when a built-in role exists only under a differently-cased name', function () {
        DB::table('roles')->where('name', 'operator')->update(['name' => 'Operator']);

        expect(fn () => $this->sync->plan())->toThrow(RoleSyncRefused::class, 'Operator');
    });

    it('fails visibly when a built-in role holds a permission of another guard', function () {
        $foreign = Permission::create(['name' => 'tickets.view', 'guard_name' => 'api']);
        DB::table('role_has_permissions')->insert(['role_id' => Role::findByName('user', 'web')->id, 'permission_id' => $foreign->id]);

        expect(fn () => $this->sync->plan())->toThrow(RoleSyncRefused::class, 'guard "api"');
    });
});

describe('a malformed or partial manifest cannot escalate privilege unseen', function () {
    it('shows a definition that grants account administration to the user role as an administrator change, and needs its fingerprint', function () {
        $member = User::factory()->create()->assignRole('user');
        $suspended = User::factory()->suspended()->create()->assignRole('user');
        $definitions = ['operator' => PermissionCatalogue::all(), 'user' => [...PermissionCatalogue::userDefaults(), 'users.view', 'users.manage']];
        $sync = new BuiltInRoleSynchronizer(app(AccountAdministrators::class), app(AccessGrants::class), $definitions);

        $plan = $sync->plan();

        expect($plan->administratorsChange())->toBeTrue()
            ->and($plan->administratorsBefore)->toBe([])
            ->and($plan->administratorsAfter)->toBe([$member->id], 'a suspended user never counts as an administrator')
            ->and($plan->fingerprint())->not->toBe($this->sync->plan()->fingerprint())
            ->and(fn () => $sync->apply($this->sync->plan()->fingerprint()))->toThrow(RoleSyncRefused::class, 'stale');

        $sync->apply($plan->fingerprint());
        expect(app(AccountAdministrators::class)->ids()->all())->toBe([$member->id], 'the prediction is exactly what the guard computes');
        expect($suspended->fresh()->isSuspended())->toBeTrue();
    });

    it('predicts the administrators after the plan exactly as the guard computes them', function () {
        $viaOperator = User::factory()->create()->assignRole('operator');
        $viaDrift = User::factory()->create()->assignRole('user');
        $direct = User::factory()->create()->givePermissionTo(['users.view', 'users.manage']);
        Role::findByName('user', 'web')->givePermissionTo(['users.view', 'users.manage']);

        $plan = $this->sync->plan();

        expect($plan->administratorsBefore)->toBe([$viaOperator->id, $viaDrift->id, $direct->id])
            ->and($plan->administratorsAfter)->toBe([$viaOperator->id, $direct->id]);

        $this->sync->apply($plan->fingerprint(), allowRevocations: true);
        expect(app(AccountAdministrators::class)->ids()->all())->toBe($plan->administratorsAfter);
    });
});

describe('administrator continuity', function () {
    it('cannot remove the last active administrator, and rolls the whole plan back', function () {
        $last = User::factory()->create()->assignRole('user');
        Role::findByName('user', 'web')->givePermissionTo(['users.view', 'users.manage', 'billing.admin']);
        $plan = $this->sync->plan();
        $state = syncRelevantState();

        expect($plan->administratorsBefore)->toBe([$last->id])
            ->and($plan->administratorsAfter)->toBe([])
            ->and(fn () => $this->sync->apply($plan->fingerprint(), allowRevocations: true))->toThrow(LastAccountAdministratorException::class)
            ->and(syncRelevantState())->toBe($state)
            ->and(app(AccountAdministrators::class)->ids()->all())->toBe([$last->id]);
    });

    it('does not count a suspended user, so revoking from them is not a last-administrator removal', function () {
        $suspended = User::factory()->suspended()->create()->assignRole('user');
        $active = User::factory()->create()->assignRole('operator');
        Role::findByName('user', 'web')->givePermissionTo(['users.view', 'users.manage']);

        $plan = $this->sync->plan();

        expect($plan->administratorsBefore)->toBe([$active->id])
            ->and($plan->administratorsAfter)->toBe([$active->id]);
        $this->sync->apply($plan->fingerprint(), allowRevocations: true);
        expect(grantNames('user'))->toBe(sortedDefinition('user'))
            ->and($suspended->fresh()->hasRole('user'))->toBeTrue();
    });

    it('lets an addition through when no administrator exists yet (a new installation)', function () {
        emptyPermissionTables();

        $this->sync->apply($this->sync->plan()->fingerprint());

        expect(app(AccountAdministrators::class)->ids()->all())->toBe([]);
    });
});

describe('atomicity and the shared permission cache', function () {
    it('is all-or-nothing when a step fails midway', function () {
        Permission::query()->where('name', 'time.log')->update(['name' => 'Time.Log']);
        $state = syncRelevantState();
        $plan = $this->sync->plan();

        expect(actionsOf($plan, 'create_permission'))->toBe([':time.log'])
            ->and(fn () => $this->sync->apply($plan->fingerprint(), allowRevocations: true))->toThrow(QueryException::class)
            ->and(syncRelevantState())->toBe($state)
            ->and(Activity::where('description', 'synchronized built-in roles')->exists())->toBeFalse();
    });

    it('clears the cache when the plan fails after reloading it from rows it had created, so no uncreated permission survives', function () {
        // `billing.view` is missing and is created first; `time.log` then collides (MariaDB compares names without case)
        // after the permission library has reloaded its cache, from the new, uncommitted row.
        DB::table('role_has_permissions')->where('permission_id', Permission::findByName('billing.view')->id)->delete();
        Permission::findByName('billing.view')->delete();
        Permission::query()->where('name', 'time.log')->update(['name' => 'Time.Log']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $plan = $this->sync->plan();

        expect(actionsOf($plan, 'create_permission'))->toBe([':billing.view', ':time.log'])
            ->and(fn () => $this->sync->apply($plan->fingerprint(), allowRevocations: true))->toThrow(QueryException::class);

        app(PermissionRegistrar::class)->clearPermissionsCollection(); // the next request reads the shared store
        expect(app(PermissionRegistrar::class)->getPermissions(['name' => 'billing.view']))->toHaveCount(0)
            ->and(Permission::where('name', 'billing.view')->exists())->toBeFalse();
    });

    it('serves the new grants straight after the commit, whatever the cache held before', function () {
        $member = User::factory()->create()->assignRole('user');
        Role::findByName('user', 'web')->givePermissionTo('billing.admin');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        expect($member->fresh()->hasPermissionTo('billing.admin'))->toBeTrue(); // loads and caches the drifted mapping

        $this->sync->apply($this->sync->plan()->fingerprint(), allowRevocations: true);
        app(PermissionRegistrar::class)->clearPermissionsCollection(); // the next request reads the shared store

        expect($member->fresh()->hasPermissionTo('billing.admin'))->toBeFalse()
            ->and($member->fresh()->hasPermissionTo('tickets.view'))->toBeTrue();
    });

    it('clears the cache again when an outer transaction rolls the applied plan back', function () {
        $member = User::factory()->create()->assignRole('user');
        Role::findByName('user', 'web')->givePermissionTo('billing.admin');
        $plan = $this->sync->plan();
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        try {
            DB::transaction(function () use ($plan) {
                $this->sync->apply($plan->fingerprint(), allowRevocations: true);
                app(PermissionRegistrar::class)->getPermissions(); // caches the uncommitted state (billing.admin revoked)

                throw new RuntimeException('roll back');
            });
        } catch (RuntimeException) {
        }

        app(PermissionRegistrar::class)->clearPermissionsCollection();
        expect($member->fresh()->hasPermissionTo('billing.admin'))->toBeTrue('the rolled-back revocation must not survive in the cache');
    });
});
