<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A project's board does not have exactly one designated Done column (EPIC-014 Q2, INV-8), or has
 * no open column to reopen into, so a board task cannot be completed or reopened through it.
 * Raised instead of guessing a column. `TaskController` maps it to a validation error on the
 * operation's key; the bulk endpoint reports it per task.
 */
class DoneColumnConfigurationException extends RuntimeException
{
    public function __construct(
        public readonly int $projectId,
        public readonly int $doneColumnCount,
        public readonly bool $noOpenColumn = false,
    ) {
        parent::__construct($noOpenColumn
            ? "Project {$projectId} has no open column to reopen into."
            : ($doneColumnCount === 0
            ? "Project {$projectId} has no Done column; exactly one is required."
            : "Project {$projectId} has {$doneColumnCount} Done columns; exactly one is required."));
    }

    /** Every column of the project is a Done column, so Reopen has nowhere to go (§8). */
    public static function forMissingOpenColumn(int $projectId, int $doneColumnCount): self
    {
        return new self($projectId, $doneColumnCount, noOpenColumn: true);
    }
}
