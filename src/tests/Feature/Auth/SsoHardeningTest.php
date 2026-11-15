<?php

/*
 * Release 1.0 pre-RC1 hardening of SSO (docs/product/release-1.0-pre-rc1-security-hardening.md, section 2). In terms of what could go wrong:
 *
 *  - a hijacked or unattended session must not be able to attach a provider silently (fresh authentication before linking);
 *  - a database or backup leak must not hand out provider credentials (no token is stored, and old ones are scrubbed);
 *  - a provider sign-in must not outlive the session unasked (no remember-me);
 *  - starting and completing a provider round trip is limited, per account when signed in.
 * The provider is faked (Socialite::fake); the contract around it (verified email, subject identity, MFA, session regeneration) is SsoCompletionTest.
 */

require_once __DIR__.'/InvitationTestHelpers.php';
require_once __DIR__.'/../Pilot/PilotTestHelpers.php';

use App\Auth\Sso\SsoFreshness;
use App\Auth\Sso\SsoThrottle;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Auth\Events\Login;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    pilotMode('pilot');
});

function shgCtx(string $intent, array $target = []): array
{
    return ['intent' => $intent, 'provider' => 'google', 'initiatingUserId' => null, 'invitationHash' => null, 'startedAt' => now()->toIso8601String(), ...$target];
}

/** A provider account that carries credentials a careless implementation would store. */
function shgPerson(array $attributes = []): SocialiteUser
{
    $person = SocialiteUser::fake(['email_verified' => true, 'id' => 'subject-1', 'name' => 'Provider Person', 'email' => 'invited@example.test', ...$attributes]);
    $person->token = 'ACCESS-TOKEN-MUST-NOT-BE-STORED';
    $person->refreshToken = 'REFRESH-TOKEN-MUST-NOT-BE-STORED';
    $person->expiresIn = 3600;

    return $person;
}

function shgTotp(User $user): User
{
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    return $user;
}

describe('fresh authentication before linking a provider', function () {
    it('sends a password account to confirm its password, starting nothing', function () {
        $customer = pilotCustomer();
        Socialite::fake('google', shgPerson());

        $this->actingAs($customer)->get(route('sso.redirect', 'google'))
            ->assertRedirect(route('password.confirm'))
            ->assertSessionHas('url.intended', route('profile.show'))
            ->assertSessionHas('status')
            ->assertSessionMissing('oauth_context');
    });

    it('completes with the real confirmation page: refused, confirm the password, return to the profile, connect', function () {
        $customer = pilotCustomer(['password' => bcrypt('Password1!')]);
        Socialite::fake('google', shgPerson());

        $this->actingAs($customer)->get(route('sso.redirect', 'google'))->assertRedirect(route('password.confirm'));
        $this->post(route('password.confirm.store'), ['password' => 'Wrong-Password1!'])->assertSessionHasErrors('password');
        $this->get(route('sso.redirect', 'google'))->assertRedirect(route('password.confirm'));

        $this->post(route('password.confirm.store'), ['password' => 'Password1!'])->assertRedirect(route('profile.show'));
        $this->get(route('sso.redirect', 'google'))->assertRedirect('https://socialite.fake/google/authorize');
    });

    it('tells an account without a password to sign in again with its provider, starting nothing', function () {
        $customer = pilotCustomer(['password' => null]);
        Socialite::fake('google', shgPerson());

        $this->actingAs($customer)->get(route('sso.redirect', 'google'))
            ->assertRedirect(route('profile.show'))
            ->assertSessionHasErrors('provider')
            ->assertSessionMissing('oauth_context');
    });

    it('starts the link only within five minutes of the confirmation', function (int $secondsAgo, bool $allowed) {
        $customer = pilotCustomer();
        Socialite::fake('google', shgPerson());

        $response = $this->actingAs($customer)->withSession(['auth.password_confirmed_at' => now()->getTimestamp() - $secondsAgo])->get(route('sso.redirect', 'google'));

        $allowed ? $response->assertRedirect('https://socialite.fake/google/authorize') : $response->assertRedirect(route('password.confirm'));
    })->with([
        'just now' => [0, true],
        'four minutes ago' => [240, true],
        'five minutes ago' => [SsoFreshness::WINDOW_SECONDS, true],
        'six minutes ago' => [SsoFreshness::WINDOW_SECONDS + 60, false],
        'long ago (the framework default window is three hours)' => [7200, false],
    ]);

    it('lets an account without a password link after a provider sign-in, and not after the window', function () {
        $user = pilotCustomer(['password' => null]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', shgPerson());
        Socialite::fake('microsoft', shgPerson(['id' => 'tid.oid']));

        $this->withSession(['oauth_context' => shgCtx('login')])->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->get(route('sso.redirect', 'microsoft'))->assertRedirect('https://socialite.fake/microsoft/authorize');

        $this->travel(SsoFreshness::WINDOW_SECONDS + 1)->seconds();
        $this->get(route('sso.redirect', 'microsoft'))->assertRedirect(route('profile.show'))->assertSessionHasErrors('provider');
    });

    it('counts the second-factor challenge: not fresh while the challenge is pending, fresh once it is passed', function () {
        $user = shgTotp(pilotCustomer(['password' => null]));
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', shgPerson());
        Socialite::fake('microsoft', shgPerson(['id' => 'tid.oid']));

        $this->withSession(['oauth_context' => shgCtx('login')])->get(route('sso.callback', 'google'))->assertRedirect(route('two-factor.login'));
        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertRedirect();
        $this->assertAuthenticatedAs($user);

        $this->get(route('sso.redirect', 'microsoft'))->assertRedirect('https://socialite.fake/microsoft/authorize');
    });

    it('turns a noted provider authentication into a proof only for the user it names, and only while recent', function (string $case, bool $proven) {
        // Real requests: the listener reads the session of the request that signs the user in. A password sign-in is the carrier here only because it
        // fires the same `Login` event the provider path does (a control for the positive case).
        $bob = User::factory()->create(['password' => bcrypt('Password1!')]);
        $alice = User::factory()->create();

        $noted = match ($case) {
            'for the same user, recent' => ['user' => $bob->id, 'at' => time()],
            'for another user' => ['user' => $alice->id, 'at' => time()],
            'for the same user, older than the window' => ['user' => $bob->id, 'at' => time() - SsoFreshness::WINDOW_SECONDS - 5],
            'malformed' => ['user' => $bob->id, 'at' => 'yesterday'],
        };

        $response = $this->withSession(['sso.authenticated' => $noted])
            ->post(route('login.store'), ['email' => $bob->email, 'password' => 'Password1!']);

        $this->assertAuthenticatedAs($bob);
        $proven ? $response->assertSessionHas('auth.sso_authenticated_at', ['user' => $bob->id, 'at' => $noted['at']]) : $response->assertSessionMissing('auth.sso_authenticated_at');
        $response->assertSessionMissing('sso.authenticated'); // consumed whatever the outcome
    })->with([
        'for the same user, recent' => ['for the same user, recent', true],
        'for another user' => ['for another user', false],
        'for the same user, older than the window' => ['for the same user, older than the window', false],
        'malformed' => ['malformed', false],
    ]);

    it('does not let a provider sign-in stand in for the password on an account that has one', function () {
        $user = pilotCustomer(['password' => bcrypt('Password1!')]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', shgPerson());
        Socialite::fake('microsoft', shgPerson(['id' => 'tid.oid']));

        $this->withSession(['oauth_context' => shgCtx('login')])->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);

        $this->get(route('sso.redirect', 'microsoft'))->assertRedirect(route('password.confirm'));
    });

    it('does not carry an abandoned second-factor challenge into a different sign-in on the same session', function () {
        $alice = shgTotp(pilotCustomer(['password' => null]));
        SocialAccount::create(['user_id' => $alice->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', shgPerson());
        $bob = pilotCustomer(['password' => bcrypt('Password1!')]);
        $bobNoPassword = pilotCustomer(['password' => null]);

        // Alice starts a provider sign-in and abandons the challenge; the session is not yet authenticated and holds no proof.
        $this->withSession(['oauth_context' => shgCtx('login')])->get(route('sso.callback', 'google'))->assertRedirect(route('two-factor.login'));
        $this->assertGuest();
        expect(session('auth.sso_authenticated_at'))->toBeNull();

        // The next person to sign in on that session (a password sign-in is the carrier) does not inherit a proof.
        $this->post(route('login.store'), ['email' => $bob->email, 'password' => 'Password1!'])->assertSessionMissing('auth.sso_authenticated_at');
        $this->assertAuthenticatedAs($bob);
        expect($bobNoPassword->password)->toBeNull();
    });

    it('does not apply to a guest signing in, nor to an invitation', function () {
        Socialite::fake('google', shgPerson());
        [$invitation, $token] = regInvOpen('invited@example.test');

        $this->get(route('sso.redirect', 'google'))->assertRedirect('https://socialite.fake/google/authorize');
        $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]))->assertRedirect('https://socialite.fake/google/authorize');
    });

    it('refuses to complete a link whose context is older than ten minutes', function () {
        $customer = pilotCustomer();
        Socialite::fake('google', shgPerson());

        $this->actingAs($customer)->withSession(['oauth_context' => shgCtx('link', ['initiatingUserId' => $customer->id, 'startedAt' => now()->subMinutes(11)->toIso8601String()])])
            ->get(route('sso.callback', 'google'))
            ->assertSessionHasErrors(['provider' => 'This provider sign-in request is no longer valid.']);

        expect(SocialAccount::where('user_id', $customer->id)->count())->toBe(0);
    });
});

describe('provider tokens are not stored', function () {
    it('keeps the access and refresh token out of the database on sign-in, link and invitation acceptance', function () {
        // sign-in
        $user = pilotCustomer(['password' => null]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', shgPerson());
        $this->withSession(['oauth_context' => shgCtx('login')])->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));

        // link (a second provider)
        Socialite::fake('microsoft', shgPerson(['id' => 'tid.oid']));
        $this->withSession(['oauth_context' => [...shgCtx('link', ['initiatingUserId' => $user->id]), 'provider' => 'microsoft']])
            ->get(route('sso.callback', 'microsoft'))->assertRedirect(route('profile.show'));

        // invitation acceptance (a fresh guest)
        auth()->logout();
        [$invitation] = regInvOpen('invited@example.test');
        Socialite::fake('google', shgPerson(['id' => 'subject-2']));
        $this->withSession(['oauth_context' => shgCtx('invitation', ['invitationHash' => $invitation->token_hash])])->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));

        expect(SocialAccount::count())->toBe(3);

        foreach (DB::table('social_accounts')->get() as $row) {
            expect($row->token)->toBeNull()->and($row->refresh_token)->toBeNull()->and($row->token_expires_at)->toBeNull();
        }

        // Nothing carries them anywhere else in the database either (a row dump is the leak this prevents).
        $dump = json_encode(DB::table('social_accounts')->get()).json_encode(DB::table('sessions')->get());
        expect($dump)->not->toContain('MUST-NOT-BE-STORED');
    });

    it('does not let a mass assignment write one', function () {
        $user = User::factory()->create();
        $account = SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'x', 'token' => 'a', 'refresh_token' => 'b']);

        expect($account->fresh()->token)->toBeNull()->and($account->fresh()->refresh_token)->toBeNull();
    });

    it('scrubs what earlier builds stored, by migration, and changes no schema', function () {
        $user = User::factory()->create();
        DB::table('social_accounts')->insert([
            ['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'old-1', 'token' => 'OLD-ACCESS', 'refresh_token' => 'OLD-REFRESH', 'token_expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()],
            ['user_id' => $user->id, 'provider' => 'microsoft', 'provider_id' => 'old-2', 'token' => 'OLD-ACCESS-2', 'refresh_token' => null, 'token_expires_at' => null, 'created_at' => now(), 'updated_at' => now()],
        ]);
        $columns = Schema::getColumnListing('social_accounts');

        (require database_path('migrations/2026_11_14_090000_scrub_social_account_provider_tokens.php'))->up();

        foreach (DB::table('social_accounts')->get() as $row) {
            expect($row->token)->toBeNull()->and($row->refresh_token)->toBeNull()->and($row->token_expires_at)->toBeNull();
        }
        expect(DB::table('social_accounts')->pluck('provider_id')->sort()->values()->all())->toBe(['old-1', 'old-2'])
            ->and(Schema::getColumnListing('social_accounts'))->toBe($columns);
    });
});

describe('remember-me', function () {
    $rememberCookie = fn ($response) => collect($response->headers->getCookies())->contains(fn ($cookie) => str_starts_with($cookie->getName(), 'remember_web_'));

    it('is not set by a provider sign-in', function () use ($rememberCookie) {
        $user = pilotCustomer(['password' => null]);
        $rememberTokenBefore = $user->remember_token;
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', shgPerson());

        $response = $this->withSession(['oauth_context' => shgCtx('login')])->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        expect($rememberCookie($response))->toBeFalse()->and($user->fresh()->remember_token)->toBe($rememberTokenBefore);
    });

    it('is not set by a provider invitation acceptance either', function () use ($rememberCookie) {
        [$invitation] = regInvOpen('invited@example.test');
        Socialite::fake('google', shgPerson());

        $response = $this->withSession(['oauth_context' => shgCtx('invitation', ['invitationHash' => $invitation->token_hash])])->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));

        expect($rememberCookie($response))->toBeFalse();
    });

    it('is not carried into the second-factor challenge', function () {
        $user = shgTotp(pilotCustomer(['password' => null]));
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', shgPerson());

        $this->withSession(['oauth_context' => shgCtx('login')])->get(route('sso.callback', 'google'))
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.remember', false);
    });

    it('is still a visible choice on the password form (control: the cookie helper detects a real remember cookie)', function () use ($rememberCookie) {
        $user = User::factory()->create(['password' => bcrypt('Password1!')]);

        $response = $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Password1!', 'remember' => true]);

        expect($rememberCookie($response))->toBeTrue();
    });
});

describe('throttling', function () {
    it('limits link starts per account, counting a refused one, and leaves another account alone', function () {
        $a = pilotCustomer();
        $b = pilotCustomer();
        Socialite::fake('google', shgPerson());

        // A refused start (not fresh) counts as an attempt.
        for ($i = 0; $i < SsoThrottle::START_PER_ACCOUNT_PER_HOUR; $i++) {
            $this->actingAs($a)->get(route('sso.redirect', 'google'))->assertRedirect(route('password.confirm'));
        }
        $this->actingAs($a)->withSession(['auth.password_confirmed_at' => time()])->get(route('sso.redirect', 'google'))->assertStatus(429);

        $this->actingAs($b)->withSession(['auth.password_confirmed_at' => time()])->get(route('sso.redirect', 'google'))->assertRedirect('https://socialite.fake/google/authorize');

        SsoThrottle::clearFor($a->id);
        $this->actingAs($a)->withSession(['auth.password_confirmed_at' => time()])->get(route('sso.redirect', 'google'))->assertRedirect('https://socialite.fake/google/authorize');
    });

    it('limits callbacks per account, before the controller touches the provider', function () {
        $a = pilotCustomer();
        Socialite::fake('google', shgPerson());

        for ($i = 0; $i < SsoThrottle::CALLBACK_PER_ACCOUNT_PER_HOUR; $i++) {
            $this->actingAs($a)->get(route('sso.callback', 'google'))->assertRedirect(route('profile.show')); // no context: "no longer valid"
        }
        $this->actingAs($a)->withSession(['oauth_context' => shgCtx('link', ['initiatingUserId' => $a->id])])->get(route('sso.callback', 'google'))->assertStatus(429);

        expect(SocialAccount::where('user_id', $a->id)->count())->toBe(0);
    });

    it('limits a guest per client address', function () {
        Socialite::fake('google', shgPerson());

        for ($i = 0; $i < SsoThrottle::CALLBACK_PER_ADDRESS_PER_MINUTE; $i++) {
            $this->get(route('sso.callback', 'google'))->assertRedirect(route('login'));
        }

        $this->get(route('sso.callback', 'google'))->assertStatus(429);
    });
});
