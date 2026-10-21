<?php

namespace App\Support\Runtime;

use RuntimeException;

/**
 * A guarded production command that was refused at startup (R0.9 WP3-A, slice A3). It carries only the categorical violations
 * {@see RuntimeProfile} produced (configuration names and driver or table names; never a key, credential or URL).
 *
 * `report()` returns true, which tells Laravel's exception handler that the exception is already reported: {@see RuntimeStartupGuard}
 * writes the one critical log entry itself, and the handler must not add a second, stack-traced one for the same refusal.
 */
final class RuntimeProfileRefused extends RuntimeException
{
    /** @param  list<string>  $violations */
    public function __construct(public readonly string $command, public readonly array $violations)
    {
        parent::__construct(
            "Refusing to start `{$command}`: the production runtime profile is not satisfied. Nothing was started, reserved or run.\n  - "
            .implode("\n  - ", $violations)
            ."\nFix the environment, run `php artisan config:cache`, then `php artisan runtime:preflight` (docs/architecture/runtime-preflight.md)."
        );
    }

    public function report(): bool
    {
        return true;
    }
}
