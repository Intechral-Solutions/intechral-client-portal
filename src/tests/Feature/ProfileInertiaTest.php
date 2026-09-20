<?php

use App\Models\SocialAccount;
use App\Models\User;
use App\Services\DatabaseSessionManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

it('requires authentication for profile', function () {
    $this->get(route('profile.show'))->assertRedirect('/login');
});

it('shares only the explicit profile security contract', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('secret'),
        'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code'])),
    ]);
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'provider-123',
        'token' => 'secret-token',
        'refresh_token' => 'secret-refresh',
    ]);

    $this->actingAs($user)
        ->get(route('profile.show'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('profile/show')
            ->where('profile.name', $user->name)
            ->where('profile.email', $user->email)
            ->where('profile.hasPassword', true)
            ->where('twoFactor.enabled', true)
            ->where('twoFactor.confirmed', false)
            ->where('connectedAccounts.0.provider', 'google')
            ->where('connectedAccounts.0.connected', true)
            ->missing('profile.password')
            ->missing('twoFactor.secret')
            ->missing('twoFactor.recoveryCodes')
            ->missing('connectedAccounts.0.providerId')
            ->missing('connectedAccounts.0.token'));
});

it('updates profile information through fortify', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('user-profile-information.update'), [
        'name' => 'Updated Name',
        'email' => 'UPDATED@EXAMPLE.COM',
    ])->assertRedirect()->assertSessionHas('status');

    expect($user->fresh())
        ->name->toBe('Updated Name')
        ->email->toBe('updated@example.com');
});

it('returns profile validation through the named error bag', function () {
    $user = User::factory()->create();

    $this->actingAs($user)->put(route('user-profile-information.update'), [
        'name' => '',
        'email' => 'not-an-email',
    ])->assertSessionHasErrorsIn('updateProfileInformation', ['name', 'email']);
});

it('updates a local password and revokes other database sessions', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create(['password' => Hash::make('Str0ng!Password1')]);
    DB::table('sessions')->insert([
        sessionRow('current-session', $user->id),
        sessionRow('other-session', $user->id),
    ]);
    session()->setId('current-session');

    $this->actingAs($user)->put(route('user-password.update'), [
        'current_password' => 'Str0ng!Password1',
        'password' => 'NewStr0ng!Pass99',
        'password_confirmation' => 'NewStr0ng!Pass99',
    ])->assertRedirect()->assertSessionHas('status');

    expect(Hash::check('NewStr0ng!Pass99', $user->fresh()->password))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other-session')->exists())->toBeFalse();
});

it('lists safe database session metadata and revokes only another session', function () {
    config(['session.driver' => 'database']);
    $user = User::factory()->create();
    $other = User::factory()->create();
    DB::table('sessions')->insert([
        sessionRow('current', $user->id),
        sessionRow('other', $user->id),
        sessionRow('foreign', $other->id),
    ]);

    $manager = app(DatabaseSessionManager::class);
    $sessions = $manager->forUser($user, 'current');
    $manager->revokeOtherSessions($user, 'current');

    expect($sessions)->toHaveCount(2)
        ->and($sessions[0])->not->toHaveKey('id')
        ->and($sessions[0]['userAgent'])->toBe('Test Browser')
        ->and(collect($sessions)->contains('isCurrent', true))->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'current')->exists())->toBeTrue()
        ->and(DB::table('sessions')->where('id', 'other')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'foreign')->exists())->toBeTrue();
});

it('revokes other sessions through the profile action and preserves unrelated sessions', function () {
    $user = User::factory()->create(['password' => Hash::make('Str0ng!Password1')]);
    $other = User::factory()->create();
    DB::table('sessions')->insert([
        sessionRow('current', $user->id),
        sessionRow('other', $user->id),
        sessionRow('foreign', $other->id),
    ]);
    session()->setId('current');

    $this->actingAs($user)->delete(route('profile.sessions.destroy'), [
        'password' => 'Str0ng!Password1',
    ])->assertRedirect()->assertSessionHas('status');

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(1)
        ->and(DB::table('sessions')->where('id', 'other')->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', 'foreign')->exists())->toBeTrue();
});

it('requires the current password to revoke sessions using the named bag', function () {
    $user = User::factory()->create(['password' => Hash::make('Str0ng!Password1')]);

    $this->actingAs($user)->delete(route('profile.sessions.destroy'), [
        'password' => 'incorrect',
    ])->assertSessionHasErrorsIn('destroySessions', 'password');
});

it('requires recent password confirmation before enabling two factor authentication', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('two-factor.enable'))
        ->assertRedirect(route('password.confirm'));

    expect($user->fresh()->two_factor_secret)->toBeNull();
});

it('returns from the existing password confirmation page to profile', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->get(route('profile.password.confirm'))
        ->assertRedirect(route('password.confirm'));

    expect(session('url.intended'))->toBe(route('profile.show'));
});

it('enables two factor authentication in a pending state', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('two-factor.enable'))
        ->assertRedirect();

    expect($user->fresh())
        ->two_factor_secret->not->toBeNull()
        ->two_factor_recovery_codes->not->toBeNull()
        ->two_factor_confirmed_at->toBeNull();
});

it('retrieves setup details only after explicit password-confirmed requests', function () {
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['code-one'])),
    ]);

    $this->actingAs($user)
        ->get(route('two-factor.secret-key'))
        ->assertRedirect(route('password.confirm'));

    $this->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->getJson(route('two-factor.secret-key'))
        ->assertOk()
        ->assertJson(['secretKey' => 'test-secret']);

    $this->getJson(route('two-factor.recovery-codes'))
        ->assertOk()
        ->assertExactJson(['code-one']);
});

it('confirms and disables two factor authentication through fortify', function () {
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['code-one'])),
    ]);
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->once()->with('test-secret', '123456')->andReturnTrue();
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('two-factor.confirm'), ['code' => '123456'])
        ->assertRedirect();

    expect($user->fresh()->two_factor_confirmed_at)->not->toBeNull();

    $this->delete(route('two-factor.disable'))->assertRedirect();

    expect($user->fresh())
        ->two_factor_secret->toBeNull()
        ->two_factor_recovery_codes->toBeNull()
        ->two_factor_confirmed_at->toBeNull();
});

it('returns invalid two factor confirmation codes in the fortify error bag', function () {
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-secret'),
    ]);
    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->once()->andReturnFalse();
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('two-factor.confirm'), ['code' => '000000'])
        ->assertSessionHasErrorsIn('confirmTwoFactorAuthentication', 'code');

    expect($user->fresh()->two_factor_confirmed_at)->toBeNull();
});

it('regenerates recovery codes using the canonical post action', function () {
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('test-secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['old-code'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->actingAs($user)
        ->withSession(['auth.password_confirmed_at' => now()->timestamp])
        ->post(route('two-factor.regenerate-recovery-codes'))
        ->assertRedirect();

    $codes = json_decode(Fortify::currentEncrypter()->decrypt(
        $user->fresh()->two_factor_recovery_codes,
    ), true);

    expect($codes)->toHaveCount(8)->not->toContain('old-code');
});

it('protects passwordless users from unlinking their only provider', function () {
    $user = User::factory()->create(['password' => null]);
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'provider-123',
    ]);

    $this->actingAs($user)
        ->delete(route('profile.social.unlink', 'google'))
        ->assertSessionHasErrors('provider');

    expect($user->socialAccounts()->where('provider', 'google')->exists())->toBeTrue();
});

it('rejects unsupported social providers', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->delete(route('profile.social.unlink', 'unsupported'))
        ->assertSessionHasErrors('provider');
});

it('unlinks a supported provider when another sign-in method remains', function () {
    $user = User::factory()->create();
    SocialAccount::create([
        'user_id' => $user->id,
        'provider' => 'google',
        'provider_id' => 'provider-123',
        'token' => 'secret-token',
    ]);

    $this->actingAs($user)
        ->delete(route('profile.social.unlink', 'google'))
        ->assertRedirect()
        ->assertSessionHas('status');

    expect($user->socialAccounts()->where('provider', 'google')->exists())->toBeFalse();
});

function sessionRow(string $id, int $userId): array
{
    return [
        'id' => $id,
        'user_id' => $userId,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test Browser',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ];
}
