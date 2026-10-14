<?php

/*
 * R0.9-CS1: the customer surface as a whole. The Helpdesk and the account essentials still work, the shared
 * Inertia props and the navigation carry nothing about excluded modules, SSO is deferred for customers and not
 * for operators, and account suspension is still enforced in pilot mode.
 */

use App\Exceptions\LastAccountAdministratorException;
use App\Models\Invitation;
use App\Models\SocialAccount;
use App\Models\Ticket;
use App\Models\User;
use App\Services\AccountLifecycleService;
use App\Shared\Permissions\PermissionCatalogue;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\User as SocialiteUser;

require_once __DIR__.'/PilotTestHelpers.php';
require_once __DIR__.'/../Tickets/TicketTestHelpers.php';

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

// ── Helpdesk still works ──────────────────────────────────────────────────────

it('keeps the customer Helpdesk working end to end in pilot mode', function () {
    Notification::fake();
    Storage::fake('local');
    $customer = pilotCustomer();
    pilotMode('pilot');

    $this->actingAs($customer)->get(route('tickets.index'))->assertOk();
    $this->actingAs($customer)->get(route('tickets.create'))->assertOk();

    $created = $this->actingAs($customer)->post(route('tickets.store'), [
        'title' => 'Printer on fire', 'description' => 'It is', 'category' => 'General', 'priority' => 'low',
    ]);
    $ticket = Ticket::where('title', 'Printer on fire')->firstOrFail();
    $created->assertRedirect(route('tickets.show', $ticket));

    $this->actingAs($customer)->get(route('tickets.show', $ticket))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('tickets/show'));

    $this->actingAs($customer)->post(route('tickets.replies.store', $ticket), ['body' => 'More detail'])->assertRedirect();
    expect($ticket->replies()->where('body', 'More detail')->exists())->toBeTrue();
});

it('keeps the Helpdesk visibility rules: another customer’s ticket and internal attachments stay closed', function () {
    Notification::fake();
    Storage::fake('local');
    $owner = pilotCustomer();
    $stranger = pilotCustomer();
    $operator = pilotOperator();
    $ticket = ticketFor($owner);
    $own = ticketAttachment($ticket, null, $owner, 'mine.txt', 'my bytes');
    $internalReply = ticketReply($ticket, $operator, true, 'internal note');
    $internal = ticketAttachment($ticket, $internalReply, $operator, 'internal.txt', 'operator-only bytes');
    pilotMode('pilot');

    $this->actingAs($owner)->get(route('tickets.attachment.download', $own))->assertOk();
    $this->actingAs($owner)->get(route('tickets.attachment.download', $internal))->assertForbidden();
    $this->actingAs($stranger)->get(route('tickets.attachment.download', $own))->assertForbidden();
    $this->actingAs($stranger)->get(route('tickets.show', $ticket))->assertForbidden();
    $this->actingAs($stranger)->post(route('tickets.replies.store', $ticket), ['body' => 'intrusion'])->assertForbidden();

    expect($ticket->replies()->where('body', 'intrusion')->exists())->toBeFalse();

    // The operator reply route (a different route name) is not a customer route, whoever owns the ticket.
    $this->actingAs($owner)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'x'])->assertNotFound();
});

it('keeps operator Helpdesk work, including internal attachments, in pilot mode', function () {
    Notification::fake();
    Storage::fake('local');
    $owner = pilotCustomer();
    $operator = pilotOperator();
    $ticket = ticketFor($owner);
    $internal = ticketAttachment($ticket, ticketReply($ticket, $operator, true), $operator, 'internal.txt', 'operator-only bytes');
    pilotMode('pilot');

    $this->actingAs($operator)->get(route('operator.tickets.index'))->assertOk();
    $this->actingAs($operator)->get(route('operator.tickets.show', $ticket))->assertOk();
    $this->actingAs($operator)->get(route('tickets.attachment.download', $internal))->assertOk();
    $this->actingAs($operator)->post(route('operator.tickets.replies.store', $ticket), ['body' => 'Working on it'])->assertRedirect();
});

// ── Dashboard, shared props, navigation ───────────────────────────────────────

it('sends the customer Home without the invoices section, and without invoice wording', function () {
    $customer = pilotCustomer();
    pilotInvoiceFor($customer);
    pilotMode('pilot');

    $response = $this->actingAs($customer)->get(route('dashboard'));

    $response->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('dashboard/customer-home')
        ->where('invoices', null)
        ->has('projects'));
    expect($response->getContent())->not->toContain('/my/invoices');

    // In legacy mode the same customer still gets the section (the pre-pilot behaviour).
    pilotMode('legacy');
    $this->actingAs($customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->where('invoices.href', route('billing.client.invoices.index')));
});

it('offers a customer Home, Support and Projects only, in the Focused shell', function () {
    $customer = pilotCustomer();
    pilotMode('pilot');

    $this->actingAs($customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('shell.presentation', 'focused')
        ->where('navigation.workspaces', fn ($workspaces) => collect($workspaces)->pluck('key')->all() === ['home', 'helpdesk', 'projects']
            && collect($workspaces)->pluck('label')->all() === ['Home', 'Support', 'Projects']));

    // Legacy: the pre-pilot Focused shell, with Invoices and Resources.
    pilotMode('legacy');
    $this->actingAs($customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('navigation.workspaces', fn ($workspaces) => collect($workspaces)->pluck('key')->all() === ['home', 'helpdesk', 'projects', 'finance', 'resources']));
});

it('offers an operator the unchanged navigation in pilot mode', function () {
    pilotMode('legacy');
    $before = $this->actingAs(pilotOperator())->get(route('dashboard'))->viewData('page')['props']['navigation'];

    pilotMode('pilot');
    $after = $this->actingAs(pilotOperator())->get(route('dashboard'))->viewData('page')['props']['navigation'];

    expect(collect($after['workspaces'])->pluck('key')->all())->toBe(collect($before['workspaces'])->pluck('key')->all())
        ->and(collect($after['workspaces'])->pluck('key')->all())->toContain('tasks', 'time', 'directory', 'finance', 'system');
});

it('tells the browser only the capabilities the pilot surface uses', function () {
    $customer = pilotCustomer();
    pilotMode('pilot');

    $this->actingAs($customer)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.permissions', ['projects.view', 'tickets.create', 'tickets.view']));

    $this->actingAs($customer)->get(route('projects.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.permissions', ['projects.view', 'tickets.create', 'tickets.view']));

    // An operator is told everything, as before.
    $this->actingAs(pilotOperator())->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.permissions', fn ($permissions) => count($permissions) === count(PermissionCatalogue::all())));
});

it('renders the Blade error page for a refused module without a link into any excluded module', function () {
    $customer = pilotCustomer();
    pilotMode('pilot');

    $body = $this->actingAs($customer)->get('/my/invoices')->assertNotFound()->getContent();

    foreach (['/my/invoices', '/time', '/tasks', '/pages', '/billing', 'Invoices', 'Resources'] as $needle) {
        expect($body)->not->toContain($needle, "the refusal page links to [{$needle}]");
    }
});

// ── Account essentials ────────────────────────────────────────────────────────

it('keeps profile, password confirmation, two-factor and sign out reachable for a customer', function () {
    $customer = pilotCustomer(['password' => bcrypt('Password1!')]);
    pilotMode('pilot');

    $this->actingAs($customer)->get(route('profile.show'))->assertOk();
    $this->actingAs($customer)->get(route('password.confirm'))->assertOk();
    $this->actingAs($customer)->post(route('logout'))->assertRedirect();
    $this->assertGuest();
});

it('turns a signed-in customer away from the login page the usual way', function () {
    $customer = pilotCustomer();
    pilotMode('pilot');

    $this->actingAs($customer)->get('/login')->assertRedirect('/dashboard');
});

it('still lets a customer sign in with a password and reach the Home', function () {
    $customer = pilotCustomer(['password' => bcrypt('Password1!')]);
    pilotMode('pilot');

    $this->post('/login', ['email' => $customer->email, 'password' => 'Password1!'])->assertRedirect('/dashboard');
    $this->get('/dashboard')->assertOk();
});

// ── SSO is deferred for customers, not for operators ──────────────────────────

function pilotSocialUser(array $attributes = []): SocialiteUser
{
    return SocialiteUser::fake(['id' => 'pilot-provider-1', 'name' => 'Provider Person', 'email' => 'provider@example.com', ...$attributes]);
}

function pilotOauthContext(string $intent, array $target = []): array
{
    return ['intent' => $intent, 'provider' => 'google', 'initiatingUserId' => null, 'invitationToken' => null, 'startedAt' => now()->toIso8601String(), ...$target];
}

it('refuses SSO sign-in for a customer with the unlinked-identity answer, and signs an operator in', function () {
    Socialite::fake('google', pilotSocialUser());
    $customer = pilotCustomer();
    SocialAccount::create(['user_id' => $customer->id, 'provider' => 'google', 'provider_id' => 'pilot-provider-1']);
    pilotMode('pilot');

    $this->withSession(['oauth_context' => pilotOauthContext('login')])
        ->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'That provider account is not linked to a portal account.']);
    $this->assertGuest();

    // The same identity on an operator account is unaffected.
    SocialAccount::query()->delete();
    $operator = pilotOperator();
    SocialAccount::create(['user_id' => $operator->id, 'provider' => 'google', 'provider_id' => 'pilot-provider-1']);

    $this->withSession(['oauth_context' => pilotOauthContext('login')])
        ->get(route('sso.callback', 'google'))
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($operator);
});

it('signs a customer in with SSO in legacy mode (the proof that the mode is what refuses it)', function () {
    Socialite::fake('google', pilotSocialUser());
    $customer = pilotCustomer();
    SocialAccount::create(['user_id' => $customer->id, 'provider' => 'google', 'provider_id' => 'pilot-provider-1']);
    pilotMode('legacy');

    $this->withSession(['oauth_context' => pilotOauthContext('login')])
        ->get(route('sso.callback', 'google'))
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($customer);
});

it('refuses SSO invitation acceptance in pilot mode, creating no account and keeping the invitation', function () {
    Socialite::fake('google', pilotSocialUser(['email' => 'invited@example.com']));
    $invitation = Invitation::factory()->pending()->create(['email' => 'invited@example.com']);
    pilotMode('pilot');

    $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $invitation->token]))
        ->assertRedirect(route('invitation.show', $invitation->token))
        ->assertSessionHasErrors('provider');

    $this->withSession(['oauth_context' => pilotOauthContext('invitation', ['invitationToken' => $invitation->token])])
        ->get(route('sso.callback', 'google'))
        ->assertRedirect(route('invitation.show', $invitation->token))
        ->assertSessionHasErrors('provider');

    $this->assertGuest();
    expect(User::where('email', 'invited@example.com')->exists())->toBeFalse()
        ->and($invitation->fresh()->status)->toBe('pending');
});

it('offers the invitation page the password form only in pilot mode', function () {
    $invitation = Invitation::factory()->pending()->create();

    pilotMode('pilot');
    $this->get(route('invitation.show', $invitation->token))->assertInertia(fn (Assert $page) => $page->where('providersAvailable', false));

    pilotMode('legacy');
    $this->get(route('invitation.show', $invitation->token))->assertInertia(fn (Assert $page) => $page->where('providersAvailable', true));
});

it('still accepts a password invitation in pilot mode', function () {
    Notification::fake();
    $invitation = Invitation::factory()->pending()->create(['email' => 'newcustomer@example.com']);
    pilotMode('pilot');

    $this->post(route('invitation.register', $invitation->token), [
        'name' => 'New Customer', 'password' => 'Str0ng!Passw0rd-xyz', 'password_confirmation' => 'Str0ng!Passw0rd-xyz',
    ])->assertRedirect(route('dashboard'));

    expect(User::where('email', 'newcustomer@example.com')->firstOrFail()->hasRole('user'))->toBeTrue();
});

it('refuses SSO linking for a signed-in customer and offers no provider on the profile', function () {
    Socialite::fake('google', pilotSocialUser());
    $customer = pilotCustomer();
    pilotMode('pilot');

    $this->actingAs($customer)->get(route('sso.redirect', 'google'))->assertNotFound();
    $this->actingAs($customer)->withSession(['oauth_context' => pilotOauthContext('link', ['initiatingUserId' => $customer->id])])
        ->get(route('sso.callback', 'google'))->assertNotFound();
    expect(SocialAccount::count())->toBe(0);

    $this->actingAs($customer)->get(route('profile.show'))->assertInertia(fn (Assert $page) => $page->where('connectedAccounts', []));
});

it('lets a customer who already has a connected provider disconnect it', function () {
    $customer = pilotCustomer(['password' => bcrypt('Password1!')]);
    SocialAccount::create(['user_id' => $customer->id, 'provider' => 'google', 'provider_id' => 'pilot-provider-1']);
    pilotMode('pilot');

    $this->actingAs($customer)->get(route('profile.show'))->assertInertia(fn (Assert $page) => $page
        ->where('connectedAccounts', [['provider' => 'google', 'connected' => true]]));

    $this->actingAs($customer)->delete(route('profile.social.unlink', 'google'))->assertRedirect();
    expect(SocialAccount::count())->toBe(0);
});

it('keeps an operator able to link and see every provider in pilot mode', function () {
    $operator = pilotOperator();
    pilotMode('pilot');

    $this->actingAs($operator)->get(route('profile.show'))->assertInertia(fn (Assert $page) => $page
        ->where('connectedAccounts', [['provider' => 'google', 'connected' => false], ['provider' => 'microsoft', 'connected' => false]]));
    Socialite::fake('google', pilotSocialUser());
    $this->actingAs($operator)->get(route('sso.redirect', 'google'))->assertRedirect();
});

// ── Suspension and administrator safeguards are untouched ─────────────────────

it('still refuses a suspended customer, in pilot mode', function () {
    $customer = pilotCustomer(['password' => bcrypt('Password1!')]);
    $operator = pilotOperator();
    pilotMode('pilot');

    app(AccountLifecycleService::class)->suspend($customer, $operator, 'Pilot test');

    $this->post('/login', ['email' => $customer->email, 'password' => 'Password1!'])->assertSessionHasErrors('email');
    $this->assertGuest();
    $this->get('/dashboard')->assertRedirect('/login');
});

it('still refuses to demote the last active administrator, in pilot mode', function () {
    $last = pilotOperator();
    pilotMode('pilot');

    $this->actingAs($last)
        ->putJson(route('users.roles.update', $last), ['roles' => []])
        ->assertStatus(409)
        ->assertJson(['message' => LastAccountAdministratorException::MESSAGE]);

    expect($last->fresh()->hasRole('operator'))->toBeTrue();
});

// ── The empty invitation token (R0.9-CS1 F-5) ─────────────────────────────────

it('answers a missing, empty, repeated or unknown SSO invitation token with the ordinary unavailable response, in every mode', function (string $mode) {
    Socialite::fake('google', pilotSocialUser());
    pilotMode($mode);

    foreach (['?invitation=' => 'login', '?invitation[]=x' => 'login', '?invitation[a]=' => 'login', '?invitation=nonexistent-token' => 'login'] as $query => $target) {
        $this->get('/auth/google/redirect'.$query)
            ->assertRedirect(route($target))
            ->assertSessionHasErrors(['email' => 'This invitation is no longer available.']);

        $this->assertGuest();
        expect(session('oauth_context'))->toBeNull($query);
    }

    // No invitation at all is the plain sign-in redirect, unchanged.
    $this->get('/auth/google/redirect')->assertRedirect();
    expect(session('oauth_context.intent'))->toBe('login');
})->with(['closed', 'pilot', 'legacy']);

it('leaves an unrelated, expired or accepted invitation unusable through SSO, creating nothing', function () {
    Socialite::fake('google', pilotSocialUser(['email' => 'late@example.com']));
    pilotMode('pilot');
    $accepted = Invitation::factory()->create(['email' => 'late@example.com', 'status' => 'accepted']);
    $expired = Invitation::factory()->create(['email' => 'late2@example.com', 'status' => 'expired']);

    foreach ([$accepted, $expired] as $invitation) {
        $this->get(route('sso.redirect', ['provider' => 'google', 'invitation' => $invitation->token]))
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');
    }

    $this->assertGuest();
    expect(User::where('email', 'like', 'late%')->exists())->toBeFalse();
});

it('keeps the operator’s SSO sign-in working when the invitation token is empty elsewhere', function () {
    Socialite::fake('google', pilotSocialUser());
    $operator = pilotOperator();
    SocialAccount::create(['user_id' => $operator->id, 'provider' => 'google', 'provider_id' => 'pilot-provider-1']);
    pilotMode('pilot');

    $this->get('/auth/google/redirect')->assertRedirect();
    $this->withSession(['oauth_context' => pilotOauthContext('login')])
        ->get(route('sso.callback', 'google'))
        ->assertRedirect(route('dashboard'));
    $this->assertAuthenticatedAs($operator);
});

it('refuses SSO for a closed-mode customer exactly as in pilot mode', function () {
    Socialite::fake('google', pilotSocialUser());
    $customer = pilotCustomer();
    SocialAccount::create(['user_id' => $customer->id, 'provider' => 'google', 'provider_id' => 'pilot-provider-1']);
    pilotMode('closed');

    $this->withSession(['oauth_context' => pilotOauthContext('login')])
        ->get(route('sso.callback', 'google'))
        ->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'That provider account is not linked to a portal account.']);
    $this->assertGuest();
});
