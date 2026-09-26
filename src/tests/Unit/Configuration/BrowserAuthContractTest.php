<?php

/*
 * EPIC-013 WP4 remediation: the browser suite's authentication contract.
 *
 * The suite used to submit the real login form once per test — 64 call sites, 53 of them as the
 * operator — against Fortify's five-per-minute limiter. A measured run made 114 `POST /login`
 * requests and was refused 45 times, and two tests failed having exhausted every retry.
 *
 * Feature specs now reuse authenticated state minted once per persona. These are static assertions
 * because the property is architectural: "no feature spec authenticates" cannot be observed by
 * running one spec, and the failure mode it prevents only appears in a ten-minute full run.
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
        // One test edits the operator's own name and email; a failure part-way would leave the
        // reusable baseline describing a different person.
        'inertia-coexistence.spec.ts',
        // Creates its own actor with a per-run email, so there is no reusable state to mint.
        'projects-migration.spec.ts',
        // Signs out. Sessions are database-backed, so it destroys the session row it presents.
        'shell.spec.ts',
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

it('mints one authentication per persona, from a real login', function () {
    $setup = (string) file_get_contents(base_path('tests/Browser/auth.setup.ts'));

    // The state must originate from the application's own authentication flow: never a forged or
    // hard-coded session cookie.
    expect($setup)->toContain('signIn(page, email)')
        ->and($setup)->toContain('storageState()');

    $config = (string) file_get_contents(base_path('playwright.config.ts'));

    expect($config)->toContain("name: 'setup'")
        ->and($config)->toContain("dependencies: ['setup']");
});

it('keeps the two capability profiles distinct', function () {
    $auth = (string) file_get_contents(base_path('tests/Browser/support/auth.ts'));

    // Collapsing the member onto the operator would save logins by deleting the authorization
    // coverage that the capability-filtering and read-only-board specs exist to provide.
    expect($auth)->toContain("'operator@intechral.test'")
        ->and($auth)->toContain("'user@intechral.test'")
        ->and($auth)->toContain('operator.json')
        ->and($auth)->toContain('member.json');
});

it('stores authentication only, never shell preferences', function () {
    $setup = (string) file_get_contents(base_path('tests/Browser/auth.setup.ts'));

    // A captured `localStorage` would bake the appearance theme the shell writes on mount — and any
    // drawer or pin state — into the baseline every spec inherits, so the drawer-persistence tests
    // could no longer establish their own initial conditions.
    expect($setup)->toContain('cookies: state.cookies')
        ->and($setup)->toContain('origins: []');
});

it('never source-controls the generated session material', function () {
    $ignored = (string) file_get_contents(base_path('.gitignore'));

    expect($ignored)->toContain('/tests/Browser/.auth');

    // And nothing is tracked there today.
    $tracked = [];
    exec('cd '.escapeshellarg(base_path()).' && git ls-files tests/Browser/.auth 2>/dev/null', $tracked);

    expect($tracked)->toBe([]);
});
