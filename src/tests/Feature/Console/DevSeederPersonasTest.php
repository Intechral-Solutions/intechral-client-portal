<?php

use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Shell\ShellPresentation;
use App\Shared\Shell\ShellPresentationResolver;
use Database\Seeders\DevSeeder;
use Spatie\Permission\Models\Role;

/*
 * R1-1: the built-in `user` role no longer carries billing.view or time.log, but the legacy-mode browser suite drives invoices and time
 * through seeded personas. This pins what each persona effectively holds after DevSeeder: the explicit, persona-specific grants, and
 * that the default customer role was not given them back.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function seededPersona(string $email): User
{
    return User::where('email', $email)->firstOrFail();
}

it('grants the legacy browser personas exactly the billing and time capabilities their specs need, and not the plain customer', function () {
    $this->seed(DevSeeder::class);

    $member = seededPersona('user@intechral.test');
    $customer = seededPersona('e2e-customer@intechral.test');
    $other = seededPersona('e2e-customer-other@intechral.test');
    $operational = seededPersona('e2e-operational-member@intechral.test');

    expect($member->can('billing.view'))->toBeTrue()
        ->and($member->can('time.log'))->toBeTrue()
        ->and($customer->can('billing.view'))->toBeTrue()
        ->and($customer->can('time.log'))->toBeTrue()
        ->and($operational->can('billing.view'))->toBeTrue()
        ->and($operational->can('time.log'))->toBeTrue()
        ->and($operational->can('projects.manage'))->toBeTrue()
        ->and($other->can('billing.view'))->toBeFalse()
        ->and($other->can('time.log'))->toBeFalse();

    // Nothing else was widened: no persona holds an operational billing or time capability.
    foreach ([$member, $customer, $other, $operational] as $persona) {
        expect($persona->can('billing.manage'))->toBeFalse()->and($persona->can('time.view_all'))->toBeFalse();
    }
});

it('keeps the personas\' shells: the two customers Focused, the operational member Operational', function () {
    $this->seed(DevSeeder::class);
    $resolver = app(ShellPresentationResolver::class);

    expect($resolver->forUser(seededPersona('e2e-customer@intechral.test')))->toBe(ShellPresentation::Focused)
        ->and($resolver->forUser(seededPersona('e2e-customer-other@intechral.test')))->toBe(ShellPresentation::Focused)
        ->and($resolver->forUser(seededPersona('e2e-operational-member@intechral.test')))->toBe(ShellPresentation::Operational);
});

it('does not hand those capabilities back to the default customer role, and is idempotent', function () {
    $this->seed(DevSeeder::class);
    $this->seed(DevSeeder::class);

    $role = Role::findByName('user', 'web')->permissions->pluck('name')->all();
    expect($role)->toEqualCanonicalizing(PermissionCatalogue::userDefaults())
        ->and($role)->not->toContain('billing.view', 'time.log');

    $customer = seededPersona('e2e-customer@intechral.test');
    expect($customer->getDirectPermissions()->pluck('name')->all())->toEqualCanonicalizing(['billing.view', 'time.log'])
        ->and(User::where('email', 'e2e-customer@intechral.test')->count())->toBe(1);
});
