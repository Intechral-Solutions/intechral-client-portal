<?php

/*
 * Release 1.0 (session and authentication hardening): the per-account session epoch.
 *
 * What is under test, and what is not. The epoch (users.session_epoch, rotated in the SAME UPDATE as a change to the second factor's confirmation)
 * lets the operator boundary refuse a session that was opened under an earlier factor state. It judges a request when it ARRIVES. It does not
 * stop a request already past the gate, and it does not stop a stale request from writing a session row (it only makes that row unprivileged for
 * an operator). The tests below are written to those three claims separately; docs/architecture/operator-mfa.md section 4 records the same split.
 *
 * Self-contained: helpers are prefixed `epoch` so this file does not depend on the load order of the other MFA suites.
 */

require_once __DIR__.'/../Pilot/PilotTestHelpers.php';

use App\Models\User;
use App\Services\TwoFactorReset;
use App\Shared\Mfa\OperatorMfaGate;
use Illuminate\Auth\Events\Attempting;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;

const EPOCH_SECRET = 'JBSWY3DPEHPK3PXP';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    config(['operator_mfa.enforced' => true]);
});

/** @param 'none'|'unconfirmed'|'confirmed' $state */
function epochOperator(string $state = 'confirmed'): User
{
    $operator = pilotOperator(['password' => Hash::make('Password1!')]);

    return $state === 'none' ? $operator : epochEnrol($operator, $state === 'confirmed');
}

/** Store a secret and (optionally) confirm it, exactly as Fortify's confirm action does: one `forceFill()->save()` on the user row. */
function epochEnrol(User $user, bool $confirmed = true): User
{
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt(EPOCH_SECRET),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one'])),
        'two_factor_confirmed_at' => $confirmed ? now() : null,
    ])->save();

    return $user->fresh();
}

/** A request carrying the session stamp a sign-in under `$epoch` would have left, for the account as it stands NOW in the database. */
function epochRequestWithStamp($test, User $user, ?string $epoch)
{
    return $test->actingAs(User::find($user->id))->withSession([OperatorMfaGate::SESSION_KEY => (string) $epoch]);
}

describe('the epoch rotates in the same UPDATE as the confirmation', function () {
    it('rotates the epoch and the remember-me token in the single statement that records a confirmation', function () {
        $user = User::factory()->create();
        $user->forceFill(['remember_token' => 'old-token'])->save();
        $user = $user->fresh();
        $before = $user->session_epoch;

        $updates = [];
        DB::listen(function ($query) use (&$updates) {
            if (str_starts_with(strtolower($query->sql), 'update `users`') || str_starts_with(strtolower($query->sql), 'update "users"')) {
                $updates[] = $query->sql;
            }
        });

        epochEnrol($user);

        expect($updates)->toHaveCount(1)
            ->and($updates[0])->toContain('two_factor_confirmed_at')->toContain('session_epoch')->toContain('remember_token');

        $after = $user->fresh();
        expect($after->session_epoch)->not->toBe($before)->not->toBeEmpty()
            ->and($after->remember_token)->not->toBe('old-token');
    });

    it('rotates the epoch, but not the remember-me token, when a factor is disabled', function () {
        $user = epochEnrol(User::factory()->create());
        $user->forceFill(['remember_token' => 'kept'])->save();
        $epoch = $user->fresh()->session_epoch;

        $user->fresh()->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();

        expect($user->fresh()->session_epoch)->not->toBe($epoch)
            ->and($user->fresh()->remember_token)->toBe('kept');
    });

    it('leaves both alone for an unrelated change and for a save that changes nothing', function () {
        $user = epochEnrol(User::factory()->create());
        $user->forceFill(['remember_token' => 'kept'])->save();
        $fresh = $user->fresh();

        $fresh->forceFill(['name' => 'Someone Else'])->save();
        $fresh->forceFill(['two_factor_confirmed_at' => $fresh->two_factor_confirmed_at])->save();

        expect($user->fresh()->session_epoch)->toBe($fresh->session_epoch)
            ->and($user->fresh()->remember_token)->toBe('kept');
    });

    it('does not rotate when only the stored secret is replaced and the confirmation is untouched (an enrolment in progress)', function () {
        $user = User::factory()->create();
        $user->forceFill(['two_factor_secret' => Fortify::currentEncrypter()->encrypt(EPOCH_SECRET)])->save();

        expect($user->fresh()->session_epoch)->toBeNull();
    });

    it('is never part of the serialised user', function () {
        expect(epochOperator()->toArray())->not->toHaveKey('session_epoch');
    });
});

describe('claim 1: a session opened under an earlier factor state is refused on its next request', function () {
    it('refuses an operator session stamped before the confirmation, with no revocation having run', function () {
        $operator = epochOperator('none');
        $stampedBefore = (string) $operator->session_epoch;

        // Fortify's action saves the confirmation and only THEN dispatches the event our revocation listens to. Here the save has happened and the
        // listener has not: this is the instant the old design left an existing session privileged.
        epochEnrol($operator);

        epochRequestWithStamp($this, $operator, $stampedBefore)->get(route('dashboard'))->assertRedirect(route('login'));
    });

    it('signs the stale session out, and tells a JSON caller with 401 and a code', function () {
        $operator = epochEnrol(epochOperator('none'));

        epochRequestWithStamp($this, $operator, 'an-earlier-epoch')->getJson(route('operator.tickets.index'))
            ->assertStatus(401)
            ->assertJsonPath('code', OperatorMfaGate::STALE_CODE);
        $this->assertGuest();
    });

    it('does NOT hand the stale session the profile: the endpoints that change the factor stay out of its reach', function () {
        $operator = epochOperator();
        $before = $operator->two_factor_confirmed_at;

        epochRequestWithStamp($this, $operator, 'an-earlier-epoch')->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('two-factor.disable'))->assertRedirect(route('login'));

        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue()
            ->and($operator->fresh()->two_factor_confirmed_at->equalTo($before))->toBeTrue();
    });

    it('leaves a privileged mutation undone', function () {
        $operator = epochOperator();
        $target = User::factory()->create();

        epochRequestWithStamp($this, $operator, 'an-earlier-epoch')
            ->postJson(route('users.suspend', $target->id), ['reason' => 'x'])->assertStatus(401);

        expect($target->fresh()->isSuspended())->toBeFalse();
    });

    it('ends only this device: the remember-me token is not rotated by the refusal', function () {
        $operator = epochOperator();
        $operator->forceFill(['remember_token' => 'other-device'])->save();

        epochRequestWithStamp($this, $operator, 'an-earlier-epoch')->get(route('dashboard'));

        expect($operator->fresh()->remember_token)->toBe('other-device');
    });

    it('lets the person sign in again at once (the redirect target is not itself refused)', function () {
        $operator = epochOperator();

        epochRequestWithStamp($this, $operator, 'an-earlier-epoch')->get(route('dashboard'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk();
    });

    it('passes a session stamped with the current epoch', function () {
        $operator = epochOperator();

        epochRequestWithStamp($this, $operator, $operator->session_epoch)->get(route('dashboard'))->assertOk();
    });

    it('lets an account that never changed its factor through with an unstamped session (the rollout case)', function () {
        $operator = epochOperator();
        $operator->forceFill(['session_epoch' => null])->save();

        $this->actingAs(User::find($operator->id))->flushSession()->get(route('dashboard'))->assertOk();
    });

    it('refuses an UNSTAMPED session once the account has an epoch', function () {
        $operator = epochOperator();

        $this->actingAs(User::find($operator->id))->flushSession()->get(route('dashboard'))->assertRedirect(route('login'));
    });

    it('never judges a customer or a guest', function () {
        $customer = pilotCustomer();
        epochEnrol($customer);

        epochRequestWithStamp($this, $customer, 'an-earlier-epoch')->get(route('dashboard'))->assertOk();
        $this->flushSession();
        auth()->forgetGuards();
        $this->get(route('dashboard'))->assertRedirect(route('login'));
    });

    it('judges a customer who is promoted afterwards by the epoch they hold, so a pre-enrolment session cannot become privileged', function () {
        $customer = pilotCustomer(['password' => Hash::make('Password1!')]);
        $stamp = (string) $customer->session_epoch;
        epochEnrol($customer);
        $customer->fresh()->assignRole('operator');

        epochRequestWithStamp($this, $customer, $stamp)->get(route('dashboard'))->assertRedirect(route('login'));
        epochRequestWithStamp($this, $customer, User::find($customer->id)->session_epoch)->get(route('dashboard'))->assertOk();
    });

    it('is off only under APP_ENV=testing: in any other environment the testing switch is ignored', function () {
        $operator = epochOperator();
        config(['operator_mfa.enforced' => false]);

        epochRequestWithStamp($this, $operator, 'an-earlier-epoch')->get(route('dashboard'))->assertOk();

        app()->detectEnvironment(fn () => 'production');
        epochRequestWithStamp($this, $operator, 'an-earlier-epoch')->get(route('dashboard'))->assertRedirect(route('login'));
    });

    it('applies after a reset and re-enrolment too: a session from before the reset stays refused', function () {
        $operator = epochOperator();
        $stamp = (string) $operator->session_epoch;

        app(TwoFactorReset::class)->reset($operator->fresh(), 'ticket 1: lost device', 'tester');
        epochEnrol($operator->fresh());

        epochRequestWithStamp($this, $operator, $stamp)->get(route('dashboard'))->assertRedirect(route('login'));
    });
});

describe('every sign-in stamps the session with the epoch it saw', function () {
    it('stamps a password sign-in', function () {
        $operator = epochOperator('none');

        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect();

        expect(session(OperatorMfaGate::SESSION_KEY))->toBe('');
    });

    it('stamps the sign-in that completes a second-factor challenge, with the epoch of the confirmed factor', function () {
        $operator = epochOperator();

        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect(route('two-factor.login'));
        $this->post(route('two-factor.login.store'), ['code' => app(Google2FA::class)->getCurrentOtp(EPOCH_SECRET)])->assertRedirect();

        expect(session(OperatorMfaGate::SESSION_KEY))->toBe($operator->session_epoch);
        Auth::forgetGuards();
        $this->get(route('dashboard'))->assertOk();
    });

    it('stamps a remember-me login, which is the path a stale cookie takes', function () {
        $operator = epochOperator('none');
        $recaller = $operator->id.'|remembered-token|'.$operator->getAuthPassword();
        $operator->forceFill(['remember_token' => 'remembered-token'])->save();

        $this->withCookie(Auth::guard()->getRecallerName(), $recaller)->get(route('profile.show'))->assertOk();

        expect(session(OperatorMfaGate::SESSION_KEY))->toBe('');
    });

    it('re-stamps the session that confirms a factor, so it continues while every earlier one is stale', function () {
        $operator = epochOperator('unconfirmed');
        $operator->forceFill(['two_factor_confirmed_at' => null])->save();
        $stale = (string) $operator->fresh()->session_epoch;

        $this->actingAs($operator->fresh())->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp(EPOCH_SECRET)])
            ->assertRedirect();

        $fresh = User::find($operator->id);
        expect($fresh->hasConfirmedTwoFactor())->toBeTrue()
            ->and($fresh->session_epoch)->not->toBe($stale)
            ->and(session(OperatorMfaGate::SESSION_KEY))->toBe($fresh->session_epoch);
    });
});

describe('claim 2: a stale in-flight request cannot restore a PRIVILEGED session', function () {
    it('leaves a sign-in that loaded the user before the confirmation and finished after it unprivileged', function () {
        $operator = epochOperator('none');
        $heldByTheInFlightRequest = User::find($operator->id);

        // The owner confirms while the other request is part-way through signing in. That request holds the user row it loaded earlier.
        epochEnrol(User::find($operator->id));

        Route::middleware('web')->get('/__in-flight-sign-in', function () use ($heldByTheInFlightRequest) {
            Auth::login($heldByTheInFlightRequest);

            return 'signed in';
        });
        $this->get('/__in-flight-sign-in')->assertOk();
        $stampTheNewSessionCarries = session(OperatorMfaGate::SESSION_KEY);

        expect($stampTheNewSessionCarries)->not->toBe(User::find($operator->id)->session_epoch);

        Auth::forgetGuards();
        epochRequestWithStamp($this, $operator, $stampTheNewSessionCarries)->getJson(route('operator.tickets.index'))->assertStatus(401);
    });

    it('invalidates a remember-me cookie at the instant of confirmation, with no revocation having run', function () {
        $operator = epochOperator('none');
        $operator->forceFill(['remember_token' => 'remembered-token'])->save();
        $recaller = $operator->id.'|remembered-token|'.$operator->fresh()->getAuthPassword();
        $cookie = Auth::guard()->getRecallerName();

        // Control: before the confirmation the cookie signs the browser back in.
        $this->withCookie($cookie, $recaller)->get(route('profile.show'))->assertOk();
        $this->flushSession();
        Auth::forgetGuards();

        epochEnrol(User::find($operator->id));

        $this->withCookie($cookie, $recaller)->get(route('profile.show'))->assertRedirect(route('login'));
    });
});

describe('a password sign-in that straddles a confirmation (review B1)', function () {
    it('stamps the epoch the first load saw, not the second: Fortify loads the user twice and the confirmation can land between', function () {
        $operator = epochOperator('none');
        $before = (string) $operator->session_epoch;
        $landed = false;

        // `attempt()` fires Attempting AFTER the step that decided "no challenge" and BEFORE it loads the user again: the instant the owner's confirmation commits.
        Event::listen(Attempting::class, function () use ($operator, &$landed) {
            if (! $landed) {
                $landed = true;
                epochEnrol(User::find($operator->id));
            }
        });

        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect();

        expect($landed)->toBeTrue()
            ->and(User::find($operator->id)->hasConfirmedTwoFactor())->toBeTrue()
            ->and(session(OperatorMfaGate::SESSION_KEY))->toBe($before)->not->toBe(User::find($operator->id)->session_epoch)
            ->and(session()->has(OperatorMfaGate::PENDING_KEY))->toBeFalse();

        Auth::forgetGuards();
        epochRequestWithStamp($this, $operator, session(OperatorMfaGate::SESSION_KEY))->getJson(route('operator.tickets.index'))->assertStatus(401);
    });

    it('stamps the current epoch when nothing intervenes', function () {
        $operator = epochOperator('none');
        DB::table('users')->where('id', $operator->id)->update(['session_epoch' => 'current-epoch']);

        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect();

        expect(session(OperatorMfaGate::SESSION_KEY))->toBe('current-epoch');
    });

    it('does not let a recorded epoch leak to another account\'s sign-in', function () {
        $operator = epochOperator('none');
        $other = epochOperator('none');
        DB::table('users')->where('id', $other->id)->update(['session_epoch' => 'others-epoch']);
        session([OperatorMfaGate::PENDING_KEY => ['id' => $operator->id, 'epoch' => 'not-for-you']]);

        $this->post(route('login.store'), ['email' => $other->email, 'password' => 'Password1!'])->assertRedirect();

        expect(session(OperatorMfaGate::SESSION_KEY))->toBe('others-epoch');
    });
});

describe('a stale sign-in cannot mint a remember-me token (review B2)', function () {
    it('writes nothing when the account\'s epoch moved after the sign-in loaded it, and the cookie it queued matches no stored token', function () {
        $operator = epochOperator('none');
        DB::table('users')->where('id', $operator->id)->update(['remember_token' => null]);
        $stale = User::find($operator->id);

        epochEnrol(User::find($operator->id));
        $stored = User::find($operator->id)->remember_token;
        expect($stored)->not->toBeEmpty();

        Route::middleware('web')->get('/__stale-remember-login', function () use ($stale) {
            Auth::login($stale, remember: true);

            return 'signed in';
        });
        $this->get('/__stale-remember-login')->assertOk();

        expect(User::find($operator->id)->remember_token)->toBe($stored)->not->toBe($stale->getRememberToken());

        // The cookie that sign-in queued carries the token it generated; presenting it must not log anyone in.
        Auth::forgetGuards();
        $this->flushSession();
        $this->withCookie(Auth::guard()->getRecallerName(), $operator->id.'|'.$stale->getRememberToken().'|'.$stale->getAuthPassword())
            ->get(route('profile.show'))->assertRedirect(route('login'));
    });

    it('still stores a remember-me token for an ordinary sign-in of an account that has none', function () {
        $operator = epochOperator('none');
        DB::table('users')->where('id', $operator->id)->update(['remember_token' => null]);
        $fresh = User::find($operator->id);

        Route::middleware('web')->get('/__fresh-remember-login', function () use ($fresh) {
            Auth::login($fresh, remember: true);

            return 'signed in';
        });
        $this->get('/__fresh-remember-login')->assertOk();

        expect(User::find($operator->id)->remember_token)->not->toBeEmpty()->toBe($fresh->getRememberToken());
    });

    it('still cycles the token on logout (the guard\'s other use of the write)', function () {
        $operator = epochOperator('none');
        $operator->forceFill(['remember_token' => 'before-logout'])->save();

        $this->actingAs(User::find($operator->id))->post(route('logout'))->assertRedirect();

        expect(User::find($operator->id)->remember_token)->not->toBe('before-logout')->not->toBeEmpty();
    });
});

describe('an operator who disables their own factor', function () {
    it('is signed out on the next request (fails closed; they sign in again)', function () {
        $operator = epochOperator();
        $stamp = (string) $operator->session_epoch;

        $this->actingAs(User::find($operator->id))->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('two-factor.disable'))->assertRedirect();
        Auth::forgetGuards();

        expect(User::find($operator->id)->hasConfirmedTwoFactor())->toBeFalse()
            ->and(User::find($operator->id)->session_epoch)->not->toBe($stamp);
        epochRequestWithStamp($this, $operator, $stamp)->get(route('profile.show'))->assertRedirect(route('login'));
    });
});

describe('claim 3 is not made: what the epoch cannot do, pinned so nobody assumes it does', function () {
    it('still lets a request that was admitted before the rotation run to completion', function () {
        $operator = epochOperator();
        $admitted = false;
        Route::middleware(['web', 'auth'])->get('/__long-privileged-probe', function () use ($operator, &$admitted) {
            // The gate already ran for this request. Rotating the epoch now changes what the NEXT request sees, not this one.
            epochEnrol(User::find($operator->id)->forceFill(['two_factor_confirmed_at' => null]));
            $admitted = true;

            return 'done';
        })->name('long.probe');

        epochRequestWithStamp($this, $operator, $operator->session_epoch)->get('/__long-privileged-probe')->assertOk();

        expect($admitted)->toBeTrue();
    });
});

describe('what the session driver does with a stale in-flight request (the framework behaviour the design relies on)', function () {
    function epochHandler(): DatabaseSessionHandler
    {
        return new DatabaseSessionHandler(DB::connection(), 'sessions', 120, app());
    }

    function epochSessionRow(string $id, ?int $userId = null): void
    {
        DB::table('sessions')->insert(['id' => $id, 'user_id' => $userId, 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => 'x', 'last_activity' => time()]);
    }

    it('cannot recreate a session row from a request that already read it: its write is an UPDATE that matches nothing', function () {
        epochSessionRow('existing-session');
        $handler = epochHandler();
        $handler->read('existing-session');

        DB::table('sessions')->where('id', 'existing-session')->delete();
        $handler->write('existing-session', 'payload-written-by-the-stale-request');

        expect(DB::table('sessions')->where('id', 'existing-session')->exists())->toBeFalse();
    });

    it('DOES create a row for a request that began with no row (a sign-in completing across a revocation): the epoch, not the driver, makes it unprivileged', function () {
        $handler = epochHandler();
        $handler->read('new-session');
        $handler->write('new-session', 'a login completed across the revocation');

        expect(DB::table('sessions')->where('id', 'new-session')->exists())->toBeTrue();
    });
});
