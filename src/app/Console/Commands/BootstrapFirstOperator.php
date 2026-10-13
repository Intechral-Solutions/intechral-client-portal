<?php

namespace App\Console\Commands;

use App\Exceptions\OperatorBootstrapRefused;
use App\Services\OperatorBootstrap;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

#[Signature('operator:bootstrap {email : Email address of the new operator account} {--name= : Display name (asked for when omitted)}')]
#[Description('Create the first account administrator of a NEW installation (refuses once any exists; not a recovery path)')]
class BootstrapFirstOperator extends Command
{
    public function handle(OperatorBootstrap $bootstrap): int
    {
        $name = $this->option('name') ?: $this->ask('Name of the new operator');
        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');

        try {
            $user = $bootstrap->provision([
                'name' => (string) $name,
                'email' => (string) $this->argument('email'),
                'password' => (string) $password,
                'password_confirmation' => (string) $confirmation,
            ]);
        } catch (OperatorBootstrapRefused $refusal) {
            $this->components->error($refusal->getMessage());

            return self::FAILURE;
        } catch (ValidationException $invalid) {
            foreach ($invalid->errors() as $messages) {
                foreach ($messages as $message) {
                    $this->components->error($message);
                }
            }
            $this->line('Nothing was created.');

            return self::FAILURE;
        }

        $this->components->info("Created the operator account {$user->email} (id {$user->id}). They can sign in now.");
        $this->components->warn('Operator two-factor authentication is not currently enforced.');
        $this->line('Whether it must be remains an owner decision; the account may enable it under Profile.');

        return self::SUCCESS;
    }
}
