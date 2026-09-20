<?php

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(fn () => $this->seedRolesAndPermissions());

test('forgot password page is an inertia response', function () {
    $this->get(route('password.request'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/forgot-password'));
});

test('password reset link can be requested for a local account', function () {
    Notification::fake();
    $user = User::factory()->create();

    $response = $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    Notification::assertSentTo($user, ResetPasswordNotification::class);
    expect($response->getSession()->get('status'))->toBe(passwordResetAcceptedMessage());
});

test('unknown and local password reset requests have the same browser response', function () {
    Notification::fake();
    $user = User::factory()->create();

    $local = $this->post(route('password.email'), ['email' => $user->email]);
    $unknown = $this->post(route('password.email'), ['email' => 'unknown@example.com']);

    expect($local->getSession()->get('status'))
        ->toBe(passwordResetAcceptedMessage())
        ->and($unknown->getSession()->get('status'))->toBe(passwordResetAcceptedMessage());
});

test('passwordless reset request is generic and sends no notification', function () {
    Notification::fake();
    $user = User::factory()->create(['password' => null]);

    $response = $this->post(route('password.email'), ['email' => $user->email])
        ->assertSessionHasNoErrors()
        ->assertSessionHas('status');

    expect($response->getSession()->get('status'))->toBe(passwordResetAcceptedMessage());
    Notification::assertNothingSent();
});

test('forgot password still validates malformed email input', function () {
    $this->post(route('password.email'), ['email' => 'not-an-email'])
        ->assertSessionHasErrors('email')
        ->assertSessionMissing('status');
});

test('reset password page receives only explicit reset props', function () {
    $this->get(route('password.reset', [
        'token' => 'reset-token',
        'email' => 'person@example.com',
    ]))->assertInertia(fn (Assert $page) => $page
        ->component('auth/reset-password')
        ->where('token', 'reset-token')
        ->where('email', 'person@example.com')
        ->missing('user')
        ->missing('invitation'));
});

test('password can be reset with valid token', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewStr0ng!Pass99',
        'password_confirmation' => 'NewStr0ng!Pass99',
    ])->assertRedirect('/login');

    expect(Hash::check('NewStr0ng!Pass99', $user->fresh()->password))->toBeTrue();
    expect($user->passwordHistories()->count())->toBe(1);
});

test('password reset revokes database sessions for the account', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);
    DB::table('sessions')->insert([
        'id' => 'session-to-revoke',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewStr0ng!Pass99',
        'password_confirmation' => 'NewStr0ng!Pass99',
    ])->assertRedirect(route('login'));

    expect(DB::table('sessions')->where('user_id', $user->id)->exists())
        ->toBeFalse();
});

test('passwordless account cannot use an issued reset token to enroll a password', function () {
    $user = User::factory()->create(['password' => null]);
    $token = Password::broker()->createToken($user);

    $this->post(route('password.update'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'NewStr0ng!Pass99',
        'password_confirmation' => 'NewStr0ng!Pass99',
    ])->assertSessionHasErrors('email');

    expect($user->fresh()->password)->toBeNull()
        ->and($user->passwordHistories()->count())->toBe(0);
});

test('cannot reuse last 5 passwords on update', function () {
    $user = User::factory()->create(['password' => Hash::make('Str0ng!Password1')]);

    PasswordHistory::create([
        'user_id' => $user->id,
        'password' => $user->password,
        'created_at' => now(),
    ]);

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'Str0ng!Password1',
        'password' => 'Str0ng!Password1',
        'password_confirmation' => 'Str0ng!Password1',
    ])->assertSessionHasErrorsIn('updatePassword', 'password');
});

test('password change requires current password', function () {
    $user = User::factory()->create(['password' => Hash::make('Str0ng!Password1')]);

    $this->actingAs($user)->put('/user/password', [
        'current_password' => 'wrongcurrent',
        'password' => 'NewStr0ng!Pass99',
        'password_confirmation' => 'NewStr0ng!Pass99',
    ])->assertSessionHasErrorsIn('updatePassword', 'current_password');
});

test('password confirmation page is inertia and records recent confirmation', function () {
    $user = User::factory()->create(['password' => Hash::make('Password1!')]);

    $this->actingAs($user)
        ->get(route('password.confirm'))
        ->assertInertia(fn (Assert $page) => $page->component('auth/confirm-password'));

    $this->post(route('password.confirm.store'), ['password' => 'Password1!'])
        ->assertRedirect();

    expect(session('auth.password_confirmed_at'))->toBeInt();
});

function passwordResetAcceptedMessage(): string
{
    return 'If an account can use password sign-in, we have sent a password reset link.';
}
