<?php

/*
 * Release 1.0 (ORG-2 CP4; ORG-3b): the Organization routes. Until ORG-3b the Directory's Organization pages were `crm.companies.*` on a `{company}` parameter. ORG-3b moved them to
 * `organizations.*` on `{organization}` (the Directory list is `organizations.index`, the System list `organizations.access`, the workspace pages `organizations.show`, `.people` and
 * `.portal`), and retired the old names. Route-model binding matches the PARAMETER NAME to the controller argument NAME: a controller argument named differently from the URI segment
 * is not bound, and Laravel injects an EMPTY model, silently. These tests pin the names, URIs and argument names, prove the bound Organization is the requested one, that an
 * unknown id is a 404 with nothing created or changed, that generated URLs and the forms the pages render work, and that the retired names are gone while their GET URLs redirect.
 */

use App\Http\Controllers\Organization\OrganizationAccessController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Organization\OrganizationWorkspaceController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

it('declares every Organization route under its name, URI and {organization} parameter, bound to Organization under the matching controller argument name', function () {
    $routes = app('router')->getRoutes();
    $expected = [
        'organizations.index' => ['GET', 'organizations', [], OrganizationController::class],
        'organizations.create' => ['GET', 'organizations/create', [], OrganizationController::class],
        'organizations.store' => ['POST', 'organizations', [], OrganizationController::class],
        'organizations.access' => ['GET', 'organizations/access', [], OrganizationAccessController::class],
        'organizations.show' => ['GET', 'organizations/{organization}', ['organization'], OrganizationWorkspaceController::class],
        'organizations.people' => ['GET', 'organizations/{organization}/people', ['organization'], OrganizationWorkspaceController::class],
        'organizations.portal' => ['GET', 'organizations/{organization}/portal', ['organization'], OrganizationWorkspaceController::class],
        'organizations.edit' => ['GET', 'organizations/{organization}/edit', ['organization'], OrganizationController::class],
        'organizations.update' => ['PUT', 'organizations/{organization}', ['organization'], OrganizationController::class],
        'organizations.destroy' => ['DELETE', 'organizations/{organization}', ['organization'], OrganizationController::class],
        'organizations.promote' => ['POST', 'organizations/{organization}/promote', ['organization'], OrganizationController::class],
    ];

    foreach ($expected as $name => [$method, $uri, $parameters, $controller]) {
        $route = $routes->getByName($name);

        expect($route)->not->toBeNull("route {$name} is gone")
            ->and($route->uri())->toBe($uri)
            ->and($route->methods())->toContain($method)
            ->and($route->parameterNames())->toBe($parameters);

        [$class, $action] = explode('@', $route->getActionName());
        expect($class)->toBe($controller);

        foreach ((new ReflectionMethod($class, $action))->getParameters() as $parameter) {
            if ($parameters !== [] && $parameter->getType()?->getName() === Organization::class) {
                expect($parameter->getName())->toBe('organization', "{$name}: the Organization argument must be named like the route parameter, or binding injects an empty model");
            }
        }
    }
});

it('keeps the security-reviewed administrative action endpoints under their names, URIs and parameters', function () {
    $routes = app('router')->getRoutes();

    foreach ([
        'organizations.members.store' => ['POST', 'organizations/{organization}/members'],
        'organizations.members.role' => ['PUT', 'organizations/{organization}/members/{user}/role'],
        'organizations.members.destroy' => ['DELETE', 'organizations/{organization}/members/{user}'],
        'organizations.members.approve' => ['POST', 'organizations/{organization}/members/{user}/approval'],
        'organizations.members.approval.revoke' => ['DELETE', 'organizations/{organization}/members/{user}/approval'],
        'organizations.invitations.store' => ['POST', 'organizations/{organization}/invitations'],
        'organizations.invitations.destroy' => ['DELETE', 'organizations/{organization}/invitations/{invitation}'],
        'organizations.members.support.grant' => ['POST', 'organizations/{organizationId}/members/{userId}/support'],
        'organizations.members.support.revoke' => ['DELETE', 'organizations/{organizationId}/members/{userId}/support'],
        'organizations.disable' => ['POST', 'organizations/{organizationId}/disable'],
        'organizations.enable' => ['POST', 'organizations/{organizationId}/enable'],
        'organizations.partner.designate' => ['PUT', 'organizations/{organizationId}/partner'],
        'organizations.partner.remove' => ['DELETE', 'organizations/{organizationId}/partner'],
        'organizations.customers.link' => ['POST', 'organizations/{organizationId}/customers'],
        'organizations.manager.assign' => ['PUT', 'organizations/{organizationId}/manager'],
        'organizations.manager.unlink' => ['DELETE', 'organizations/{organizationId}/manager'],
    ] as $name => [$method, $uri]) {
        $route = $routes->getByName($name);

        expect($route)->not->toBeNull("route {$name} is gone")->and($route->uri())->toBe($uri)->and($route->methods())->toContain($method);
    }
});

it('retires the crm.companies.* names, and keeps their four GET URLs as permanent, unnamed redirects behind the same gates', function () {
    $routes = app('router')->getRoutes();

    foreach (['index', 'create', 'store', 'show', 'edit', 'update', 'destroy', 'promote'] as $action) {
        expect($routes->getByName("crm.companies.{$action}"))->toBeNull("crm.companies.{$action} still exists");
    }

    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $operator = $this->actingAs($this->operator);

    $operator->get('/crm/companies')->assertStatus(301)->assertRedirect(route('organizations.index'));
    $operator->get('/crm/companies?search=acme&page=2')->assertStatus(301)->assertRedirect(route('organizations.index', ['search' => 'acme', 'page' => 2]));
    $operator->get('/crm/companies/create')->assertStatus(301)->assertRedirect(route('organizations.create'));
    $operator->get("/crm/companies/{$organization->id}")->assertStatus(301)->assertRedirect(route('organizations.show', $organization));
    $operator->get("/crm/companies/{$organization->id}/edit")->assertStatus(301)->assertRedirect(route('organizations.edit', $organization));

    // Only a numeric id is redirected: free text is never turned into a target.
    $operator->get('/crm/companies/not-a-number')->assertNotFound();
    $operator->get('/crm/companies/..%2F..%2Fadmin')->assertNotFound();

    // No write is served at the old URIs.
    $operator->post('/crm/companies', ['name' => 'Old Door'])->assertStatus(405);
    $operator->put("/crm/companies/{$organization->id}", ['name' => 'Old Door'])->assertStatus(405);
    $operator->delete("/crm/companies/{$organization->id}")->assertStatus(405);
    $operator->post("/crm/companies/{$organization->id}/promote")->assertNotFound();
    expect(DB::table('organizations')->where('id', $organization->id)->value('name'))->toBe($organization->name)
        ->and(DB::table('organizations')->where('name', 'Old Door')->exists())->toBeFalse();
});

it('answers the retired URLs to a customer exactly as the old pages did: the route gate in legacy mode, the surface gate in closed and pilot, and a guest the login', function (string $mode) {
    config(['portal.customer_surface_mode' => $mode]);
    $organization = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $customer = User::factory()->create()->assignRole('user');
    $organization->members()->attach($customer, ['role' => 'member']);

    foreach (['/crm/companies', '/crm/companies/create', "/crm/companies/{$organization->id}", "/crm/companies/{$organization->id}/edit"] as $uri) {
        $status = $this->actingAs($customer)->get($uri)->getStatusCode();
        expect($status)->toBe($mode === 'legacy' ? 403 : 404, "{$mode}: {$uri}");
    }

    auth()->logout();
    $this->get('/crm/companies')->assertRedirect('/login');
})->with(['legacy', 'closed', 'pilot']);

it('routes no closure on the Organization or Directory URLs, so route:cache can serialize them', function () {
    foreach (app('router')->getRoutes()->getRoutes() as $route) {
        if (str_starts_with($route->uri(), 'organizations') || str_starts_with($route->uri(), 'crm/')) {
            expect($route->getAction('uses'))->not->toBeInstanceOf(Closure::class, $route->uri().' is a closure route');
        }
    }
});

it('resolves the requested Organization, and changes that one and no other', function () {
    $first = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id, 'name' => 'First', 'phone' => '1']);
    $second = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id, 'name' => 'Second', 'phone' => '2']);

    $this->actingAs($this->operator)->get(route('organizations.show', $second))->assertOk()->assertSee('Second')->assertDontSee('First');
    $this->actingAs($this->operator)->get(route('organizations.edit', $second))->assertOk()->assertSee('value="Second"', false);

    $this->actingAs($this->operator)->put(route('organizations.update', $second), ['name' => 'Second Renamed', 'phone' => '22'])->assertRedirect(route('organizations.show', $second));

    expect(DB::table('organizations')->where('id', $second->id)->first(['name', 'phone']))->toEqual((object) ['name' => 'Second Renamed', 'phone' => '22'])
        ->and(DB::table('organizations')->where('id', $first->id)->first(['name', 'phone']))->toEqual((object) ['name' => 'First', 'phone' => '1']);

    $this->actingAs($this->operator)->post(route('organizations.promote', $first))->assertRedirect(route('organizations.portal', $first));
    expect(DB::table('organizations')->where('id', $first->id)->value('portal_enabled_at'))->not->toBeNull()
        ->and(DB::table('organizations')->where('id', $second->id)->value('portal_enabled_at'))->toBeNull();

    $this->actingAs($this->operator)->delete(route('organizations.destroy', $second))->assertRedirect(route('organizations.index'));
    expect(DB::table('organizations')->where('id', $second->id)->exists())->toBeFalse()
        ->and(DB::table('organizations')->where('id', $first->id)->exists())->toBeTrue();
});

it('answers an unknown id with a 404 on every route, and never injects an empty model: nothing is created, changed or deleted', function (string $method, string $route, array $payload) {
    $existing = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $world = [DB::table('organizations')->orderBy('id')->get()->all(), DB::table('activity_log')->count()];

    foreach ([999999, 'not-a-number', '0', '-1', '1e3'] as $bad) {
        $this->actingAs($this->operator)->{$method}(route($route, $bad), $payload)->assertNotFound();
    }

    expect([DB::table('organizations')->orderBy('id')->get()->all(), DB::table('activity_log')->count()])->toEqual($world)
        ->and($existing->fresh()->name)->toBe($existing->name);
})->with([
    'show' => ['get', 'organizations.show', []],
    'people' => ['get', 'organizations.people', []],
    'portal' => ['get', 'organizations.portal', []],
    'edit' => ['get', 'organizations.edit', []],
    'update' => ['put', 'organizations.update', ['name' => 'Ghost']],
    'destroy' => ['delete', 'organizations.destroy', []],
    'promote' => ['post', 'organizations.promote', []],
]);

it('generates the workspace URLs from an Organization, and the pages render forms that post to them', function () {
    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);

    expect(route('organizations.show', $organization, false))->toBe("/organizations/{$organization->id}")
        ->and(route('organizations.people', $organization, false))->toBe("/organizations/{$organization->id}/people")
        ->and(route('organizations.portal', $organization, false))->toBe("/organizations/{$organization->id}/portal")
        ->and(route('organizations.edit', $organization, false))->toBe("/organizations/{$organization->id}/edit")
        ->and(route('organizations.update', $organization, false))->toBe("/organizations/{$organization->id}")
        ->and(route('organizations.promote', $organization, false))->toBe("/organizations/{$organization->id}/promote")
        ->and(route('organizations.access', [], false))->toBe('/organizations/access');

    $edit = $this->actingAs($this->operator)->get(route('organizations.edit', $organization))->assertOk()->getContent();
    expect($edit)->toContain('action="'.route('organizations.update', $organization).'"')
        ->toContain('action="'.route('organizations.destroy', $organization).'"')
        ->toContain('name="_method" value="PUT"');

    // Initial enablement is on the Portal access page (one place for each lifecycle act), not on the Overview.
    $portal = $this->actingAs($this->operator)->get(route('organizations.portal', $organization))->assertOk()->getContent();
    $overview = $this->actingAs($this->operator)->get(route('organizations.show', $organization))->assertOk()->getContent();
    expect($portal)->toContain('action="'.route('organizations.promote', $organization).'"')
        ->and($overview)->not->toContain('action="'.route('organizations.promote', $organization).'"');
});

it('binds a Directory-only Organization for an operator, and answers a hidden Organization as not found for a member of another', function () {
    $mine = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $theirs = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $directoryOnly = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $member = User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage');
    $mine->members()->attach($member, ['role' => 'admin']);

    $this->actingAs($this->operator)->get(route('organizations.show', $directoryOnly))->assertOk();
    foreach (['organizations.show', 'organizations.people', 'organizations.portal'] as $page) {
        $this->actingAs($member)->get(route($page, $theirs))->assertNotFound();
        $this->actingAs($member)->get(route($page, $directoryOnly))->assertNotFound();
    }
});
