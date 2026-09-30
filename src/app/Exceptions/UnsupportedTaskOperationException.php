<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * A task operation was requested for a row the domain refuses to mutate: a ticket-kind task,
 * whose product UX is Future (EPIC-014 Q6), or a malformed row linked to both a project and a
 * ticket (INV-13). Raised before anything is written, so nothing is partially changed.
 */
class UnsupportedTaskOperationException extends RuntimeException
{
    public function __construct(public readonly int $taskId, public readonly string $operation, string $reason)
    {
        parent::__construct("Task {$taskId}: {$operation} is not supported for {$reason}.");
    }
}
