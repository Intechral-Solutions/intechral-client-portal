<?php

use App\Auth\ActiveUserProvider;
use App\Models\Invitation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\AccountLifecycleService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

/*
 * Release 0.9 (H3, R0.9-1C): every way an existing account can become or stay authenticated refuses a suspended
 * account, and tells an unauthenticated caller nothing it would not tell them about an account that does not exist.
 * The entry-point inventory is docs/architecture/account-lifecycle.md.
 */

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function suspendAccount(User $user): void
{
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    app(AccountLifecycleService::class)->suspend($user, $operator, 'Test suspension');
}

/** The session key the web guard stores the signed-in user id under. */
function webGuardSessionKey(): string
{
    return Auth::guard('web')->getName();
}

/** A remember-me cookie exactly as the guard queues it at login: [name, value]. */
function recallerCookieFor(User $user): array
{
    $guard = Auth::guard('web');

    return [
        $guard->getRecallerName(),
        $user->getAuthIdentifier().'|'.$user->getRememberToken().'|'.$guard->hashPasswordForCookie($user->getAuthPassword()),
    ];
}

it('resolves every guard and password-broker lookup through the active-account provider', function () {
    expect(Auth::guard('web')->getProvider())->toBeInstanceOf(ActiveUserProvider::class)
        ->and(Password::broker()->getUser(['email' => 'nobody@example.com']))->toBeNull();

    $active = User::factory()->create();
    $suspended = User::factory()->suspended()->create();
    $provider = Auth::guard('web')->getProvider();

    expect($provider->retrieveById($active->id)?->id)->toBe($active->id)
        ->and($provider->retrieveById($suspended->id))->toBeNull()
        ->and($provider->retrieveByCredentials(['email' => $suspended->email]))->toBeNull()
        ->and($provider->retrieveByToken($suspended->id, $suspended->getRememberToken()))->toBeNull()
        ->and(Password::broker()->getUser(['email' => $suspended->email]))->toBeNull();
});

describe('password login', function () {
    it('refuses a suspended account with the correct password exactly as it refuses an unknown account', function () {
        $user = User::factory()->create(['password' => Hash::make('Password1!')]);
        suspendAccount($user);

        $suspended = $this->from('/login')->post(route('login.store'), ['email' => $user->email, 'password' => 'Password1!']);
        $unknown = $this->from('/login')->post(route('login.store'), ['email' => 'nobody@example.com', 'password' => 'Password1!']);

        $suspended->assertRedirect('/login')->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $unknown->assertRedirect('/login')->assertSessionHasErrors(['email' => trans('auth.failed')]);
        $this->assertGuest();
    });

    it('still counts the refusal against the login rate limit', function () {
        $user = User::factory()->create(['password' => Hash::make('Password1!')]);
        suspendAccount($user);

        foreach (range(1, 5) as $attempt) {
            $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Password1!']);
        }

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Password1!'])
            ->assertStatus(429);
    });

    it('never starts a two-factor challenge for a suspended account', function () {
        $user = User::factory()->create([
            'password' => Hash::make('Password1!'),
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        suspendAccount($user);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Password1!'])
            ->assertSessionHasErrors('email')
            ->assertSessionMissing('login.id');
        $this->assertGuest();
    });
});

describe('a two-factor challenge started before the suspension', function () {
    beforeEach(function () {
        $this->user = User::factory()->create([
            'password' => Hash::make('Password1!'),
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
            'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one'])),
            'two_factor_confirmed_at' => now(),
        ]);

        // The password step completes while the account is active...
        $this->post(route('login.store'), ['email' => $this->user->email, 'password' => 'Password1!', 'remember' => true])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $this->user->id);

        // ...and the account is suspended before the code is entered.
        suspendAccount($this->user);
    });

    it('cannot be completed with a valid code, and the code is never checked', function () {
        $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
        $provider->shouldNotReceive('verify');
        app()->instance(TwoFactorAuthenticationProvider::class, $provider);

        $this->post(route('two-factor.login.store'), ['code' => '123456'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionMissing('login.id')
            ->assertSessionMissing('login.remember');

        $this->assertGuest();
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
    });

    it('cannot be completed with a recovery code, which is not consumed', function () {
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionMissing('login.id');

        $this->assertGuest();
        expect($this->user->fresh()->recoveryCodes())->toBe(['recovery-one']);
    });

    it('no longer shows the challenge page', function () {
        $this->get(route('two-factor.login'))->assertRedirect(route('login'));
        $this->assertGuest();
    });
});

describe('an existing session', function () {
    it('stops working on the next request, even if its row was never purged', function () {
        $active = User::factory()->create();
        $suspended = User::factory()->suspended()->create();

        // A session carrying the user id, as a session the suspension could not delete would.
        $this->withSession([webGuardSessionKey() => $active->id])->get(route('dashboard'))->assertOk();
        Auth::forgetGuards();

        $this->withSession([webGuardSessionKey() => $suspended->id])->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('is deleted by the suspension, and a signed-in user is signed out on their next request', function () {
        $user = User::factory()->create(['password' => Hash::make('Password1!')]);
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Password1!'])->assertRedirect('/dashboard');
        Auth::forgetGuards();
        DB::table('sessions')->insert([
            'id' => 'other-device-session', 'user_id' => $user->id, 'ip_address' => '127.0.0.1',
            'user_agent' => 'Pest', 'payload' => 'payload', 'last_activity' => now()->timestamp,
        ]);

        suspendAccount($user);

        expect(DB::table('sessions')->where('user_id', $user->id)->exists())->toBeFalse();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    });
});

describe('a remembered login', function () {
    it('cannot sign a suspended account back in, and the suspension rotates the remember token', function () {
        $user = User::factory()->create();
        [$name, $value] = recallerCookieFor($user);

        // Control: the cookie works while the account is active.
        $this->withCookie($name, $value)->get(route('dashboard'))->assertOk();
        $this->assertAuthenticatedAs($user);
        Auth::forgetGuards();
        $this->flushSession();

        $tokenBefore = $user->fresh()->getRememberToken();
        suspendAccount($user);

        expect($user->fresh()->getRememberToken())->not->toBe($tokenBefore);
        $this->withCookie($name, $value)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('is refused while suspended even with a cookie whose token was never rotated', function () {
        // Independent of the rotation: the provider itself refuses a suspended account's remember token.
        $user = User::factory()->suspended()->create();
        [$name, $value] = recallerCookieFor($user);

        $this->withCookie($name, $value)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    });

    it('does not come back after reactivation either: the old cookie is dead', function () {
        $user = User::factory()->create();
        [$name, $value] = recallerCookieFor($user);
        suspendAccount($user);

        app(AccountLifecycleService::class)->reactivate($user, User::role('operator')->firstOrFail());

        $this->withCookie($name, $value)->get(route('dashboard'))->assertRedirect(route('login'));
        $this->assertGuest();
    });
});

describe('password recovery', function () {
    it('sends no reset link to a suspended account and answers exactly as for an unknown one', function () {
        Notification::fake();
        $user = User::factory()->create();
        suspendAccount($user);

        $suspended = $this->post(route('password.email'), ['email' => $user->email]);
        $unknown = $this->post(route('password.email'), ['email' => 'nobody@example.com']);

        $suspended->assertSessionHasNoErrors();
        expect($suspended->getSession()->get('status'))->toBe($unknown->getSession()->get('status'));
        Notification::assertNothingSent();
        expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
    });

    it('refuses a reset link issued before the suspension, changes no password and does not reactivate', function () {
        $user = User::factory()->create(['password' => Hash::make('Password1!')]);
        $token = Password::broker()->createToken($user);
        suspendAccount($user);
        $hash = $user->fresh()->password;

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewStr0ng!Pass99',
            'password_confirmation' => 'NewStr0ng!Pass99',
        ])->assertSessionHasErrors('email');

        $fresh = $user->fresh();
        expect($fresh->password)->toBe($hash)
            ->and($fresh->isSuspended())->toBeTrue();
        $this->assertGuest();
    });

    it('removes an outstanding reset token on suspension, so it fails even after a reactivation within its validity', function () {
        // R0.9-1C review finding L-1: the link must not come back to life when the account does.
        $user = User::factory()->create(['password' => Hash::make('Password1!')]);
        $broker = Password::broker(config('fortify.passwords'));
        $token = $broker->createToken($user);
        expect($broker->tokenExists($user, $token))->toBeTrue();

        suspendAccount($user);
        expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();

        app(AccountLifecycleService::class)->reactivate($user, User::role('operator')->firstOrFail());
        $hash = $user->fresh()->password;

        $this->post(route('password.update'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NewStr0ng!Pass99',
            'password_confirmation' => 'NewStr0ng!Pass99',
        ])->assertSessionHasErrors('email');
        expect($user->fresh()->password)->toBe($hash);

        // Control: the reactivated, active account recovers its password as usual with a new link.
        $fresh = $broker->createToken($user->fresh());
        $this->post(route('password.update'), [
            'token' => $fresh,
            'email' => $user->email,
            'password' => 'NewStr0ng!Pass99',
            'password_confirmation' => 'NewStr0ng!Pass99',
        ])->assertSessionHasNoErrors();
        expect(Hash::check('NewStr0ng!Pass99', $user->fresh()->password))->toBeTrue();
    });

    it('never mails a reset notification to a suspended user, whoever asks', function () {
        Notification::fake();
        $user = User::factory()->suspended()->create();

        $user->sendPasswordResetNotification('token');

        Notification::assertNotSentTo($user, ResetPassword::class);
    });
});

describe('SSO', function () {
    beforeEach(function () {
        Socialite::fake('google', SocialiteUser::fake(['id' => 'provider-123', 'name' => 'Provider Person', 'email' => 'p@example.com']));
    });

    it('refuses a suspended account with the unlinked-identity answer, and leaves its stored token alone', function () {
        $user = User::factory()->create(['password' => null]);
        $account = SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'provider-123', 'token' => 'stored']);
        suspendAccount($user);

        $context = ['intent' => 'login', 'provider' => 'google', 'initiatingUserId' => null, 'invitationToken' => null, 'startedAt' => now()->toIso8601String()];

        $this->withSession(['oauth_context' => $context])
            ->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'That provider account is not linked to a portal account.']);

        $this->assertGuest();
        expect($account->fresh()->token)->toBe('stored');
    });

    it('still signs in the same linked account once it is reactivated', function () {
        $user = User::factory()->create(['password' => null]);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'provider-123']);
        suspendAccount($user);
        app(AccountLifecycleService::class)->reactivate($user, User::role('operator')->firstOrFail());

        $context = ['intent' => 'login', 'provider' => 'google', 'initiatingUserId' => null, 'invitationToken' => null, 'startedAt' => now()->toIso8601String()];

        $this->withSession(['oauth_context' => $context])
            ->get(route('sso.callback', 'google'))
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    });
});

describe('invitation acceptance', function () {
    it('cannot sign in, change or reactivate an existing suspended account by password registration', function () {
        $user = User::factory()->create(['email' => 'invited@example.com']);
        suspendAccount($user);
        $before = DB::table('users')->where('id', $user->id)->first();
        $invitation = Invitation::factory()->pending()->create(['email' => 'invited@example.com']);

        $this->post(route('invitation.register', $invitation->token), [
            'name' => 'Someone Else',
            'password' => 'NewStr0ng!Pass99',
            'password_confirmation' => 'NewStr0ng!Pass99',
        ])->assertRedirect(route('invitation.show', $invitation->token));

        $this->assertGuest();
        expect(DB::table('users')->where('id', $user->id)->first())->toEqual($before)
            ->and(User::where('email', 'invited@example.com')->count())->toBe(1);
    });

    it('cannot sign in or reactivate an existing suspended account through SSO acceptance', function () {
        Socialite::fake('google', SocialiteUser::fake(['id' => 'new-identity', 'name' => 'Invited', 'email' => 'invited@example.com']));
        $user = User::factory()->create(['email' => 'invited@example.com']);
        suspendAccount($user);
        $invitation = Invitation::factory()->pending()->create(['email' => 'invited@example.com']);

        $context = ['intent' => 'invitation', 'provider' => 'google', 'initiatingUserId' => null, 'invitationToken' => $invitation->token, 'startedAt' => now()->toIso8601String()];

        $this->withSession(['oauth_context' => $context])
            ->get(route('sso.callback', 'google'))
            ->assertRedirect(route('invitation.show', $invitation->token));

        $this->assertGuest();
        expect($user->fresh()->isSuspended())->toBeTrue()
            ->and(SocialAccount::where('provider_id', 'new-identity')->exists())->toBeFalse();
    });
});

describe('after reactivation', function () {
    it('signs in only through the ordinary credential and two-factor steps', function () {
        $user = User::factory()->create([
            'password' => Hash::make('Password1!'),
            'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
            'two_factor_confirmed_at' => now(),
        ]);
        suspendAccount($user);
        app(AccountLifecycleService::class)->reactivate($user, User::role('operator')->firstOrFail());

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'wrong-password'])
            ->assertSessionHasErrors('email');

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Password1!'])
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id);
        $this->assertGuest();
    });
});
