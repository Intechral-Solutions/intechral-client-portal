<?php

namespace App\Support\Rehearsal;

use RuntimeException;

/**
 * Raised when a rehearsal target (or the rehearsal lock) does not meet the safety contract. The message does not
 * say whether anything was changed: that depends on WHEN it was raised, and only the caller knows. Before a run
 * or a cleanup begins it is a refusal and nothing was changed; the commands say so. After a run has begun the
 * runner reports it as a failure of that step instead.
 */
final class UnsafeRehearsalTarget extends RuntimeException
{
    /** @param  list<string>  $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            "Refusing: the rehearsal target does not meet the safety contract.\n  - "
            .implode("\n  - ", $violations)
        );
    }
}
