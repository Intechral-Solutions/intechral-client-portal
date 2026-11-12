<?php

/*
 * Release 1.0 (R1-3B): recipient registration through an ORGANIZATION invitation, over HTTP. The foundation's own rules (state, slot,
 * authority, locks, rollback, duplicate keys) are proved in tests/Feature/Crm/OrganizationInvitation*Test.php; this file proves that the
 * routes the pilot already allow-lists (`invitation.show`, `invitation.register`) reach that core, take every fact from the verified
 * invitation, answer every dead link with one page, and that GET never consumes. The retired legacy flow is in LegacyInvitationRetirementTest.
 */

require_once __DIR__.'/InvitationTestHelpers.php';

use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\User;
use App\Notifications\OrganizationInvitationNotification;
use App\Services\AccountLifecycleService;
use App\Services\OrganizationInvitations;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Activitylog\Models\Activity;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Notification::fake();
    $this->seedRolesAndPermissions();
});

describe('the registration page', function () {
    it('renders for an open invitation, names the organization, and exposes nothing but what the recipient was mailed', function () {
        [$invitation, $token] = regInvOpen('invited@example.test');

        $this->get(route('invitation.show', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/invitation-register')
                ->where('invitation.email', 'invited@example.test')
                ->where('invitation.organizationName', 'Acme Holdings')
                ->has('invitation.expiresAt')
                ->where('token', $token)
                ->where('providersAvailable', true)
                ->missing('invitation.id')
                ->missing('invitation.invited_by')
                ->missing('invitation.organization_id')
                ->missing('invitation.token_hash')
                ->missing('invitation.role'));
    });

    it('never puts the invitation hash, an id or the issuer in the page, whatever is looked at', function () {
        [$invitation, $token] = regInvOpen();

        $content = $this->get(route('invitation.show', $token))->assertOk()->getContent();

        expect($content)->not->toContain($invitation->token_hash)
            ->and($content)->not->toContain('token_hash')
            ->and($content)->not->toContain('invited_by');
    });

    it('is a read: any number of GETs consume nothing and write nothing', function () {
        [$invitation, $token] = regInvOpen();
        $before = regInvFootprint();

        foreach (range(1, 3) as $_) {
            $this->get(route('invitation.show', $token))->assertOk();
        }

        // A link preview or a mail scanner that fetches the link (even with a prefetch purpose header) must not burn it.
        $this->withHeaders(['Sec-Purpose' => 'prefetch', 'Purpose' => 'prefetch'])->get(route('invitation.show', $token))->assertOk();

        expect(regInvFootprint())->toEqual($before)
            ->and(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING);
    });

    it('is for guests only: a signed-in user is sent to the dashboard and nothing happens', function () {
        [, $token] = regInvOpen();
        $user = User::factory()->create();
        $before = regInvFootprint();

        $this->actingAs($user)->get(route('invitation.show', $token))->assertRedirect(route('dashboard'));
        $this->actingAs($user)->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        expect(regInvFootprint())->toEqual($before);
    });
});

describe('every dead link is the one invalid page', function () {
    /** @return array<string, Closure(): string> each builds its dead state and returns the plaintext token */
    $deadLinks = [
        'unknown' => fn () => str_repeat('a', 64),
        'malformed (wrong length)' => fn () => 'short',
        'malformed (wrong alphabet)' => fn () => str_repeat('!', 64),
        'a retired legacy token' => fn () => Str::random(64), // the old table is gone: a plaintext token it once held resolves nowhere
        'expired' => fn () => regInvOpen('a@example.test', null, null, ['expires_at' => now()->subMinute()])[1],
        'revoked by an operator' => function () {
            [$invitation, $token] = regInvOpen('b@example.test');
            DB::table('organization_invitations')->where('id', $invitation->id)->update(['revoked_at' => now(), 'revoked_reason' => 'operator', 'live_email' => null]);

            return $token;
        },
        'superseded' => function () {
            [$invitation, $token] = regInvOpen('c@example.test');
            DB::table('organization_invitations')->where('id', $invitation->id)->update(['revoked_at' => now(), 'revoked_reason' => 'superseded', 'live_email' => null]);

            return $token;
        },
        'accepted' => function () {
            [$invitation, $token] = regInvOpen('d@example.test');
            $user = User::factory()->create(['email' => 'someone-else@example.test']);
            DB::table('organization_invitations')->where('id', $invitation->id)->update(['accepted_at' => now(), 'accepted_user_id' => $user->id, 'live_email' => null]);

            return $token;
        },
        'the address already has an account' => function () {
            User::factory()->create(['email' => 'e@example.test']);

            return regInvOpen('e@example.test')[1];
        },
        'the address has a SUSPENDED account' => function () {
            $user = User::factory()->create(['email' => 'f@example.test']);
            $user->forceFill(['disabled_at' => now()])->save();

            return regInvOpen('f@example.test')[1];
        },
        'the issuer is suspended' => function () {
            $issuer = regInvIssuer();
            [, $token] = regInvOpen('g@example.test', null, $issuer);
            $issuer->forceFill(['disabled_at' => now()])->save();

            return $token;
        },
        'the issuer lost membership-management authority' => function () {
            $issuer = regInvIssuer();
            [, $token] = regInvOpen('h@example.test', null, $issuer);
            DB::table('model_has_permissions')->where('model_id', $issuer->id)->where('permission_id', Permission::findByName('crm.manage')->id)->delete();

            return $token;
        },
    ];

    it('answers GET with the same component and the same props, whatever the cause', function (string $name) use ($deadLinks) {
        $token = $deadLinks[$name]();

        $this->get(route('invitation.show', $token))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('auth/invitation-invalid')
                ->missing('token')
                ->missing('invitation')
                ->missing('errors.token'));
    })->with(array_keys($deadLinks));

    it('answers POST by redirecting to that same page and writes nothing', function (string $name) use ($deadLinks) {
        $token = $deadLinks[$name]();
        $before = regInvFootprint();

        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('invitation.show', $token));

        $this->assertGuest();
        expect(regInvFootprint())->toEqual($before);
    })->with(array_keys($deadLinks));
});

describe('password acceptance', function () {
    it('atomically creates the account, the user role, an ordinary unapproved member and an accepted invitation', function () {
        $issuer = regInvIssuer(['name' => 'Ivy Issuer']);
        $organization = Organization::factory()->create(['name' => 'Acme Holdings', 'owner_id' => $issuer->id]);
        [$invitation, $token] = regInvOpen('invited@example.test', $organization, $issuer);

        $this->post(route('invitation.register', $token), regInvForm(['name' => 'New Person']))->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();
        $user = User::where('email', 'invited@example.test')->sole();
        $member = DB::table('organization_members')->where('user_id', $user->id)->sole();
        $row = DB::table('organization_invitations')->find($invitation->id);

        expect($user->name)->toBe('New Person')
            ->and($user->getRoleNames()->all())->toBe(['user'])
            ->and($user->getAllPermissions()->pluck('name')->all())->not->toContain('crm.manage')
            ->and($user->invited_by)->toBe($issuer->id)
            // R1-3 provenance is the invitation row; the legacy link stays empty.
            ->and($user->invitation_id)->toBeNull()
            ->and($user->passwordHistories()->count())->toBe(1)
            ->and(Hash::check(REG_INV_PASSWORD, $user->password))->toBeTrue()
            ->and((int) $member->organization_id)->toBe($organization->id)
            ->and($member->role)->toBe('member')
            ->and($member->admin_approved_at)->toBeNull()
            ->and($member->admin_approved_by)->toBeNull()
            ->and($row->accepted_at)->not->toBeNull()
            ->and((int) $row->accepted_user_id)->toBe($user->id)
            ->and($row->live_email)->toBeNull();

        expect(Activity::where('description', 'organization member added (invitation accepted)')->count())->toBe(1);
    });

    it('takes the address, the organization and the role from the invitation, never from the request', function () {
        $issuer = regInvIssuer();
        $acme = Organization::factory()->create(['name' => 'Acme Holdings', 'owner_id' => $issuer->id]);
        $other = Organization::factory()->create(['name' => 'Other Org', 'owner_id' => $issuer->id]);
        [, $token] = regInvOpen('invited@example.test', $acme, $issuer);

        $this->post(route('invitation.register', $token), regInvForm([
            'email' => 'attacker@example.test',
            'organization_id' => $other->id,
            'organization' => $other->id,
            'role' => 'admin',
            'roles' => ['operator'],
            'admin_approved_at' => now()->toDateTimeString(),
            'invited_by' => User::factory()->create()->id,
        ]))->assertRedirect(route('dashboard'));

        $user = User::where('email', 'invited@example.test')->sole();
        $members = DB::table('organization_members')->where('user_id', $user->id)->get();

        expect(User::where('email', 'attacker@example.test')->exists())->toBeFalse()
            ->and($members)->toHaveCount(1)
            ->and((int) $members->first()->organization_id)->toBe($acme->id)
            ->and($members->first()->role)->toBe('member')
            ->and($members->first()->admin_approved_at)->toBeNull()
            ->and($user->getRoleNames()->all())->toBe(['user'])
            ->and($user->invited_by)->toBe($issuer->id);
    });

    it('can be used once: the second POST of the same link is the dead link', function () {
        [, $token] = regInvOpen('invited@example.test');

        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('dashboard'));
        auth()->logout();
        $afterFirst = regInvFootprint();

        $this->post(route('invitation.register', $token), regInvForm(['name' => 'Second Person']))->assertRedirect(route('invitation.show', $token));
        $this->get(route('invitation.show', $token))->assertInertia(fn (Assert $page) => $page->component('auth/invitation-invalid'));

        $this->assertGuest();
        expect(regInvFootprint())->toEqual($afterFirst);
    });

    it('refuses a weak password back at the invitation page without consuming the invitation', function () {
        [$invitation, $token] = regInvOpen();
        $before = regInvFootprint();

        $this->post(route('invitation.register', $token), ['name' => 'New Person', 'password' => 'weak', 'password_confirmation' => 'weak'])
            // Named, not `back()`: the page sends no Referer (Referrer-Policy: no-referrer) and the session keeps no previous URL.
            ->assertRedirect(route('invitation.show', $token))
            ->assertSessionHasErrors('password');

        $this->post(route('invitation.register', $token), ['password' => REG_INV_PASSWORD, 'password_confirmation' => REG_INV_PASSWORD])
            ->assertRedirect(route('invitation.show', $token))
            ->assertSessionHasErrors('name');

        $this->post(route('invitation.register', $token), regInvForm(['password_confirmation' => 'different']))
            ->assertRedirect(route('invitation.show', $token))
            ->assertSessionHasErrors('password');

        $this->assertGuest();
        expect(regInvFootprint())->toEqual($before)
            ->and(OrganizationInvitation::find($invitation->id)->state())->toBe(OrganizationInvitation::PENDING);

        // ...and the same link still works once the form is right.
        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('dashboard'));
    });

    it('never makes a suspended account usable: an existing suspended account is untouched and nobody is signed in', function () {
        $existing = User::factory()->create(['email' => 'invited@example.test']);
        $existing->forceFill(['disabled_at' => now()])->save();
        $before = DB::table('users')->where('id', $existing->id)->first();
        [, $token] = regInvOpen('invited@example.test');

        $this->post(route('invitation.register', $token), regInvForm(['name' => 'Someone Else']))
            ->assertRedirect(route('invitation.show', $token));

        $this->assertGuest();
        expect(DB::table('users')->where('id', $existing->id)->first())->toEqual($before)
            ->and(User::where('email', 'invited@example.test')->count())->toBe(1)
            ->and(DB::table('organization_members')->count())->toBe(0);
    });

    it('lets a new account sign in with the password it just set, and creates it unsuspended', function () {
        [, $token] = regInvOpen('invited@example.test');
        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('dashboard'));
        $user = User::where('email', 'invited@example.test')->sole();
        auth()->logout();

        expect($user->isSuspended())->toBeFalse();
        $this->post(route('login'), ['email' => 'invited@example.test', 'password' => REG_INV_PASSWORD])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    });

    it('does not accept an invitation whose issuer was suspended after it was issued', function () {
        $issuer = regInvIssuer();
        $admin = regInvIssuer();
        [$invitation, $token] = regInvOpen('invited@example.test', null, $issuer);

        app(AccountLifecycleService::class)->suspend($issuer, $admin, 'test');

        // The suspension revoked the invitation permanently (6.2); even restoring the issuer does not revive it.
        app(AccountLifecycleService::class)->reactivate($issuer, $admin);
        $this->post(route('invitation.register', $token), regInvForm())->assertRedirect(route('invitation.show', $token));

        expect(DB::table('organization_invitations')->find($invitation->id)->revoked_at)->not->toBeNull()
            ->and(User::where('email', 'invited@example.test')->exists())->toBeFalse();
    });
});

describe('the invitation mail reaches this handler', function () {
    it('mails a link that the registration page and the registration POST resolve', function () {
        $operator = regInvIssuer();
        $organization = Organization::factory()->create(['name' => 'Acme Holdings', 'owner_id' => $operator->id]);

        app(OrganizationInvitations::class)->issue($organization, 'Mailed.Person@Example.Test', $operator);

        $url = null;
        Notification::assertSentOnDemand(OrganizationInvitationNotification::class, function ($notification, $channels, AnonymousNotifiable $notifiable) use (&$url) {
            $url = $notification->toMail($notifiable)->actionUrl;

            return $notifiable->routes['mail'] === 'mailed.person@example.test';
        });

        expect($url)->toStartWith(url('/invitation/'));
        $path = parse_url($url, PHP_URL_PATH);

        $this->get($path)->assertInertia(fn (Assert $page) => $page
            ->component('auth/invitation-register')
            ->where('invitation.email', 'mailed.person@example.test')
            ->where('invitation.organizationName', 'Acme Holdings'));

        $this->post($path, regInvForm())->assertRedirect(route('dashboard'));

        $user = User::where('email', 'mailed.person@example.test')->sole();
        expect(DB::table('organization_members')->where('user_id', $user->id)->where('organization_id', $organization->id)->where('role', 'member')->exists())->toBeTrue();
    });
});
