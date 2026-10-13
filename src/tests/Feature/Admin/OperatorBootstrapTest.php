<?php

use App\Models\User;
use App\Services\AccountAdministrators;
use App\Services\BuiltInRoleSynchronizer;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/*
 * Release 0.9 (R0.9-1D, decision H4): the first-operator bootstrap. `php artisan operator:bootstrap <email>` creates the
 * first account administrator of a NEW installation, at the server's command line only. It creates a new account through
 * the application's own registration rules and never elevates an existing one, and it refuses once account
 * administration exists, active or not. The concurrent case is in LastAccountAdministratorConcurrencyTest.
 */

const BOOTSTRAP_PASSWORD = 'Bootstrap-Passw0rd!x9';

beforeEach(function () {
    Http::fake(['api.pwnedpasswords.com/*' => Http::response('', 200)]); // the "uncompromised" rule's lookup: not pwned
});

/** A new installation after the first, reviewed role synchronization: permissions and roles exist, nobody has them. */
function synchronizedInstallation(): void
{
    $sync = app(BuiltInRoleSynchronizer::class);
    $sync->apply($sync->plan()->fingerprint());
}

function bootstrapOperator(string $email = 'first.operator@example.com', array $answers = []): array
{
    $answers += ['name' => 'First Operator', 'password' => BOOTSTRAP_PASSWORD, 'confirmation' => BOOTSTRAP_PASSWORD];

    $command = test()->artisan('operator:bootstrap', ['email' => $email] + ($answers['name'] === '' ? [] : ['--name' => $answers['name']]));
    if ($answers['name'] === '') {
        $command->expectsQuestion('Name of the new operator', '');
    }
    $command
        ->expectsQuestion('Password', $answers['password'])
        ->expectsQuestion('Confirm password', $answers['confirmation']);

    return [$command];
}

it('creates the first operator of a new installation, who then administers accounts and can sign in', function () {
    synchronizedInstallation();

    [$command] = bootstrapOperator();
    $command->expectsOutputToContain('Created the operator account first.operator@example.com')
        ->expectsOutputToContain('is not currently enforced')
        ->expectsOutputToContain('remains an owner decision')
        ->assertExitCode(0)->run();

    $user = User::where('email', 'first.operator@example.com')->sole();
    expect($user->name)->toBe('First Operator')
        ->and($user->getRoleNames()->all())->toBe(['operator'])
        ->and($user->getDirectPermissions())->toHaveCount(0)
        ->and($user->isSuspended())->toBeFalse()
        ->and(Hash::check(BOOTSTRAP_PASSWORD, $user->password))->toBeTrue()
        ->and($user->two_factor_secret)->toBeNull('two-factor authentication is not enforced for operators; whether it must be is an open owner decision')
        ->and(app(AccountAdministrators::class)->ids()->all())->toBe([$user->id])
        ->and(Activity::where('description', 'bootstrapped first operator')->sole()->properties['email'])->toBe('first.operator@example.com');

    $this->post('/login', ['email' => 'first.operator@example.com', 'password' => BOOTSTRAP_PASSWORD])->assertRedirect();
    $this->assertAuthenticatedAs($user);
    $this->get(route('users.index'))->assertOk();
});

it('is idempotent and observable: a second run is refused, creates nothing and says why', function () {
    synchronizedInstallation();
    [$first] = bootstrapOperator();
    $first->assertExitCode(0)->run();

    [$second] = bootstrapOperator('another.operator@example.com');
    $second->expectsOutputToContain('already established')->assertExitCode(1)->run();

    expect(User::count())->toBe(1)
        ->and(Activity::where('description', 'bootstrapped first operator')->count())->toBe(1);
});

it('refuses once account administration exists in any form, active or suspended, direct or through a custom role', function (string $existing) {
    synchronizedInstallation();
    match ($existing) {
        'an active operator' => User::factory()->create()->assignRole('operator'),
        'a suspended operator (a recovery case, not a new installation)' => User::factory()->suspended()->create()->assignRole('operator'),
        'direct permissions' => User::factory()->create()->givePermissionTo(['users.view', 'users.manage']),
        'a custom role' => User::factory()->create()->assignRole(Role::create(['name' => 'accounts', 'guard_name' => 'web'])->givePermissionTo(['users.view', 'users.manage'])),
    };
    $users = User::count();

    [$command] = bootstrapOperator();
    $command->expectsOutputToContain('already established')->assertExitCode(1)->run();

    expect(User::count())->toBe($users)
        ->and(User::where('email', 'first.operator@example.com')->exists())->toBeFalse();
})->with([
    'an active operator',
    'a suspended operator (a recovery case, not a new installation)',
    'direct permissions',
    'a custom role',
]);

it('still bootstraps beside ordinary customers: only account administration blocks it', function () {
    synchronizedInstallation();
    User::factory()->count(2)->create()->each->assignRole('user');

    [$command] = bootstrapOperator();
    $command->assertExitCode(0)->run();

    expect(User::role('operator')->count())->toBe(1);
});

it('never elevates an existing account, whatever its email', function (string $email) {
    synchronizedInstallation();
    $customer = User::factory()->create(['email' => 'customer@example.com'])->assignRole('user');

    [$command] = bootstrapOperator($email);
    $command->expectsOutputToContain('never elevates an existing one')->assertExitCode(1)->run();

    expect($customer->fresh()->getRoleNames()->all())->toBe(['user'])
        ->and(User::count())->toBe(1)
        ->and(app(AccountAdministrators::class)->ids()->all())->toBe([]);
})->with(['customer@example.com', 'Customer@Example.com']);

it('refuses until the roles are synchronized, and says to run roles:sync', function () {
    [$command] = bootstrapOperator();
    $command->expectsOutputToContain('roles:sync')->assertExitCode(1)->run();

    expect(User::count())->toBe(0);
});

it('refuses when the operator role cannot administer accounts, rather than create a powerless operator', function () {
    synchronizedInstallation();
    Role::findByName('operator', 'web')->permissions()->detach(Permission::findByName('users.manage')->id);

    [$command] = bootstrapOperator();
    $command->expectsOutputToContain('roles:sync')->assertExitCode(1)->run();

    expect(User::count())->toBe(0);
});

it('applies the application\'s own credential rules and creates nothing when they are not met', function (array $answers, string $message) {
    synchronizedInstallation();

    [$command] = bootstrapOperator($answers['email'] ?? 'first.operator@example.com', $answers);
    $command->expectsOutputToContain($message)->assertExitCode(1)->run();

    expect(User::count())->toBe(0)
        ->and(Activity::where('description', 'bootstrapped first operator')->exists())->toBeFalse();
})->with([
    'a weak password' => [['password' => 'password', 'confirmation' => 'password'], 'at least 12 characters'],
    'a mismatched confirmation' => [['confirmation' => BOOTSTRAP_PASSWORD.'x'], 'confirmation does not match'],
    'a malformed email' => [['email' => 'not-an-email'], 'valid email'],
    'no name' => [['name' => ''], 'name field is required'],
]);

it('cannot be run non-interactively with an empty password: there is no default credential', function () {
    synchronizedInstallation();

    $exit = Artisan::call('operator:bootstrap', ['email' => 'first.operator@example.com', '--name' => 'First', '--no-interaction' => true]);

    expect($exit)->toBe(1)
        ->and(User::count())->toBe(0);
});

it('has no web surface: no route reaches it and registration stays off', function () {
    synchronizedInstallation();

    $reaching = collect(Route::getRoutes()->getRoutes())->filter(fn ($route) => str_contains(strtolower($route->getActionName().$route->uri()), 'bootstrap'));

    expect($reaching->all())->toBe([]);
    $this->get('/register')->assertNotFound();
    $this->post('/register', ['name' => 'X', 'email' => 'x@example.com', 'password' => BOOTSTRAP_PASSWORD, 'password_confirmation' => BOOTSTRAP_PASSWORD])->assertNotFound();
    expect(User::count())->toBe(0);
});

it('does not use RoleSeeder: the installation is prepared by the reviewed synchronization alone', function () {
    expect(file_get_contents(app_path('Services/OperatorBootstrap.php')))->not->toContain('RoleSeeder')
        ->and(file_get_contents(app_path('Console/Commands/BootstrapFirstOperator.php')))->not->toContain('RoleSeeder');
});
