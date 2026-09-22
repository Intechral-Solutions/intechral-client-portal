<?php

use App\Models\User;
use App\Shared\Navigation\NavigationBuilder;
use Illuminate\Http\Request;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function navigationItems(array $groups, string $key): array
{
    return collect($groups)->firstWhere('key', $key)['items'] ?? [];
}

it('builds user navigation from effective permissions', function () {
    $user = User::factory()->create();
    $user->assignRole('user');
    $request = Request::create('/tickets');
    $request->setUserResolver(fn () => $user);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName('tickets.index'));

    $groups = app(NavigationBuilder::class)->build($request);
    $items = collect(navigationItems($groups, 'primary'))->keyBy('key');

    expect($items->keys()->all())->toContain('tickets', 'projects', 'tasks', 'time', 'billing', 'pages')
        ->and($items->keys()->all())->not->toContain('crm')
        ->and($items['billing']['href'])->toBe(route('billing.client.invoices.index'))
        ->and($items['time']['visit'])->toBe('inertia')
        // Projects index/create/edit are React pages (WP3); the board, milestones, task pages
        // and /tasks are still Blade, so only the projects entry point flips.
        ->and($items['projects']['visit'])->toBe('inertia')
        ->and($items['tasks']['visit'])->toBe('document')
        ->and($items['tickets']['visit'])->toBe('document')
        ->and($items['tickets']['isActive'])->toBeTrue()
        ->and(collect($groups)->contains('key', 'management'))->toBeFalse();
});

it('builds operator management navigation and operator billing destination', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');
    $request = Request::create('/billing/invoices');
    $request->setUserResolver(fn () => $operator);
    $request->setRouteResolver(fn () => app('router')->getRoutes()->getByName('billing.invoices.index'));

    $groups = app(NavigationBuilder::class)->build($request);
    $primary = collect(navigationItems($groups, 'primary'))->keyBy('key');
    $management = collect(navigationItems($groups, 'management'))->keyBy('key');

    expect($primary['billing']['href'])->toBe(route('billing.invoices.index'))
        ->and($primary['billing']['isActive'])->toBeTrue()
        ->and($management['time-reports']['visit'])->toBe('inertia')
        ->and($management->keys()->all())->toContain(
            'ticket-queue',
            'time-reports',
            'organizations',
            'cms-pages',
            'users',
            'roles',
        );
});

it('returns no navigation for guests', function () {
    $request = Request::create('/login');

    expect(app(NavigationBuilder::class)->build($request))->toBe([]);
});
