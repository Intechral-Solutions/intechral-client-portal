<?php

use App\Models\Ticket;
use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Shell\ShellPresentation;
use App\Shared\Shell\ShellPresentationResolver;
use Database\Seeders\DevSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
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

it('R1-2: gives each persona that files requests one organization of its own, attributes the fixture tickets to them, and stays idempotent', function () {
    $this->seed(DevSeeder::class);
    $this->seed(DevSeeder::class);

    $memberships = fn (string $email) => DB::table('organization_members')->where('user_id', seededPersona($email)->id)
        ->join('organizations', 'organizations.id', '=', 'organization_members.organization_id')->pluck('organizations.slug', 'organization_members.role')->all();

    // One organization each, a plain member: no administrator role or approval, so no persona holds more than eligibility.
    expect($memberships('e2e-customer@intechral.test'))->toBe(['member' => 'e2e-customer-org'])
        ->and($memberships('e2e-customer-other@intechral.test'))->toBe(['member' => 'e2e-other-org'])
        ->and($memberships('operator@intechral.test'))->toBe(['member' => 'e2e-operator-org'])
        ->and(DB::table('organizations')->count())->toBe(3)
        ->and(DB::table('organization_members')->count())->toBe(3);

    $customerTicket = Ticket::where('ticket_number', 'TK-E2E00-00002')->sole();
    $operatorTicket = Ticket::where('ticket_number', 'TK-E2E00-00001')->sole();

    expect($customerTicket->organization->slug)->toBe('e2e-customer-org')
        ->and($customerTicket->submitted_by_id)->toBe($customerTicket->user_id)
        ->and($operatorTicket->organization->slug)->toBe('e2e-operator-org')
        ->and($operatorTicket->submitted_by_id)->toBe($operatorTicket->user_id)
        ->and(Ticket::count())->toBe(2);
});

it('R1-2: the seeded customer can raise a request through the real form (the browser specs do), and the seeded operator is not exempt from membership', function () {
    Notification::fake();
    $this->seed(DevSeeder::class);
    $payload = ['title' => 'From the seeded customer', 'description' => 'x', 'category' => 'General', 'priority' => 'low'];

    $this->actingAs(seededPersona('e2e-customer@intechral.test'))->post(route('tickets.store'), $payload)->assertRedirect();
    expect(Ticket::where('title', 'From the seeded customer')->sole()->organization->slug)->toBe('e2e-customer-org');

    // Not a member of the customer's organization: naming it is refused like any other foreign id.
    $foreign = DB::table('organizations')->where('slug', 'e2e-customer-org')->value('id');
    $this->actingAs(seededPersona('e2e-customer-other@intechral.test'))->post(route('tickets.store'), [...$payload, 'organization_id' => $foreign])
        ->assertSessionHasErrors('organization_id');
});
