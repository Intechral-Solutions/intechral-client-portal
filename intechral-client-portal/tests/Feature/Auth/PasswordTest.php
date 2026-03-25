<?php

use App\Models\PasswordHistory;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

beforeEach(fn () => $this->seedRolesAndPermissions());

test('password reset link can be requested', function () {
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email])
        ->assertSessionHasNoErrors();
});

test('password can be reset with valid token', function () {
    $user = User::factory()->create();
    $token = Password::broker()->createToken($user);

    $this->post('/reset-password', [
        'token'                 => $token,
        'email'                 => $user->email,
        'password'              => 'NewStr0ng!Pass99',
        'password_confirmation' => 'NewStr0ng!Pass99',
    ])->assertRedirect('/login');

    expect(Hash::check('NewStr0ng!Pass99', $user->fresh()->password))->toBeTrue();
});

test('cannot reuse last 5 passwords on update', function () {
    $user = User::factory()->create(['password' => Hash::make('Str0ng!Password1')]);

    PasswordHistory::create([
        'user_id'    => $user->id,
        'password'   => $user->password,
        'created_at' => now(),
    ]);

    $this->actingAs($user)->put('/user/password', [
        'current_password'      => 'Str0ng!Password1',
        'password'              => 'Str0ng!Password1',
        'password_confirmation' => 'Str0ng!Password1',
    ])->assertSessionHasErrorsIn('updatePassword', 'password');
});

test('password change requires current password', function () {
    $user = User::factory()->create(['password' => Hash::make('Str0ng!Password1')]);

    $this->actingAs($user)->put('/user/password', [
        'current_password'      => 'wrongcurrent',
        'password'              => 'NewStr0ng!Pass99',
        'password_confirmation' => 'NewStr0ng!Pass99',
    ])->assertSessionHasErrorsIn('updatePassword', 'current_password');
});
