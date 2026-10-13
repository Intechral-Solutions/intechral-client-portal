<?php

use App\Exceptions\BuiltInRoleException;
use App\Models\User;
use App\Services\AccessGrants;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

/*
 * Release 0.9 (R0.9-1D, decision H4): built-in roles (operator, user) are owned by the application. Ordinary administration,
 * through the Roles screens or the service they call, can neither change their permissions nor delete them. Custom roles
 * keep their full management, and assigning users to a built-in role is still ordinary user administration.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

function grantsOf(string $role): array
{
    return Role::findByName($role, 'web')->permissions()->pluck('name')->sort()->values()->all();
}

describe('the authoritative catalogue', function () {
    it('names exactly the roles the seeders create and the screens call built-in', function () {
        expect(BuiltInRoles::names())->toBe(['operator', 'user'])
            ->and(BuiltInRoles::isBuiltIn('operator'))->toBeTrue()
            ->and(BuiltInRoles::isBuiltIn('Operator'))->toBeTrue('names compare as the roles table does: case-insensitively')
            ->and(BuiltInRoles::isBuiltIn('support-agent'))->toBeFalse()
            ->and(BuiltInRoles::problems())->toBe([]);
    });

    it('defines operator as the whole permission catalogue and user as the platform-user defaults', function () {
        $definitions = BuiltInRoles::definitions();

        expect($definitions['operator'])->toEqualCanonicalizing(PermissionCatalogue::all())
            ->and($definitions['user'])->toEqualCanonicalizing(PermissionCatalogue::userDefaults());
    });
});

describe('editing a built-in role', function () {
    it('is refused for both built-in roles, changes nothing and logs nothing', function (string $name) {
        $before = grantsOf($name);
        $activities = Activity::count();

        $this->actingAs($this->operator)
            ->from(route('roles.edit', Role::findByName($name)))
            ->put(route('roles.update', Role::findByName($name)), ['permissions' => ['tickets.view']])
            ->assertRedirect(route('roles.edit', Role::findByName($name)))
            ->assertSessionHasErrors(['role' => (new BuiltInRoleException($name))->getMessage()]);

        expect(grantsOf($name))->toBe($before)
            ->and(Activity::count())->toBe($activities);
    })->with(['operator', 'user']);

    it('answers a JSON caller with 409 and the same message', function () {
        $this->actingAs($this->operator)
            ->putJson(route('roles.update', Role::findByName('user')), ['permissions' => []])
            ->assertStatus(409)
            ->assertJson(['message' => (new BuiltInRoleException('user'))->getMessage()]);
    });

    it('is refused before the request is validated, so invalid input cannot reveal anything or change the answer', function () {
        $this->actingAs($this->operator)
            ->put(route('roles.update', Role::findByName('user')), ['permissions' => ['no.such.permission']])
            ->assertSessionHasErrors('role')
            ->assertSessionDoesntHaveErrors('permissions.0');
    });

    it('shows the edit page read-only: permissions are listed but disabled, there is nothing to save or delete', function () {
        $html = $this->actingAs($this->operator)->get(route('roles.edit', Role::findByName('user')))->assertOk()
            ->assertSee('Built-in')
            ->assertDontSee('Save Changes')
            ->assertDontSee('Delete Role')
            ->getContent();

        $xpath = uiDom($html);
        expect($xpath->query('//fieldset[@disabled]//input[@type="checkbox"]')->length)->toBe(count(PermissionCatalogue::all()))
            ->and($xpath->query('//fieldset[@disabled]//input[@type="checkbox"][@checked]')->length)->toBe(count(BuiltInRoles::definitions()['user']));
    });
});

describe('deleting a built-in role', function () {
    it('is refused for both built-in roles, even with no holders', function (string $name) {
        $role = Role::findByName($name);
        User::query()->get()->each(fn (User $user) => $user->removeRole($name));
        $before = grantsOf($name);

        $this->actingAs($this->operator->fresh()->assignRole('operator'))
            ->delete(route('roles.destroy', $role))
            ->assertRedirect()
            ->assertSessionHasErrors('role');

        expect(Role::where('name', $name)->exists())->toBeTrue()
            ->and(grantsOf($name))->toBe($before);
    })->with(['operator', 'user']);

    it('answers a JSON caller with 409', function () {
        $this->actingAs($this->operator)
            ->deleteJson(route('roles.destroy', Role::findByName('user')))
            ->assertStatus(409);
    });
});

describe('the service behind the screens', function () {
    it('refuses a built-in role on a direct call too', function () {
        $grants = app(AccessGrants::class);
        $user = Role::findByName('user');

        expect(fn () => $grants->syncRolePermissions($user, []))->toThrow(BuiltInRoleException::class)
            ->and(fn () => $grants->deleteUnassignedRole($user))->toThrow(BuiltInRoleException::class)
            ->and(grantsOf('user'))->toBe(collect(BuiltInRoles::definitions()['user'])->sort()->values()->all());
    });

    it('refuses a role whose name is only a differently-cased built-in name', function () {
        // A MariaDB comparison treats "Operator" as the built-in row; the service must too.
        expect(fn () => app(AccessGrants::class)->assertCustom(new Role(['name' => 'OPERATOR', 'guard_name' => 'web'])))
            ->toThrow(BuiltInRoleException::class);
    });
});

describe('custom roles', function () {
    it('keep creating, editing and deleting, with the same audit trail and last-administrator guard', function () {
        $this->actingAs($this->operator)
            ->post(route('roles.store'), ['name' => 'support-agent', 'permissions' => ['tickets.view']])
            ->assertRedirect(route('roles.index'));
        $role = Role::findByName('support-agent');

        $this->actingAs($this->operator)
            ->put(route('roles.update', $role), ['permissions' => ['tickets.view', 'tickets.create']])
            ->assertRedirect(route('roles.index'))
            ->assertSessionHasNoErrors();
        expect(grantsOf('support-agent'))->toBe(['tickets.create', 'tickets.view']);

        $this->actingAs($this->operator)->delete(route('roles.destroy', $role))->assertRedirect(route('roles.index'));
        expect(Role::where('name', 'support-agent')->exists())->toBeFalse()
            ->and(Activity::where('description', 'updated role permissions')->count())->toBe(1)
            ->and(Activity::where('description', 'deleted role "support-agent"')->count())->toBe(1);
    });

    it('cannot take a reserved built-in name, in any letter case', function (string $name) {
        $this->actingAs($this->operator)
            ->post(route('roles.store'), ['name' => $name])
            ->assertSessionHasErrors('name');

        expect(Role::whereIn('name', ['operator', 'user'])->count())->toBe(2);
    })->with(['operator', 'Operator', 'USER']);

    it('are never presented as built-in', function () {
        Role::create(['name' => 'support-agent', 'guard_name' => 'web']);

        $html = $this->actingAs($this->operator)->get(route('roles.index'))->assertOk()->getContent();
        $rows = collect(uiDom($html)->query('//tbody/tr'))->mapWithKeys(fn ($row) => [trim($row->childNodes->item(1)->textContent) => trim($row->textContent)]);

        expect($rows['support-agent'])->toContain('Custom')->toContain('Edit')->not->toContain('Built-in')
            ->and($rows['operator'])->toContain('Built-in')->toContain('View')->not->toContain('Edit')
            ->and($rows['user'])->toContain('Built-in');
    });
});

describe('who may ask', function () {
    it('keeps every role mutation behind its permission, built-in role or not', function () {
        $viewer = User::factory()->create()->givePermissionTo('roles.view');
        $manager = User::factory()->create()->givePermissionTo(['roles.view', 'roles.manage']);
        $custom = Role::create(['name' => 'in-reach', 'guard_name' => 'web']);

        $this->actingAs($viewer)->put(route('roles.update', $custom), ['permissions' => []])->assertForbidden();
        $this->actingAs($viewer)->put(route('roles.update', Role::findByName('user')), ['permissions' => []])->assertForbidden();
        $this->actingAs($manager)->delete(route('roles.destroy', $custom))->assertForbidden();
        $this->actingAs($manager)->delete(route('roles.destroy', Role::findByName('user')))->assertForbidden();
        auth()->logout();
        $this->put(route('roles.update', $custom), ['permissions' => []])->assertRedirect('/login');

        expect(Role::where('name', 'in-reach')->exists())->toBeTrue()
            ->and(grantsOf('user'))->toBe(collect(BuiltInRoles::definitions()['user'])->sort()->values()->all());
    });
});

describe('role assignments', function () {
    it('stay ordinary user administration: users keep and change their built-in roles, ids unchanged', function () {
        $member = User::factory()->create()->assignRole('user');
        $roleIds = Role::whereIn('name', ['operator', 'user'])->pluck('id', 'name')->all();

        // A refused built-in edit and delete leave every assignment, and the role ids, as they were.
        $this->actingAs($this->operator)->put(route('roles.update', Role::findByName('user')), ['permissions' => []]);
        $this->actingAs($this->operator)->delete(route('roles.destroy', Role::findByName('user')));
        expect($member->fresh()->hasRole('user'))->toBeTrue()
            ->and($this->operator->fresh()->hasRole('operator'))->toBeTrue()
            ->and(Role::whereIn('name', ['operator', 'user'])->pluck('id', 'name')->all())->toBe($roleIds);

        // Moving a user between roles, including a built-in one, is still allowed.
        $this->actingAs($this->operator)
            ->put(route('users.roles.update', $member), ['roles' => ['operator']])
            ->assertSessionHasNoErrors();
        expect($member->fresh()->hasRole('operator'))->toBeTrue();
    });
});
