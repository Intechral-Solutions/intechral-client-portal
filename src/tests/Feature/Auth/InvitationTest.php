<?php

use App\Models\Invitation;
use App\Models\PasswordHistory;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\InvitationService;
use Illuminate\Support\Facades\Notification;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    Notification::fake();
    $this->seedRolesAndPermissions();
});

test('operator can send an invitation', function () {
    $operator = User::factory()->create();
    $operator->assignRole('operator');

    $service = app(InvitationService::class);
    $invitation = $service->invite('newuser@example.com', $operator);

    expect($invitation->email)->toBe('newuser@example.com')
        ->and($invitation->status)->toBe('pending')
        ->and($invitation->expires_at->isFuture())->toBeTrue();

    Notification::assertSentOnDemand(InvitationNotification::class);
});

test('sending a new invitation invalidates the previous one', function () {
    $operator = User::factory()->create();
    $service = app(InvitationService::class);

    $first = $service->invite('user@example.com', $operator);
    $service->invite('user@example.com', $operator);

    expect($first->fresh()->status)->toBe('expired');
});

test('invitation email normalization invalidates case and whitespace variants', function () {
    $operator = User::factory()->create();
    $service = app(InvitationService::class);

    $first = $service->invite(' User@Example.com ', $operator);
    $second = $service->invite('user@example.com', $operator);

    expect($first->fresh()->status)->toBe('expired')
        ->and($second->email)->toBe('user@example.com');
});

test('invitation show page renders for a valid token', function () {
    $invitation = Invitation::factory()->pending()->create();

    $this->get("/invitation/{$invitation->token}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/invitation-register')
            ->where('invitation.email', $invitation->email)
            ->has('invitation.expiresAt')
            ->where('token', $invitation->token)
            ->missing('invitation.id')
            ->missing('invitation.invited_by'));
});

test('expired invitation shows error page', function () {
    $invitation = Invitation::factory()->expired()->create();

    $this->get("/invitation/{$invitation->token}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('auth/invitation-invalid')
            ->missing('token')
            ->missing('invitation'));
});

test('accepted and missing invitations use the same safe page', function () {
    $invitation = Invitation::factory()->accepted()->create();

    $this->get(route('invitation.show', $invitation->token))
        ->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));

    $this->get(route('invitation.show', str_repeat('x', 64)))
        ->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));
});

test('invitation routes are guest only', function () {
    $user = User::factory()->create();
    $invitation = Invitation::factory()->pending()->create();

    $this->actingAs($user)
        ->get(route('invitation.show', $invitation->token))
        ->assertRedirect(route('dashboard'));

    $this->post(route('invitation.register', $invitation->token), [
        'name' => 'Other Person',
        'password' => 'Str0ng!Password99',
        'password_confirmation' => 'Str0ng!Password99',
    ])->assertRedirect(route('dashboard'));

    $this->assertAuthenticatedAs($user);
});

test('user can register via a valid invitation', function () {
    $invitation = Invitation::factory()->pending()->create(['email' => 'invited@example.com']);

    $this->post("/invitation/{$invitation->token}", [
        'name' => 'New User',
        'password' => 'Str0ng!Password99',
        'password_confirmation' => 'Str0ng!Password99',
    ])->assertRedirect('/dashboard');

    $this->assertAuthenticated();

    $user = User::where('email', 'invited@example.com')->first();
    expect($user)->not->toBeNull()
        ->and($user->hasRole('user'))->toBeTrue()
        ->and($user->invitation_id)->toBe($invitation->id)
        ->and($user->invited_by)->toBe($invitation->invited_by)
        ->and($user->organizations()->count())->toBe(0)
        ->and($user->passwordHistories()->count())->toBe(1);

    expect($invitation->fresh()->status)->toBe('accepted');
});

test('email is always taken from invitation, not form input', function () {
    $invitation = Invitation::factory()->pending()->create(['email' => 'correct@example.com']);

    $this->post("/invitation/{$invitation->token}", [
        'name' => 'New User',
        'password' => 'Str0ng!Password99',
        'password_confirmation' => 'Str0ng!Password99',
    ])->assertRedirect('/dashboard');

    expect(User::where('email', 'correct@example.com')->exists())->toBeTrue();
});

test('password must meet complexity requirements', function () {
    $invitation = Invitation::factory()->pending()->create();

    $this->post("/invitation/{$invitation->token}", [
        'name' => 'New User',
        'password' => 'weak',
        'password_confirmation' => 'weak',
    ])->assertSessionHasErrors('password');
});

test('duplicate account conflict leaves no partial invitation state', function () {
    User::factory()->create(['email' => 'existing@example.com']);
    $invitation = Invitation::factory()->pending()->create(['email' => 'existing@example.com']);

    $this->post(route('invitation.register', $invitation->token), [
        'name' => 'Duplicate User',
        'password' => 'Str0ng!Password99',
        'password_confirmation' => 'Str0ng!Password99',
    ])->assertRedirect(route('invitation.show', $invitation->token));

    expect(User::where('email', 'existing@example.com')->count())->toBe(1)
        ->and(PasswordHistory::count())->toBe(0)
        ->and($invitation->fresh()->status)->toBe('expired');
});

test('accepted user inverse resolves through invitation id', function () {
    $invitation = Invitation::factory()->accepted()->create();
    $user = User::factory()->create(['invitation_id' => $invitation->id]);

    expect($invitation->acceptedUser->is($user))->toBeTrue();
});
