<?php

require_once __DIR__.'/InvitationTestHelpers.php';

use App\Models\OrganizationInvitation;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\OrganizationInvitations;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function fakeSocialUser(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake(['email_verified' => true, 'xms_edov' => true,
        'id' => 'provider-123',
        'name' => 'Provider Person',
        'email' => 'provider@example.com',
        ...$attributes,
    ]);
}

function oauthContext(string $intent, string $provider = 'google', array $target = []): array
{
    return [
        'intent' => $intent,
        'provider' => $provider,
        'initiatingUserId' => null,
        'invitationHash' => null,
        'startedAt' => now()->toIso8601String(),
        ...$target,
    ];
}

test('guest redirect stores explicit login context and replaces stale context', function () {
    Socialite::fake('google', fakeSocialUser());

    $this->withSession(['oauth_context' => ['intent' => 'stale']])
        ->get(route('sso.redirect', 'google'))
        ->assertRedirect('https://socialite.fake/google/authorize')
        ->assertSessionHas('oauth_context.intent', 'login')
        ->assertSessionHas('oauth_context.provider', 'google')
        ->assertSessionMissing('oauth_context.invitationToken')
        ->assertSessionHas('oauth_context', fn (array $context) => $context['invitationHash'] === null)
        ->assertSessionMissing('oauth_context.initiatingUserId');
});

test('profile redirect stores link context bound to the current user', function () {
    Socialite::fake('microsoft', fakeSocialUser());
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('sso.redirect', 'microsoft'))
        ->assertRedirect('https://socialite.fake/microsoft/authorize')
        ->assertSessionHas('oauth_context.intent', 'link')
        ->assertSessionHas('oauth_context.provider', 'microsoft')
        ->assertSessionHas('oauth_context.initiatingUserId', $user->id);
});

test('invitation redirect stores server side invitation context for a guest: the HASH, never the token', function () {
    Socialite::fake('google', fakeSocialUser());
    [, $token] = regInvOpen();

    $this->get(route('sso.redirect', [
        'provider' => 'google',
        'invitation' => $token,
    ]))->assertRedirect('https://socialite.fake/google/authorize')
        ->assertSessionHas('oauth_context.intent', 'invitation')
        ->assertSessionHas('oauth_context.invitationHash', OrganizationInvitations::hashToken($token))
        ->assertSessionMissing('oauth_context.invitationToken');
});

test('authenticated users cannot initiate invitation registration', function () {
    Socialite::fake('google', fakeSocialUser());
    $user = User::factory()->create();
    [, $token] = regInvOpen();

    $this->actingAs($user)
        ->get(route('sso.redirect', [
            'provider' => 'google',
            'invitation' => $token,
        ]))->assertRedirect(route('profile.show'))
        ->assertSessionMissing('oauth_context');
});

test('callback requires valid matching application context', function () {
    Socialite::fake('google', fakeSocialUser());

    $this->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'));

    $this->withSession([
        'oauth_context' => oauthContext('login', 'microsoft'),
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('oauth_context');
});

test('callback rejects malformed intent context without contacting the provider', function () {
    Socialite::fake('google', function () {
        throw new RuntimeException('Provider should not be called');
    });

    $this->withSession([
        'oauth_context' => ['intent' => 'link', 'provider' => 'google'],
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('oauth_context');
});

test('unsupported providers are rejected without storing context', function () {
    $this->get(route('sso.redirect', 'github'))
        ->assertNotFound()
        ->assertSessionMissing('oauth_context');
});

test('provider exception consumes context', function () {
    Socialite::fake('google', function () {
        throw new RuntimeException('Provider failed');
    });

    $this->withSession([
        'oauth_context' => oauthContext('login'),
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionMissing('oauth_context');
});

test('login intent authenticates the linked identity owner', function () {
    Socialite::fake('google', fakeSocialUser());
    $owner = User::factory()->create();
    SocialAccount::create([
        'user_id' => $owner->id,
        'provider' => 'google',
        'provider_id' => 'provider-123',
    ]);

    $this->withSession([
        'oauth_context' => oauthContext('login'),
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('dashboard'))
        ->assertSessionMissing('oauth_context');

    $this->assertAuthenticatedAs($owner);
});

test('unlinked login does not authenticate or auto link by matching email', function () {
    Socialite::fake('google', fakeSocialUser(['email' => 'matching@example.com']));
    $user = User::factory()->create(['email' => 'matching@example.com']);

    $this->withSession([
        'oauth_context' => oauthContext('login'),
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'));

    $this->assertGuest();
    expect($user->socialAccounts()->count())->toBe(0);
});

test('link intent creates a provider link only for the initiating user', function () {
    Socialite::fake('google', fakeSocialUser(['email' => 'different@example.com']));
    $user = User::factory()->create(['email' => 'portal@example.com']);

    $this->actingAs($user)
        ->withSession([
            'oauth_context' => oauthContext('link', target: [
                'initiatingUserId' => $user->id,
            ]),
        ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('profile.show'));

    $this->assertAuthenticatedAs($user);
    expect($user->socialAccounts()->where('provider', 'google')->where('provider_id', 'provider-123')->exists())->toBeTrue();
});

test('link intent is idempotent for the same user and rejects another owner', function () {
    Socialite::fake('google', fakeSocialUser());
    $user = User::factory()->create();
    $other = User::factory()->create();
    $account = SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'provider-123',
    ]);

    $this->actingAs($user)
        ->withSession([
            'oauth_context' => oauthContext('link', target: [
                'initiatingUserId' => $user->id,
            ]),
        ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('profile.show'));

    expect($account->fresh()->user_id)->toBe($user->id)
        ->and(SocialAccount::where('provider', 'google')->count())->toBe(1);

    auth()->logout();

    $this->actingAs($other)
        ->withSession([
            'oauth_context' => oauthContext('link', target: [
                'initiatingUserId' => $other->id,
            ]),
        ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('profile.show'))
        ->assertSessionHasErrors('provider');

    $this->assertAuthenticatedAs($other);
    expect($account->fresh()->user_id)->toBe($user->id);
});

test('link callback rejects a different authenticated user', function () {
    Socialite::fake('google', fakeSocialUser());
    $initiator = User::factory()->create();
    $current = User::factory()->create();

    $this->actingAs($current)
        ->withSession([
            'oauth_context' => oauthContext('link', target: [
                'initiatingUserId' => $initiator->id,
            ]),
        ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('profile.show'))
        ->assertSessionHasErrors('provider');

    expect(SocialAccount::count())->toBe(0);
    $this->assertAuthenticatedAs($current);
});

test('invitation intent creates and authenticates only the invited passwordless user, as an ordinary member', function () {
    Socialite::fake('microsoft', fakeSocialUser([
        'id' => 'microsoft-123',
        'email' => ' INVITED@example.com ',
    ]));
    [$invitation] = regInvOpen('invited@example.com');

    $this->withSession([
        'oauth_context' => oauthContext('invitation', 'microsoft', [
            'invitationHash' => $invitation->token_hash,
        ]),
    ])->get(route('sso.callback', 'microsoft'))
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'invited@example.com')->firstOrFail();
    $member = DB::table('organization_members')->where('user_id', $user->id)->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->password)->toBeNull()
        ->and($user->invitation_id)->toBeNull()
        ->and($user->hasRole('user'))->toBeTrue()
        ->and($user->getRoleNames()->all())->toBe(['user'])
        ->and((int) $member->organization_id)->toBe($invitation->organization_id)
        ->and($member->role)->toBe('member')
        ->and($member->admin_approved_at)->toBeNull()
        ->and($user->socialAccounts()->where('provider', 'microsoft')->where('provider_id', 'microsoft-123')->exists())->toBeTrue()
        ->and(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::ACCEPTED);
});

test('invitation email mismatch leaves the invitation open and never rebuilds a link', function () {
    Socialite::fake('google', fakeSocialUser(['email' => 'different@example.com']));
    [$invitation, $token] = regInvOpen('invited@example.com');

    $response = $this->withSession([
        'oauth_context' => oauthContext('invitation', target: [
            'invitationHash' => $invitation->token_hash,
        ]),
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'The provider account email does not match the invitation email.']);

    $this->assertGuest();
    expect($response->headers->get('Location'))->not->toContain($token)->not->toContain($invitation->token_hash)
        ->and(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING)
        ->and(User::where('email', 'invited@example.com')->exists())->toBeFalse()
        ->and(DB::table('organization_members')->count())->toBe(0);
});

test('database prevents linking two identities from the same provider to one user', function () {
    $user = User::factory()->create();
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'first-provider-id',
    ]);

    expect(fn () => SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'second-provider-id',
    ]))->toThrow(QueryException::class);
});

test('invitation intent never falls through to linked account login', function () {
    Socialite::fake('google', fakeSocialUser(['email' => 'invited@example.com']));
    $owner = User::factory()->create();
    SocialAccount::create([
        'user_id' => $owner->id,
        'provider' => 'google',
        'provider_id' => 'provider-123',
    ]);
    [$invitation] = regInvOpen('invited@example.com');

    $this->withSession([
        'oauth_context' => oauthContext('invitation', target: [
            'invitationHash' => $invitation->token_hash,
        ]),
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'));

    // The provider identity already belongs to another account: refused, rolled back, nobody signed in. The invitation is untouched (a refused
    // attempt writes nothing), so the recipient can still use the password form.
    $this->assertGuest();
    expect(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING)
        ->and(User::where('email', 'invited@example.com')->exists())->toBeFalse()
        ->and(DB::table('organization_members')->count())->toBe(0);
});

test('callback context cannot be replayed', function () {
    Socialite::fake('google', fakeSocialUser());
    $owner = User::factory()->create();
    SocialAccount::create([
        'user_id' => $owner->id,
        'provider' => 'google',
        'provider_id' => 'provider-123',
    ]);

    $this->withSession([
        'oauth_context' => oauthContext('login'),
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('dashboard'));

    auth()->logout();

    $this->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'));
    $this->assertGuest();
});
