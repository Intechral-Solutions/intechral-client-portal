<?php

use App\Models\User;
use App\Shared\Permissions\BuiltInRoles;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Release 0.9 (R0.9-1D, decision H4): `php artisan roles:sync` is the controlled production workflow. The default is a
 * dry run that writes nothing; applying needs the reviewed plan's fingerprint, and revocations need a further explicit flag.
 */

beforeEach(fn () => $this->seedRolesAndPermissions());

function drift(): void
{
    Role::findByName('user', 'web')->permissions()->detach(Permission::findByName('tickets.view')->id);
    Role::findByName('user', 'web')->givePermissionTo('billing.admin');
}

/** @param  array<int|string, mixed>  $arguments  flags may be listed bare: ['--apply', '--plan' => 'abc'] */
function commandOutput(array $arguments): array
{
    $options = [];
    foreach ($arguments as $key => $value) {
        is_int($key) ? $options[$value] = true : $options[$key] = $value;
    }
    $exit = Artisan::call('roles:sync', $options);

    return [$exit, Artisan::output()];
}

function fingerprintIn(string $output): string
{
    preg_match('/Plan ([0-9a-f]{16})/', $output, $match);

    return $match[1];
}

it('is a dry run by default: it prints the plan and the exact command to apply it, and writes nothing', function () {
    drift();
    $before = [DB::table('role_has_permissions')->count(), Activity::count()];
    $statements = [];
    DB::listen(function ($query) use (&$statements) {
        $statements[] = strtolower(ltrim($query->sql));
    });

    [$exit, $output] = commandOutput([]);

    expect($exit)->toBe(0)
        ->and($output)->toContain('grant tickets.view to user')
        ->toContain('REVOKE billing.admin from user')
        ->toContain('1 revocation(s)')
        ->toContain('Active account administrators now:')
        ->toContain('Dry run: nothing was written.')
        ->toContain('--apply --plan='.fingerprintIn($output))
        ->toContain('Applying this plan requires explicit --allow-revocations approval.')
        ->not->toContain('--plan='.fingerprintIn($output).' --allow-revocations')
        ->and([DB::table('role_has_permissions')->count(), Activity::count()])->toBe($before)
        ->and(collect($statements)->reject(fn ($sql) => str_starts_with($sql, 'select'))->all())->toBe([]);
});

it('prints the same plan every time, in a stable order', function () {
    drift();

    expect(commandOutput([])[1])->toBe(commandOutput([])[1])
        ->and(commandOutput(['--json'])[1])->toBe(commandOutput(['--json'])[1]);

    $json = json_decode(commandOutput(['--json'])[1], true);
    expect($json['in_sync'])->toBeFalse()
        ->and($json['revocations'])->toBe(1)
        ->and($json['fingerprint'])->toBe(fingerprintIn(commandOutput([])[1]));
});

it('reports built-in roles in sync when they are', function () {
    [$exit, $output] = commandOutput([]);

    expect($exit)->toBe(0)->and($output)->toContain('in sync')->toContain('Dry run: nothing was written.');
});

it('does not mention the revocation approval when the plan revokes nothing', function () {
    Role::findByName('user', 'web')->permissions()->detach(Permission::findByName('tickets.view')->id);

    [$exit, $output] = commandOutput([]);

    expect($exit)->toBe(0)->and($output)->toContain('--apply --plan=')->not->toContain('allow-revocations');
});

it('applies only with the reviewed fingerprint, and revocations only with the explicit approval', function () {
    drift();
    $fingerprint = fingerprintIn(commandOutput([])[1]);
    $state = DB::table('role_has_permissions')->count();

    [$noPlan, $noPlanOut] = commandOutput(['--apply']);
    [$stale, $staleOut] = commandOutput(['--apply', '--plan' => '0123456789abcdef', '--allow-revocations' => true]);
    [$unapproved, $unapprovedOut] = commandOutput(['--apply', '--plan' => $fingerprint]);

    expect($noPlan)->toBe(1)->and($noPlanOut)->toContain('--apply needs --plan=<fingerprint>')
        ->and($stale)->toBe(1)->and($staleOut)->toContain('stale')
        ->and(preg_replace('/\s+/', '', $staleOut))->not->toContain($fingerprint) // a stale refusal never discloses the replacement plan; whitespace removed so console wrapping cannot hide it
        ->and($unapproved)->toBe(1)->and($unapprovedOut)->toContain('--allow-revocations')
        ->and(DB::table('role_has_permissions')->count())->toBe($state, 'every refusal left the grants alone')
        ->and(Activity::where('description', 'synchronized built-in roles')->exists())->toBeFalse();

    [$applied, $appliedOut] = commandOutput(['--apply', '--plan' => $fingerprint, '--allow-revocations' => true]);

    expect($applied)->toBe(0)->and($appliedOut)->toContain("Applied plan {$fingerprint}.")
        ->and(Role::findByName('user', 'web')->permissions()->pluck('name')->sort()->values()->all())->toBe(collect(BuiltInRoles::definitions()['user'])->sort()->values()->all());
});

it('applies additions with the fingerprint alone, and is idempotent afterwards', function () {
    Role::findByName('user', 'web')->permissions()->detach(Permission::findByName('tickets.view')->id);
    $fingerprint = fingerprintIn(commandOutput([])[1]);

    expect(commandOutput(['--apply', '--plan' => $fingerprint])[0])->toBe(0);

    [$exit, $output] = commandOutput([]);
    expect($exit)->toBe(0)->and($output)->toContain('in sync');

    [$again, $againOut] = commandOutput(['--apply', '--plan' => fingerprintIn($output)]);
    expect($again)->toBe(0)->and($againOut)->toContain('Already in sync')
        ->and(Activity::where('description', 'synchronized built-in roles')->count())->toBe(1);
});

it('rejects flags that only make sense with --apply instead of silently running a dry run', function () {
    drift();
    $state = DB::table('role_has_permissions')->count();

    [$exit, $output] = commandOutput(['--plan' => '0123456789abcdef']);
    [$exit2] = commandOutput(['--allow-revocations' => true]);

    expect($exit)->toBe(1)->and($output)->toContain('only apply together with --apply')
        ->and($exit2)->toBe(1)
        ->and(DB::table('role_has_permissions')->count())->toBe($state);
});

it('refuses to leave the portal without an account administrator and says why', function () {
    $last = User::factory()->create()->assignRole('user');
    Role::findByName('user', 'web')->givePermissionTo(['users.view', 'users.manage']);
    $fingerprint = fingerprintIn(commandOutput([])[1]);

    [$exit, $output] = commandOutput(['--apply', '--plan' => $fingerprint, '--allow-revocations' => true]);

    expect($exit)->toBe(1)->and($output)->toContain('without an active account administrator')
        ->and(Role::findByName('user', 'web')->hasPermissionTo('users.manage'))->toBeTrue()
        ->and($last->fresh()->hasRole('user'))->toBeTrue();
});

it('fails visibly, with a non-zero exit and nothing changed, on ambiguous stored state', function () {
    Role::create(['name' => 'operator', 'guard_name' => 'api']);

    [$exit, $output] = commandOutput([]);

    expect($exit)->toBe(1)->and($output)->toContain('ambiguous');
});

it('is never run by anything but an operator at the command line', function () {
    // The synchronizer is reachable from the command and the disposable-environment seeder only: not from a request,
    // a migration, a provider, a listener or boot.
    $users = collect(['app', 'routes', 'bootstrap', 'config', 'database'])
        ->flatMap(fn ($dir) => collect(File::allFiles(base_path($dir)))->map->getPathname())
        ->filter(fn ($path) => str_ends_with($path, '.php') && Str::contains(File::get($path), ['BuiltInRoleSynchronizer $', 'app(BuiltInRoleSynchronizer', 'new BuiltInRoleSynchronizer', 'BuiltInRoleSynchronizer::class']))
        ->map(fn ($path) => Str::after($path, base_path().'/'))
        ->sort()->values()->all();

    expect($users)->toBe([
        'app/Console/Commands/SyncBuiltInRoles.php',
        'database/seeders/RoleSeeder.php',
    ]);
});
