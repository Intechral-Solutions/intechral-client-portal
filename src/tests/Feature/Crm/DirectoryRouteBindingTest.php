<?php

/*
 * Release 1.0 (ORG-2, CP4): the transitional Directory routes keep their `crm.companies.*` names, URIs and `{company}` parameter until ORG-3, and the parameter
 * now binds to the canonical Organization. Route-model binding matches the PARAMETER NAME to the controller argument NAME: a controller argument named
 * differently from the URI segment (`Organization $organization` on `{company}`) is not bound, and Laravel injects an EMPTY model, silently. These tests pin the
 * names, and prove the bound Organization is the requested one, that an unknown id is a 404 with nothing created or changed, and that generated URLs and the
 * forms the pages render still work.
 */

use App\Http\Controllers\Crm\CompanyController;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->operator = User::factory()->create()->assignRole('operator');
});

it('keeps every route name, URI and the {company} parameter, bound to Organization under the matching controller argument name', function () {
    $routes = app('router')->getRoutes();
    $expected = [
        'crm.companies.index' => ['GET', 'crm/companies', []],
        'crm.companies.create' => ['GET', 'crm/companies/create', []],
        'crm.companies.store' => ['POST', 'crm/companies', []],
        'crm.companies.show' => ['GET', 'crm/companies/{company}', ['company']],
        'crm.companies.edit' => ['GET', 'crm/companies/{company}/edit', ['company']],
        'crm.companies.update' => ['PUT', 'crm/companies/{company}', ['company']],
        'crm.companies.destroy' => ['DELETE', 'crm/companies/{company}', ['company']],
        'crm.companies.promote' => ['POST', 'crm/companies/{company}/promote', ['company']],
    ];

    foreach ($expected as $name => [$method, $uri, $parameters]) {
        $route = $routes->getByName($name);

        expect($route)->not->toBeNull("route {$name} is gone")
            ->and($route->uri())->toBe($uri)
            ->and($route->methods())->toContain($method)
            ->and($route->parameterNames())->toBe($parameters);

        [$class, $action] = explode('@', $route->getActionName());
        expect($class)->toBe(CompanyController::class);

        foreach ((new ReflectionMethod($class, $action))->getParameters() as $parameter) {
            if ($parameters !== []) {
                $type = $parameter->getType()?->getName();

                if ($type === Organization::class) {
                    expect($parameter->getName())->toBe('company', "{$name}: the Organization argument must be named like the route parameter, or binding injects an empty model");
                }
            }
        }
    }
});

it('resolves the requested Organization, and changes that one and no other', function () {
    $first = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id, 'name' => 'First', 'phone' => '1']);
    $second = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id, 'name' => 'Second', 'phone' => '2']);

    $this->actingAs($this->operator)->get(route('crm.companies.show', $second))->assertOk()->assertSee('Second')->assertDontSee('First');
    $this->actingAs($this->operator)->get(route('crm.companies.edit', $second))->assertOk()->assertSee('value="Second"', false);

    $this->actingAs($this->operator)->put(route('crm.companies.update', $second), ['name' => 'Second Renamed', 'phone' => '22'])->assertRedirect(route('crm.companies.show', $second));

    expect(DB::table('organizations')->where('id', $second->id)->first(['name', 'phone']))->toEqual((object) ['name' => 'Second Renamed', 'phone' => '22'])
        ->and(DB::table('organizations')->where('id', $first->id)->first(['name', 'phone']))->toEqual((object) ['name' => 'First', 'phone' => '1']);

    $this->actingAs($this->operator)->post(route('crm.companies.promote', $first))->assertRedirect(route('organizations.show', $first));
    expect(DB::table('organizations')->where('id', $first->id)->value('portal_enabled_at'))->not->toBeNull()
        ->and(DB::table('organizations')->where('id', $second->id)->value('portal_enabled_at'))->toBeNull();

    $this->actingAs($this->operator)->delete(route('crm.companies.destroy', $second))->assertRedirect(route('crm.companies.index'));
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
    'show' => ['get', 'crm.companies.show', []],
    'edit' => ['get', 'crm.companies.edit', []],
    'update' => ['put', 'crm.companies.update', ['name' => 'Ghost']],
    'destroy' => ['delete', 'crm.companies.destroy', []],
    'promote' => ['post', 'crm.companies.promote', []],
]);

it('generates the same URLs as before from an Organization, and the pages render forms that post to them', function () {
    $organization = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);

    expect(route('crm.companies.show', $organization, false))->toBe("/crm/companies/{$organization->id}")
        ->and(route('crm.companies.edit', $organization, false))->toBe("/crm/companies/{$organization->id}/edit")
        ->and(route('crm.companies.update', $organization, false))->toBe("/crm/companies/{$organization->id}")
        ->and(route('crm.companies.promote', $organization, false))->toBe("/crm/companies/{$organization->id}/promote");

    $edit = $this->actingAs($this->operator)->get(route('crm.companies.edit', $organization))->assertOk()->getContent();
    expect($edit)->toContain('action="'.route('crm.companies.update', $organization).'"')
        ->toContain('action="'.route('crm.companies.destroy', $organization).'"')
        ->toContain('name="_method" value="PUT"');

    $show = $this->actingAs($this->operator)->get(route('crm.companies.show', $organization))->assertOk()->getContent();
    expect($show)->toContain('action="'.route('crm.companies.promote', $organization).'"');
});

it('binds a Directory-only Organization for an operator, and answers a hidden Organization as not found for a member of another', function () {
    $mine = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $theirs = Organization::factory()->create(['portal_enabled_by' => $this->operator->id]);
    $directoryOnly = Organization::factory()->directoryOnly()->create(['created_by' => $this->operator->id]);
    $member = User::factory()->create()->assignRole('user')->givePermissionTo('crm.manage');
    $mine->members()->attach($member, ['role' => 'admin']);

    $this->actingAs($this->operator)->get(route('crm.companies.show', $directoryOnly))->assertOk();
    $this->actingAs($member)->get(route('crm.companies.show', $theirs))->assertNotFound();
    $this->actingAs($member)->get(route('crm.companies.show', $directoryOnly))->assertNotFound();
});
