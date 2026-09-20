<?php

use App\Exceptions\InvitationUnavailableException;
use App\Models\Invitation;
use App\Models\PasswordHistory;
use App\Models\SocialAccount;
use App\Models\User;
use App\Notifications\InvitationNotification;
use App\Services\InvitationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
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

test('duplicate provider identity expires the invitation without partial state', function () {
    $owner = User::factory()->create();
    SocialAccount::create([
        'user_id' => $owner->id,
        'provider' => 'google',
        'provider_id' => 'provider-123',
    ]);
    $invitation = Invitation::factory()->pending()->create(['email' => 'invited@example.com']);

    expect(fn () => app(InvitationService::class)->acceptWithSocialAccount(
        $invitation->token,
        'google',
        [
            'name' => 'Invited Person',
            'email' => 'invited@example.com',
            'provider_id' => 'provider-123',
            'token' => 'provider-token',
            'refresh_token' => 'provider-refresh-token',
            'token_expires_at' => now()->addHour(),
        ],
    ))->toThrow(InvitationUnavailableException::class);

    expect($invitation->fresh()->status)->toBe('expired')
        ->and(User::where('email', 'invited@example.com')->exists())->toBeFalse()
        ->and(SocialAccount::count())->toBe(1)
        ->and(PasswordHistory::count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
});

test('transient database failure rolls back partial state and leaves invitation pending', function () {
    $invitation = Invitation::factory()->pending()->create(['email' => 'retry@example.com']);
    $event = 'eloquent.updating: '.Invitation::class;

    Event::listen($event, function (Invitation $updating): void {
        if ($updating->status !== 'accepted') {
            return;
        }

        $previous = new PDOException('Temporary database infrastructure failure', 1205);
        $previous->errorInfo = ['HY000', 1205, 'Temporary database infrastructure failure'];

        throw new QueryException(
            'mysql',
            'update invitations set status = ?',
            ['accepted'],
            $previous,
        );
    });

    try {
        expect(fn () => app(InvitationService::class)->acceptWithPassword(
            $invitation->token,
            'Retry Person',
            'Str0ng!Password99',
        ))->toThrow(QueryException::class);
    } finally {
        Event::forget($event);
    }

    expect($invitation->fresh()->status)->toBe('pending')
        ->and(User::where('email', 'retry@example.com')->exists())->toBeFalse()
        ->and(SocialAccount::count())->toBe(0)
        ->and(PasswordHistory::count())->toBe(0)
        ->and(DB::table('model_has_roles')->count())->toBe(0);
});

test('accepted user inverse resolves through invitation id', function () {
    $invitation = Invitation::factory()->accepted()->create();
    $user = User::factory()->create(['invitation_id' => $invitation->id]);

    expect($invitation->acceptedUser->is($user))->toBeTrue();
});
