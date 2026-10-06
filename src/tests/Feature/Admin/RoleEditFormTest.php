<?php

use App\Models\User;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/../../Support/UiHtmlHelpers.php';

/*
 * Hotfix: `admin/roles/edit` nested the "Delete Role" <form> inside the update <form>. Browsers do not
 * nest forms: the inner start tag is dropped, so both buttons belonged to the update form, its `_method`
 * fields were PUT and then DELETE (PHP keeps the last), and Save Changes and Delete Role both sent DELETE,
 * with the delete form's confirm() lost. These tests pin the corrected structure by form OWNERSHIP, the way
 * a browser resolves it, plus the two request paths and the protections that must not move.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

/** The number of <form> elements open at the deepest point of the markup (1 = no nesting). */
function roleFormDepth(string $html): int
{
    $depth = 0;
    $max = 0;

    preg_match_all('/<(\/?)form\b/i', $html, $tags, PREG_SET_ORDER);
    foreach ($tags as $tag) {
        $depth += $tag[1] === '/' ? -1 : 1;
        $max = max($max, $depth);
    }

    return $max;
}

/**
 * The form that owns a control by HTML's ownership rule for VALID markup: the `form` attribute (an id) wins, otherwise the nearest
 * ancestor <form>.
 */
function roleControlOwner(DOMXPath $xpath, DOMElement $control): ?DOMElement
{
    if ($control->hasAttribute('form')) {
        $owner = $xpath->query('//form[@id="'.$control->getAttribute('form').'"]')->item(0);

        return $owner instanceof DOMElement ? $owner : null;
    }

    for ($node = $control->parentNode; $node instanceof DOMElement; $node = $node->parentNode) {
        if ($node->tagName === 'form') {
            return $node;
        }
    }

    return null;
}

/** The `_method` values a form submits: every `_method` input it owns. */
function roleOwnedMethods(DOMXPath $xpath, DOMElement $form): array
{
    $methods = [];

    foreach ($xpath->query('//input[@name="_method"]') as $input) {
        if (roleControlOwner($xpath, $input) === $form) {
            $methods[] = $input->getAttribute('value');
        }
    }

    return $methods;
}

function roleEditPage($test, Role $role): string
{
    return $test->actingAs($test->operator)->get(route('roles.edit', $role))->assertOk()->getContent();
}

it('renders one update form and one separate delete form, never nested', function () {
    $role = Role::create(['name' => 'form-structure', 'guard_name' => 'web']);
    $html = roleEditPage($this, $role);
    $xpath = uiDom($html);

    expect(roleFormDepth($html))->toBe(1);

    // Update and delete share one URL (that is the hazard), so the forms are told apart by what they own.
    $update = roleControlOwner($xpath, uiOne($xpath, '//button[normalize-space()="Save Changes"]'));
    $delete = uiOne($xpath, '//form[@id="role-delete-form"]');

    expect($xpath->query('//form[@action="'.route('roles.update', $role).'"]')->length)->toBe(2)
        ->and($update)->not->toBeNull()
        ->and($update)->not->toBe($delete)
        ->and($xpath->query('.//form', $update)->length)->toBe(0)
        ->and($xpath->query('.//form', $delete)->length)->toBe(0)
        ->and($xpath->query('ancestor::form', $delete)->length)->toBe(0)
        ->and($xpath->query('ancestor::form', $update)->length)->toBe(0);
});

it('owns Save Changes in the update form and Delete Role in the delete form, each with its own method', function () {
    $role = Role::create(['name' => 'form-ownership', 'guard_name' => 'web']);
    $html = roleEditPage($this, $role);
    $xpath = uiDom($html);

    $save = uiOne($xpath, '//button[normalize-space()="Save Changes"]');
    $remove = uiOne($xpath, '//button[normalize-space()="Delete Role"]');
    $update = roleControlOwner($xpath, $save);
    $delete = uiOne($xpath, '//form[@id="role-delete-form"]');

    // No <form> is ever open inside another: this alone fails on the old nested markup.
    expect(roleFormDepth($html))->toBe(1);

    expect($update)->not->toBeNull()
        ->and($update->getAttribute('action'))->toBe(route('roles.update', $role))
        ->and($delete->getAttribute('action'))->toBe(route('roles.destroy', $role))
        ->and(roleControlOwner($xpath, $save))->toBe($update)
        ->and(roleControlOwner($xpath, $remove))->toBe($delete)
        ->and($save->getAttribute('type'))->toBe('submit')
        ->and($remove->getAttribute('type'))->toBe('submit')
        // What each form submits: the update form only PUT, the delete form only DELETE.
        ->and(roleOwnedMethods($xpath, $update))->toBe(['PUT'])
        ->and(roleOwnedMethods($xpath, $delete))->toBe(['DELETE'])
        // The confirmation belongs to the delete form, and only to it.
        ->and($delete->getAttribute('onsubmit'))->toContain("confirm('Delete role \\'form-ownership\\'?')")
        ->and($update->hasAttribute('onsubmit'))->toBeFalse();
});

it('keeps both buttons named and the page free of duplicate ids', function () {
    $role = Role::create(['name' => 'form-a11y', 'guard_name' => 'web']);
    $html = roleEditPage($this, $role);

    expect(uiAccessibilityViolations($html))->toBe([]);

    $xpath = uiDom($html);
    expect($xpath->query('//*[@id="role-delete-form"]')->length)->toBe(1)
        ->and($xpath->query('//button[normalize-space()="Save Changes"]')->length)->toBe(1)
        ->and($xpath->query('//button[normalize-space()="Delete Role"]')->length)->toBe(1);
});

it('offers no delete control for a built-in role, and still refuses the request', function () {
    $builtIn = Role::findByName('user');
    $html = roleEditPage($this, $builtIn);
    $xpath = uiDom($html);

    expect($xpath->query('//form[@id="role-delete-form"]')->length)->toBe(0)
        ->and($xpath->query('//button[normalize-space()="Delete Role"]')->length)->toBe(0)
        ->and(roleFormDepth($html))->toBe(1);

    $this->actingAs($this->operator)->delete(route('roles.destroy', $builtIn))->assertSessionHasErrors('role');
    expect(Role::where('name', 'user')->exists())->toBeTrue();
});

it('offers no delete control to a user without roles.admin', function () {
    $manager = User::factory()->create();
    $manager->givePermissionTo(['roles.view', 'roles.manage']);
    $role = Role::create(['name' => 'no-admin-view', 'guard_name' => 'web']);

    $html = $this->actingAs($manager)->get(route('roles.edit', $role))->assertOk()->getContent();

    expect(uiDom($html)->query('//button[normalize-space()="Delete Role"]')->length)->toBe(0)
        ->and(uiDom($html)->query('//form[@id="role-delete-form"]')->length)->toBe(0);
});

it('saves through the update endpoint only: the permissions persist, the role stays, nothing is deleted', function () {
    $role = Role::create(['name' => 'save-only', 'guard_name' => 'web']);

    $this->actingAs($this->operator)
        ->put(route('roles.update', $role), ['permissions' => ['tickets.view']])
        ->assertRedirect(route('roles.index'))
        ->assertSessionHas('status', 'Role "save-only" updated.');

    expect(Role::where('name', 'save-only')->exists())->toBeTrue()
        ->and($role->fresh()->hasPermissionTo('tickets.view'))->toBeTrue()
        ->and(Activity::where('description', 'updated role permissions')->count())->toBe(1)
        ->and(Activity::where('description', 'like', 'deleted role%')->count())->toBe(0);
});

it('deletes through the delete endpoint only: the role goes and the update path is never invoked', function () {
    $role = Role::create(['name' => 'delete-only', 'guard_name' => 'web']);
    $role->givePermissionTo('tickets.view');

    $this->actingAs($this->operator)
        ->delete(route('roles.destroy', $role))
        ->assertRedirect(route('roles.index'))
        ->assertSessionHas('status', 'Role "delete-only" deleted.');

    expect(Role::where('name', 'delete-only')->exists())->toBeFalse()
        ->and(Activity::where('description', 'deleted role "delete-only"')->count())->toBe(1)
        ->and(Activity::where('description', 'updated role permissions')->count())->toBe(0);
});

it('still refuses to delete a role with assigned users and leaves the users untouched', function () {
    $role = Role::create(['name' => 'in-use', 'guard_name' => 'web']);
    $member = User::factory()->create()->assignRole('in-use');

    $this->actingAs($this->operator)
        ->delete(route('roles.destroy', $role))
        ->assertRedirect()
        ->assertSessionHasErrors('role');

    expect(Role::where('name', 'in-use')->exists())->toBeTrue()
        ->and($member->fresh()->hasRole('in-use'))->toBeTrue()
        ->and(Activity::where('description', 'like', 'deleted role%')->count())->toBe(0);
});
