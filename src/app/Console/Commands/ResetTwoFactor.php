<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\TwoFactorReset;
use App\Shared\Pilot\CustomerPilotGate;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Console\ConfirmableTrait;
use RuntimeException;

/**
 * Administrative recovery for an account that has lost BOTH its authenticator and its recovery codes. See {@see TwoFactorReset} and
 * docs/operations/operator-mfa.md (the runbook, including how to verify the person first).
 *
 * Deliberately narrow. It identifies the account by its exact email address (no search, no id, no pattern), shows what it found, requires a stated
 * reason, asks for the production confirmation (`--force` skips only that prompt) and then requires the exact email to be typed back: there is no
 * flag that skips the typed confirmation, so `--no-interaction` or `--quiet` cannot satisfy it (a person at a shell can still answer or script it: shell access is the authorization). It prints no secret, recovery code or code; the audit entry holds none.
 */
#[Signature('user:reset-two-factor {email : The exact email address of the account} {--reason= : Why the reset is needed, e.g. a ticket reference (recorded; asked for when omitted)} {--force : Skip the production prompt only; the typed confirmation is still required}')]
#[Description('Remove an account\'s two-factor authentication after it lost both its authenticator and its recovery codes (shell access is the authorization)')]
class ResetTwoFactor extends Command
{
    use ConfirmableTrait;

    public function handle(TwoFactorReset $reset): int
    {
        $email = trim((string) $this->argument('email'));

        $matches = $email === '' ? collect() : User::query()->where('email', $email)->get();

        // The database may compare emails case-insensitively; "exact" here means the address as stored, byte for byte.
        $matches = $matches->filter(fn (User $candidate) => hash_equals($candidate->email, $email))->values();

        if ($matches->count() !== 1) {
            $this->components->error('No single account has exactly that email address. Nothing was changed.');

            return self::FAILURE;
        }

        /** @var User $user */
        $user = $matches->first();

        $this->components->twoColumnDetail('Account', "#{$user->getKey()}  {$user->name} <{$user->email}>");
        $this->components->twoColumnDetail('Operator', CustomerPilotGate::isOperator($user) ? 'yes' : 'no');
        $this->components->twoColumnDetail('Suspended', $user->isSuspended() ? 'yes' : 'no');
        $this->components->twoColumnDetail('Two-factor', $this->stateLabel($user));

        if (! TwoFactorReset::hasState($user)) {
            $this->components->info('This account has no two-factor state. Nothing was changed.');

            return self::SUCCESS;
        }

        if (! $this->confirmToProceed('Application in production. Resetting an account\'s second factor lowers its protection until it enrols again.')) {
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        $reason = trim((string) ($this->option('reason') ?: ($this->input->isInteractive() ? $this->ask('Reason (recorded in the audit log)') : '')));

        if ($reason === '') {
            $this->components->error('A reason is required. Nothing was changed.');

            return self::FAILURE;
        }

        $typed = $this->input->isInteractive() ? (string) $this->ask('Type the account email address again to confirm') : '';

        if ($typed === '' || ! hash_equals($user->email, $typed)) {
            $this->components->error('The confirmation did not match the account email address exactly. Nothing was changed.');

            return self::FAILURE;
        }

        try {
            $cleared = $reset->reset($user, $reason, $this->runBy());
        } catch (RuntimeException $failure) {
            $this->components->error($failure->getMessage());
            $this->line('Nothing was changed.');

            return self::FAILURE;
        }

        if (! $cleared) {
            $this->components->info('The account no longer had any two-factor state. Nothing was changed.');

            return self::SUCCESS;
        }

        $this->components->info("Two-factor authentication was reset for {$user->email}. It was signed out everywhere and recorded in the activity log.");
        $this->line('The account must enrol again (Profile > Two-factor authentication) before an operator account can use privileged features.');

        return self::SUCCESS;
    }

    private function stateLabel(User $user): string
    {
        return match (true) {
            $user->hasConfirmedTwoFactor() => 'confirmed',
            TwoFactorReset::hasState($user) => 'enrolment started, never confirmed',
            default => 'none',
        };
    }

    private function runBy(): string
    {
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());

            if (is_array($entry) && isset($entry['name'])) {
                return (string) $entry['name'];
            }
        }

        return get_current_user() ?: 'unknown';
    }
}
