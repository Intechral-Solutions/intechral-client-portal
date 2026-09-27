<?php

/*
 * EPIC-013 WP4 remediation, then POST-WP4 E2E hardening: the browser suite's authentication
 * contract. See docs/testing/e2e-browser-suite.md for the full history and login budget.
 *
 * The suite used to submit the real login form once per test — 64 call sites, 53 of them as the
 * operator — against Fortify's five-per-minute limiter. A measured run made 114 `POST /login`
 * requests and was refused 45 times.
 *
 * A first remediation minted one authenticated session per persona per RUN, through a Playwright
 * `setup` project, and had every worker reuse it. That fixed the login volume but every worker then
 * read and wrote the *same* database-backed Laravel session row, so one worker's flash messages and
 * validation errors could appear in another's pages.
 *
 * The current design mints a session per WORKER instead (`support/auth.ts`'s `sessions` fixture),
 * so no two workers ever share one. Doing that naively reopened the limiter problem one level up:
 * with enough spec files defaulting to the operator persona, and Playwright's default worker count
 * tied to machine core count, ordinary parallel execution alone could mint enough real operator
 * logins inside one Fortify window to collide with it — before any auth-subject test ran at all. The
 * fix has two parts: an explicit, deliberately modest worker cap (a worker mints a given persona's
 * session at most once for its whole lifetime, however many spec files it goes on to run, so the cap
 * bounds the persona's total login count for the entire run), and moving auth-subject flows that
 * don't need the canonical operator/member identity onto dedicated seeded fixtures, so they spend
 * their own limiter bucket instead of the reusable personas'.
 *
 * These are static assertions because the properties are architectural: "no feature spec
 * authenticates" cannot be observed by running one spec, and the failure modes here only appear in
 * a full parallel run.
 */

function browserSpecs(): array
{
    $files = glob(base_path('tests/Browser/*.spec.ts'));
    sort($files);

    return $files;
}

/** The specs allowed to drive the real login form, and why each one must. */
function authenticationOwnedSpecs(): array
{
    return [
        // Invalid credentials, the logout transition, and password confirmation where a fresh
        // authentication event is the point.
        'auth-migration.spec.ts',
        // One test edits its own name and email; a failure part-way would leave the account
        // describing a different person.
        'inertia-coexistence.spec.ts',
        // Creates its own actor with a per-run email, so there is no reusable state to mint.
        'projects-migration.spec.ts',
        // Signs out. Sessions are database-backed, so it destroys the session row it presents.
        'shell.spec.ts',
    ];
}

/**
 * Real-login flows that don't need the canonical operator/member identity, mapped to the dedicated
 * DevSeeder fixture each now uses instead — so they spend their own Fortify bucket rather than the
 * one every worker's own per-persona login shares.
 */
function dedicatedAuthFixtures(): array
{
    return [
        'auth-migration.spec.ts' => 'e2e-login-flow@intechral.test',
        'inertia-coexistence.spec.ts' => 'e2e-profile-mutation@intechral.test',
        'shell.spec.ts' => 'e2e-signout@intechral.test',
    ];
}

it('finds the browser suite it is asserting over', function () {
    // A rename that emptied this list would make every assertion below vacuously true.
    expect(browserSpecs())->not->toBeEmpty()
        ->and(count(browserSpecs()))->toBeGreaterThan(5);
});

it('lets no ordinary feature spec authenticate through the login form', function () {
    foreach (browserSpecs() as $file) {
        if (in_array(basename($file), authenticationOwnedSpecs(), true)) {
            continue;
        }

        expect((string) file_get_contents($file))
            ->not->toContain('signIn(', basename($file).' must start from reusable authenticated state');
    }
});

it('keeps real-form authentication confined to the specs that own it, one flow each', function () {
    $counts = [];

    foreach (browserSpecs() as $file) {
        $calls = preg_match_all('/\bsignIn\(/', (string) file_get_contents($file));

        if ($calls > 0) {
            $counts[basename($file)] = $calls;
        }
    }

    // Exactly the four documented flows. A fifth would be a new limiter consumer and should be a
    // deliberate decision, not a quiet regression back to logging in per test.
    expect($counts)->toBe([
        'auth-migration.spec.ts' => 1,
        'inertia-coexistence.spec.ts' => 1,
        'projects-migration.spec.ts' => 1,
        'shell.spec.ts' => 1,
    ]);
});

it('keeps auth-subject flows off the reusable personas\' own Fortify bucket', function () {
    foreach (dedicatedAuthFixtures() as $file => $fixtureEmail) {
        $contents = (string) file_get_contents(base_path("tests/Browser/{$file}"));

        expect($contents)->toContain($fixtureEmail);

        // Every worker that needs the operator/member persona mints its own real login too
        // (support/auth.ts); an auth-subject flow spending the SAME identity's login budget on top
        // of that is exactly the collision the dedicated fixtures exist to avoid.
        foreach (['operator@intechral.test', 'user@intechral.test'] as $reusable) {
            expect($contents)->not->toContain("signIn(page, '{$reusable}'");
        }
    }

    $seeder = (string) file_get_contents(base_path('database/seeders/DevSeeder.php'));

    foreach (dedicatedAuthFixtures() as $fixtureEmail) {
        expect($seeder)->toContain($fixtureEmail);
    }
});

it('mints an authenticated session per worker, from a real login, never from a shared setup project', function () {
    $auth = (string) file_get_contents(base_path('tests/Browser/support/auth.ts'));

    // The state must originate from the application's own authentication flow: never a forged or
    // hard-coded session cookie.
    expect($auth)->toContain("scope: 'worker'")
        ->and($auth)->toContain('signIn(')
        ->and($auth)->toContain('storageState()');

    $config = (string) file_get_contents(base_path('playwright.config.ts'));

    // A `setup` project handing every worker the same minted state is the shared-session design
    // this file exists to keep out.
    expect($config)->not->toContain("name: 'setup'")
        ->and($config)->not->toContain('dependencies:');
});

it('caps parallel workers so a persona\'s login budget cannot depend on machine core count', function () {
    $config = (string) file_get_contents(base_path('playwright.config.ts'));

    expect($config)->toMatch('/\bworkers:\s*(\d+)\b/');

    preg_match('/\bworkers:\s*(\d+)\b/', $config, $matches);
    $workers = (int) $matches[1];

    // A worker mints a given persona's session at most once for its whole lifetime, so this bounds
    // that persona's total real logins for the entire run. Fortify allows 5 per minute per
    // email+IP; this asserts headroom under that ceiling rather than pinning the exact number.
    expect($workers)->toBeGreaterThan(0)->toBeLessThanOrEqual(4);
});

it('keeps the two capability profiles distinct', function () {
    $auth = (string) file_get_contents(base_path('tests/Browser/support/auth.ts'));

    // Collapsing the member onto the operator would save logins by deleting the authorization
    // coverage that the capability-filtering and read-only-board specs exist to provide.
    expect($auth)->toContain("'operator@intechral.test'")
        ->and($auth)->toContain("'user@intechral.test'");
});

it('stores authentication only, never shell preferences', function () {
    $auth = (string) file_get_contents(base_path('tests/Browser/support/auth.ts'));

    // A captured `localStorage` would bake the appearance theme the shell writes on mount — and any
    // drawer or pin state — into every session every spec inherits, so the drawer-persistence tests
    // could no longer establish their own initial conditions.
    expect($auth)->toContain('cookies')
        ->and($auth)->toContain('origins: []');
});

it('never writes the minted session state to disk', function () {
    $auth = (string) file_get_contents(base_path('tests/Browser/support/auth.ts'));

    // Sessions live in worker memory only. The one legitimate disk write here is the claim-file
    // registry (a token hash and an owner label — never a cookie), which exists to fail loudly if
    // two workers are ever handed the same session again.
    expect(substr_count($auth, 'writeFileSync('))->toBe(1)
        ->and($auth)->toContain('join(tmpdir()')
        ->and($auth)->not->toContain('JSON.stringify(state');
});

it('never source-controls generated session material', function () {
    $tracked = [];
    exec(
        'cd '.escapeshellarg(base_path()).' && git ls-files tests/Browser/.auth 2>/dev/null',
        $tracked,
    );

    expect($tracked)->toBe([]);
});
