<?php

/*
 * Release 1.0 (R1-3B): accepting an ORGANIZATION invitation through single sign-on. The contract (docs/product/
 * release-1.0-r1-3-organization-invitations.md, 7.3): the session carries the invitation's HASH only; the context is one-time, bound to its
 * provider and has a maximum age; the callback never reads a token from its own request; every fact is revalidated under lock by the
 * foundation; no failure rebuilds an invitation link from session state; and SSO stays deferred for customers in `closed` and `pilot` mode.
 * The ordinary login and link intents are covered by SocialiteTest.
 */

require_once __DIR__.'/InvitationTestHelpers.php';
require_once __DIR__.'/../Pilot/PilotTestHelpers.php';

use App\Http\Controllers\Auth\SocialiteController;
use App\Models\OrganizationInvitation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OrganizationInvitations;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function ssoInvProvider(array $attributes = [], string $provider = 'google'): void
{
    Socialite::fake($provider, SocialiteUser::fake([
        'id' => 'provider-123',
        'name' => 'Provider Person',
        'email' => 'invited@example.test',
        ...$attributes,
    ]));
}

/** How many times the application contacted the (faked) identity provider since ssoInvProviderMustNotBeCalled() was armed. */
function ssoInvProviderCalls(?int $set = null): int
{
    static $calls = 0;
    if ($set !== null) {
        $calls = $set;
    }

    return $calls;
}

/** A provider that throws, and counts, if the application contacts it: "refused before the provider is contacted" is then a measurement. */
function ssoInvProviderMustNotBeCalled(string $provider = 'google'): void
{
    ssoInvProviderCalls(0);
    Socialite::fake($provider, function () {
        ssoInvProviderCalls(ssoInvProviderCalls() + 1);

        throw new RuntimeException('The provider must not be contacted');
    });
}

function ssoInvContext(string $hash, array $override = []): array
{
    return array_merge([
        'intent' => 'invitation',
        'provider' => 'google',
        'initiatingUserId' => null,
        'invitationHash' => $hash,
        'startedAt' => now()->toIso8601String(),
    ], $override);
}

/** Everything in the stored session, as one string: payload of every row of the sessions table plus the in-memory store. */
function ssoInvSessionDump(): string
{
    $rows = DB::table('sessions')->pluck('payload')->map(fn ($payload) => base64_decode($payload, true) ?: $payload)->implode("\n");

    return $rows."\n".json_encode(session()->all());
}

describe('initiation from an invitation link', function () {
    it('stores the hash, the provider and the start time, and never the token', function () {
        ssoInvProvider();
        [, $token] = regInvOpen();

        $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]))
            ->assertRedirect('https://socialite.fake/google/authorize')
            ->assertSessionHas('oauth_context', fn (array $context) => $context['intent'] === 'invitation'
                && $context['provider'] === 'google'
                && $context['initiatingUserId'] === null
                && $context['invitationHash'] === OrganizationInvitations::hashToken($token)
                && ! array_key_exists('invitationToken', $context)
                && Carbon::parse($context['startedAt'])->diffInSeconds(now(), true) < 5);

        expect(ssoInvSessionDump())->not->toContain($token);
    });

    it('refuses every dead or foreign token with the one answer and stores no context', function (string $kind) {
        ssoInvProvider();
        $token = match ($kind) {
            'unknown' => str_repeat('a', 64),
            'malformed' => 'x',
            'a retired legacy token' => Str::random(64), // the old table is gone: a plaintext token it once held resolves nowhere
            'expired' => regInvOpen('a@example.test', null, null, ['expires_at' => now()->subMinute()])[1],
            'an empty token' => '',
        };

        $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This invitation is no longer available.'])
            ->assertSessionMissing('oauth_context');
    })->with(['unknown', 'malformed', 'a retired legacy token', 'expired', 'an empty token']);

    it('refuses a repeated (array) invitation parameter', function () {
        ssoInvProvider();

        $this->get(route('sso.redirect', ['provider' => 'google']).'?invitation[]=a&invitation[]=b')
            ->assertRedirect(route('login'))
            ->assertSessionMissing('oauth_context');
    });
});

describe('the callback', function () {
    it('creates the account only through the foundation, and consumes the context', function () {
        ssoInvProvider(['email' => ' Invited@Example.Test ']);
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash)])
            ->get(route('sso.callback', 'google'))
            ->assertRedirect(route('dashboard'))
            ->assertSessionMissing('oauth_context');

        $user = User::where('email', 'invited@example.test')->sole();
        $this->assertAuthenticatedAs($user);
        expect(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::ACCEPTED)
            ->and(DB::table('organization_members')->where('user_id', $user->id)->where('role', 'member')->whereNull('admin_approved_at')->count())->toBe(1)
            ->and($user->getRoleNames()->all())->toBe(['user']);
    });

    it('is one-time: the context is gone after the first callback, whatever its outcome', function () {
        ssoInvProvider();
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash)])
            ->get(route('sso.callback', 'google'))->assertRedirect(route('dashboard'));
        auth()->logout();
        $afterFirst = regInvFootprint();

        $this->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['provider' => 'This provider sign-in request is no longer valid.']);

        $this->assertGuest();
        expect(regInvFootprint())->toEqual($afterFirst);
    });

    it('is bound to the provider that started it', function () {
        ssoInvProviderMustNotBeCalled('microsoft');
        [$invitation] = regInvOpen();
        $before = regInvFootprint();

        $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash, ['provider' => 'google'])])
            ->get(route('sso.callback', 'microsoft'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['provider' => 'This provider sign-in request is no longer valid.'])
            ->assertSessionMissing('oauth_context');

        $this->assertGuest();
        expect(regInvFootprint())->toEqual($before)->and(ssoInvProviderCalls())->toBe(0);
    });

    it('honours the maximum age to the second, a start slightly in the future (clock skew), and no further', function (int $ageSeconds, bool $accepted) {
        Carbon::setTestNow(now()->startOfSecond());
        [$invitation] = regInvOpen('invited@example.test');
        $startedAt = now()->subSeconds($ageSeconds)->toIso8601String();

        if ($accepted) {
            ssoInvProvider();
        } else {
            ssoInvProviderMustNotBeCalled(); // refused before the provider is contacted
        }

        $response = $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash, ['startedAt' => $startedAt])])
            ->get(route('sso.callback', 'google'))
            ->assertSessionMissing('oauth_context');

        if ($accepted) {
            $response->assertRedirect(route('dashboard'));
            expect(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::ACCEPTED);
        } else {
            $response->assertRedirect(route('login'))->assertSessionHasErrors(['provider' => 'This provider sign-in request is no longer valid.']);
            $this->assertGuest();
            expect(ssoInvProviderCalls())->toBe(0);
            expect(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING)
                ->and(User::where('email', 'invited@example.test')->exists())->toBeFalse();
        }

        Carbon::setTestNow();
    })->with(function () {
        $max = SocialiteController::INVITATION_CONTEXT_MAX_AGE_SECONDS;

        return [
            'just started' => [0, true],
            'exactly at the limit' => [$max, true],
            'one second past the limit' => [$max + 1, false],
            'a day old' => [86400, false],
            'thirty seconds in the future (skew)' => [-30, true],
            'five minutes in the future' => [-300, false],
        ];
    });

    it('refuses a context in the shape the retired flow wrote (a plaintext token, no hash), for every intent, before contacting the provider', function (string $intent) {
        ssoInvProviderMustNotBeCalled();
        [$invitation, $token] = regInvOpen();
        $user = User::factory()->create();

        $this->actingAs($user)->withSession(['oauth_context' => [
            'intent' => $intent,
            'provider' => 'google',
            'initiatingUserId' => $intent === 'link' ? $user->id : null,
            'invitationToken' => $token,
            'startedAt' => now()->toIso8601String(),
        ]])->get(route('sso.callback', 'google'))
            ->assertSessionHasErrors(['provider' => 'This provider sign-in request is no longer valid.'])
            ->assertSessionMissing('oauth_context');

        expect(ssoInvProviderCalls())->toBe(0)
            ->and(DB::table('social_accounts')->count())->toBe(0)
            ->and(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING);
    })->with(['invitation', 'login', 'link']);

    it('refuses a hash that is not 64 lower-case hex characters, an empty one, and a non-string', function (mixed $hash) {
        ssoInvProviderMustNotBeCalled();
        [$invitation] = regInvOpen();
        $before = regInvFootprint();

        $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash, ['invitationHash' => $hash])])
            ->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['provider' => 'This provider sign-in request is no longer valid.'])
            ->assertSessionMissing('oauth_context');

        $this->assertGuest();
        expect(regInvFootprint())->toEqual($before)->and(ssoInvProviderCalls())->toBe(0);
    })->with([
        'empty' => '',
        'short' => 'abc',
        'upper case' => strtoupper(hash('sha256', 'x')),
        'not hex' => str_repeat('z', 64),
        'an array' => [['x']],
        'null' => null,
    ]);

    it('never reads a token or a hash from its own request: query parameters are ignored', function () {
        ssoInvProviderMustNotBeCalled();
        [$invitation, $token] = regInvOpen();

        $this->get(route('sso.callback', ['provider' => 'google', 'invitation' => $token, 'invitationHash' => $invitation->token_hash, 'token' => $token]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['provider' => 'This provider sign-in request is no longer valid.']);

        $this->assertGuest();
        expect(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING);
    });

    it('revalidates everything under lock: each way the invitation can have died since initiation is the one answer', function (string $kind) {
        ssoInvProvider();
        $issuer = regInvIssuer();
        [$invitation] = regInvOpen('invited@example.test', null, $issuer);

        match ($kind) {
            'revoked' => DB::table('organization_invitations')->where('id', $invitation->id)->update(['revoked_at' => now(), 'revoked_reason' => 'operator', 'live_email' => null]),
            'expired' => DB::table('organization_invitations')->where('id', $invitation->id)->update(['expires_at' => now()->subSecond()]),
            'issuer suspended' => $issuer->forceFill(['disabled_at' => now()])->save(),
            'issuer lost authority' => DB::table('model_has_permissions')->where('model_id', $issuer->id)->where('permission_id', Permission::findByName('crm.manage')->id)->delete(),
            'address now has an account' => User::factory()->create(['email' => 'invited@example.test']),
            'address has a suspended account' => User::factory()->create(['email' => 'invited@example.test', 'disabled_at' => now()]),
            'accepted by someone else' => DB::table('organization_invitations')->where('id', $invitation->id)->update(['accepted_at' => now(), 'accepted_user_id' => User::factory()->create()->id, 'live_email' => null]),
        };
        $before = regInvFootprint();

        $response = $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash)])->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This invitation is no longer available.']);

        $this->assertGuest();
        expect(regInvFootprint())->toEqual($before)
            ->and(SocialAccount::count())->toBe(0)
            ->and($response->headers->get('Location'))->toBe(route('login'));
    })->with(['revoked', 'expired', 'issuer suspended', 'issuer lost authority', 'address now has an account', 'address has a suspended account', 'accepted by someone else']);

    it('does not resolve a retired legacy invitation, even by its hash', function () {
        ssoInvProvider(['email' => 'legacy@example.test']);
        // The legacy table no longer exists; a hash of a token it once held is simply unknown to the only table that is consulted.
        $this->withSession(['oauth_context' => ssoInvContext(hash('sha256', Str::random(64)))])
            ->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This invitation is no longer available.']);

        $this->assertGuest();
        expect(User::where('email', 'legacy@example.test')->exists())->toBeFalse();
    });

    it('validates the provider identity against the invited address, and leaves the invitation open on a mismatch', function (?string $providerEmail) {
        ssoInvProvider(['email' => $providerEmail]);
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash)])->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'The provider account email does not match the invitation email.']);

        $this->assertGuest();
        expect(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING)
            ->and(SocialAccount::count())->toBe(0)
            ->and(User::where('email', 'invited@example.test')->exists())->toBeFalse();
    })->with(['a different address' => 'other@example.test', 'no address at all' => null, 'an empty address' => '   ', 'a look-alike' => 'invited@example.test.evil.test']);

    it('turns a provider failure into the login page without a link, and consumes the context', function () {
        Socialite::fake('google', function () {
            throw new RuntimeException('Provider failed');
        });
        [$invitation, $token] = regInvOpen();

        $response = $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash)])->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors(['email' => 'This invitation could not be completed. Open the link from your email again.'])
            ->assertSessionMissing('oauth_context');

        expect($response->headers->get('Location'))->toBe(route('login'))
            ->and(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING);
        expect(json_encode(session()->all()))->not->toContain($token)->not->toContain($invitation->token_hash);
    });

    it('leaves neither the token nor the hash in the session, a redirect, a flash message or the login page after any failure', function () {
        ssoInvProvider(['email' => 'different@example.test']);
        [$invitation, $token] = regInvOpen();
        $secrets = [$token, $invitation->token_hash];

        $response = $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash)])->get(route('sso.callback', 'google'));
        $login = $this->get($response->headers->get('Location'))->assertOk();

        foreach ($secrets as $secret) {
            expect($response->headers->get('Location'))->not->toContain($secret)
                ->and($login->getContent())->not->toContain($secret)
                ->and(ssoInvSessionDump())->not->toContain($secret);
        }
    });
});

describe('SSO stays deferred for customers, as before', function () {
    it('refuses to start the flow in closed and pilot mode, pointing back at the link the request itself carried', function (string $mode) {
        pilotMode($mode);
        ssoInvProvider();
        [, $token] = regInvOpen();

        $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $token]))
            ->assertRedirect(route('invitation.show', $token))
            ->assertSessionHasErrors(['provider' => 'Single sign-on is not available yet. Create a password to accept this invitation.'])
            ->assertSessionMissing('oauth_context');

        expect(ssoInvSessionDump())->not->toContain(OrganizationInvitations::hashToken($token));
    })->with(['closed', 'pilot']);

    it('refuses a callback whose context somehow names an invitation, with no link, and creates nothing', function (string $mode) {
        pilotMode($mode);
        ssoInvProvider();
        [$invitation, $token] = regInvOpen('invited@example.test');
        $before = regInvFootprint();

        $response = $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash)])->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
        expect($response->headers->get('Location'))->not->toContain($token)->not->toContain($invitation->token_hash)
            ->and(regInvFootprint())->toEqual($before);
    })->with(['closed', 'pilot']);

    it('offers no provider links on the registration page in those modes', function (string $mode) {
        pilotMode($mode);
        [, $token] = regInvOpen();

        $this->get(route('invitation.show', $token))->assertInertia(fn ($page) => $page->where('providersAvailable', false));
    })->with(['closed', 'pilot']);
});

describe('a suspended existing account', function () {
    it('cannot be signed in, changed or reactivated by an SSO acceptance', function () {
        ssoInvProvider(['id' => 'new-identity']);
        $user = User::factory()->create(['email' => 'invited@example.test']);
        $user->forceFill(['disabled_at' => now()])->save();
        $before = DB::table('users')->where('id', $user->id)->first();
        [$invitation] = regInvOpen('invited@example.test');

        $this->withSession(['oauth_context' => ssoInvContext($invitation->token_hash)])->get(route('sso.callback', 'google'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
        expect(DB::table('users')->where('id', $user->id)->first())->toEqual($before)
            ->and(SocialAccount::where('provider_id', 'new-identity')->exists())->toBeFalse();
    });
});
