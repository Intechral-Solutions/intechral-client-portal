<?php

/*
 * Release 1.0: `user:reset-two-factor`, the narrow administrative recovery for an account that lost BOTH its authenticator and its recovery codes
 * (App\Console\Commands\ResetTwoFactor, App\Services\TwoFactorReset). It has no HTTP surface, prints and logs no secret, signs the account out
 * everywhere, is audited, and forces re-enrolment before privileged access resumes.
 */

require_once __DIR__.'/../Pilot/PilotTestHelpers.php';

use App\Models\User;
use App\Services\TwoFactorReset;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Laravel\Fortify\Fortify;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Activitylog\Models\Activity;

const RESET_SECRET = 'JBSWY3DPEHPK3PXP';

beforeEach(function () {
    $this->seedRolesAndPermissions();
    config(['operator_mfa.enforced' => true]);
});

function resetEnrolled(User $user, bool $confirmed = true): User
{
    $user->forceFill([
        'two_factor_secret' => Fortify::currentEncrypter()->encrypt(RESET_SECRET),
        'two_factor_recovery_codes' => Fortify::currentEncrypter()->encrypt(json_encode(['recovery-one-SECRET', 'recovery-two-SECRET'])),
        'two_factor_confirmed_at' => $confirmed ? now() : null,
    ])->save();

    return $user->fresh();
}

function resetOperator(): User
{
    return resetEnrolled(pilotOperator(['password' => Hash::make('Password1!'), 'remember_token' => 'remember-before']));
}

function resetSessionRows(User $user, int $count = 2): void
{
    foreach (range(1, $count) as $n) {
        DB::table('sessions')->insert(['id' => "session-{$user->id}-{$n}", 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 'Test', 'payload' => 'x', 'last_activity' => time()]);
    }
}

/** Run the command the way a person would: reason given, email typed back. */
function resetRun($test, User $target, ?string $typed = null, string $reason = 'ticket 1234: lost phone and codes')
{
    return $test->artisan('user:reset-two-factor', ['email' => $target->email, '--reason' => $reason])
        ->expectsQuestion('Type the account email address again to confirm', $typed ?? $target->email);
}

function resetState(User $user): array
{
    $fresh = $user->fresh();

    return [$fresh->two_factor_secret, $fresh->two_factor_recovery_codes, $fresh->two_factor_confirmed_at?->toIso8601String()];
}

describe('what it does', function () {
    it('clears the secret, the recovery codes and the confirmation, and says nothing secret', function () {
        $operator = resetOperator();
        $other = resetEnrolled(pilotOperator());

        resetRun($this, $operator)
            ->expectsOutputToContain('Two-factor authentication was reset for '.$operator->email)
            ->doesntExpectOutputToContain(RESET_SECRET)
            ->doesntExpectOutputToContain('recovery-one-SECRET')
            ->assertSuccessful();

        expect(resetState($operator))->toBe([null, null, null])
            ->and($operator->fresh()->hasConfirmedTwoFactor())->toBeFalse()
            ->and($other->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    });

    it('signs the account out everywhere: sessions, remember-me and reset tokens, and only that account\'s', function () {
        $operator = resetOperator();
        $other = pilotOperator();
        resetSessionRows($operator, 3);
        resetSessionRows($other, 1);
        DB::table('password_reset_tokens')->insert(['email' => $operator->email, 'token' => 'hash', 'created_at' => now()]);

        resetRun($this, $operator)->assertSuccessful();

        expect(DB::table('sessions')->where('user_id', $operator->id)->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $other->id)->count())->toBe(1)
            ->and($operator->fresh()->remember_token)->not->toBe('remember-before')
            ->and(DB::table('password_reset_tokens')->where('email', $operator->email)->count())->toBe(0);
    });

    it('leaves the password, roles and email alone, and sets nothing for anyone', function () {
        $operator = resetOperator();
        $hash = $operator->password;
        $users = User::count();

        resetRun($this, $operator)->assertSuccessful();

        $fresh = $operator->fresh();
        expect($fresh->password)->toBe($hash)->and($fresh->email)->toBe($operator->email)->and($fresh->hasRole('operator'))->toBeTrue()
            ->and(User::count())->toBe($users);
    });

    it('also works for a customer, and records that the account was not an operator', function () {
        $customer = resetEnrolled(pilotCustomer());

        resetRun($this, $customer)->assertSuccessful();

        expect(resetState($customer))->toBe([null, null, null])
            ->and(Activity::where('description', TwoFactorReset::EVENT)->latest('id')->first()->properties['target_was_operator'])->toBeFalse();
    });

    it('clears an enrolment that was started and never confirmed', function () {
        $operator = resetEnrolled(pilotOperator(), confirmed: false);

        resetRun($this, $operator)->assertSuccessful();

        expect(resetState($operator))->toBe([null, null, null]);
    });
});

describe('it is auditable, and the audit holds no secret', function () {
    it('records the account, the reason, the person running it and what was there', function () {
        $operator = resetOperator();
        resetSessionRows($operator, 2);

        resetRun($this, $operator, reason: 'ticket 1234: lost phone and codes')->assertSuccessful();

        $entry = Activity::where('description', TwoFactorReset::EVENT)->sole();
        expect($entry->subject_type)->toBe(User::class)->and($entry->subject_id)->toBe($operator->id)
            ->and($entry->causer_id)->toBeNull()
            ->and($entry->properties->toArray())->toBe([
                'source' => 'user:reset-two-factor',
                'reason' => 'ticket 1234: lost phone and codes',
                'run_by' => $entry->properties['run_by'],
                'was_confirmed' => true,
                'target_was_operator' => true,
                'sessions_revoked' => 2,
            ])
            ->and($entry->properties['run_by'])->toBeString()->not->toBe('');

        $stored = json_encode(Activity::all()->toArray());
        foreach ([RESET_SECRET, 'recovery-one-SECRET', 'recovery-two-SECRET'] as $secret) {
            expect($stored)->not->toContain($secret);
        }
    });

    it('writes nothing to the log channels that contains a secret', function () {
        Log::spy();
        $operator = resetOperator();

        resetRun($this, $operator)->assertSuccessful();

        Log::shouldNotHaveReceived('error');
    });
});

describe('exact target identification', function () {
    it('refuses an unknown address, an empty one and a partial one, and changes nothing', function (string $given) {
        $operator = resetOperator();

        $this->artisan('user:reset-two-factor', ['email' => $given, '--reason' => 'x'])
            ->expectsOutputToContain('No single account has exactly that email address')
            ->assertFailed();

        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue()->and(Activity::where('description', TwoFactorReset::EVENT)->count())->toBe(0);
    })->with(['nobody@example.test', ' ', '%', 'operator', '*']);

    it('does not accept a numeric id, a name, or a case-different spelling of the address', function () {
        $operator = resetOperator();

        foreach ([(string) $operator->id, $operator->name, strtoupper($operator->email)] as $given) {
            $this->artisan('user:reset-two-factor', ['email' => $given, '--reason' => 'x'])->assertFailed();
        }

        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    });

    it('shows what it found before it asks, and changes only the named account', function () {
        $target = resetOperator();
        $bystander = resetOperator();

        $this->artisan('user:reset-two-factor', ['email' => $target->email, '--reason' => 'x'])
            ->expectsOutputToContain($target->email)
            ->expectsQuestion('Type the account email address again to confirm', 'someone-else@example.test')
            ->assertFailed();

        expect($target->fresh()->hasConfirmedTwoFactor())->toBeTrue()->and($bystander->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    });
});

describe('interactive confirmation and production safeguards', function () {
    it('refuses when the typed address does not match exactly, and when it is typed in another case', function (string $typed) {
        $operator = resetOperator();

        resetRun($this, $operator, $typed)
            ->expectsOutputToContain('did not match the account email address exactly')
            ->assertFailed();

        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue()->and(Activity::where('description', TwoFactorReset::EVENT)->count())->toBe(0);
    })->with(fn () => ['', ' ', 'wrong@example.test', 'OPERATOR-CASE@EXAMPLE.TEST']);

    it('is refused under --no-interaction: no flag skips the typed confirmation', function () {
        $operator = resetOperator();

        $this->artisan('user:reset-two-factor', ['email' => $operator->email, '--reason' => 'x', '--force' => true, '--no-interaction' => true])->assertFailed();

        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    });

    it('requires a stated reason', function () {
        $operator = resetOperator();

        $this->artisan('user:reset-two-factor', ['email' => $operator->email, '--no-interaction' => true])->assertFailed();
        $this->artisan('user:reset-two-factor', ['email' => $operator->email])
            ->expectsQuestion('Reason (recorded in the audit log)', '   ')
            ->expectsOutputToContain('A reason is required')
            ->assertFailed();

        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    });

    it('asks for the reason when it was not given on the command line', function () {
        $operator = resetOperator();

        $this->artisan('user:reset-two-factor', ['email' => $operator->email])
            ->expectsQuestion('Reason (recorded in the audit log)', 'asked interactively')
            ->expectsQuestion('Type the account email address again to confirm', $operator->email)
            ->assertSuccessful();

        expect(Activity::where('description', TwoFactorReset::EVENT)->sole()->properties['reason'])->toBe('asked interactively');
    });

    it('in production asks first, and --force skips only that prompt', function () {
        $operator = resetOperator();
        app()->detectEnvironment(fn () => 'production');

        $this->artisan('user:reset-two-factor', ['email' => $operator->email, '--reason' => 'x'])
            ->expectsConfirmation('Are you sure you want to run this command?', 'no')
            ->assertFailed();
        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue();

        // --force only skips the production prompt: the typed confirmation is still demanded.
        $this->artisan('user:reset-two-factor', ['email' => $operator->email, '--reason' => 'x', '--force' => true])
            ->expectsQuestion('Type the account email address again to confirm', 'wrong@example.test')
            ->assertFailed();
        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue();

        $this->artisan('user:reset-two-factor', ['email' => $operator->email, '--reason' => 'x'])
            ->expectsConfirmation('Are you sure you want to run this command?', 'yes')
            ->expectsQuestion('Type the account email address again to confirm', $operator->email)
            ->assertSuccessful();
        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeFalse();
    });

    it('does nothing, and logs nothing, for an account with no second-factor state', function () {
        $operator = pilotOperator();

        $this->artisan('user:reset-two-factor', ['email' => $operator->email, '--reason' => 'x'])
            ->expectsOutputToContain('no two-factor state')
            ->assertSuccessful();

        expect(Activity::where('description', TwoFactorReset::EVENT)->count())->toBe(0);
    });

    it('fails closed, changing nothing, when sessions cannot be revoked', function () {
        $operator = resetOperator();
        config(['session.driver' => 'array']);

        resetRun($this, $operator)->expectsOutputToContain('SESSION_DRIVER=database')->assertFailed();

        expect($operator->fresh()->hasConfirmedTwoFactor())->toBeTrue()->and(Activity::where('description', TwoFactorReset::EVENT)->count())->toBe(0);
    });
});

describe('re-enrolment is required before privileged access resumes', function () {
    it('blocks the operator at once, lets them enrol again, and reopens access only after a confirmed code', function () {
        $operator = resetOperator();
        $this->actingAs($operator)->get(route('dashboard'))->assertOk();

        resetRun($this, $operator)->assertSuccessful();

        Auth::forgetGuards();
        $this->actingAs($operator->fresh())->get(route('dashboard'))->assertRedirect(route('profile.show'));
        $this->actingAs($operator->fresh())->getJson(route('operator.tickets.index'))->assertForbidden();

        $this->actingAs($operator->fresh())->withSession(['auth.password_confirmed_at' => time()])->post(route('two-factor.enable'));
        $secret = Fortify::currentEncrypter()->decrypt($operator->fresh()->two_factor_secret);
        expect($secret)->not->toBe(RESET_SECRET);
        $this->actingAs($operator->fresh())->get(route('dashboard'))->assertRedirect(route('profile.show'));

        $this->actingAs($operator->fresh())->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertSessionHasNoErrors();
        $this->actingAs($operator->fresh())->get(route('dashboard'))->assertOk();
    });

    it('does not let the OLD secret or the OLD recovery codes sign in after a reset', function () {
        $operator = resetOperator();
        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect(route('two-factor.login'));
        resetRun($this, $operator)->assertSuccessful();

        $this->post(route('two-factor.login.store'), ['code' => app(Google2FA::class)->getCurrentOtp(RESET_SECRET)]);
        $this->post(route('two-factor.login.store'), ['recovery_code' => 'recovery-one-SECRET']);
        $this->assertGuest();

        // A fresh password sign-in now succeeds as the first factor only, and is limited to the profile.
        $this->post(route('login.store'), ['email' => $operator->email, 'password' => 'Password1!'])->assertRedirect();
        $this->assertAuthenticatedAs($operator);
        Auth::forgetGuards();
        $this->get(route('dashboard'))->assertRedirect(route('profile.show'));
    });
});

describe('it is not a remote capability', function () {
    it('has no HTTP route, and no route can reset another account\'s second factor', function () {
        foreach (Route::getRoutes() as $route) {
            expect($route->uri().' '.($route->getName() ?? ''))->not->toContain('reset-two-factor');
        }

        $operator = resetOperator();
        $victim = resetEnrolled(pilotCustomer());

        // Fortify's own endpoints act on the signed-in account only; there is no user id to supply.
        $this->actingAs($operator)->withSession(['auth.password_confirmed_at' => time()])
            ->delete(route('two-factor.disable'), ['user_id' => $victim->id, 'id' => $victim->id, 'email' => $victim->email]);

        expect($victim->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    });

    it('is registered as an Artisan command and nothing but a person at a shell runs it', function () {
        expect(array_keys(Artisan::all()))->toContain('user:reset-two-factor');
    });
});
