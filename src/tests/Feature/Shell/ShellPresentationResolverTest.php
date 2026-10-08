<?php

/*
 * EPIC-017 WP1 (P1): the one presentation resolver.
 *
 * `focused` for a user who holds no operator-surface capability, `operational` for anyone who holds
 * any. The rule reads capabilities only — never a role name — and is never authorization. The drift
 * guard that keeps the capability sets complete is `ShellPresentationDriftGuardTest`.
 *
 * Helper names carry the `presentation` prefix: Pest loads every test file into one process, so a
 * global helper must not collide with another file's.
 */

use App\Models\User;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Shell\ShellPresentation;
use App\Shared\Shell\ShellPresentationResolver;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

/** A user with an optional built-in role and direct permissions, reloaded so nothing is cached. */
function presentationUser(?string $role = null, array $permissions = []): User
{
    $user = User::factory()->create();

    if ($role !== null) {
        $user->assignRole($role);
    }

    if ($permissions !== []) {
        $user->givePermissionTo($permissions);
    }

    return User::findOrFail($user->id);
}

/** @return array<string, array{0: string}> */
function presentationOperationalCapabilities(): array
{
    return collect(ShellPresentationResolver::OPERATIONAL)
        ->mapWithKeys(fn (string $capability) => [$capability => [$capability]])
        ->all();
}

it('selects the Operational shell for each operator-surface capability held alone', function (string $capability) {
    $user = presentationUser(null, [$capability]);

    expect(app(ShellPresentationResolver::class)->forUser($user))->toBe(ShellPresentation::Operational);
})->with(presentationOperationalCapabilities());

it('selects the Operational shell for a mixed-capability user: any one operator capability wins', function (string $capability) {
    // The customer defaults plus one operator-surface capability.
    $user = presentationUser('user', [$capability]);

    expect(app(ShellPresentationResolver::class)->forUser($user))->toBe(ShellPresentation::Operational);
})->with(presentationOperationalCapabilities());

it('selects the Focused shell for the built-in user role and for a user with no permissions', function () {
    $resolver = app(ShellPresentationResolver::class);

    expect($resolver->forUser(presentationUser('user')))->toBe(ShellPresentation::Focused)
        ->and($resolver->forUser(presentationUser()))->toBe(ShellPresentation::Focused);
});

it('selects the Operational shell for the operator and for a user who is also a project manager', function () {
    $resolver = app(ShellPresentationResolver::class);

    expect($resolver->forUser(presentationUser('operator')))->toBe(ShellPresentation::Operational)
        ->and($resolver->forUser(presentationUser('user', [PermissionCatalogue::PROJECTS_MANAGE])))->toBe(ShellPresentation::Operational);
});

it('keeps a user who holds every presentation-neutral capability on the Focused shell', function () {
    $user = presentationUser('user', array_keys(ShellPresentationResolver::PRESENTATION_NEUTRAL));

    expect(app(ShellPresentationResolver::class)->forUser($user))->toBe(ShellPresentation::Focused);
});

it('keeps a guest on the Operational frame, as before the resolver existed', function () {
    expect(app(ShellPresentationResolver::class)->forUser(null))->toBe(ShellPresentation::Operational);
});

it('reads capabilities through the Gate and never a role name', function () {
    // A custom role carrying an operator-surface capability is Operational although its name means
    // nothing to the resolver; a role literally named "operator" with no permissions is Focused.
    $custom = Role::create(['name' => 'queue-lead', 'guard_name' => 'web']);
    $custom->givePermissionTo(PermissionCatalogue::TICKETS_ASSIGN);
    $bare = Role::create(['name' => 'operator-lookalike', 'guard_name' => 'web']);

    $resolver = app(ShellPresentationResolver::class);

    expect($resolver->forUser(presentationUser('queue-lead')))->toBe(ShellPresentation::Operational)
        ->and($resolver->forUser(presentationUser($bare->name)))->toBe(ShellPresentation::Focused);
});

it('takes a role change into effect on the next request', function () {
    $user = presentationUser('user');

    $this->actingAs($user)->get(route('profile.show'))
        ->assertInertia(fn (Assert $page) => $page->where('shell.presentation', 'focused'));

    $user->assignRole('operator');

    // A new request loads the user afresh, as every real request does.
    $this->actingAs(User::findOrFail($user->id))->get(route('profile.show'))
        ->assertInertia(fn (Assert $page) => $page->where('shell.presentation', 'operational'));
});

it('adds no query once the actor permissions are hydrated', function () {
    $resolver = app(ShellPresentationResolver::class);
    $user = presentationUser('user');

    // Hydrate the way a real request does: the navigation and `auth.permissions` read them first.
    $user->getAllPermissions();
    $user->can(PermissionCatalogue::TICKETS_VIEW);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $resolver->forUser($user);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($queries)->toBe([]);
});

it('resolves once per request: the second call is a memo hit and issues no query', function () {
    $resolver = app(ShellPresentationResolver::class);
    $user = presentationUser('user');
    $user->getAllPermissions();

    $request = Request::create('/dashboard');
    $request->setUserResolver(fn () => $user);

    $first = $resolver->forRequest($request);

    DB::flushQueryLog();
    DB::enableQueryLog();
    $second = $resolver->forRequest($request);
    $queries = DB::getQueryLog();
    DB::disableQueryLog();

    expect($first)->toBe(ShellPresentation::Focused)
        ->and($second)->toBe($first)
        ->and($queries)->toBe([]);
});

it('never serves a memoized answer to a different user on the same request', function () {
    $resolver = app(ShellPresentationResolver::class);
    $customer = presentationUser('user');
    $operator = presentationUser('operator');
    $acting = $customer;

    $request = Request::create('/dashboard');
    $request->setUserResolver(function () use (&$acting) {
        return $acting;
    });

    expect($resolver->forRequest($request))->toBe(ShellPresentation::Focused);

    $acting = $operator;

    expect($resolver->forRequest($request))->toBe(ShellPresentation::Operational);
});

it('resolves nothing for a partial reload that does not ask for the shell', function () {
    $user = presentationUser('user');

    // Warm: the first request of a process reads the permission registry.
    $this->actingAs($user)->get(route('profile.show'))->assertOk();

    $partial = fn (string $only) => [
        'X-Inertia' => 'true',
        'X-Inertia-Version' => Inertia\Inertia::getVersion(),
        'X-Inertia-Partial-Component' => 'profile/show',
        'X-Inertia-Partial-Data' => $only,
    ];

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->actingAs(User::findOrFail($user->id))->get(route('profile.show'), $partial('flash'))->assertOk();
    $permissionQueries = collect(DB::getQueryLog())
        ->pluck('query')
        ->filter(fn (string $sql) => preg_match('/permissions|model_has_roles|role_has_permissions/', $sql))
        ->all();
    DB::disableQueryLog();

    expect($permissionQueries)->toBe([])
        ->and($response->json('props'))->not->toHaveKey('shell');

    // The control: the same partial reload that DOES ask for the shell receives it.
    $this->actingAs(User::findOrFail($user->id))->get(route('profile.show'), $partial('shell'))
        ->assertOk()
        ->assertJsonPath('props.shell.presentation', 'focused');
});
