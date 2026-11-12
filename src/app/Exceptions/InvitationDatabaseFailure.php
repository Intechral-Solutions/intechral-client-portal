<?php

namespace App\Exceptions;

use App\Support\Database\DuplicateKey;
use Illuminate\Database\QueryException;
use RuntimeException;

/**
 * What is reported when a database operation fails while an invitation credential is in play (Release 1.0, R1-3B; see
 * App\Http\Controllers\Auth\Concerns\WithholdsQueryDetails).
 *
 * A QueryException's message and `getSql()`/`getBindings()` carry the SQL WITH its bindings, and an invitation lookup binds the token's hash;
 * its `errorInfo[2]` is the driver's text, which for a duplicate entry quotes the duplicated VALUE (a hash or an e-mail address). So this
 * exception is built from an ALLOWLIST of fields, each a bounded, validated scalar, and holds neither the original exception nor its message:
 *
 *  - SQLSTATE (`errorInfo[0]`) and the driver error code (`errorInfo[1]`), the two values that say WHY;
 *  - the violated key's NAME for a duplicate entry (DuplicateKey::name(), a schema identifier, never the value);
 *  - the connection name and the original exception's class;
 *  - the original stack as `file:line Class->function` strings, taken from the frames' own file, line, class and function and NEVER their
 *    arguments (a frame argument can be the token: the controller method takes it).
 *
 * The exception's own stack trace carries no arguments either (see `from()`).
 *
 * The values reach the log as structured context (Handler::exceptionContext() calls `context()`); the message is a constant.
 */
final class InvitationDatabaseFailure extends RuntimeException
{
    public const MESSAGE = 'An invitation database operation failed; query details were suppressed.';

    /** @var array<string, mixed> */
    private array $diagnostics;

    private function __construct(array $diagnostics)
    {
        // No `previous`: chaining the original would hand its message (and so its bindings) to every log formatter.
        parent::__construct(self::MESSAGE);

        $this->diagnostics = $diagnostics;
    }

    public static function from(QueryException $original): self
    {
        $sqlState = $original->errorInfo[0] ?? null;
        $driverCode = $original->errorInfo[1] ?? null;
        $key = DuplicateKey::name($original);

        $diagnostics = [
            'sqlstate' => is_string($sqlState) && preg_match('/^[0-9A-Z]{5}$/', $sqlState) === 1 ? $sqlState : null,
            'driver_code' => is_int($driverCode) || (is_string($driverCode) && ctype_digit($driverCode)) ? (int) $driverCode : null,
            'violated_key' => $key !== null && preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $key) === 1 ? $key : null,
            'connection' => preg_match('/^[A-Za-z0-9_.-]{1,64}$/', (string) $original->connectionName) === 1 ? $original->connectionName : null,
            'original_class' => $original::class,
            'original_frames' => self::frames($original),
        ];

        // This exception's OWN trace is captured when it is constructed, here, beneath the controller frame whose argument is the plaintext
        // token. With `zend.exception_ignore_args` off (the PHP default, and what a development image runs) that trace would carry the token
        // as an argument, to every consumer of `getTrace()` / `getTraceAsString()`. Constructing it with argument capture switched off keeps
        // it out where the runtime override is permitted (a host that forbids ini_set() for this directive defeats it: production exception-
        // argument redaction stays a Release 1.0 readiness gate); the previous value is restored at once.
        $ignoreArgs = ini_set('zend.exception_ignore_args', '1');

        try {
            return new self($diagnostics);
        } finally {
            if ($ignoreArgs !== false) {
                ini_set('zend.exception_ignore_args', $ignoreArgs);
            }
        }
    }

    /** @return array<string, mixed> */
    public function context(): array
    {
        return ['invitation_database_failure' => $this->diagnostics];
    }

    /** @return list<string> */
    private static function frames(QueryException $original): array
    {
        $base = base_path().DIRECTORY_SEPARATOR;
        $strip = static fn (string $path): string => str_starts_with($path, $base) ? substr($path, strlen($base)) : $path;

        $frames = [$strip($original->getFile()).':'.$original->getLine().' (thrown)'];

        // Only these four keys of a frame are ever read; `args` (and `object`) are not.
        foreach ($original->getTrace() as $frame) {
            $location = isset($frame['file'], $frame['line']) ? $strip((string) $frame['file']).':'.$frame['line'] : '[internal function]';
            $frames[] = $location.' '.($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '');
        }

        return $frames;
    }
}
