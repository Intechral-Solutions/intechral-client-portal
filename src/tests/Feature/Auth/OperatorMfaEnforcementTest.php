<?php

/*
 * Release 1.0: the operator second-factor boundary (App\Shared\Mfa\OperatorMfaGate, App\Http\Middleware\EnforceOperatorMfa).
 *
 * Policy under test: an account holding `portal.operator` must have a CONFIRMED second factor before it reaches anything privileged; enforced at the
 * application boundary (not at sign-in), customers keep optional MFA, SSO is the first factor only, no grace period.
 *
 * The suite's baseline turns enforcement OFF (tests/TestCase.php; honoured only under APP_ENV=testing), so every test here that is about the
 * boundary switches it on with enforceOperatorMfa().
 */

require_once __DIR__.'/../Pilot/PilotTestHelpers.php';

use App\Models\SocialAccount;
use App\Models\User;
use App\Shared\Mfa\OperatorMfaGate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Events\TwoFactorAuthenticationConfirmed;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\PermissionRegistrar;

const MFA_SECRET = 'JBSWY3DPEHPK3PXP';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    enforceOperatorMfa();
});

function enforceOperatorMfa(bool $on = true): void
{
    config(['operator_mfa.enforced' => $on]);
}

/** Give a user a stored secret and recovery codes; `confirmed` decides whether the enrolment was completed. */
function mfaEnrol(User $user, bool $confirmed = true): User
{
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt(MFA_SECRET),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one', 'recovery-two'])),
        'two_factor_confirmed_at' => $confirmed ? now() : null,
    ])->save();

    return $user->fresh();
}

function mfaOperator(string $state = 'none'): User
{
    $operator = pilotOperator(['password' => Hash::make('Password1!')]);

    return match ($state) {
        'confirmed' => mfaEnrol($operator),
        'unconfirmed' => mfaEnrol($operator, confirmed: false),
        default => $operator,
    };
}

function mfaCode(): string
{
    return app(Google2FA::class)->getCurrentOtp(MFA_SECRET);
}

/** Every request after the first loads the user from the session, as a real request does (not a cached guard user). */
function mfaNextRequest(): void
{
    Auth::forgetGuards();
}

function mfaPasswordConfirmed(): array
{
    return ['auth.password_confirmed_at' => time()];
}

describe('what confirmed means', function () {
    it('needs a stored secret AND a confirmation time', function () {
        $none = User::factory()->create();
        $secretOnly = mfaEnrol(User::factory()->create(), confirmed: false);
        $confirmedAtOnly = User::factory()->create();
        $confirmedAtOnly->forceFill(['two_factor_confirmed_at' => now()])->save();
        $both = mfaEnrol(User::factory()->create());

        expect($none->fresh()->hasConfirmedTwoFactor())->toBeFalse()
            ->and($secretOnly->hasConfirmedTwoFactor())->toBeFalse()
            ->and($confirmedAtOnly->fresh()->hasConfirmedTwoFactor())->toBeFalse()
            ->and($both->hasConfirmedTwoFactor())->toBeTrue();
    });

    it('blocks an operator with no factor or an unconfirmed one, never a confirmed one', function (string $state, bool $blocked) {
        expect(OperatorMfaGate::blocks(mfaOperator($state)))->toBe($blocked);
    })->with([['none', true], ['unconfirmed', true], ['confirmed', false]]);

    it('never blocks a customer, whatever their factor state, or a guest', function (string $state) {
        $customer = pilotCustomer();
        $customer = match ($state) {
            'confirmed' => mfaEnrol($customer),
            'unconfirmed' => mfaEnrol($customer, confirmed: false),
            default => $customer,
        };

        expect(OperatorMfaGate::blocks($customer))->toBeFalse()
            ->and(OperatorMfaGate::blocks(null))->toBeFalse();
    })->with(['none', 'unconfirmed', 'confirmed']);

    it('treats a direct portal.operator grant as operator eligibility, not only the built-in role', function () {
        $holder = pilotCustomRoleUser('platform-admin', ['portal.operator']);
        $customer = pilotCustomer();

        expect(OperatorMfaGate::blocks($holder))->toBeTrue()
            ->and(OperatorMfaGate::blocks($customer))->toBeFalse();
    });

    it('has an allow-list that names only routes that exist', function () {
        foreach (OperatorMfaGate::ALLOWED_ROUTES as $name) {
            expect(Route::has($name))->toBeTrue("allow-listed route [{$name}] does not exist");
        }
    });
});

describe('the enforcement switch cannot be turned off outside the testing environment', function () {
    it('is honoured under APP_ENV=testing only', function (string $environment, bool $expected) {
        enforceOperatorMfa(false);
        app()->detectEnvironment(fn () => $environment);

        expect(OperatorMfaGate::enforced())->toBe($expected)
            ->and(OperatorMfaGate::blocks(mfaOperator()))->toBe($expected);
    })->with([['testing', false], ['local', true], ['production', true], ['staging', true]]);

    it('ships enforced, with no environment variable behind it', function () {
        $config = file_get_contents(config_path('operator_mfa.php'));

        expect(preg_match("/'enforced'\s*=>\s*true,/", $config))->toBe(1)->and($config)->not->toContain('env(');
    });
});

/** Every privileged route group, including the ones outside the `/operator` prefix. [name, method, uri parameters, body] */
dataset('privileged routes', [
    'dashboard' => ['dashboard', 'get'],
    'operator tickets' => ['operator.tickets.index', 'get'],
    'operator ticket reports' => ['operator.tickets.reports', 'get'],
    'operator ticket export' => ['operator.tickets.export', 'get'],
    'operator time' => ['operator.time.index', 'get'],
    'operator cms' => ['operator.cms.index', 'get'],
    'admin users' => ['users.index', 'get'],
    'admin roles' => ['roles.index', 'get'],
    'crm companies' => ['crm.companies.index', 'get'],
    'organizations' => ['organizations.index', 'get'],
    'billing invoices' => ['billing.invoices.index', 'get'],
    'tickets (shared, outside /operator)' => ['tickets.index', 'get'],
    'projects (shared)' => ['projects.index', 'get'],
    'time (shared)' => ['time.index', 'get'],
    'timers json' => ['time.timers.active', 'get'],
    'tasks (shared)' => ['tasks.index', 'get'],
    'cms viewer' => ['cms.index', 'get'],
    'partner support' => ['support.index', 'get'],
    'client invoices' => ['billing.client.invoices.index', 'get'],
]);

describe('an operator without a confirmed second factor', function () {
    it('is sent to the profile, with the reason, from every privileged route group', function (string $name, string $method, string $state) {
        $operator = mfaOperator($state);

        $this->actingAs($operator)->{$method}(route($name))
            ->assertRedirect(route('profile.show'))
            ->assertSessionHas('error', OperatorMfaGate::MESSAGE);
    })->with('privileged routes')->with(['none', 'unconfirmed']);

    it('gets a 403 with a stable code, never a redirect, from a JSON caller', function (string $name, string $method) {
        $this->actingAs(mfaOperator())->{$method.'Json'}(route($name))
            ->assertForbidden()
            ->assertExactJson(['message' => OperatorMfaGate::MESSAGE, 'code' => OperatorMfaGate::CODE]);
    })->with('privileged routes');

    it('cannot mutate anything by form post, JSON or Inertia, and nothing is written', function () {
        $victim = pilotCustomer();
        $operator = mfaOperator();
        $before = [DB::table('model_has_roles')->count(), DB::table('users')->count(), DB::table('invoices')->count(), DB::table('tickets')->count()];

        $attempts = [
            ['put', route('users.roles.update', $victim->id), ['roles' => ['operator']]],
            ['post', route('users.suspend', $victim->id), ['reason' => 'x']],
            ['post', route('billing.invoices.store'), ['client_id' => 1]],
            ['post', route('operator.tickets.bulk'), ['ids' => [1], 'action' => 'close']],
            ['delete', route('projects.destroy', 1), []],
            ['post', route('tickets.store'), ['subject' => 'x', 'description' => 'y']],
            ['post', route('organizations.members.store', 1), ['email' => $victim->email]],
            ['put', route('roles.update', 1), ['name' => 'x']],
            ['post', route('operator.cms.publish', 1), []],
        ];

        foreach ($attempts as [$method, $url, $body]) {
            // A browser form post (redirect), a JSON caller (403) and an Inertia visit (redirect; 303 for PUT/PATCH/DELETE).
            $this->actingAs($operator)->{$method}($url, $body)->assertRedirect(route('profile.show'));
            $this->actingAs($operator)->{$method.'Json'}($url, $body)->assertForbidden();

            $inertia = $this->actingAs($operator)->withHeaders(['X-Inertia' => 'true', 'X-Requested-With' => 'XMLHttpRequest'])->{$method}($url, $body);
            $inertia->assertRedirect(route('profile.show'));
            expect($inertia->getStatusCode())->toBe(in_array($method, ['put', 'patch', 'delete'], true) ? 303 : 302);
        }

        expect([DB::table('model_has_roles')->count(), DB::table('users')->count(), DB::table('invoices')->count(), DB::table('tickets')->count()])->toBe($before);
        expect($victim->fresh()->hasRole('operator'))->toBeFalse()->and($victim->fresh()->isSuspended())->toBeFalse();
    });

    it('is refused before route-model binding, so an unknown id and a real one answer identically', function () {
        $operator = mfaOperator();

        $this->actingAs($operator)->getJson(route('users.show', 99999))->assertForbidden()->assertJsonPath('code', OperatorMfaGate::CODE);
        $this->actingAs($operator)->getJson(route('users.show', $operator->id))->assertForbidden()->assertJsonPath('code', OperatorMfaGate::CODE);
    });

    it('discloses nothing privileged in the refusal', function () {
        $operator = mfaOperator();

        $body = $this->actingAs($operator)->getJson(route('operator.tickets.index'))->getContent();

        expect(json_decode($body, true))->toBe(['message' => OperatorMfaGate::MESSAGE, 'code' => OperatorMfaGate::CODE]);
    });

    it('is refused on a route that has no name at all (deny by default)', function () {
        Route::middleware(['web', 'auth'])->get('/unnamed-privileged-probe', fn () => 'privileged');

        $this->actingAs(mfaOperator())->getJson('/unnamed-privileged-probe')->assertForbidden();
        $this->actingAs(mfaOperator('confirmed'))->get('/unnamed-privileged-probe')->assertOk();
    });

    it('is refused on a NEW named route nobody listed, until it is allow-listed', function () {
        Route::middleware(['web', 'auth'])->get('/later-added-probe', fn () => 'privileged')->name('later.added');

        $this->actingAs(mfaOperator())->getJson('/later-added-probe')->assertForbidden();
        $this->actingAs(mfaOperator('confirmed'))->get('/later-added-probe')->assertOk();
    });

    it('covers every named route: each one is either allow-listed, public by design or refused', function () {
        // Routes that a signed-in operator reaches but that are not privileged: guest-only authentication and the credential-bearing public routes
        // (each has its own `guest` middleware or is unauthenticated), Stripe's signed webhook, the framework health check, and sign-out.
        $publicByDesign = [
            'login', 'login.store', 'password.request', 'password.email', 'password.reset', 'password.update',
            'two-factor.login', 'two-factor.login.store', 'invitation.show', 'invitation.register',
            'sso.redirect', 'sso.callback', 'webhooks.stripe',
        ];

        $operator = mfaOperator();
        $unexpected = [];

        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();

            if ($name === null || in_array($name, $publicByDesign, true) || OperatorMfaGate::allowsRoute($name) || str_starts_with($name, 'generated::') || str_starts_with($name, 'storage.')) {
                continue;
            }

            $method = collect($route->methods())->first(fn ($m) => ! in_array($m, ['HEAD', 'OPTIONS'], true));
            $uri = preg_replace('/\{[^}]+\}/', '1', $route->uri());

            $status = $this->actingAs($operator)->json($method, '/'.ltrim($uri, '/'))->getStatusCode();

            if ($status !== 403) {
                $unexpected[] = "{$method} {$route->uri()} [{$name}] answered {$status}";
            }
        }

        expect($unexpected)->toBe([]);
    });

    it('is NOT refused on the exact routes it needs to enrol', function () {
        $operator = mfaOperator();

        $this->actingAs($operator)->get(route('profile.show'))->assertOk();
        $this->actingAs($operator)->get(route('profile.password.confirm'))->assertRedirect(route('password.confirm'));
        $this->actingAs($operator)->get(route('password.confirm'))->assertOk();
        $this->actingAs($operator)->getJson(route('password.confirmation'))->assertOk()->assertJson(['confirmed' => false]);
    });

    it('is told on the profile that setup is required, and the page keeps working', function () {
        $this->actingAs(mfaOperator())->get(route('profile.show'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('profile/show')->where('twoFactor.required', true)->where('twoFactor.confirmed', false));

        $this->actingAs(mfaOperator('unconfirmed'))->get(route('profile.show'))
            ->assertInertia(fn ($page) => $page->where('twoFactor.required', true)->where('twoFactor.enabled', true)->where('twoFactor.confirmed', false));

        $this->actingAs(mfaOperator('confirmed'))->get(route('profile.show'))
            ->assertInertia(fn ($page) => $page->where('twoFactor.required', false));

        $this->actingAs(pilotCustomer())->get(route('profile.show'))
            ->assertInertia(fn ($page) => $page->where('twoFactor.required', false));
    });
});

describe('no redirect loops', function () {
    it('lands on the profile from every entry point, and the profile does not redirect on', function (string $entry) {
        $this->actingAs(mfaOperator());

        $response = $this->get($entry);
        $hops = 0;

        while ($response->isRedirect() && $hops < 5) {
            $location = $response->headers->get('Location');
            $response = $this->get($location);
            $hops++;
        }

        $response->assertOk();
        expect(parse_url($this->app['request']->fullUrl(), PHP_URL_PATH))->toBe('/profile');
        expect($hops)->toBeLessThan(5);
    })->with(['/', '/dashboard', '/tickets', '/operator/tickets', '/login', '/profile']);

    it('lets an operator without a factor sign out', function () {
        $this->actingAs(mfaOperator())->post(route('logout'))->assertRedirect();
        $this->assertGuest();
    });
});

describe('enrolment is complete only when the second factor is confirmed', function () {
    it('keeps the account blocked after enabling, and after a wrong or empty confirmation code, until a valid code', function () {
        $operator = mfaOperator();
        $this->actingAs($operator)->withSession(mfaPasswordConfirmed());

        $this->post(route('two-factor.enable'))->assertRedirect();
        expect($operator->fresh()->two_factor_secret)->not->toBeNull()->and($operator->fresh()->two_factor_confirmed_at)->toBeNull();
        mfaNextRequest();
        $this->actingAs($operator->fresh())->get(route('dashboard'))->assertRedirect(route('profile.show'));

        $this->actingAs($operator->fresh())->post(route('two-factor.confirm'), ['code' => '000000'])->assertSessionHasErrors(['code'], errorBag: 'confirmTwoFactorAuthentication');
        $this->actingAs($operator->fresh())->post(route('two-factor.confirm'), ['code' => ''])->assertSessionHasErrors(['code'], errorBag: 'confirmTwoFactorAuthentication');
        expect($operator->fresh()->two_factor_confirmed_at)->toBeNull();
        $this->actingAs($operator->fresh())->get(route('dashboard'))->assertRedirect(route('profile.show'));
        $this->actingAs($operator->fresh())->getJson(route('operator.tickets.index'))->assertForbidden();
    });

    it('opens privileged access on the very next request once a valid code is confirmed', function () {
        $operator = mfaOperator();
        $this->actingAs($operator)->withSession(mfaPasswordConfirmed());
        $this->post(route('two-factor.enable'));
        $secret = Fortify::currentEncrypter()->decrypt($operator->fresh()->two_factor_secret);

        $this->actingAs($operator->fresh())->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])
            ->assertSessionHasNoErrors();

        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue();
        $this->actingAs($operator->fresh())->get(route('dashboard'))->assertOk();
        $this->actingAs($operator->fresh())->get(route('operator.tickets.index'))->assertOk();
    });

    it('reaches the setup endpoints only after the password step, which is itself reachable', function () {
        $operator = mfaOperator();

        $this->actingAs($operator)->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));
        expect($operator->fresh()->two_factor_secret)->toBeNull();
        $this->actingAs($operator)->get(route('password.confirm'))->assertOk();
    });

    it('serves the setup details to an enrolling operator only after the password step', function () {
        $operator = mfaOperator('unconfirmed');

        $this->actingAs($operator)->withSession(mfaPasswordConfirmed())->getJson(route('two-factor.qr-code'))->assertOk()->assertJsonStructure(['svg']);
        $this->actingAs($operator)->withSession(mfaPasswordConfirmed())->getJson(route('two-factor.secret-key'))->assertOk();
        $this->flushSession();
        $this->actingAs($operator)->getJson(route('two-factor.secret-key'))->assertStatus(423);
    });
});

describe('the operator\'s state is read on every request', function () {
    it('blocks an operator at once when their second factor is disabled, whatever the session remembers', function () {
        $operator = mfaOperator('confirmed');
        $this->actingAs($operator)->get(route('dashboard'))->assertOk();

        $this->actingAs($operator)->withSession(mfaPasswordConfirmed())->delete(route('two-factor.disable'));
        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeFalse();

        mfaNextRequest();
        $this->actingAs($operator->fresh())->get(route('dashboard'))->assertRedirect(route('profile.show'));
    });

    it('applies a reset made elsewhere to a signed-in session on its next request', function () {
        $operator = mfaOperator('confirmed');
        $this->actingAs($operator)->get(route('dashboard'))->assertOk();

        DB::table('users')->where('id', $operator->id)->update(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null]);

        mfaNextRequest();
        $this->actingAs(User::find($operator->id))->getJson(route('operator.tickets.index'))->assertForbidden();
    });

    it('treats a customer who is granted the operator role as blocked from that moment, and an operator who loses it as a customer', function () {
        $user = pilotCustomer();
        $this->actingAs($user)->get(route('dashboard'))->assertOk();

        $user->assignRole('operator');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::find($user->id))->get(route('dashboard'))->assertRedirect(route('profile.show'));

        $user->removeRole('operator');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actingAs(User::find($user->id))->get(route('dashboard'))->assertOk();
    });
});

describe('customers keep optional MFA', function () {
    it('are never refused by this boundary, with no factor or an unfinished one', function (string $state) {
        $customer = pilotCustomer();
        if ($state === 'unconfirmed') {
            mfaEnrol($customer, confirmed: false);
        }

        $this->actingAs($customer->fresh())->get(route('dashboard'))->assertOk();
        $this->actingAs($customer->fresh())->get(route('tickets.index'))->assertOk();
        $this->actingAs($customer->fresh())->get(route('profile.show'))->assertOk();
    })->with(['none', 'unconfirmed']);

    it('may enrol, confirm and disable their own second factor as before', function () {
        $customer = pilotCustomer();
        $this->actingAs($customer)->withSession(mfaPasswordConfirmed());

        $this->post(route('two-factor.enable'))->assertRedirect();
        $secret = Fortify::currentEncrypter()->decrypt($customer->fresh()->two_factor_secret);
        $this->actingAs($customer->fresh())->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertSessionHasNoErrors();
        $this->actingAs($customer->fresh())->delete(route('two-factor.disable'));

        expect($customer->fresh()->hasConfirmedTwoFactor())->toBeFalse();
        $this->actingAs($customer->fresh())->get(route('dashboard'))->assertOk();
    });
});

describe('password sign-in', function () {
    it('signs an operator without a factor in (first factor only) and then limits them to the profile', function () {
        $operator = mfaOperator();

        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect();
        $this->assertAuthenticatedAs($operator);

        mfaNextRequest();
        $this->get(route('dashboard'))->assertRedirect(route('profile.show'));
        $this->get(route('profile.show'))->assertOk();
    });

    it('treats an unfinished enrolment as no factor: no challenge, and still limited', function () {
        $operator = mfaOperator('unconfirmed');

        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect()->assertSessionMissing('login.id');
        $this->assertAuthenticatedAs($operator);
        mfaNextRequest();
        $this->getJson(route('operator.tickets.index'))->assertForbidden();
    });

    it('challenges an operator with a confirmed factor, and opens privileged access only after the code', function () {
        $operator = mfaOperator('confirmed');

        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        $this->get(route('dashboard'))->assertRedirect(route('login'));

        $this->post(route('two-factor.login.store'), ['code' => mfaCode()])->assertRedirect();
        $this->assertAuthenticatedAs($operator);
        mfaNextRequest();
        $this->get(route('dashboard'))->assertOk();
    });

    it('does not let a wrong code or a recovery code that is not theirs in', function () {
        $operator = mfaOperator('confirmed');
        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!']);

        $this->post(route('two-factor.login.store'), ['code' => '000000']);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'someone-elses-code']);

        $this->assertGuest();
    });
});

describe('single sign-on', function () {
    function mfaSsoLogin(User $user): void
    {
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', SocialiteUser::fake(['id' => 'subject-1', 'name' => $user->name, 'email' => $user->email, 'email_verified' => true]));
    }

    it('provider sign-in alone never substitutes for the portal second factor', function () {
        $operator = mfaOperator();
        mfaSsoLogin($operator);

        $this->withSession(['oauth_context' => ['intent' => 'login', 'provider' => 'google', 'initiatingUserId' => null, 'invitationHash' => null, 'startedAt' => now()->toIso8601String()]])
            ->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($operator);

        mfaNextRequest();
        $this->get(route('dashboard'))->assertRedirect(route('profile.show'));
        $this->getJson(route('operator.tickets.index'))->assertForbidden();
    });

    it('still asks a confirmed operator for the code after the provider', function () {
        $operator = mfaOperator('confirmed');
        mfaSsoLogin($operator);

        $this->withSession(['oauth_context' => ['intent' => 'login', 'provider' => 'google', 'initiatingUserId' => null, 'invitationHash' => null, 'startedAt' => now()->toIso8601String()]])
            ->get(route('sso.callback', 'google'))->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['code' => mfaCode()])->assertRedirect();
        $this->assertAuthenticatedAs($operator);
    });

    it('cannot link another provider before the second factor is confirmed', function () {
        $operator = mfaOperator();

        $this->actingAs($operator)->get(route('sso.redirect', 'google'))->assertRedirect(route('profile.show'));
        $this->actingAs($operator)->getJson(route('sso.redirect', 'google'))->assertForbidden();
    });
});

describe('a sign-in parked at the challenge when the second factor disappears', function () {
    it('cannot be completed with a code, a recovery code or a guess, and does not error', function (string $attempt) {
        $operator = mfaOperator('confirmed');
        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect(route('two-factor.login'));

        // The account's factor is removed (by user:reset-two-factor, or by the account itself) while the challenge is pending.
        $operator->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        $body = $attempt === 'recovery' ? ['recovery_code' => 'recovery-one'] : ['code' => $attempt === 'valid-for-old-secret' ? mfaCode() : '123456'];
        $this->post(route('two-factor.login.store'), $body)->assertRedirect(route('two-factor.login'))->assertSessionMissing('login.id');

        $this->assertGuest();
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    })->with(['recovery', 'valid-for-old-secret', 'guess']);

    it('cannot be completed against a NEW unconfirmed secret either', function () {
        $operator = mfaOperator('confirmed');
        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!']);
        mfaEnrol($operator, confirmed: false);

        $this->post(route('two-factor.login.store'), ['code' => mfaCode()])->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
    });
});

describe('sessions opened before enrolment do not become privileged when the account enrols (review B1)', function () {
    function mfaSessionRow(User $user, string $id): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $user->id, 'ip_address' => '203.0.113.9', 'user_agent' => 'Other browser', 'payload' => 'x', 'last_activity' => time()]);
    }

    function mfaConfirmWithCode(User $user): void
    {
        $secret = Fortify::currentEncrypter()->decrypt($user->fresh()->two_factor_secret);
        test()->actingAs($user->fresh())->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertSessionHasNoErrors();
    }

    it('signs the operator\'s other sessions and remembered browsers out at confirmation, and keeps the confirming one', function () {
        $operator = mfaOperator();
        $operator->forceFill(['remember_token' => 'attacker-recaller'])->save();
        mfaSessionRow($operator, 'attacker-session');
        mfaSessionRow($operator, 'forgotten-session');
        $bystander = mfaOperator('confirmed');
        mfaSessionRow($bystander, 'bystander-session');

        $this->actingAs($operator)->withSession(mfaPasswordConfirmed());
        $this->post(route('two-factor.enable'));
        mfaConfirmWithCode($operator);

        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $operator->id)->whereIn('id', ['attacker-session', 'forgotten-session'])->count())->toBe(0)
            ->and($operator->fresh()->remember_token)->not->toBe('attacker-recaller')
            ->and(DB::table('sessions')->where('id', 'bystander-session')->count())->toBe(1);

        $this->actingAs($operator->fresh())->get(route('dashboard'))->assertOk();
    });

    it('keeps exactly the session that confirmed (the listener, driven with a known session id)', function () {
        $operator = mfaOperator('confirmed');
        mfaSessionRow($operator, 'attacker-session');
        mfaSessionRow($operator, 'confirming-session');
        mfaSessionRow(pilotCustomer(), 'someone-elses-session');

        // The test client mints a fresh session id per request, so the confirming session is bound to the request by hand.
        $store = app('session.store');
        $store->setId(str_pad('confirming-session', 40, 'x'));
        DB::table('sessions')->where('id', 'confirming-session')->update(['id' => $store->getId()]);
        request()->setLaravelSession($store);

        event(new TwoFactorAuthenticationConfirmed($operator));

        expect(DB::table('sessions')->where('id', $store->getId())->count())->toBe(1)
            ->and(DB::table('sessions')->where('id', 'attacker-session')->count())->toBe(0)
            ->and(DB::table('sessions')->where('id', 'someone-elses-session')->count())->toBe(1);
    });

    it('does the same for a customer, who may be granted the operator role later', function () {
        $customer = pilotCustomer(['password' => Hash::make('Password1!')]);
        $customer->forceFill(['remember_token' => 'thief-recaller'])->save();
        mfaSessionRow($customer, 'customer-other-browser');

        $this->actingAs($customer)->withSession(mfaPasswordConfirmed());
        $this->post(route('two-factor.enable'));
        mfaConfirmWithCode($customer);

        expect(DB::table('sessions')->where('id', 'customer-other-browser')->count())->toBe(0)
            ->and($customer->fresh()->remember_token)->not->toBe('thief-recaller');

        // ...and the later promotion finds no pre-enrolment session to inherit.
        $customer->assignRole('operator');
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        expect(OperatorMfaGate::blocks($customer->fresh()))->toBeFalse();
    });

    it('applies to an operator who holds portal.operator only through a custom role', function () {
        $holder = pilotCustomRoleUser('platform-admin', ['portal.operator'], ['password' => Hash::make('Password1!')]);
        mfaSessionRow($holder, 'holder-other-session');

        $this->actingAs($holder)->withSession(mfaPasswordConfirmed());
        $this->post(route('two-factor.enable'));
        mfaConfirmWithCode($holder);

        expect(DB::table('sessions')->where('id', 'holder-other-session')->count())->toBe(0);
    });

    it('does not end anything when the confirmation code is wrong', function () {
        $operator = mfaOperator();
        mfaSessionRow($operator, 'attacker-session');

        $this->actingAs($operator)->withSession(mfaPasswordConfirmed());
        $this->post(route('two-factor.enable'));
        $this->actingAs($operator->fresh())->post(route('two-factor.confirm'), ['code' => '000000']);

        expect(DB::table('sessions')->where('id', 'attacker-session')->count())->toBe(1);
    });
});

describe('the profile-limited session cannot change the email (review S1)', function () {
    it('refuses a profile-information update, so a stolen session cannot redirect password reset to the thief', function () {
        $operator = mfaOperator();

        $this->actingAs($operator)->put(route('user-profile-information.update'), ['name' => $operator->name, 'email' => 'thief@example.test'])
            ->assertRedirect(route('profile.show'));
        $this->actingAs($operator)->putJson(route('user-profile-information.update'), ['name' => $operator->name, 'email' => 'thief@example.test'])
            ->assertForbidden();

        expect($operator->fresh()->email)->toBe($operator->email)->and(OperatorMfaGate::allowsRoute('user-profile-information.update'))->toBeFalse();
    });
});
