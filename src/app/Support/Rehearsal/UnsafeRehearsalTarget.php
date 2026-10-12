<?php

namespace App\Support\Rehearsal;

use RuntimeException;

/** Raised, before anything is changed, when a rehearsal target does not meet the safety contract. */
final class UnsafeRehearsalTarget extends RuntimeException
{
    /** @param  list<string>  $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct(
            "Refusing: the rehearsal target does not meet the safety contract. Nothing was changed.\n  - "
            .implode("\n  - ", $violations)
        );
    }
}
