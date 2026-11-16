<?php

/*
 * Release 1.0 (session and authentication hardening): the limit on password guesses at Fortify's confirmation endpoint (POST /user/confirm-password).
 *
 * App\Auth\PasswordConfirmationThrottle (5 a minute and 20 an hour, per ACCOUNT) applied by App\Http\Middleware\ThrottlePasswordConfirmation to that one
 * route. It must keep Fortify's normal flow working, must not become an account lockout, and must not spread to other routes.
 */

require_once __DIR__.'/../Pilot/PilotTestHelpers.php';

use App\Auth\PasswordConfirmationThrottle;
use App\Models\User;
use App\Shared\Mfa\OperatorMfaGate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ViewErrorBag;
use PragmaRX\Google2FA\Google2FA;

beforeEach(function () {
    $this->seedRolesAndPermissions();
});

function throttleUser(array $attributes = []): User
{
    return User::factory()->create(['password' => Hash::make('Password1!')] + $attributes);
}

/** One attempt as a signed-in browser form: Fortify's confirmation endpoint. */
function confirmAttempt($test, User $user, string $password = 'wrong-password')
{
    return $test->actingAs($user)->from(route('password.confirm'))->post(route('password.confirm.store'), ['password' => $password]);
}

/** The refusal message of the last response for an operation, whether the session hands errors back as a message bag or as its serialised form. */
function throttleMessage(string $operation = 'confirm'): string
{
    [$bag, $field] = match ($operation) {
        'change' => ['updatePassword', 'current_password'],
        'sessions' => ['destroySessions', 'password'],
        default => ['default', 'password'],
    };
    $errors = session('errors');

    return (string) ($errors instanceof ViewErrorBag
        ? $errors->getBag($bag)->first($field)
        : ($errors[$bag]['messages'][$field][0] ?? ''));
}

function guessesUntilLimited($test, User $user, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        confirmAttempt($test, $user)->assertSessionHasErrors('password');
    }
}

describe('the limit', function () {
    it('lets ordinary use through: a few wrong passwords get Fortify\'s normal failure, and the right one then confirms', function () {
        $user = throttleUser();

        confirmAttempt($this, $user)->assertRedirect(route('password.confirm'))->assertSessionHasErrors(['password' => 'The provided password was incorrect.']);
        confirmAttempt($this, $user)->assertSessionHasErrors(['password' => 'The provided password was incorrect.']);
        confirmAttempt($this, $user, 'Password1!')->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');
    });

    it('refuses the attempt after the per-minute allowance, and does not look at the password: the CORRECT one is refused too', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        $refused = confirmAttempt($this, $user, 'Password1!');

        $refused->assertRedirect(route('password.confirm'))->assertSessionHasErrors('password')->assertSessionMissing('auth.password_confirmed_at');
        expect(throttleMessage())->toStartWith('Too many password attempts. Try again in ')
            ->and($refused->headers->get('Retry-After'))->toBeNumeric();
    });

    it('answers a JSON caller with 429, Retry-After and the same field error', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        $this->actingAs($user)->postJson(route('password.confirm.store'), ['password' => 'Password1!'])
            ->assertStatus(429)
            ->assertHeader('Retry-After')
            ->assertJsonValidationErrors('password');
    });

    it('lets the owner confirm again once the minute has passed', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);
        confirmAttempt($this, $user, 'Password1!')->assertSessionHasErrors('password');

        $this->travel(61)->seconds();

        confirmAttempt($this, $user, 'Password1!')->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');
    });

    it('does not extend the wait when the attempt is refused: knocking again does not keep the owner out', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        $wait = (int) confirmAttempt($this, $user)->headers->get('Retry-After');
        $this->travel(20)->seconds();
        confirmAttempt($this, $user)->assertSessionHasErrors('password');
        confirmAttempt($this, $user)->assertSessionHasErrors('password');

        $this->travel($wait - 20 + 1)->seconds();
        confirmAttempt($this, $user, 'Password1!')->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');
    });

    it('caps a patient guesser per hour even when every minute window is clear', function () {
        $user = throttleUser();

        for ($i = 0; $i < PasswordConfirmationThrottle::PER_HOUR; $i++) {
            confirmAttempt($this, $user)->assertSessionHasErrors(['password' => 'The provided password was incorrect.']);
            $this->travel(61)->seconds();
        }

        confirmAttempt($this, $user, 'Password1!')->assertSessionHasErrors('password')->assertSessionMissing('auth.password_confirmed_at');
        expect(throttleMessage())->toStartWith('Too many password attempts');

        $this->travel(61)->minutes();
        confirmAttempt($this, $user, 'Password1!')->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');
    });
});

describe('it limits an account, not an address, and nothing but this endpoint', function () {
    it('keys on the account: another account is untouched while this one is limited', function () {
        $guessed = throttleUser();
        $bystander = throttleUser();
        guessesUntilLimited($this, $guessed, PasswordConfirmationThrottle::PER_MINUTE);

        confirmAttempt($this, $guessed, 'Password1!')->assertSessionHasErrors('password');
        confirmAttempt($this, $bystander, 'Password1!')->assertSessionHasNoErrors()->assertSessionHas('auth.password_confirmed_at');
    });

    it('is not escaped by changing address', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.77'])->actingAs($user)->from(route('password.confirm'))
            ->post(route('password.confirm.store'), ['password' => 'Password1!'])->assertSessionHasErrors('password')->assertSessionMissing('auth.password_confirmed_at');
    });

    it('is not an account lockout: sign-in, the confirmation page and its status check, and sign-out all still work while it is limited', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        $this->actingAs($user)->get(route('password.confirm'))->assertOk();
        $this->actingAs($user)->getJson(route('password.confirmation'))->assertOk();
        $this->post(route('logout'))->assertRedirect();

        auth()->forgetGuards();
        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'Password1!'])->assertRedirect();
        $this->assertAuthenticatedAs($user);
    });

    it('does not count or answer a guest: the auth middleware redirects them as before', function () {
        for ($i = 0; $i < PasswordConfirmationThrottle::PER_MINUTE + 3; $i++) {
            $this->post(route('password.confirm.store'), ['password' => 'x'])->assertRedirect(route('login'));
        }
    });

    it('does not apply to the GET page that shares the path', function () {
        $user = throttleUser();

        for ($i = 0; $i < PasswordConfirmationThrottle::PER_MINUTE + 3; $i++) {
            $this->actingAs($user)->get(route('password.confirm'))->assertOk();
        }
    });
});

describe('the flows that stand behind a password confirmation still work', function () {
    it('lets a person enrol a second factor: confirmation, enable, confirm', function () {
        $user = throttleUser();

        $this->actingAs($user)->post(route('two-factor.enable'))->assertRedirect(route('password.confirm'));
        confirmAttempt($this, $user)->assertSessionHasErrors('password');
        confirmAttempt($this, $user, 'Password1!')->assertSessionHas('auth.password_confirmed_at');

        $this->actingAs($user)->withSession(['auth.password_confirmed_at' => time()])->post(route('two-factor.enable'))->assertRedirect();
        $secret = decrypt($user->fresh()->two_factor_secret);
        $this->actingAs($user->fresh())->withSession(['auth.password_confirmed_at' => time()])
            ->post(route('two-factor.confirm'), ['code' => app(Google2FA::class)->getCurrentOtp($secret)])->assertRedirect();

        expect($user->fresh()->hasConfirmedTwoFactor())->toBeTrue();
    });

    it('does not block an unconfirmed operator from the confirmation they need to enrol, even while enforcement is on', function () {
        config(['operator_mfa.enforced' => true]);
        $operator = pilotOperator(['password' => Hash::make('Password1!')]);
        guessesUntilLimited($this, $operator, PasswordConfirmationThrottle::PER_MINUTE);

        confirmAttempt($this, $operator, 'Password1!')->assertRedirect(route('password.confirm'));
        expect(throttleMessage())->toStartWith('Too many password attempts');

        $this->travel(61)->seconds();
        confirmAttempt($this, $operator, 'Password1!')->assertSessionHas('auth.password_confirmed_at');
    });

    it('treats an account with no local password as a failed guess that counts, never as a way past the limit', function () {
        $passwordless = User::factory()->create(['password' => null]);

        for ($i = 0; $i < PasswordConfirmationThrottle::PER_MINUTE; $i++) {
            confirmAttempt($this, $passwordless, '')->assertSessionHasErrors('password');
        }
        confirmAttempt($this, $passwordless, '')->assertSessionHasErrors('password');
        expect(throttleMessage())->toStartWith('Too many password attempts');
    });
});

/** One wrong-password attempt at each of the three password-verifying operations. */
function throttleAttempt($test, User $user, string $operation, string $password = 'wrong-password')
{
    $acting = $test->actingAs($user)->from(route('profile.show'));

    return match ($operation) {
        'confirm' => $acting->post(route('password.confirm.store'), ['password' => $password]),
        'change' => $acting->put(route('user-password.update'), [
            'current_password' => $password, 'password' => 'An0ther-Str0ng-Pass!', 'password_confirmation' => 'An0ther-Str0ng-Pass!',
        ]),
        'sessions' => $acting->delete(route('profile.sessions.destroy'), ['password' => $password]),
    };
}

/** Where a refusal must land for each operation: the error bag and field its page already reads for a wrong password. */
function throttleRefused($response, string $operation): void
{
    match ($operation) {
        'confirm' => $response->assertSessionHasErrors('password'),
        'change' => $response->assertSessionHasErrorsIn('updatePassword', 'current_password'),
        'sessions' => $response->assertSessionHasErrorsIn('destroySessions', 'password'),
    };
}

function throttleSessionRows(User $user, int $count = 2): void
{
    foreach (range(1, $count) as $i) {
        DB::table('sessions')->insert(['id' => "other-$user->id-$i", 'user_id' => $user->id, 'ip_address' => '127.0.0.1', 'user_agent' => 't', 'payload' => 'x', 'last_activity' => time()]);
    }
}

describe('the three password-verifying operations share one allowance per account', function () {
    it('binds every one of them to a route that exists', function () {
        foreach (array_keys(PasswordConfirmationThrottle::ROUTES) as $name) {
            expect(Route::has($name))->toBeTrue("route [$name] is not registered");
        }
        expect(array_keys(PasswordConfirmationThrottle::ROUTES))->toBe(['password.confirm.store', 'user-password.update', 'profile.sessions.destroy']);
    });

    it('spends one bucket across them: five attempts split between the operations, then every operation is refused', function (string $sixth) {
        $user = throttleUser();

        throttleAttempt($this, $user, 'confirm');
        throttleAttempt($this, $user, 'change');
        throttleAttempt($this, $user, 'sessions');
        throttleAttempt($this, $user, 'change');
        throttleAttempt($this, $user, 'confirm');

        $refused = throttleAttempt($this, $user, $sixth);

        throttleRefused($refused, $sixth);
        expect(throttleMessage($sixth))->toStartWith('Too many password attempts. Try again in ');
    })->with(['confirm', 'change', 'sessions']);

    it('shows a normal wrong-password failure, in the same bag and field, below the limit', function () {
        $user = throttleUser();

        throttleAttempt($this, $user, 'change')->assertSessionHasErrorsIn('updatePassword', 'current_password');
        expect(session('errors')->getBag('updatePassword')->first('current_password'))->toBe('The provided password does not match your current password.');
        throttleAttempt($this, $user, 'sessions')->assertSessionHasErrorsIn('destroySessions', 'password');
    });

    it('caps the hour across the operations too', function () {
        $user = throttleUser();
        $operations = ['confirm', 'change', 'sessions'];

        for ($i = 0; $i < PasswordConfirmationThrottle::PER_HOUR; $i++) {
            throttleAttempt($this, $user, $operations[$i % 3]);
            $this->travel(61)->seconds();
        }

        throttleRefused(throttleAttempt($this, $user, 'sessions', 'Password1!'), 'sessions');
    });

    it('answers a JSON caller with 429, Retry-After and the field the operation uses', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        $this->actingAs($user)->putJson(route('user-password.update'), ['current_password' => 'Password1!', 'password' => 'An0ther-Str0ng-Pass!', 'password_confirmation' => 'An0ther-Str0ng-Pass!'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonValidationErrors('current_password');
        $this->actingAs($user)->deleteJson(route('profile.sessions.destroy'), ['password' => 'Password1!'])
            ->assertStatus(429)->assertHeader('Retry-After')->assertJsonValidationErrors('password');
    });
});

describe('a refused request changes nothing', function () {
    it('does not change the password, store history or end other sessions, even with the CORRECT current password', function () {
        $user = throttleUser();
        throttleSessionRows($user);
        $hash = $user->fresh()->password;
        $token = $user->fresh()->remember_token;
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        throttleRefused(throttleAttempt($this, $user, 'change', 'Password1!'), 'change');

        expect($user->fresh()->password)->toBe($hash)
            ->and($user->passwordHistories()->count())->toBe(0)
            ->and(DB::table('sessions')->where('user_id', $user->id)->where('id', 'like', 'other-%')->count())->toBe(2)
            ->and($user->fresh()->remember_token)->toBe($token);
    });

    it('does not end other sessions or rotate the remember-me token on a refused sign-out-others, even with the CORRECT password', function () {
        $user = throttleUser();
        throttleSessionRows($user);
        $token = $user->fresh()->remember_token;
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        throttleRefused(throttleAttempt($this, $user, 'sessions', 'Password1!'), 'sessions');

        expect(DB::table('sessions')->where('user_id', $user->id)->where('id', 'like', 'other-%')->count())->toBe(2)
            ->and($user->fresh()->remember_token)->toBe($token);
    });

    it('does not mark the password confirmed on a refused confirmation', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        throttleAttempt($this, $user, 'confirm', 'Password1!')->assertSessionMissing('auth.password_confirmed_at');
    });
});

describe('accounts are independent, and ordinary requests still work', function () {
    it('leaves another account\'s allowance untouched across all three operations', function () {
        $guessed = throttleUser();
        $bystander = throttleUser();
        throttleSessionRows($bystander);
        guessesUntilLimited($this, $guessed, PasswordConfirmationThrottle::PER_MINUTE);

        throttleAttempt($this, $bystander, 'change', 'Password1!')->assertSessionHasNoErrors();
        expect(Hash::check('An0ther-Str0ng-Pass!', $bystander->fresh()->password))->toBeTrue();

        throttleAttempt($this, $bystander, 'sessions', 'An0ther-Str0ng-Pass!')->assertSessionHasNoErrors();
        expect(DB::table('sessions')->where('user_id', $bystander->id)->where('id', 'like', 'other-%')->count())->toBe(0);
    });

    it('lets a valid password change and a valid sign-out-others through, with their usual effects', function () {
        $user = throttleUser();
        throttleSessionRows($user);

        throttleAttempt($this, $user, 'change', 'Password1!')->assertSessionHasNoErrors();
        expect(Hash::check('An0ther-Str0ng-Pass!', $user->fresh()->password))->toBeTrue()
            ->and(DB::table('sessions')->where('user_id', $user->id)->where('id', 'like', 'other-%')->count())->toBe(0);

        throttleSessionRows($user);
        throttleAttempt($this, $user->fresh(), 'sessions', 'An0ther-Str0ng-Pass!')->assertSessionHasNoErrors()->assertSessionHas('status');
        expect(DB::table('sessions')->where('user_id', $user->id)->where('id', 'like', 'other-%')->count())->toBe(0);
    });

    it('lets the owner through again once the window has passed', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);
        $this->travel(61)->seconds();

        throttleAttempt($this, $user, 'change', 'Password1!')->assertSessionHasNoErrors();
        expect(Hash::check('An0ther-Str0ng-Pass!', $user->fresh()->password))->toBeTrue();
    });
});

describe('the limit is neither an account-existence oracle nor a way around the second-factor boundary', function () {
    it('refuses identically whether the password presented is right or wrong', function () {
        $user = throttleUser();
        guessesUntilLimited($this, $user, PasswordConfirmationThrottle::PER_MINUTE);

        $wrong = throttleAttempt($this, $user, 'change', 'not-the-password');
        $wrongMessage = throttleMessage('change');
        $right = throttleAttempt($this, $user, 'change', 'Password1!');
        $rightMessage = throttleMessage('change');

        expect($right->getStatusCode())->toBe($wrong->getStatusCode())
            ->and($rightMessage)->toBe($wrongMessage)
            ->and($right->headers->get('Retry-After'))->toBe($wrong->headers->get('Retry-After'));
    });

    it('never reaches a guest: the auth middleware answers as before, for all three operations, and nothing is counted', function () {
        for ($i = 0; $i < PasswordConfirmationThrottle::PER_MINUTE + 3; $i++) {
            $this->post(route('password.confirm.store'), ['password' => 'x'])->assertRedirect(route('login'));
            $this->put(route('user-password.update'), ['current_password' => 'x'])->assertRedirect(route('login'));
            $this->delete(route('profile.sessions.destroy'), ['password' => 'x'])->assertRedirect(route('login'));
        }

        $user = throttleUser();
        throttleAttempt($this, $user, 'change', 'Password1!')->assertSessionHasNoErrors();
    });

    it('keeps a throttled, unconfirmed operator inside the second-factor boundary: refused here, and still refused everywhere else', function () {
        config(['operator_mfa.enforced' => true]);
        $operator = pilotOperator(['password' => Hash::make('Password1!')]);
        guessesUntilLimited($this, $operator, PasswordConfirmationThrottle::PER_MINUTE);

        throttleRefused(throttleAttempt($this, $operator, 'sessions', 'Password1!'), 'sessions');
        $this->actingAs($operator)->getJson(route('operator.tickets.index'))->assertForbidden();

        $this->travel(61)->seconds();
        throttleAttempt($this, $operator, 'change', 'Password1!')->assertSessionHasNoErrors();
        $this->actingAs($operator->fresh())->getJson(route('operator.tickets.index'))->assertForbidden();
    });

    it('lets a stale operator session neither use nor consume the allowance: it is signed out before the limiter', function () {
        config(['operator_mfa.enforced' => true]);
        $operator = pilotOperator(['password' => Hash::make('Password1!')]);
        $operator->forceFill(['session_epoch' => 'current-epoch'])->save();

        for ($i = 0; $i < PasswordConfirmationThrottle::PER_MINUTE + 2; $i++) {
            $this->actingAs(User::find($operator->id))->withSession([OperatorMfaGate::SESSION_KEY => 'an-earlier-epoch'])
                ->deleteJson(route('profile.sessions.destroy'), ['password' => 'Password1!'])->assertStatus(401);
        }

        throttleAttempt($this, User::find($operator->id), 'confirm')->assertSessionHasErrors('password');
        expect(throttleMessage('confirm'))->toBe('The provided password was incorrect.');
    });
});
