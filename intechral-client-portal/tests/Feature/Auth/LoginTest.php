<?php

use App\Models\User;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

test('login page is accessible to guests', function () {
    $this->get('/login')->assertOk();
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
});
