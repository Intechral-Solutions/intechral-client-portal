<?php

/*
 * Release 1.0 (SSO completion): the security contract of Google and Microsoft sign-in, linking and invitation acceptance once customer SSO is
 * enabled in pilot mode. What it proves, each in terms of what could go wrong:
 *
 *  - an address is trusted only on the provider's verified-email evidence, and a missing or ambiguous claim refuses (fail closed);
 *  - an identity is found by the provider's subject, never by its address (no takeover of an existing account by a matching address);
 *  - a provider that is not configured is a plain refusal, never an error page, and merging this changes nothing without credentials;
 *  - a confirmed second factor is asked for after SSO exactly as after a password (no MFA bypass), and the session id is regenerated;
 *  - `identity_taken` has its own accurate message and leaves the invitation open;
 *  - closed mode still defers customer SSO.
 * The provider itself is faked here (Socialite::fake); the real Microsoft driver is exercised by MicrosoftProviderTest, and live end-to-end
 * verification against the real providers is a separate, credentialed step (docs/product/release-1.0-sso-completion.md).
 */

require_once __DIR__.'/InvitationTestHelpers.php';
require_once __DIR__.'/../Pilot/PilotTestHelpers.php';

use App\Models\OrganizationInvitation;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Fortify\Fortify;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seedRolesAndPermissions();
    pilotMode('pilot');
});

function ssoCtx(string $intent, string $provider = 'google', array $target = []): array
{
    return ['intent' => $intent, 'provider' => $provider, 'initiatingUserId' => null, 'invitationHash' => null, 'startedAt' => now()->toIso8601String(), ...$target];
}

/** A provider account carrying the evidence the given provider is documented to use. */
function ssoPerson(string $provider, array $attributes = []): SocialiteUser
{
    $evidence = $provider === 'microsoft' ? ['xms_edov' => true] : ['email_verified' => true];

    return SocialiteUser::fake([...$evidence, 'id' => 'subject-1', 'name' => 'Provider Person', 'email' => 'invited@example.test', ...$attributes]);
}

function ssoWithTotp(User $user, bool $confirmed = true): User
{
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one'])),
        'two_factor_confirmed_at' => $confirmed ? now() : null,
    ])->save();

    return $user;
}

function ssoUserCount(): int
{
    return DB::table('users')->count();
}

describe('verified-email enforcement on invitation acceptance (fail closed)', function () {
    it('accepts a verified address from either provider, creating the account, the identity and the membership', function (string $provider) {
        Socialite::fake($provider, ssoPerson($provider));
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoCtx('invitation', $provider, ['invitationHash' => $invitation->token_hash])])
            ->get(route('sso.callback', $provider))
            ->assertRedirect(route('dashboard'));

        $user = User::where('email', 'invited@example.test')->firstOrFail();
        $this->assertAuthenticatedAs($user);
        expect($user->hasRole('user'))->toBeTrue()
            ->and(SocialAccount::where('user_id', $user->id)->where('provider', $provider)->where('provider_id', 'subject-1')->exists())->toBeTrue()
            ->and(DB::table('organization_members')->where('user_id', $user->id)->where('organization_id', $invitation->organization_id)->where('role', 'member')->exists())->toBeTrue()
            ->and(DB::table('organization_invitations')->find($invitation->id)->accepted_at)->not->toBeNull();
    })->with(['google', 'microsoft']);

    it('refuses an address the provider did not verify, writing nothing and leaving the invitation open', function (string $provider, array $claims) {
        Socialite::fake($provider, SocialiteUser::fake([...$claims, 'id' => 'subject-1', 'name' => 'P', 'email' => 'invited@example.test']));
        [$invitation] = regInvOpen('invited@example.test');
        $users = ssoUserCount();

        $this->withSession(['oauth_context' => ssoCtx('invitation', $provider, ['invitationHash' => $invitation->token_hash])])
            ->get(route('sso.callback', $provider))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => ucfirst($provider).' did not confirm that your email address is verified, so it cannot be used to accept this invitation. Open the link from your email again and create a password instead.']);

        $this->assertGuest();
        expect(ssoUserCount())->toBe($users)
            ->and(SocialAccount::count())->toBe(0)
            ->and(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING);
    })->with([
        'google: claim absent' => ['google', []],
        'google: claim false' => ['google', ['email_verified' => false]],
        'google: the string "false"' => ['google', ['email_verified' => 'false']],
        'google: the word "yes"' => ['google', ['email_verified' => 'yes']],
        'google: a number is not evidence' => ['google', ['email_verified' => 1]],
        'microsoft: claim absent' => ['microsoft', []],
        'microsoft: claim false' => ['microsoft', ['xms_edov' => false]],
        'microsoft: claim 0' => ['microsoft', ['xms_edov' => 0]],
        'microsoft: only the Google claim present' => ['microsoft', ['email_verified' => true]],
        'google: only the Microsoft claim present' => ['google', ['xms_edov' => true]],
    ]);

    it('never reads another address-like value of the provider as the verified one', function () {
        // A tenant administrator can set a user's mail / UPN to anything; the verified claim travels with the `email` claim only.
        Socialite::fake('microsoft', SocialiteUser::fake([
            'xms_edov' => true, 'id' => 'tid.oid', 'name' => 'P', 'email' => null,
            'mail' => 'invited@example.test', 'preferred_username' => 'invited@example.test', 'userPrincipalName' => 'invited@example.test',
        ]));
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoCtx('invitation', 'microsoft', ['invitationHash' => $invitation->token_hash])])
            ->get(route('sso.callback', 'microsoft'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'Microsoft did not confirm that your email address is verified, so it cannot be used to accept this invitation. Open the link from your email again and create a password instead.']);

        $this->assertGuest();
        expect(User::where('email', 'invited@example.test')->exists())->toBeFalse();
    });

    it('does not let a verified but different address through', function () {
        Socialite::fake('google', ssoPerson('google', ['email' => 'someone-else@example.test']));
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoCtx('invitation', 'google', ['invitationHash' => $invitation->token_hash])])
            ->get(route('sso.callback', 'google'))
            ->assertSessionHasErrors(['email' => 'The provider account email does not match the invitation email.']);

        $this->assertGuest();
        expect(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING);
    });

    it('refuses an invitation for an address that already has a portal account, with the uniform answer', function () {
        User::factory()->create(['email' => 'invited@example.test']);
        Socialite::fake('google', ssoPerson('google'));
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoCtx('invitation', 'google', ['invitationHash' => $invitation->token_hash])])
            ->get(route('sso.callback', 'google'))
            ->assertSessionHasErrors(['email' => 'This invitation is no longer available.']);

        $this->assertGuest();
        expect(SocialAccount::count())->toBe(0);
    });

    it('truncates an over-long provider display name to the column', function () {
        Socialite::fake('google', ssoPerson('google', ['name' => str_repeat('N', 400)]));
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoCtx('invitation', 'google', ['invitationHash' => $invitation->token_hash])])
            ->get(route('sso.callback', 'google'))
            ->assertRedirect(route('dashboard'));

        expect(mb_strlen(User::where('email', 'invited@example.test')->firstOrFail()->name))->toBe(255);
    });
});

describe('identity_taken', function () {
    it('says that the provider account already belongs to a portal account, and keeps the invitation open', function (string $provider) {
        $owner = User::factory()->create(['email' => 'owner@example.test']);
        SocialAccount::create(['user_id' => $owner->id, 'provider' => $provider, 'provider_id' => 'subject-1']);
        Socialite::fake($provider, ssoPerson($provider));
        [$invitation] = regInvOpen('invited@example.test');
        $users = ssoUserCount();

        $response = $this->withSession(['oauth_context' => ssoCtx('invitation', $provider, ['invitationHash' => $invitation->token_hash])])
            ->get(route('sso.callback', $provider))
            ->assertRedirect(route('login'));

        $label = ucfirst($provider);
        $response->assertSessionHasErrors(['email' => "That {$label} account is already connected to a portal account. Sign in with it from the login page, or use a different {$label} account for this invitation."]);

        $this->assertGuest();
        expect(ssoUserCount())->toBe($users)
            ->and(User::where('email', 'invited@example.test')->exists())->toBeFalse()
            ->and(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING)
            ->and(SocialAccount::where('provider', $provider)->where('provider_id', 'subject-1')->value('user_id'))->toBe($owner->id);
    })->with(['google', 'microsoft']);
});

describe('identity is the provider subject, never the address', function () {
    it('does not sign in the account whose address the provider claims, when the identity is not linked', function () {
        $victim = pilotCustomer(['email' => 'victim@example.test']);
        Socialite::fake('google', ssoPerson('google', ['id' => 'attacker-subject', 'email' => 'victim@example.test']));

        $this->withSession(['oauth_context' => ssoCtx('login')])->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'That provider account is not linked to a portal account.']);

        $this->assertGuest();
        expect(SocialAccount::count())->toBe(0)->and($victim->fresh()->email)->toBe('victim@example.test');
    });

    it('refuses a provider response with no subject, for every intent', function (string $intent) {
        Socialite::fake('google', ssoPerson('google', ['id' => '']));
        $customer = pilotCustomer();
        [$invitation] = regInvOpen('invited@example.test');
        $context = match ($intent) {
            'login' => ssoCtx('login'),
            'link' => ssoCtx('link', 'google', ['initiatingUserId' => $customer->id]),
            'invitation' => ssoCtx('invitation', 'google', ['invitationHash' => $invitation->token_hash]),
        };

        ($intent === 'link' ? $this->actingAs($customer) : $this)
            ->withSession(['oauth_context' => $context])
            ->get(route('sso.callback', 'google'))
            ->assertSessionHasErrors();

        if ($intent !== 'link') {
            $this->assertGuest();
        }
        expect(SocialAccount::count())->toBe(0)->and(User::where('email', 'invited@example.test')->exists())->toBeFalse();
    })->with(['login', 'link', 'invitation']);
});

describe('customer SSO in pilot mode', function () {
    it('signs a linked customer in', function (string $provider) {
        $customer = pilotCustomer();
        SocialAccount::create(['user_id' => $customer->id, 'provider' => $provider, 'provider_id' => 'subject-1']);
        Socialite::fake($provider, ssoPerson($provider));

        $this->withSession(['oauth_context' => ssoCtx('login', $provider)])->get(route('sso.callback', $provider))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($customer);
    })->with(['google', 'microsoft']);

    it('still refuses a suspended customer with the unlinked-identity answer', function () {
        $customer = pilotCustomer();
        SocialAccount::create(['user_id' => $customer->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        $customer->forceFill(['disabled_at' => now()])->save();
        Socialite::fake('google', ssoPerson('google'));

        $this->withSession(['oauth_context' => ssoCtx('login')])->get(route('sso.callback', 'google'))
            ->assertSessionHasErrors(['email' => 'That provider account is not linked to a portal account.']);

        $this->assertGuest();
    });

    it('lets a signed-in customer link a provider, and offers the connect option on the profile', function () {
        $customer = pilotCustomer();
        Socialite::fake('microsoft', ssoPerson('microsoft', ['id' => 'tid.oid']));

        $this->actingAs($customer)->get(route('profile.show'))->assertInertia(fn ($page) => $page
            ->where('connectedAccounts', [['provider' => 'google', 'connected' => false], ['provider' => 'microsoft', 'connected' => false]]));

        $this->actingAs($customer)->get(route('sso.redirect', 'microsoft'))->assertRedirect('https://socialite.fake/microsoft/authorize');
        $this->actingAs($customer)->withSession(['oauth_context' => ssoCtx('link', 'microsoft', ['initiatingUserId' => $customer->id])])
            ->get(route('sso.callback', 'microsoft'))
            ->assertRedirect(route('profile.show'));

        expect(SocialAccount::where('user_id', $customer->id)->where('provider', 'microsoft')->where('provider_id', 'tid.oid')->exists())->toBeTrue();
    });

    it('refuses to link a provider account that already belongs to someone else', function () {
        $other = pilotCustomer();
        SocialAccount::create(['user_id' => $other->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        $customer = pilotCustomer();
        Socialite::fake('google', ssoPerson('google'));

        $this->actingAs($customer)->withSession(['oauth_context' => ssoCtx('link', 'google', ['initiatingUserId' => $customer->id])])
            ->get(route('sso.callback', 'google'))
            ->assertSessionHasErrors(['provider' => 'That provider account is already connected elsewhere.']);

        expect(SocialAccount::where('user_id', $customer->id)->count())->toBe(0);
    });

    it('stays deferred in closed mode for sign-in and acceptance', function () {
        pilotMode('closed');
        $customer = pilotCustomer();
        SocialAccount::create(['user_id' => $customer->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', ssoPerson('google'));
        [$invitation, $token] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoCtx('login')])->get(route('sso.callback', 'google'))->assertSessionHasErrors('email');
        $this->assertGuest();

        $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]))->assertRedirect(route('invitation.show', $token));
        $this->withSession(['oauth_context' => ssoCtx('invitation', 'google', ['invitationHash' => $invitation->token_hash])])
            ->get(route('sso.callback', 'google'))->assertSessionHasErrors('email');
        expect(User::where('email', 'invited@example.test')->exists())->toBeFalse();
    });
});

describe('a provider that is not configured', function () {
    it('is refused at initiation with a plain message, and starts nothing', function (string $provider) {
        config(["services.{$provider}.client_id" => null]);
        Socialite::fake($provider, ssoPerson($provider));

        $this->get(route('sso.redirect', $provider))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['provider' => ucfirst($provider).' sign-in is not available.'])
            ->assertSessionMissing('oauth_context');
    })->with(['google', 'microsoft']);

    it('is refused for an invitation with a pointer back at the link the request carried, and starts nothing', function () {
        config(['services.microsoft.client_secret' => '']);
        [, $token] = regInvOpen();

        $this->get(route('sso.redirect', ['provider' => 'microsoft', 'invitation' => $token]))
            ->assertRedirect(route('invitation.show', $token))
            ->assertSessionHasErrors(['provider' => 'Microsoft sign-in is not available. Create a password to accept this invitation.'])
            ->assertSessionMissing('oauth_context');
    });

    it('is refused at the callback too, creating nothing', function () {
        config(['services.google.client_id' => null]);
        Socialite::fake('google', ssoPerson('google'));
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoCtx('invitation', 'google', ['invitationHash' => $invitation->token_hash])])
            ->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        expect(User::where('email', 'invited@example.test')->exists())->toBeFalse();
    });

    it('is not offered on the invitation page when no provider is configured', function () {
        foreach (['google', 'microsoft'] as $provider) {
            config(["services.{$provider}.client_id" => null]);
        }
        [, $token] = regInvOpen();

        $this->get(route('invitation.show', $token))->assertInertia(fn ($page) => $page->where('providersAvailable', false));
    });

    it('treats a mistyped Microsoft tenant as not available, visibly, instead of refusing every person silently', function (string $tenant, bool $available) {
        config(['services.microsoft.tenant' => $tenant]);

        $response = $this->get(route('sso.redirect', 'microsoft'));

        if ($available) {
            $response->assertRedirect();
            expect($response->headers->get('Location'))->toContain('login.microsoftonline.com');
        } else {
            $response->assertRedirect(route('login'))->assertSessionHasErrors(['provider' => 'Microsoft sign-in is not available.']);
        }
    })->with([
        ['common', true], ['organizations', true], ['consumers', true], ['11111111-2222-3333-4444-555555555555', true],
        ['contoso.onmicrosoft.com', false], ['', true], ['not a tenant', false],
    ]);

    it('is not a server error for an unconfigured Microsoft driver: it is registered and inert', function () {
        config(['services.microsoft.client_id' => null]);

        $this->get('/auth/microsoft/redirect')->assertRedirect(route('login'));
    });
});

describe('two-factor authentication after SSO (no MFA bypass)', function () {
    it('sends an account with a confirmed second factor to the challenge instead of signing it in, and completes with the code', function (string $kind) {
        $user = $kind === 'operator' ? pilotOperator() : pilotCustomer();
        ssoWithTotp($user);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', ssoPerson('google'));

        $this->withSession(['oauth_context' => ssoCtx('login')])->get(route('sso.callback', 'google'))
            ->assertRedirect(route('two-factor.login'))
            ->assertSessionHas('login.id', $user->id);

        $this->assertGuest();

        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    })->with(['customer', 'operator']);

    it('does not challenge an account whose second factor was never confirmed (the same rule as the password login)', function () {
        $user = ssoWithTotp(pilotCustomer(), confirmed: false);
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', ssoPerson('google'));

        $this->withSession(['oauth_context' => ssoCtx('login')])->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    });

    it('does not sign in a pending challenge whose account is suspended before the code is entered', function () {
        $user = ssoWithTotp(pilotCustomer());
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', ssoPerson('google'));
        $this->withSession(['oauth_context' => ssoCtx('login')])->get(route('sso.callback', 'google'))->assertRedirect(route('two-factor.login'));

        $user->forceFill(['disabled_at' => now()])->save();
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one']);

        $this->assertGuest();
    });
});

/** A session row the "attacker" planted before the provider round trip; the request carries its id. */
function ssoPlantSession(array $data): string
{
    $id = Str::random(40);
    DB::table('sessions')->insert(['id' => $id, 'payload' => base64_encode(json_encode(['_token' => 'planted', ...$data])), 'last_activity' => time()]);

    return $id;
}

function ssoSessionPayload(string $id): string
{
    return (string) base64_decode((string) DB::table('sessions')->where('id', $id)->value('payload'), true);
}

describe('session integrity', function () {
    it('keeps the sign-in out of a session identifier that existed before the provider round trip', function () {
        $user = pilotCustomer();
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', ssoPerson('google'));
        $planted = ssoPlantSession(['oauth_context' => ssoCtx('login')]);

        $this->withCookie(config('session.cookie'), $planted)->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        // The planted identifier is destroyed (regenerate with destroy) and never received the authenticated state.
        expect(DB::table('sessions')->where('id', $planted)->exists())->toBeFalse()
            ->and(ssoSessionPayload($planted))->not->toContain('login_web_')
            ->and(DB::table('sessions')->where('id', '!=', $planted)->get()->contains(fn ($row) => str_contains((string) base64_decode($row->payload, true), 'login_web_')))->toBeTrue();
    });

    it('keeps the pending challenge out of the planted session identifier as well', function () {
        $user = ssoWithTotp(pilotCustomer());
        SocialAccount::create(['user_id' => $user->id, 'provider' => 'google', 'provider_id' => 'subject-1']);
        Socialite::fake('google', ssoPerson('google'));
        $planted = ssoPlantSession(['oauth_context' => ssoCtx('login')]);

        $this->withCookie(config('session.cookie'), $planted)->get(route('sso.callback', 'google'))->assertRedirect(route('two-factor.login'));

        $this->assertGuest();
        expect(DB::table('sessions')->where('id', $planted)->exists())->toBeFalse()
            ->and(ssoSessionPayload($planted))->not->toContain('"login":')
            ->and(DB::table('sessions')->where('id', '!=', $planted)->get()->contains(fn ($row) => str_contains((string) base64_decode($row->payload, true), '"login":')))->toBeTrue();
    });

    it('consumes the context whatever the outcome, so a callback cannot be replayed', function () {
        Socialite::fake('google', ssoPerson('google'));

        $this->withSession(['oauth_context' => ssoCtx('login')])->get(route('sso.callback', 'google'));
        $this->get(route('sso.callback', 'google'))->assertSessionHasErrors(['provider' => 'This provider sign-in request is no longer valid.']);
    });

    it('refuses a context started for another provider', function () {
        Socialite::fake('microsoft', ssoPerson('microsoft'));

        $this->withSession(['oauth_context' => ssoCtx('login', 'google')])->get(route('sso.callback', 'microsoft'))
            ->assertSessionHasErrors(['provider' => 'This provider sign-in request is no longer valid.']);
    });

    it('answers a provider error (the user cancelled, or the state did not match) as a failed sign-in, not a server error', function () {
        Socialite::fake('google', function () {
            throw new InvalidStateException;
        });

        $this->withSession(['oauth_context' => ssoCtx('login')])->get(route('sso.callback', 'google').'?error=access_denied')
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['provider' => 'Authentication via Google failed. Please try again.']);

        $this->assertGuest();
    });
});
