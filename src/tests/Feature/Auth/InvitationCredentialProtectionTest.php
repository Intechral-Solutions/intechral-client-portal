<?php

/*
 * Release 1.0 (R1-3B): the protections around a credential that travels in a URL (design record 5.8, 5.9, I1): response headers, throttling,
 * and the plaintext token / hash staying out of the session store, the application log, the activity log and unrelated responses.
 */

require_once __DIR__.'/InvitationTestHelpers.php';
require_once __DIR__.'/../Pilot/PilotTestHelpers.php';

use App\Exceptions\InvitationDatabaseFailure;
use App\Models\Organization;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\OrganizationInvitations;
use Illuminate\Cache\ArrayStore;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    $this->logged = [];
    $this->events = [];

    Event::listen(MessageLogged::class, function (MessageLogged $event) {
        $this->events[] = $event;
        $text = $event->message;

        // The exception object is not JSON-serializable usefully, so its message chain is what a log line would carry.
        for ($e = $event->context['exception'] ?? null; $e instanceof Throwable; $e = $e->getPrevious()) {
            $text .= ' '.$e->getMessage();
        }

        $this->logged[] = $text.' '.json_encode(array_diff_key($event->context, ['exception' => 1]));
    });
});

/**
 * What the session STORE has persisted: the decoded payload of every row in the `sessions` table (the test environment does not encrypt it).
 * This is the database, not the in-memory store, so it is what a database reader, a backup or a replica would see.
 *
 * @return list<string>
 */
function credPersistedSessions(): array
{
    return DB::table('sessions')->pluck('payload')->map(fn ($payload) => (string) (base64_decode($payload, true) ?: $payload))->all();
}

/** Everything stored for any session: the persisted payloads plus the live store. */
function credSessionDump(): string
{
    return implode("\n", credPersistedSessions())."\n".json_encode(session()->all());
}

function credAssertProtected($response): void
{
    expect($response->headers->get('Referrer-Policy'))->toBe('no-referrer')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store');
}

describe('response headers', function () {
    it('are on the registration page, live or dead, and on every answer to its POST', function () {
        [, $token] = regInvOpen();

        credAssertProtected($this->get(route('invitation.show', $token))->assertOk());
        credAssertProtected($this->get(route('invitation.show', 'not-a-token'))->assertOk());

        // success, a dead link and a failed validation (a redirect back to the page)
        credAssertProtected($this->post(route('invitation.register', $token), ['name' => 'x', 'password' => 'weak', 'password_confirmation' => 'weak']));
        credAssertProtected($this->post(route('invitation.register', 'not-a-token'), regInvForm()));
        credAssertProtected($this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('dashboard')));
    });

    it('are on the SSO initiation when it carries an invitation, including the redirect to the provider, and only then', function () {
        Socialite::fake('google', SocialiteUser::fake(['id' => 'x', 'email' => 'x@example.test']));
        [, $token] = regInvOpen();

        $started = $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]))->assertRedirect('https://socialite.fake/google/authorize');
        credAssertProtected($started);

        // A dead token's answer carries the same protections: the URL it arrived on held a token.
        credAssertProtected($this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => 'dead'])));

        // Ordinary SSO login is untouched: no new headers.
        $ordinary = $this->get(route('sso.redirect', 'google'))->assertRedirect('https://socialite.fake/google/authorize');
        expect($ordinary->headers->get('Referrer-Policy'))->toBeNull()
            ->and($ordinary->headers->get('Cache-Control'))->not->toContain('no-store');
    });

    it('include a document-level no-referrer policy on the invitation page, first in the head, and on no ordinary page', function () {
        [, $token] = regInvOpen();
        $meta = '<meta name="referrer" content="no-referrer">';

        // A response header alone is not enough: a proxy can append its own Referrer-Policy and a browser applies the LAST value. The
        // document's own policy is not overridden by a header (the development nginx did exactly that and the token URL leaked as a
        // same-origin Referer), so the page carries it too, live or dead, ahead of every tag that makes a request.
        foreach ([$token, 'not-a-token'] as $candidate) {
            $html = $this->get(route('invitation.show', $candidate))->assertOk()->getContent();

            expect($html)->toContain($meta)
                ->and(substr_count($html, 'name="referrer"'))->toBe(1);

            $head = substr($html, (int) strpos($html, '<head>'));
            $position = static fn (string $needle) => strpos($head, $needle);

            expect($position('<meta charset'))->toBeLessThan($position($meta))
                ->and($position($meta))->toBeLessThan($position('<meta name="viewport"'))
                ->and($position($meta))->toBeLessThan($position('rel="preload"') ?: PHP_INT_MAX)
                ->and($position($meta))->toBeLessThan($position('<link rel="stylesheet"') ?: PHP_INT_MAX)
                ->and($position($meta))->toBeLessThan($position('<script') ?: PHP_INT_MAX);
        }

        // Ordinary pages: no tag, so no unintended global no-referrer policy.
        expect($this->get(route('login'))->assertOk()->getContent())->not->toContain('name="referrer"');
        expect($this->get(route('password.request'))->getContent())->not->toContain('name="referrer"');
    });

    it('turn the meta tag on through the request attribute only, never through the URL or a header the client controls', function () {
        // Whatever a client sends cannot switch the policy on or off: the attribute is set by the middleware on the credential routes alone.
        $login = $this->withHeaders(['X-Credential-Response' => '1', 'Referrer-Policy' => 'no-referrer'])
            ->get(route('login', ['credential_response' => 1]))->assertOk()->getContent();

        expect($login)->not->toContain('name="referrer"');
    });

    it('are not added to unrelated pages', function () {
        $login = $this->get(route('login'))->assertOk();

        expect($login->headers->get('Referrer-Policy'))->toBeNull()
            ->and($login->headers->get('Cache-Control'))->not->toContain('no-store');
    });
});

describe('throttling', function () {
    it('limits the registration page per client address, with the headers on the 429 too, and a different address is unaffected', function () {
        for ($i = 0; $i < 60; $i++) {
            $this->get(route('invitation.show', 'not-a-token'))->assertOk();
        }

        $limited = $this->get(route('invitation.show', 'not-a-token'))->assertStatus(429);
        credAssertProtected($limited);
        expect($limited->headers->get('Retry-After'))->not->toBeNull();

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.9'])->get(route('invitation.show', 'not-a-token'))->assertOk();
    });

    it('limits the registration POST harder, and the GET budget does not eat the POST budget', function () {
        for ($i = 0; $i < 20; $i++) {
            // A weak password fails validation before the compromised-password service is consulted: no external call per attempt here.
            $this->post(route('invitation.register', 'not-a-token'), ['name' => 'x', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertRedirect();
        }

        credAssertProtected($this->post(route('invitation.register', 'not-a-token'), ['name' => 'x', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertStatus(429));
        $this->get(route('invitation.show', 'not-a-token'))->assertOk();
    });

    it('limits the SSO initiation only when it carries an invitation; ordinary SSO login is never limited by it', function () {
        Socialite::fake('google', SocialiteUser::fake(['id' => 'x', 'email' => 'x@example.test']));

        for ($i = 0; $i < 30; $i++) {
            $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => 'dead']))->assertRedirect(route('login'));
        }

        credAssertProtected($this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => 'dead']))->assertStatus(429));

        // The same client address can still start an ordinary login, as many times as it likes.
        for ($i = 0; $i < 40; $i++) {
            $this->get(route('sso.redirect', 'google'))->assertRedirect('https://socialite.fake/google/authorize');
        }
    });

    it('keys the limiters on the client address alone: many different tokens from one address share ONE budget, and none of them is named in a cache key', function () {
        // If the key contained the token, every new token would start a new budget and the limiter would limit nothing.
        for ($i = 0; $i < 60; $i++) {
            $this->get(route('invitation.show', Str::random(64)))->assertOk();
        }

        credAssertProtected($this->get(route('invitation.show', Str::random(64)))->assertStatus(429));

        for ($i = 0; $i < 20; $i++) {
            $this->post(route('invitation.register', Str::random(64)), ['name' => 'x', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertRedirect();
        }

        $this->post(route('invitation.register', Str::random(64)), ['name' => 'x', 'password' => 'weak', 'password_confirmation' => 'weak'])->assertStatus(429);

        // The framework hashes a limiter's key, so a token could not be read back from the store; the budget behaviour above is the proof, and
        // this checks the store holds no key that names a token or an address anyway.
        $store = Cache::getStore();
        expect($store)->toBeInstanceOf(ArrayStore::class);
        $keys = collect(array_keys((fn () => $this->storage)->call($store)));
        expect($keys)->not->toBeEmpty()->and($keys->filter(fn ($key) => preg_match('/[A-Za-z0-9]{64}/', (string) $key) === 1 && ! preg_match('/^[a-f0-9]{32,64}(:timer)?$/', (string) $key)))->toBeEmpty();
    });
});

describe('where the credential may and may not be', function () {
    it('keeps the token-bearing URL out of the session store: not as the previous URL, not in a flash', function () {
        [$invitation, $token] = regInvOpen();

        // CONTROL: an ordinary page DOES leave itself as the session's previous URL, so the absence below is the middleware's doing.
        $this->get(route('login'))->assertOk();
        expect(session('_previous.url'))->toBe(route('login'));

        $this->get(route('invitation.show', $token))->assertOk();
        $this->post(route('invitation.register', $token), ['name' => 'x', 'password' => 'weak', 'password_confirmation' => 'weak']);
        $this->get(route('invitation.show', $token))->assertOk();

        expect(credSessionDump())->not->toContain($token)->not->toContain($invitation->token_hash)
            ->and(session('_previous.url'))->not->toContain($token);

        Socialite::fake('google', SocialiteUser::fake(['id' => 'x', 'email' => 'x@example.test']));
        $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]));
        expect(session('_previous.url'))->not->toContain($token);
    });

    it('keeps the token and the hash out of the activity log and the application log through issue, view, accept and refusals', function () {
        $operator = regInvIssuer();
        $organization = Organization::factory()->create(['owner_id' => $operator->id]);
        Notification::fake();

        $invitation = app(OrganizationInvitations::class)->issue($organization, 'invited@example.test', $operator);
        $token = null;
        Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($notification) use (&$token) {
            $token = (new ReflectionProperty($notification, 'token'))->getValue($notification);

            return true;
        });
        $hash = $invitation->token_hash;

        $this->get(route('invitation.show', $token))->assertOk();
        $this->post(route('invitation.register', $token), ['name' => 'x', 'password' => 'weak', 'password_confirmation' => 'weak']);
        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('dashboard'));
        auth()->logout();
        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('invitation.show', $token));
        $this->get(route('invitation.show', $token))->assertOk();

        $activity = DB::table('activity_log')->get()->map(fn ($row) => json_encode($row))->implode("\n");

        foreach ([$token, $hash] as $secret) {
            expect($activity)->not->toContain($secret)
                ->and(implode("\n", $this->logged))->not->toContain($secret)
                ->and(credSessionDump())->not->toContain($secret);
        }

        // The audit trail is real (the assertions above are not vacuous) and the plaintext is not in any column of the invitation table.
        expect(DB::table('activity_log')->count())->toBeGreaterThan(0);
        foreach ((array) DB::table('organization_invitations')->first() as $column => $value) {
            expect((string) $value)->not->toBe($token);
        }
    });

    it('CONTROL: the persisted session table really records a navigation, so the absences asserted below are not vacuous', function () {
        DB::table('sessions')->delete();

        $this->get(route('login'))->assertOk();

        $rows = credPersistedSessions();
        expect($rows)->toHaveCount(1)->and($rows[0])->toContain('_token')->toContain('"route":"login"');
    });

    it('keeps the token out of the PERSISTED session of a request the throttle answers with 429', function () {
        [$invitation, $token] = regInvOpen();

        for ($i = 0; $i < 60; $i++) {
            $this->get(route('invitation.show', $token))->assertOk();
        }

        // Start from an empty table so the only row afterwards is the one the throttled request itself wrote. (The database handler remembers
        // that its row exists and would UPDATE a row that is no longer there, so that memory is cleared too.)
        DB::table('sessions')->delete();
        session()->getHandler()->setExists(false);
        credAssertProtected($this->get(route('invitation.show', $token))->assertStatus(429));

        $rows = credPersistedSessions();
        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toContain('_token')->not->toContain($token)->not->toContain($invitation->token_hash);
    });

    it('issues no redirect with a URL fragment on these routes (Inertia handles a PREFETCH\'s fragment redirect differently, and these routes are marked prefetches)', function () {
        // Inertia's server middleware answers an Inertia request's redirect-with-fragment with a 409 so the client does a full visit that keeps the
        // fragment, and skips that for a prefetch. If one of these routes ever redirected to a URL with a `#`, that behaviour would silently differ.
        [, $token] = regInvOpen();
        Socialite::fake('google', SocialiteUser::fake(['id' => 'x', 'email' => 'x@example.test']));
        // The asset version is resolved by the middleware on a request, so one ordinary request comes first; without a matching version Inertia
        // answers a GET with a 409 "reload" instead of reaching the route.
        $this->get(route('login'))->assertOk();
        $inertia = ['X-Inertia' => 'true', 'X-Inertia-Version' => (string) Inertia\Inertia::getVersion(), 'X-Requested-With' => 'XMLHttpRequest'];

        $locations = [
            // validation failure, a dead link's POST, the provider redirect and a signed-in user bounced by `guest`
            $this->withHeaders($inertia)->post(route('invitation.register', $token), ['name' => 'x', 'password' => 'weak', 'password_confirmation' => 'weak']),
            $this->withHeaders($inertia)->post(route('invitation.register', 'not-a-token'), regInvForm()),
            $this->withHeaders($inertia)->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token])),
            $this->withHeaders($inertia)->get(route('sso.redirect', ['provider' => 'google', 'invitation' => 'dead'])),
            $this->actingAs(User::factory()->create())->withHeaders($inertia)->get(route('invitation.show', $token)),
        ];

        foreach ($locations as $i => $response) {
            expect($response->isRedirect())->toBeTrue("case {$i}: status {$response->getStatusCode()}")
                ->and((string) $response->headers->get('Location'))->not->toContain('#')
                ->and($response->getStatusCode())->not->toBe(409);
        }

        auth()->logout();
        $accepted = $this->withHeaders($inertia)->post(route('invitation.register', $token), regInvForm());
        expect((string) $accepted->headers->get('Location'))->toBe(route('dashboard'));
    });

    it('keeps it out of the session even when a signed-in user opens the link and `guest` redirects them away', function () {
        [, $token] = regInvOpen();

        $this->actingAs(User::factory()->create())->get(route('invitation.show', $token))->assertRedirect(route('dashboard'));

        expect(credSessionDump())->not->toContain($token);
    });

    it('keeps it out of the session when the customer pilot gate refuses the request before the controller (404), with the headers still set', function () {
        pilotMode('pilot');
        [, $token] = regInvOpen();
        Socialite::fake('google', SocialiteUser::fake(['id' => 'x', 'email' => 'x@example.test']));

        // A signed-in customer asking `sso.redirect` for an invitation: the gate answers 404 (the route is not on the allow-list).
        $response = $this->actingAs(pilotCustomer())->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]))->assertNotFound();

        credAssertProtected($response);
        expect(credSessionDump())->not->toContain($token)->and(session('_previous.url'))->toBeNull();
    });

    it('does not echo the token or the hash into an unrelated response: the login page, the dashboard, the invalid page', function () {
        [$invitation, $token] = regInvOpen();

        foreach ([route('login'), route('invitation.show', 'not-a-token')] as $url) {
            $content = $this->get($url)->getContent();
            expect($content)->not->toContain($token)->not->toContain($invitation->token_hash);
        }

        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('dashboard'));
        $dashboard = $this->get(route('dashboard'))->assertOk()->getContent();
        expect($dashboard)->not->toContain($token)->not->toContain($invitation->token_hash);
    });
});

describe('a database failure while a credential is in play', function () {
    beforeEach(function () {
        // PHP's default (and a development image's) is to capture frame arguments in an exception trace. Pinning it here makes the "no
        // arguments" assertions below meaningful whatever the machine's php.ini says; the "premise" assertions prove the arguments ARE there
        // to be leaked.
        $this->ignoreArgs = ini_set('zend.exception_ignore_args', '0');
    });

    afterEach(function () {
        ini_set('zend.exception_ignore_args', $this->ignoreArgs);
    });

    /**
     * A real-shaped driver failure: the framework puts the SQL and its bindings into the message, which is what must not reach the log.
     * The failure thrown is kept in `$this->thrown` so a test can compare what it held with what was reported.
     */
    function credFailQueries(object $test, string $table, ?Closure $make = null): void
    {
        $make ??= fn (string $query, array $bindings) => new QueryException('mysql', $query, $bindings, new PDOException('Simulated outage', 2002));

        DB::beforeExecuting(function (string $query, array $bindings) use ($test, $table, $make) {
            if (str_contains($query, $table)) {
                throw $test->thrown = $make($query, $bindings);
            }
        });
    }

    /** What the database says about a duplicate on a key nobody expected: the driver text quotes the duplicated VALUE. */
    function credDuplicateKey(string $value): Closure
    {
        return function (string $query, array $bindings) use ($value) {
            $driver = new PDOException("SQLSTATE[23000]: Integrity constraint violation: 1062 Duplicate entry '{$value}' for key 'organization_invitations_token_hash_unique'");
            $driver->errorInfo = ['23000', 1062, "Duplicate entry '{$value}' for key 'organization_invitations_token_hash_unique'"];

            return new UniqueConstraintViolationException('mysql', $query, $bindings, $driver);
        };
    }

    /** Send the request that reaches the invitation lookup by the named path. */
    function credFailureRequest(object $test, string $path, string $token, string $hash)
    {
        return match ($path) {
            'show' => $test->get(route('invitation.show', $token)),
            'register' => $test->post(route('invitation.register', $token), regInvForm()),
            'sso initiation' => $test->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token])),
            'sso callback' => $test->withSession(['oauth_context' => ['intent' => 'invitation', 'provider' => 'google', 'initiatingUserId' => null, 'invitationHash' => $hash, 'startedAt' => now()->toIso8601String()]])
                ->get(route('sso.callback', 'google')),
        };
    }

    it('proves its own premise: an unsuppressed QueryException carries the hash in its message', function () {
        $hash = OrganizationInvitations::hashToken('anything');

        expect((new QueryException('mysql', 'select * from organization_invitations where token_hash = ?', [$hash], new PDOException('x')))->getMessage())->toContain($hash);
    });

    it('is reported without SQL or bindings on every path that looks the invitation up, and answers a plain 500', function (string $path) {
        $token = str_repeat('b', 64);
        $hash = OrganizationInvitations::hashToken($token);
        Socialite::fake('google', SocialiteUser::fake(['id' => 'x', 'email' => 'x@example.test']));
        $this->withoutMiddleware(ThrottleRequests::class);
        credFailQueries($this, 'organization_invitations');

        credFailureRequest($this, $path, $token, $hash)->assertStatus(500);

        $log = implode("\n", $this->logged);
        expect($log)->toContain('query details were suppressed')
            ->and($log)->not->toContain($hash)->not->toContain($token)->not->toContain('organization_invitations');
    })->with(['show', 'register', 'sso initiation', 'sso callback']);

    it('keeps an unexpected duplicate key DIAGNOSABLE (SQLSTATE, driver code, key name, connection, class, call sites) without the credential, the e-mail address, the SQL or any argument', function (string $path) {
        $token = str_repeat('b', 64);
        $hash = OrganizationInvitations::hashToken($token);
        $email = 'private.recipient@example.test';
        Socialite::fake('google', SocialiteUser::fake(['id' => 'x', 'email' => 'x@example.test']));
        $this->withoutMiddleware(ThrottleRequests::class);
        credFailQueries($this, 'organization_invitations', credDuplicateKey($hash.' '.$email));

        credFailureRequest($this, $path, $token, $hash)->assertStatus(500);

        // PREMISE: what was thrown holds everything that must be withheld (the value in the driver text, the SQL, the bindings, the arguments).
        $original = $this->thrown;
        expect($original->getMessage())->toContain($email)->toContain('SQL:')
            ->and($original->errorInfo[2])->toContain($hash);
        if (in_array($path, ['show', 'register'], true)) {
            // (getTraceAsString() shortens an argument to 15 characters, so the arguments themselves are read.)
            expect(collect($original->getTrace())->contains(fn (array $frame) => in_array($token, $frame['args'] ?? [], true)))->toBeTrue();
        }

        $reports = array_values(array_filter($this->events, fn ($event) => isset($event->context['invitation_database_failure'])));
        expect($reports)->toHaveCount(1);
        $event = $reports[0];
        $reported = $event->context['exception'];

        // A fixed, credential-free message; no chained original.
        expect($event->message)->toBe(InvitationDatabaseFailure::MESSAGE)
            ->and($reported)->toBeInstanceOf(InvitationDatabaseFailure::class)
            ->and($reported->getMessage())->toBe(InvitationDatabaseFailure::MESSAGE)
            ->and($reported->getPrevious())->toBeNull();

        // The diagnostics an operator needs.
        $diagnostic = $event->context['invitation_database_failure'];
        expect($diagnostic)->toMatchArray([
            'sqlstate' => '23000',
            'driver_code' => 1062,
            'violated_key' => 'organization_invitations_token_hash_unique',
            'connection' => 'mysql',
            'original_class' => UniqueConstraintViolationException::class,
        ]);
        expect($diagnostic['original_frames'])->not->toBeEmpty()->and($diagnostic['original_frames'][0])->toEndWith('(thrown)');
        $controller = $path === 'show' ? 'InvitationController->show' : ($path === 'register' ? 'InvitationController->register' : 'SocialiteController->');
        expect(implode("\n", $diagnostic['original_frames']))->toContain($controller)->toContain('tests/Feature/Auth/InvitationCredentialProtectionTest.php');

        // Everything a log formatter or an error tracker could read from the report: the context, the exception's message and its own trace.
        $everything = implode("\n", [
            $event->message,
            json_encode($event->context['invitation_database_failure']),
            json_encode(array_diff_key($event->context, ['exception' => 1])),
            $reported->getMessage(),
            $reported->getTraceAsString(),
            json_encode($reported->getTrace()),
            implode("\n", $this->logged),
        ]);
        expect($everything)->not->toContain($hash)->not->toContain($token)->not->toContain($email)
            ->not->toContain('SQL:')->not->toContain('Duplicate entry')->not->toContain('Integrity constraint')->not->toContain('Simulated');
        // Frames carry no arguments, in the diagnostics or in the report's own trace.
        foreach ($diagnostic['original_frames'] as $frame) {
            expect($frame)->toBeString()->not->toContain("('")->not->toContain('Object(')->not->toContain('Array(');
        }
        foreach ($reported->getTrace() as $frame) {
            // (PHP records the file path of an include/require as that pseudo-function's argument whatever the setting; it is not a credential.)
            if (in_array($frame['function'] ?? '', ['include', 'include_once', 'require', 'require_once'], true)) {
                continue;
            }

            expect($frame)->not->toHaveKey('args');
        }
    })->with(['show', 'register', 'sso initiation', 'sso callback']);

    it('reports a failure with no readable key as a null key, not a guess', function () {
        $token = str_repeat('b', 64);
        $this->withoutMiddleware(ThrottleRequests::class);
        // A duplicate whose driver text names no key: DuplicateKey cannot read one, so the diagnostic must not invent it.
        credFailQueries($this, 'organization_invitations', function (string $query, array $bindings) {
            $driver = new PDOException('x');
            $driver->errorInfo = ['23000', 1062, "Duplicate entry 'v' for key"];

            return new UniqueConstraintViolationException('mysql', $query, $bindings, $driver);
        });

        $this->get(route('invitation.show', $token))->assertStatus(500);

        $diagnostic = collect($this->events)->map(fn ($event) => $event->context['invitation_database_failure'] ?? null)->filter()->first();
        expect($diagnostic)->toMatchArray(['sqlstate' => '23000', 'driver_code' => 1062, 'violated_key' => null]);
    });

    it('keeps the credential out of the PERSISTED session of a request that ends in a database failure (500)', function (string $path) {
        $token = str_repeat('b', 64);
        $hash = OrganizationInvitations::hashToken($token);
        Socialite::fake('google', SocialiteUser::fake(['id' => 'x', 'email' => 'x@example.test']));
        $this->withoutMiddleware(ThrottleRequests::class);
        credFailQueries($this, 'organization_invitations');

        // Only the row this request writes is in the table afterwards.
        DB::table('sessions')->delete();

        credFailureRequest($this, $path, $token, $hash)->assertStatus(500);

        $rows = credPersistedSessions();
        expect($rows)->toHaveCount(1)
            ->and($rows[0])->toContain('_token')->not->toContain($token)->not->toContain($hash);
    })->with(['show', 'register', 'sso initiation', 'sso callback']);
});
