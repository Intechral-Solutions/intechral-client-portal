<?php

namespace App\Support\Rehearsal;

use RuntimeException;
use Throwable;

/**
 * Raised when `rehearsal:clean` fails AFTER it started removing things. Unlike {@see UnsafeRehearsalTarget}
 * (a refusal, with nothing changed), part of the cleanup has happened: the message names the step that failed
 * (which may be partly cleaned) and what had already been cleaned, so the operator knows what may remain.
 */
final class RehearsalInterrupted extends RuntimeException
{
    /**
     * @param  list<string>  $completed  what had been cleaned completely when the failure happened
     * @param  string  $step  what was being cleaned when it failed
     */
    public function __construct(public readonly array $completed, public readonly string $step, Throwable $previous)
    {
        parent::__construct(
            "Cleanup stopped part-way while cleaning {$step} (which may be partly cleaned; already cleaned: "
            .($completed === [] ? 'nothing' : implode(', ', $completed)).'): '
            .mb_strimwidth(trim((string) preg_replace('/\s+/', ' ', $previous->getMessage())), 0, 300, '…')
            .' Run rehearsal:clean again once the cause is fixed.',
            0,
            $previous
        );
    }
}
