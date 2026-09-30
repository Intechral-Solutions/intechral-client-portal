<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A project's board does not have exactly one designated Done column (EPIC-014 Q2, INV-8), so a
 * board task cannot be completed or reopened through it. Raised instead of guessing a column.
 * Mapping it to a user-facing validation error is the caller's job (WP2).
 */
class DoneColumnConfigurationException extends RuntimeException
{
    public function __construct(public readonly int $projectId, public readonly int $doneColumnCount)
    {
        parent::__construct($doneColumnCount === 0
            ? "Project {$projectId} has no Done column; exactly one is required."
            : "Project {$projectId} has {$doneColumnCount} Done columns; exactly one is required.");
    }
}
