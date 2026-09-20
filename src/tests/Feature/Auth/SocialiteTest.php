<?php

use App\Models\Invitation;
use App\Models\SocialAccount;
use App\Models\User;
use Illuminate\Database\QueryException;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function fakeSocialUser(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake([
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
        'invitationToken' => null,
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

test('invitation redirect stores server side invitation context for a guest', function () {
    Socialite::fake('google', fakeSocialUser());
    $invitation = Invitation::factory()->pending()->create();

    $this->get(route('sso.redirect', [
        'provider' => 'google',
        'invitation' => $invitation->token,
    ]))->assertRedirect('https://socialite.fake/google/authorize')
        ->assertSessionHas('oauth_context.intent', 'invitation')
        ->assertSessionHas('oauth_context.invitationToken', $invitation->token);
});

test('authenticated users cannot initiate invitation registration', function () {
    Socialite::fake('google', fakeSocialUser());
    $user = User::factory()->create();
    $invitation = Invitation::factory()->pending()->create();

    $this->actingAs($user)
        ->get(route('sso.redirect', [
            'provider' => 'google',
            'invitation' => $invitation->token,
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

test('invitation intent creates and authenticates only the invited passwordless user', function () {
    Socialite::fake('microsoft', fakeSocialUser([
        'id' => 'microsoft-123',
        'email' => ' INVITED@example.com ',
    ]));
    $invitation = Invitation::factory()->pending()->create(['email' => 'invited@example.com']);

    $this->withSession([
        'oauth_context' => oauthContext('invitation', 'microsoft', [
            'invitationToken' => $invitation->token,
        ]),
    ])->get(route('sso.callback', 'microsoft'))
        ->assertRedirect(route('dashboard'));

    $user = User::where('email', 'invited@example.com')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->password)->toBeNull()
        ->and($user->invitation_id)->toBe($invitation->id)
        ->and($user->hasRole('user'))->toBeTrue()
        ->and($invitation->fresh()->status)->toBe('accepted');
});

test('invitation email mismatch is retryable and leaves the invitation pending', function () {
    Socialite::fake('google', fakeSocialUser(['email' => 'different@example.com']));
    $invitation = Invitation::factory()->pending()->create(['email' => 'invited@example.com']);

    $this->withSession([
        'oauth_context' => oauthContext('invitation', target: [
            'invitationToken' => $invitation->token,
        ]),
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('invitation.show', $invitation->token))
        ->assertSessionHasErrors('email');

    $this->assertGuest();
    expect($invitation->fresh()->status)->toBe('pending')
        ->and(User::where('email', 'invited@example.com')->exists())->toBeFalse();
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
    $invitation = Invitation::factory()->pending()->create(['email' => 'invited@example.com']);

    $this->withSession([
        'oauth_context' => oauthContext('invitation', target: [
            'invitationToken' => $invitation->token,
        ]),
    ])->get(route('sso.callback', 'google'))
        ->assertRedirect(route('invitation.show', $invitation->token));

    $this->assertGuest();
    expect($invitation->fresh()->status)->toBe('expired')
        ->and(User::count())->toBe(1);
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
