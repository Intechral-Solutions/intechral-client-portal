<?php

/*
 * R0.9-CS1: the customer surface mode and the customer/operator classification. How the mode is resolved
 * (fail closed on every input), who counts as an operator (the explicit portal.operator capability and nothing
 * else), and the pinned allowlists. The route boundary itself is CustomerPilotRouteBoundaryTest.
 */

use App\Models\User;
use App\Shared\Permissions\BuiltInRoles;
use App\Shared\Permissions\PermissionCatalogue;
use App\Shared\Pilot\CustomerPilotGate;
use App\Shared\Pilot\CustomerSurfaceMode;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;

require_once __DIR__.'/PilotTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    CustomerPilotGate::forgetWarnings();
});

/** Point the application at an environment name for the rest of the test. */
function pilotEnvironment(string $environment): void
{
    app()->detectEnvironment(fn () => $environment);
}

// ── The mode: A, B, C ─────────────────────────────────────────────────────────

it('defaults to closed when the shipped configuration is read with the variable unset', function () {
    $previous = [$_ENV['PORTAL_CUSTOMER_SURFACE_MODE'] ?? null, $_SERVER['PORTAL_CUSTOMER_SURFACE_MODE'] ?? null];
    unset($_ENV['PORTAL_CUSTOMER_SURFACE_MODE'], $_SERVER['PORTAL_CUSTOMER_SURFACE_MODE']);
    putenv('PORTAL_CUSTOMER_SURFACE_MODE');

    try {
        // The real config file evaluated with nothing in the environment: the shipped default, not a test override.
        expect((require base_path('config/portal.php'))['customer_surface_mode'])->toBe('closed');
    } finally {
        foreach (['_ENV' => $previous[0], '_SERVER' => $previous[1]] as $bag => $value) {
            if ($value !== null) {
                $GLOBALS[$bag]['PORTAL_CUSTOMER_SURFACE_MODE'] = $value;
            }
        }
        $previous[0] !== null && putenv('PORTAL_CUSTOMER_SURFACE_MODE='.$previous[0]);
    }
});

it('resolves a missing key, a stale cached config and the retired boolean switch to closed', function () {
    // A configuration cached before this key existed has no such key.
    config(['portal' => []]);
    expect(CustomerPilotGate::mode())->toBe(CustomerSurfaceMode::Closed);

    // A cache built by the previous package carries only the old boolean; it must not open anything.
    config(['portal' => ['customer_pilot_gate' => false]]);
    expect(CustomerPilotGate::mode())->toBe(CustomerSurfaceMode::Closed);

    config(['portal' => ['customer_pilot_gate' => true]]);
    expect(CustomerPilotGate::mode())->toBe(CustomerSurfaceMode::Closed);

    config(['portal' => null]);
    expect(CustomerPilotGate::mode())->toBe(CustomerSurfaceMode::Closed);
});

it('resolves every null, empty, whitespace, boolean, non-string or unrecognised value to closed', function (mixed $value) {
    config(['portal.customer_surface_mode' => $value]);

    expect(CustomerPilotGate::mode())->toBe(CustomerSurfaceMode::Closed);
})->with([
    'null' => [null],
    'empty' => [''],
    'spaces' => ['   '],
    'tab and newline' => ["\t\n"],
    'true' => [true],
    'false' => [false],
    'int zero' => [0],
    'int one' => [1],
    'array' => [['pilot']],
    'unknown word' => ['open'],
    'typo' => ['piloot'],
    'prefix' => ['pilotx'],
    'enabled' => ['enabled'],
    'on' => ['on'],
    'dotenv null text' => ['(null)'],
    'dotenv empty text' => ['(empty)'],
    'old switch value' => ['true'],
    'two modes' => ['pilot,legacy'],
]);

it('reads the three modes, ignoring case and surrounding whitespace', function (string $value, CustomerSurfaceMode $expected) {
    config(['portal.customer_surface_mode' => $value]);

    expect(CustomerPilotGate::mode())->toBe($expected);
})->with([
    ['closed', CustomerSurfaceMode::Closed],
    ['pilot', CustomerSurfaceMode::Pilot],
    ['PILOT', CustomerSurfaceMode::Pilot],
    [' pilot ', CustomerSurfaceMode::Pilot],
    ['legacy', CustomerSurfaceMode::Legacy],
    ['Legacy', CustomerSurfaceMode::Legacy],
]);

it('turns every dotenv spelling into a value that cannot open anything', function (string $dotenv, ?string $expected) {
    // What env() hands config/portal.php for each way an operator might write the variable. The file is evaluated for
    // real, then resolved by the gate.
    $_ENV['PORTAL_CUSTOMER_SURFACE_MODE'] = $_SERVER['PORTAL_CUSTOMER_SURFACE_MODE'] = $dotenv;

    try {
        config(['portal.customer_surface_mode' => (require base_path('config/portal.php'))['customer_surface_mode']]);

        expect(CustomerPilotGate::mode()->value)->toBe($expected);
    } finally {
        $_ENV['PORTAL_CUSTOMER_SURFACE_MODE'] = $_SERVER['PORTAL_CUSTOMER_SURFACE_MODE'] = 'legacy';
    }
})->with([
    'null' => ['null', 'closed'],
    '(null)' => ['(null)', 'closed'],
    'empty' => ['empty', 'closed'],
    '(empty)' => ['(empty)', 'closed'],
    'true' => ['true', 'closed'],
    '(false)' => ['(false)', 'closed'],
    'blank' => ['', 'closed'],
    'pilot' => ['pilot', 'pilot'],
    'closed' => ['closed', 'closed'],
    'legacy (testing env)' => ['legacy', 'legacy'],
]);

it('is read through configuration only, so a cached configuration cannot change what the application does', function () {
    // env() returns null once configuration is cached, so any other reader would silently fall back to a default.
    $offenders = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS));
    $read = '/(\benv|\bgetenv)\(\s*[\'"]PORTAL_CUSTOMER|\$_(ENV|SERVER)\[\s*[\'"]PORTAL_CUSTOMER/';

    foreach ($iterator as $file) {
        if ($file->isFile() && str_ends_with($file->getFilename(), '.php')
            && preg_match($read, (string) file_get_contents($file->getPathname())) === 1) {
            $offenders[] = $file->getPathname();
        }
    }

    expect($offenders)->toBe([])
        ->and((string) file_get_contents(base_path('config/portal.php')))->toContain("env('PORTAL_CUSTOMER_SURFACE_MODE', 'closed')");
});

it('exposes one configuration key and no per-module switch', function () {
    expect(array_keys(config('portal')))->toBe(['customer_surface_mode']);
});

// ── Legacy: D, E ──────────────────────────────────────────────────────────────

it('honours an explicit legacy mode in the local and testing environments', function (string $environment) {
    pilotEnvironment($environment);
    config(['portal.customer_surface_mode' => 'legacy']);
    Log::spy();

    expect(CustomerPilotGate::mode())->toBe(CustomerSurfaceMode::Legacy)
        ->and(CustomerPilotGate::restricts(pilotCustomer()))->toBeFalse();
    Log::shouldNotHaveReceived('warning');
})->with(['local', 'testing']);

it('refuses legacy everywhere else, resolving to closed and warning', function (string $environment) {
    pilotEnvironment($environment);
    config(['portal.customer_surface_mode' => 'legacy']);
    Log::spy();

    expect(CustomerPilotGate::mode())->toBe(CustomerSurfaceMode::Closed)
        ->and(CustomerPilotGate::restricts(pilotCustomer()))->toBeTrue();
    Log::shouldHaveReceived('warning')->once();
})->with(['production', 'staging', 'Production', 'prod', 'ci', 'demo', 'development']);

it('warns once per configured value, not once per request', function () {
    pilotEnvironment('production');
    config(['portal.customer_surface_mode' => 'legacy']);
    Log::spy();

    CustomerPilotGate::mode();
    CustomerPilotGate::mode();
    CustomerPilotGate::mode();

    Log::shouldHaveReceived('warning')->once();
});

it('keeps pilot and closed as they are in production', function () {
    pilotEnvironment('production');

    config(['portal.customer_surface_mode' => 'pilot']);
    expect(CustomerPilotGate::mode())->toBe(CustomerSurfaceMode::Pilot);

    config(['portal.customer_surface_mode' => 'closed']);
    expect(CustomerPilotGate::mode())->toBe(CustomerSurfaceMode::Closed);
});

// ── Who is an operator: H, I, J, K ────────────────────────────────────────────

it('treats the built-in operator as an operator and the built-in user as a customer', function () {
    expect(CustomerPilotGate::isOperator(pilotOperator()))->toBeTrue()
        ->and(CustomerPilotGate::isCustomer(pilotOperator()))->toBeFalse()
        ->and(CustomerPilotGate::isOperator(pilotCustomer()))->toBeFalse()
        ->and(CustomerPilotGate::isCustomer(pilotCustomer()))->toBeTrue()
        ->and(CustomerPilotGate::isCustomer(null))->toBeFalse()
        ->and(CustomerPilotGate::isOperator(null))->toBeFalse();
});

it('keeps portal.operator in the code-owned operator role and out of the customer role', function () {
    $definitions = BuiltInRoles::definitions();

    expect($definitions[BuiltInRoles::OPERATOR])->toContain(PermissionCatalogue::PORTAL_OPERATOR)
        ->and($definitions[BuiltInRoles::USER])->not->toContain(PermissionCatalogue::PORTAL_OPERATOR)
        ->and(PermissionCatalogue::userDefaults())->not->toContain(PermissionCatalogue::PORTAL_OPERATOR)
        ->and(BuiltInRoles::problems())->toBe([]);

    // And in the stored state the seeders produced, read from the database rather than from the definitions.
    expect(Role::findByName('operator')->hasPermissionTo('portal.operator'))->toBeTrue()
        ->and(Role::findByName('user')->hasPermissionTo('portal.operator'))->toBeFalse();
});

it('does not infer eligibility from any other capability, not even all of them', function () {
    $everythingElse = array_values(array_diff(PermissionCatalogue::all(), [PermissionCatalogue::PORTAL_OPERATOR]));
    $user = pilotCustomRoleUser('all-but-eligibility', $everythingElse);

    expect($user->getAllPermissions()->pluck('name')->all())->not->toContain('portal.operator')
        ->and(count($user->getAllPermissions()))->toBe(count($everythingElse))
        ->and(CustomerPilotGate::isOperator($user))->toBeFalse()
        ->and(CustomerPilotGate::isCustomer($user))->toBeTrue();

    // One operational capability at a time, including the ones that gate nothing today.
    foreach (['settings.view', 'tasks.view_all', 'time.view_all', 'projects.manage', 'projects.admin', 'tickets.assign', 'crm.manage', 'billing.manage', 'users.view', 'roles.view', 'cms.edit'] as $capability) {
        expect(CustomerPilotGate::isCustomer(pilotCustomRoleUser('only-'.$capability, [$capability])))->toBeTrue("{$capability} alone must not make an operator");
    }
});

it('makes a custom role eligible only when it carries portal.operator, whatever it is called', function () {
    $named = pilotCustomRoleUser('operator', ['tickets.view']);
    expect(CustomerPilotGate::isOperator($named))->toBeFalse();

    $eligible = pilotCustomRoleUser('trusted-staff', ['portal.operator']);
    expect(CustomerPilotGate::isOperator($eligible))->toBeTrue();

    // A direct grant counts like a role grant: it is the same effective capability.
    $direct = User::factory()->create();
    $direct->givePermissionTo('portal.operator');
    expect(CustomerPilotGate::isOperator(User::find($direct->id)))->toBeTrue();
});

it('restricts a user with no roles at all', function () {
    $nobody = User::factory()->create();
    pilotMode('pilot');

    expect(CustomerPilotGate::restricts($nobody))->toBeTrue();
});

it('restricts only a customer, and never in legacy mode', function () {
    $customer = pilotCustomer();
    $operator = pilotOperator();

    foreach (['closed', 'pilot'] as $mode) {
        pilotMode($mode);
        expect(CustomerPilotGate::restricts($customer))->toBeTrue("{$mode}")
            ->and(CustomerPilotGate::restricts($operator))->toBeFalse("{$mode}")
            ->and(CustomerPilotGate::restricts(null))->toBeFalse("{$mode}");
    }

    pilotMode('legacy');
    expect(CustomerPilotGate::restricts($customer))->toBeFalse();
});

it('defers SSO in closed and pilot mode and in no other', function () {
    foreach (['closed' => true, 'pilot' => true, 'legacy' => false, 'nonsense' => true] as $mode => $deferred) {
        pilotMode($mode);
        expect(CustomerPilotGate::ssoDeferred())->toBe($deferred, $mode);
    }
});

// ── What each mode exposes ────────────────────────────────────────────────────

it('exposes to the browser only what the mode uses', function () {
    $customer = pilotCustomer();

    pilotMode('pilot');
    expect(CustomerPilotGate::exposedPermissions($customer, PermissionCatalogue::userDefaults()))
        ->toEqualCanonicalizing(['tickets.view', 'tickets.create', 'projects.view']);

    pilotMode('closed');
    expect(CustomerPilotGate::exposedPermissions($customer, PermissionCatalogue::userDefaults()))->toBe([]);

    // Operators and legacy see the list unchanged.
    expect(CustomerPilotGate::exposedPermissions(pilotOperator(), PermissionCatalogue::all()))->toBe(PermissionCatalogue::all());
    pilotMode('legacy');
    expect(CustomerPilotGate::exposedPermissions($customer, PermissionCatalogue::userDefaults()))->toBe(PermissionCatalogue::userDefaults());
});

it('offers closed customers Home only and pilot customers Home, Support and Projects', function () {
    pilotMode('closed');
    expect(CustomerPilotGate::workspaces())->toBe(['home']);

    pilotMode('pilot');
    expect(CustomerPilotGate::workspaces())->toBe(['home', 'helpdesk', 'projects']);
});

// ── The allowlists ────────────────────────────────────────────────────────────

it('pins the reviewed allowlists: adding a customer route changes this test', function () {
    expect(array_keys(CustomerPilotGate::ACCOUNT_ROUTES))->toEqualCanonicalizing([
        'root', 'dashboard',
        'logout', 'profile.show', 'profile.password.confirm', 'profile.sessions.destroy', 'profile.social.unlink',
        'password.confirm', 'password.confirm.store', 'password.confirmation',
        'user-password.update', 'user-profile-information.update',
        'two-factor.enable', 'two-factor.confirm', 'two-factor.disable', 'two-factor.qr-code', 'two-factor.secret-key',
        'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
        'login', 'login.store', 'password.request', 'password.email', 'password.reset', 'password.update',
        'two-factor.login', 'two-factor.login.store', 'invitation.show', 'invitation.register',
    ])->and(array_keys(CustomerPilotGate::PILOT_ROUTES))->toEqualCanonicalizing([
        'tickets.index', 'tickets.show', 'tickets.create', 'tickets.store', 'tickets.replies.store', 'tickets.attachment.download',
        'projects.index', 'projects.show',
    ])->and(CustomerPilotGate::WORKSPACES)->toBe(['closed' => ['home'], 'pilot' => ['home', 'helpdesk', 'projects']]);
});

it('allows nothing in legacy mode through the gate (it restricts nothing there)', function () {
    expect(CustomerPilotGate::routes(CustomerSurfaceMode::Legacy))->toBe([])
        ->and(array_keys(CustomerPilotGate::routes(CustomerSurfaceMode::Closed)))->toBe(array_keys(CustomerPilotGate::ACCOUNT_ROUTES))
        ->and(array_keys(CustomerPilotGate::routes(CustomerSurfaceMode::Pilot)))->toEqualCanonicalizing([...array_keys(CustomerPilotGate::ACCOUNT_ROUTES), ...array_keys(CustomerPilotGate::PILOT_ROUTES)]);
});

it('names only routes that exist, so a rename cannot leave a dead entry silently allowed', function () {
    $registered = collect(Route::getRoutes()->getRoutes())->map->getName()->filter()->all();
    $allowed = array_keys(CustomerPilotGate::routes(CustomerSurfaceMode::Pilot));

    expect(array_values(array_diff($allowed, $registered)))->toBe([]);
});

it('allows no excluded module by name in any mode', function () {
    foreach (array_keys(CustomerPilotGate::routes(CustomerSurfaceMode::Pilot)) as $name) {
        foreach (['billing.', 'time.', 'tasks.', 'cms.', 'operator.', 'crm.', 'organizations.', 'roles.', 'users.', 'sso.', 'webhooks.', 'invitations.', 'projects.board', 'projects.tasks.', 'projects.time.', 'projects.milestones.', 'projects.create', 'projects.store', 'projects.edit', 'projects.update', 'projects.destroy', 'projects.members.', 'projects.companies.'] as $excluded) {
            expect(str_starts_with($name, $excluded))->toBeFalse("{$name} must not be on the customer allowlist");
        }
    }

    // Closed mode adds neither the Helpdesk nor projects.
    foreach (array_keys(CustomerPilotGate::routes(CustomerSurfaceMode::Closed)) as $name) {
        expect(str_starts_with($name, 'tickets.') || str_starts_with($name, 'projects.'))->toBeFalse($name);
    }
});

it('allows the customer project routes only behind projects.view', function () {
    pilotMode('pilot');

    expect(CustomerPilotGate::requiredCapability('projects.index'))->toBe('projects.view')
        ->and(CustomerPilotGate::requiredCapability('projects.show'))->toBe('projects.view')
        ->and(CustomerPilotGate::allowsRoute(null))->toBeFalse()
        ->and(CustomerPilotGate::allowsRoute('billing.client.invoices.index'))->toBeFalse()
        ->and(CustomerPilotGate::allowsRoute('tickets.index'))->toBeTrue();

    pilotMode('closed');
    expect(CustomerPilotGate::allowsRoute('tickets.index'))->toBeFalse()
        ->and(CustomerPilotGate::allowsRoute('projects.show'))->toBeFalse()
        ->and(CustomerPilotGate::allowsRoute('dashboard'))->toBeTrue();
});
