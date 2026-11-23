<?php

use App\Exceptions\OrganizationDirectoryException;
use App\Models\CrmContact;
use App\Models\Organization;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\OrganizationDirectory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/*
 * Release 1.0 (ORG-2). Successor to Release 0.9's CompanyDeletionSafetyTest (R0.9-1D, R0.9-1B follow-up L2), which held the same contract for a CRM company: a
 * deletion that something still refers to is refused with a business-level answer and NO side effect, and the RESTRICT foreign key stays the arbiter. There is
 * one Organization entity now, and only a NEVER-ENABLED one can be deleted (ORG-0 section 12, owner rulings 5 and 7), through the one authorized service
 * (OrganizationDirectory::delete(); tests/Unit/Architecture/OrganizationDeletionGuardTest.php keeps it the only path).
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

/** A never-enabled (Directory-only) Organization linked to $projects projects, with a Person affiliated with it. */
function linkedDirectoryOrganization(int $projects = 1): array
{
    $organization = Organization::factory()->directoryOnly()->create(['name' => 'Linked Co']);
    $linked = Project::factory()->count($projects)->create();
    $organization->projects()->attach($linked->pluck('id')->all());
    $contact = CrmContact::factory()->create(['organization_id' => $organization->id]);

    return [$organization, $linked, $contact];
}

/** Everything a refused deletion must leave exactly as it was. */
function organizationWorld(Organization $organization): array
{
    return [
        (array) DB::table('organizations')->where('id', $organization->id)->first(),
        DB::table('project_organization')->where('organization_id', $organization->id)->orderBy('project_id')->get()->map(fn ($row) => (array) $row)->all(),
        DB::table('crm_contacts')->where('organization_id', $organization->id)->pluck('id')->all(),
        DB::table('projects')->count(),
        DB::table('activity_log')->count(),
    ];
}

it('refuses a browser deletion of an Organization projects still reference, and keeps every record', function () {
    [$organization] = linkedDirectoryOrganization(2);
    $world = organizationWorld($organization);

    $this->actingAs($this->operator)
        ->from(route('organizations.edit', $organization))
        ->delete(route('organizations.destroy', $organization))
        ->assertRedirect(route('organizations.edit', $organization))
        ->assertSessionHasErrors(['company' => 'Cannot delete "Linked Co": 2 project(s) still refer to it. Remove the Organization from those projects first.']);

    expect(organizationWorld($organization))->toBe($world)
        ->and(Organization::withoutGlobalScopes()->find($organization->id))->not->toBeNull();
});

it('answers a JSON caller with 409 and the same message', function () {
    [$organization] = linkedDirectoryOrganization();
    $world = organizationWorld($organization);

    $this->actingAs($this->operator)
        ->deleteJson(route('organizations.destroy', $organization))
        ->assertStatus(409)
        ->assertJson(['message' => 'Cannot delete "Linked Co": 1 project(s) still refer to it. Remove the Organization from those projects first.']);

    expect(organizationWorld($organization))->toBe($world);
});

it('shows the refusal on the edit page the operator is sent back to', function () {
    [$organization] = linkedDirectoryOrganization();

    $this->actingAs($this->operator)
        ->withSession(['errors' => ['default' => ['format' => ':message', 'messages' => ['company' => [OrganizationDirectoryException::hasProjects('Linked Co', 1)->getMessage()]]]], '_flash' => ['old' => [], 'new' => ['errors']]])
        ->get(route('organizations.edit', $organization))
        ->assertOk()
        ->assertSee('Cannot delete "Linked Co"');
});

it('lets the deletion through once the last project is unlinked, clearing the Person affiliation and recording it', function () {
    [$organization, $projects, $contact] = linkedDirectoryOrganization(2);
    $this->actingAs($this->operator)->delete(route('organizations.destroy', $organization))->assertSessionHasErrors('company');

    $organization->projects()->detach($projects->first()->id);
    $this->actingAs($this->operator)->delete(route('organizations.destroy', $organization))->assertSessionHasErrors('company');

    $organization->projects()->detach();
    $this->actingAs($this->operator)->delete(route('organizations.destroy', $organization))
        ->assertRedirect(route('organizations.index'))
        ->assertSessionHas('success', 'Organization deleted.');

    expect(Organization::withoutGlobalScopes()->find($organization->id))->toBeNull()
        ->and(Project::count())->toBe(2, 'the projects themselves are never touched')
        ->and(CrmContact::withoutGlobalScopes()->find($contact->id))->not->toBeNull('a Person is kept')
        ->and($contact->fresh()->organization_id)->toBeNull();

    $entry = DB::table('activity_log')->where('description', OrganizationDirectory::DELETED)->first();
    expect($entry)->not->toBeNull()
        ->and($entry->causer_id)->toBe($this->operator->id)
        ->and(json_decode($entry->properties, true)['unaffiliated_person_ids'])->toBe([$contact->id])
        ->and(json_decode($entry->properties, true)['old']['name'])->toBe('Linked Co');
});

it('refuses an Enabled and a Disabled Organization, and clears nothing', function (bool $disabled) {
    $organization = $disabled ? Organization::factory()->disabled()->create(['name' => 'Had Access']) : Organization::factory()->create(['name' => 'Had Access']);
    $contact = CrmContact::factory()->create(['organization_id' => $organization->id]);
    $world = organizationWorld($organization);

    $this->actingAs($this->operator)->delete(route('organizations.destroy', $organization))
        ->assertSessionHasErrors(['company' => 'Cannot delete "Had Access": it has (or had) portal access. Only an Organization that never had portal access can be deleted; disable it instead.']);

    expect(organizationWorld($organization))->toBe($world)
        ->and($contact->fresh()->organization_id)->toBe($organization->id);
})->with([false, true]);

it('refuses an Organization that members, invitations, requests or a relationship still refer to, naming counts only', function (Closure $arrange, string $fragment) {
    $organization = Organization::factory()->directoryOnly()->create(['name' => 'Odd Co']);
    $arrange($organization);
    $world = organizationWorld($organization);

    $response = $this->actingAs($this->operator)->deleteJson(route('organizations.destroy', $organization));

    $response->assertStatus(409);
    expect($response->json('message'))->toContain('Cannot delete "Odd Co": it still has')->toContain($fragment)
        ->and(organizationWorld($organization))->toBe($world);
})->with([
    // A Directory-only Organization has none of these through the application; the rows are inserted directly, which is the only way they could exist.
    'a member' => [fn (Organization $o) => DB::table('organization_members')->insert(['organization_id' => $o->id, 'user_id' => User::factory()->create()->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]), '1 member(s)'],
    'a service request' => [fn (Organization $o) => Ticket::factory()->inOrganization($o)->create(), '1 service request(s)'],
    'a managed organization' => [fn (Organization $o) => DB::table('organizations')->where('id', Organization::factory()->create()->id)->update(['managed_by_organization_id' => $o->id]), '1 managed organization(s)'],
]);

it('keeps the authorization: guests and users without crm.manage are refused before anything is checked, exactly 404 in every surface mode', function (string $mode) {
    // closed and pilot: the surface gate (the route is not on the customer allowlist). legacy: the Organization is Directory-only, so no customer can see it and the
    // route-model binding finds nothing. A visible Organization reaching the `can:` middleware's 403 is the next test.
    config(['portal.customer_surface_mode' => $mode]);

    [$organization] = linkedDirectoryOrganization();
    $world = organizationWorld($organization);

    $this->delete(route('organizations.destroy', $organization))->assertRedirect('/login');
    $customer = $this->actingAs(User::factory()->create()->assignRole('user'))->deleteJson(route('organizations.destroy', $organization));
    $viewer = $this->actingAs(User::factory()->create()->givePermissionTo('crm.view'))->deleteJson(route('organizations.destroy', $organization));

    expect($customer->status())->toBe(404, $mode)
        ->and($viewer->status())->toBe(404, $mode)
        ->and(organizationWorld($organization))->toBe($world);
})->with(['closed', 'pilot', 'legacy']);

it('refuses a customer who can SEE the Organization but does not hold crm.manage exactly 403 in legacy and 404 in closed and pilot, and deletes nothing', function (string $mode, int $status) {
    config(['portal.customer_surface_mode' => $mode]);

    [$organization] = linkedDirectoryOrganization();
    $member = User::factory()->create()->assignRole('user');
    DB::table('organization_members')->insert(['organization_id' => $organization->id, 'user_id' => $member->id, 'role' => 'member', 'created_at' => now(), 'updated_at' => now()]);   // reaches the middleware
    $world = organizationWorld($organization);

    expect($this->actingAs($member)->deleteJson(route('organizations.destroy', $organization))->status())->toBe($status, $mode)
        ->and(organizationWorld($organization))->toBe($world);
})->with([['closed', 404], ['pilot', 404], ['legacy', 403]]);

it('turns the database refusal of something linked after the checks into a controlled refusal, not a 500, and rolls the Person clearing back', function () {
    $organization = Organization::factory()->directoryOnly()->create(['name' => 'Race Co']);
    $contact = CrmContact::factory()->create(['organization_id' => $organization->id]);

    // The engine refuses the DELETE itself (error 1451), as it would when a row slipped in between the checks and the delete.
    DB::beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'delete from `organizations`')) {
            $pdo = new PDOException('Cannot delete or update a parent row: a foreign key constraint fails');
            $pdo->errorInfo = ['23000', 1451, 'Cannot delete or update a parent row'];

            throw new QueryException(config('database.default'), $query, [], $pdo);
        }
    });

    expect(fn () => app(OrganizationDirectory::class)->delete($organization, $this->operator))
        ->toThrow(OrganizationDirectoryException::class, 'a record still refers to it')
        ->and(Organization::withoutGlobalScopes()->find($organization->id))->not->toBeNull()
        ->and($contact->fresh()->organization_id)->toBe($organization->id, 'the Person clearing is part of the same transaction');
});

it('does not hide an unrelated database failure behind the refusal', function () {
    $organization = Organization::factory()->directoryOnly()->create();

    DB::beforeExecuting(function (string $query) {
        if (str_starts_with($query, 'delete from `organizations`')) {
            $pdo = new PDOException('Lock wait timeout exceeded');
            $pdo->errorInfo = ['HY000', 1205, 'Lock wait timeout exceeded'];

            throw new QueryException(config('database.default'), $query, [], $pdo);
        }
    });

    expect(fn () => app(OrganizationDirectory::class)->delete($organization, $this->operator))->toThrow(QueryException::class);
});

it('is refused for an actor who may not manage the Directory, whatever the route says (the service asks again)', function () {
    $organization = Organization::factory()->directoryOnly()->create();
    $customer = User::factory()->create()->assignRole('user');

    expect(fn () => app(OrganizationDirectory::class)->delete($organization, $customer))->toThrow(AuthorizationException::class)
        ->and(Organization::withoutGlobalScopes()->find($organization->id))->not->toBeNull();
});

it('refuses when the Organization is deleted by someone else first: a missing row is a 404, never a second delete', function () {
    $organization = Organization::factory()->directoryOnly()->create();
    $stale = Organization::withoutGlobalScopes()->find($organization->id);
    DB::table('organizations')->where('id', $organization->id)->delete();

    expect(fn () => app(OrganizationDirectory::class)->delete($stale, $this->operator))->toThrow(NotFoundHttpException::class);
});
