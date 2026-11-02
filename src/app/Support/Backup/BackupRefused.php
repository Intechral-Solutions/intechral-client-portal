<?php

namespace App\Support\Backup;

use RuntimeException;

/**
 * A backup tool refused before it changed or produced anything (exit code 2). The message names the reason and is
 * safe to print.
 */
final class BackupRefused extends RuntimeException {}
