<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider;
use Laravel\Fortify\Fortify;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

test('login page is accessible to guests', function () {
    $this->get('/login')->assertInertia(fn (Assert $page) => $page
        ->component('auth/login')
        ->where('auth.user', null)
        ->where('auth.permissions', [])
        // EPIC-013 §12.2: the canonical shape is stable for every actor, so a guest gets an empty
        // workspace list rather than an empty array and no consumer branches on the shape.
        ->where('navigation', ['currentWorkspace' => null, 'workspaces' => []])
        ->where('navigationLegacy', [])
        ->where('shell.presentation', 'operational'));
});

test('authenticated users are redirected from login', function () {
    $this->actingAs(User::factory()->create())
        ->get('/login')
        ->assertRedirect('/dashboard');
});

test('users can login with valid credentials', function () {
    $user = User::factory()->create(['password' => bcrypt('Password1!')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'Password1!'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

test('login follows the intended destination and regenerates the session', function () {
    $user = User::factory()->create(['password' => Hash::make('Password1!')]);

    $this->withSession(['url.intended' => route('profile.show')]);
    $previousSessionId = session()->getId();

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'Password1!',
    ])->assertRedirect(route('profile.show'));

    expect(session()->getId())->not->toBe($previousSessionId);
    $this->assertAuthenticatedAs($user);
});

test('remember choice is preserved through a two factor challenge', function () {
    $user = User::factory()->create([
        'password' => Hash::make('Password1!'),
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'Password1!',
        'remember' => true,
    ])->assertRedirect(route('two-factor.login'))
        ->assertSessionHas('login.id', $user->id)
        ->assertSessionHas('login.remember', true);

    $this->assertGuest();
});

test('two factor challenge page requires intermediate login state', function () {
    $this->get(route('two-factor.login'))->assertRedirect(route('login'));
});

test('two factor challenge authenticates with a valid code', function () {
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);

    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->once()->andReturnTrue();
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    $this->withSession(['login.id' => $user->id, 'login.remember' => false])
        ->post(route('two-factor.login.store'), ['code' => '123456'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
});

test('two factor challenge page exposes no setup secrets', function () {
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['one-time-recovery'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->withSession(['login.id' => $user->id, 'login.remember' => false])
        ->get(route('two-factor.login'))
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/two-factor-challenge')
            ->missing('secret')
            ->missing('recovery_codes')
            ->missing('user'));
});

test('two factor recovery codes authenticate once and are consumed', function () {
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['one-time-recovery'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $this->withSession(['login.id' => $user->id, 'login.remember' => false])
        ->post(route('two-factor.login.store'), ['recovery_code' => 'one-time-recovery'])
        ->assertRedirect('/dashboard');

    $this->assertAuthenticatedAs($user);
    expect($user->fresh()->recoveryCodes())
        ->toHaveCount(1)
        ->not->toContain('one-time-recovery');
});

test('two factor challenge is rate limited after five invalid attempts', function () {
    $user = User::factory()->create([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt('secret'),
        'two_factor_confirmed_at' => now(),
    ]);

    $provider = Mockery::mock(TwoFactorAuthenticationProvider::class);
    $provider->shouldReceive('verify')->andReturnFalse();
    app()->instance(TwoFactorAuthenticationProvider::class, $provider);

    foreach (range(1, 6) as $attempt) {
        $response = $this->withSession(['login.id' => $user->id, 'login.remember' => false])
            ->post(route('two-factor.login.store'), ['code' => '000000']);
    }

    $response->assertStatus(429);
});

test('users cannot login with wrong password', function () {
    $user = User::factory()->create(['password' => bcrypt('Password1!')]);

    $this->post('/login', ['email' => $user->email, 'password' => 'wrong'])
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

test('login is rate limited after 5 attempts', function () {
    $user = User::factory()->create();

    foreach (range(1, 6) as $i) {
        $response = $this->post('/login', ['email' => $user->email, 'password' => 'wrong']);
    }

    $response->assertStatus(429);
});

test('users can logout', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post('/logout')
        ->assertRedirect('/');

    $this->assertGuest();
    expect(session()->has('url.intended'))->toBeFalse();
});
