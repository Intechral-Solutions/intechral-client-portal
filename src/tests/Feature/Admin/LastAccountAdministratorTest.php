<?php

use App\Exceptions\LastAccountAdministratorException;
use App\Models\User;
use App\Services\AccessGrants;
use App\Services\AccountAdministrators;
use App\Services\AccountLifecycleService;
use Illuminate\Support\Facades\DB;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

/*
 * Release 0.9 (H3, R0.9-1C): the portal always keeps at least one ACTIVE account administrator, a user who holds both
 * users.view and users.manage (directly or through a role). Every path that can shrink that set is refused when it
 * would empty it: suspension, a user's role change, a role's permission change, and role deletion. The concurrent
 * cases are LastAccountAdministratorConcurrencyTest.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->admins = app(AccountAdministrators::class);
});

/**
 * The account error as a redirect leaves it in the session, in the session's stored (JSON) form. A feature test keeps
 * one in-memory session store across requests, and with JSON serialization the next request re-reads `errors` as an
 * array, so a ViewErrorBag object still held in memory would come out empty; seeding the stored form avoids that.
 */
function flashedAccountError(): array
{
    return [
        'errors' => ['default' => ['format' => ':message', 'messages' => ['account' => [LastAccountAdministratorException::MESSAGE]]]],
        '_flash' => ['old' => [], 'new' => ['errors']],
    ];
}

function accountAdministrator(array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->assignRole('operator');

    return $user;
}

describe('who counts', function () {
    it('is exactly the active users the account-administration gates admit', function () {
        $viaRole = accountAdministrator();
        $direct = User::factory()->create();
        $direct->givePermissionTo(['users.view', 'users.manage']);
        $mixed = User::factory()->create();
        $mixed->givePermissionTo('users.manage');
        $mixed->assignRole(Role::create(['name' => 'directory-viewer', 'guard_name' => 'web'])->givePermissionTo('users.view'));
        $viewOnly = User::factory()->create();
        $viewOnly->givePermissionTo('users.view');
        $manageOnly = User::factory()->create();
        $manageOnly->givePermissionTo('users.manage');
        $adminOnly = User::factory()->create();
        $adminOnly->givePermissionTo(['users.view', 'users.admin']);
        $customer = User::factory()->create();
        $customer->assignRole('user');
        $suspended = accountAdministrator(['disabled_at' => now()]);

        $everyone = [$viaRole, $direct, $mixed, $viewOnly, $manageOnly, $adminOnly, $customer, $suspended];
        $gatesAdmit = collect($everyone)
            ->filter(fn (User $user) => $user->can('users.view') && $user->can('users.manage') && ! $user->isSuspended())
            ->pluck('id')->sort()->values()->all();

        expect($this->admins->ids()->all())->toBe($gatesAdmit)
            ->and($gatesAdmit)->toBe(collect([$viaRole->id, $direct->id, $mixed->id])->sort()->values()->all());
    });
});

describe('suspension', function () {
    it('is refused for the last active administrator, and the refusal changes nothing', function () {
        $last = accountAdministrator();
        $actor = User::factory()->create(); // a non-administrator actor, as a console or future path could be
        $token = $last->getRememberToken();

        expect(fn () => app(AccountLifecycleService::class)->suspend($last, $actor, 'Attempt'))
            ->toThrow(LastAccountAdministratorException::class);

        expect($last->fresh()->isSuspended())->toBeFalse()
            ->and($last->fresh()->getRememberToken())->toBe($token)
            ->and(Activity::where('description', AccountLifecycleService::SUSPENDED)->exists())->toBeFalse()
            ->and($this->admins->ids()->all())->toBe([$last->id]);
    });

    it('counts only active administrators: a suspended one does not make another suspendable', function () {
        $last = accountAdministrator();
        accountAdministrator(['disabled_at' => now()]);

        expect(fn () => app(AccountLifecycleService::class)->suspend($last, User::factory()->create(), 'Attempt'))
            ->toThrow(LastAccountAdministratorException::class);
    });

    it('is allowed while another active administrator remains', function () {
        $actor = accountAdministrator();
        $target = accountAdministrator();

        $this->actingAs($actor)->post(route('users.suspend', $target), ['reason' => 'Rotation'])->assertSessionHasNoErrors();

        expect($target->fresh()->isSuspended())->toBeTrue()
            ->and($this->admins->ids()->all())->toBe([$actor->id]);
    });
});

describe('demotion through a user\'s roles', function () {
    it('refuses removing the last administrator\'s role, and keeps the role', function () {
        $last = accountAdministrator();

        $this->actingAs($last)
            ->from(route('users.show', $last))
            ->put(route('users.roles.update', $last), ['roles' => ['user']])
            ->assertRedirect(route('users.show', $last))
            ->assertSessionHasErrors(['account' => LastAccountAdministratorException::MESSAGE]);

        expect($last->fresh()->getRoleNames()->all())->toBe(['operator'])
            ->and(Activity::where('description', 'updated user roles')->exists())->toBeFalse();
    });

    it('answers a JSON caller with 409 Conflict', function () {
        $last = accountAdministrator();

        $this->actingAs($last)
            ->putJson(route('users.roles.update', $last), ['roles' => []])
            ->assertStatus(409)
            ->assertJson(['message' => LastAccountAdministratorException::MESSAGE]);

        expect($last->fresh()->hasRole('operator'))->toBeTrue();
    });

    it('allows it while another active administrator remains, including demoting oneself', function () {
        $self = accountAdministrator();
        $other = accountAdministrator();

        $this->actingAs($self)->put(route('users.roles.update', $self), ['roles' => ['user']])->assertSessionHasNoErrors();

        expect($self->fresh()->getRoleNames()->all())->toBe(['user'])
            ->and($this->admins->ids()->all())->toBe([$other->id]);
    });

    it('allows changes that keep the administrator set non-empty, such as adding a role', function () {
        $last = accountAdministrator();

        $this->actingAs($last)->put(route('users.roles.update', $last), ['roles' => ['operator', 'user']])->assertSessionHasNoErrors();

        expect($last->fresh()->getRoleNames()->sort()->values()->all())->toBe(['operator', 'user']);
    });
});

describe('demotion through a role\'s permissions', function () {
    it('refuses removing users.manage from the role every administrator holds it through, and keeps the role intact', function () {
        $last = accountAdministrator();
        $operator = Role::findByName('operator', 'web');
        $permissionsBefore = $operator->permissions()->pluck('name')->sort()->values()->all();
        $without = array_values(array_diff($permissionsBefore, ['users.manage']));

        $this->actingAs($last)
            ->from(route('roles.edit', $operator))
            ->put(route('roles.update', $operator), ['permissions' => $without])
            ->assertRedirect(route('roles.edit', $operator))
            ->assertSessionHasErrors(['account' => LastAccountAdministratorException::MESSAGE]);

        expect(Role::findByName('operator', 'web')->permissions()->pluck('name')->sort()->values()->all())->toBe($permissionsBefore)
            ->and($last->fresh()->can('users.manage'))->toBeTrue();

        // The edit page shows the refusal (seeded in the session's stored form; see flashedAccountError()).
        $this->actingAs($last)->withSession(flashedAccountError())->get(route('roles.edit', $operator))
            ->assertSee(LastAccountAdministratorException::MESSAGE);
    });

    it('refuses removing users.view the same way', function () {
        $last = accountAdministrator();
        $operator = Role::findByName('operator', 'web');
        $without = array_values(array_diff($operator->permissions()->pluck('name')->all(), ['users.view']));

        $this->actingAs($last)->put(route('roles.update', $operator), ['permissions' => $without])
            ->assertSessionHasErrors('account');

        expect(Role::findByName('operator', 'web')->hasPermissionTo('users.view'))->toBeTrue();
    });

    it('allows it when another administrator holds the access some other way', function () {
        $viaRole = accountAdministrator();
        $direct = User::factory()->create();
        $direct->givePermissionTo(['users.view', 'users.manage']);
        $operator = Role::findByName('operator', 'web');
        $without = array_values(array_diff($operator->permissions()->pluck('name')->all(), ['users.manage']));

        $this->actingAs($viaRole)->put(route('roles.update', $operator), ['permissions' => $without])->assertSessionHasNoErrors();

        expect($this->admins->ids()->all())->toBe([$direct->id]);
    });
});

describe('role deletion', function () {
    it('still refuses a role that is assigned, and deletes an unassigned one under the guard', function () {
        $actor = accountAdministrator();
        $assigned = Role::create(['name' => 'assigned-role', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($assigned);
        $unassigned = Role::create(['name' => 'unassigned-role', 'guard_name' => 'web']);

        $this->actingAs($actor)->delete(route('roles.destroy', $assigned))
            ->assertSessionHasErrors(['role' => 'Cannot delete "assigned-role" — it is assigned to 1 user(s). Reassign them first.']);
        $this->actingAs($actor)->delete(route('roles.destroy', $unassigned))->assertRedirect(route('roles.index'));

        expect(Role::where('name', 'assigned-role')->exists())->toBeTrue()
            ->and(Role::where('name', 'unassigned-role')->exists())->toBeFalse()
            ->and(Activity::where('description', 'deleted role "unassigned-role"')->exists())->toBeTrue()
            ->and(Activity::where('description', 'deleted role "assigned-role"')->exists())->toBeFalse();
    });

    it('re-reads the holders inside the guarded transaction rather than trusting an earlier count', function () {
        $actor = accountAdministrator();
        $role = Role::create(['name' => 'late-assignment', 'guard_name' => 'web']);
        User::factory()->create()->assignRole($role);

        // The holder count is the one read inside AccessGrants::deleteUnassignedRole.
        expect(app(AccessGrants::class)->deleteUnassignedRole($role))->toBe(1)
            ->and(Role::where('name', 'late-assignment')->exists())->toBeTrue();
    });
});

describe('the guard', function () {
    it('rolls back everything the mutation did when it refuses', function () {
        $last = accountAdministrator();
        $bystander = User::factory()->create();

        expect(fn () => $this->admins->guard(function () use ($last, $bystander) {
            $bystander->forceFill(['name' => 'Changed inside the guard'])->save();
            $last->syncRoles([]);
        }))->toThrow(LastAccountAdministratorException::class);

        expect($bystander->fresh()->name)->not->toBe('Changed inside the guard')
            ->and($last->fresh()->hasRole('operator'))->toBeTrue();
    });

    it('does not refuse a mutation when there was no administrator to begin with', function () {
        $user = User::factory()->create();
        $user->assignRole('user');

        expect($this->admins->ids()->all())->toBe([]);
        app(AccessGrants::class)->syncUserRoles($user, []);

        expect($user->fresh()->getRoleNames()->all())->toBe([]);
    });

    it('takes an exclusive lock on the users.manage permission row as its first statement', function () {
        accountAdministrator();
        $queries = [];
        DB::listen(function ($query) use (&$queries) {
            $queries[] = $query->sql;
        });

        $this->admins->guard(fn () => null);

        expect($queries[0])->toContain('from `permissions`')
            ->toContain('for update');
    });
});
