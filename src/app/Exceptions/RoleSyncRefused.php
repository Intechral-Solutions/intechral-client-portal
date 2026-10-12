<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Release 0.9 (H4): the built-in role synchronization (or the first-operator bootstrap that depends on it) declined
 * to proceed. Always visible: the command prints the reason and exits non-zero, and nothing was changed.
 */
class RoleSyncRefused extends RuntimeException
{
    /** @param  list<string>  $problems */
    public static function malformedDefinitions(array $problems): self
    {
        return new self('The built-in role definitions are malformed, so nothing was planned or changed: '.implode(' ', $problems));
    }

    public static function ambiguous(string $detail): self
    {
        return new self("The stored role state is ambiguous, so nothing was planned or changed: {$detail}");
    }

    /**
     * Deliberately does not name the plan the stored state now needs: a stale approval must not hand the caller a
     * replacement approval token for a plan nobody has reviewed.
     */
    public static function stalePlan(string $reviewed): self
    {
        return new self("The reviewed plan {$reviewed} is stale: the stored state has changed since it was reviewed. Nothing was "
            .'changed. Run the dry run again, inspect the new plan and approve it deliberately.');
    }

    public static function revocationsNotApproved(int $count): self
    {
        return new self("The plan revokes {$count} grant(s). Nothing was changed. Review the revocations and re-run with "
            .'--allow-revocations to approve them.');
    }

    public static function unexpectedOutcome(string $detail): self
    {
        return new self("The synchronization did not produce the planned state ({$detail}), so it was rolled back and nothing was changed.");
    }
}
