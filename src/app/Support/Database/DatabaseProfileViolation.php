<?php

namespace App\Support\Database;

use RuntimeException;

/** A production database does not match the profile the application is built for ({@see DatabaseProfile}). */
final class DatabaseProfileViolation extends RuntimeException
{
    /** @param  list<string>  $violations */
    public function __construct(public readonly array $violations)
    {
        parent::__construct("The database does not match the supported profile:\n - ".implode("\n - ", $violations));
    }
}
