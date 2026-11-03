<?php

use Symfony\Component\Process\Process;

require_once __DIR__.'/../../Support/permission_cache_harness.php';

/*
 * The permission-cache security tests drive child processes and read one JSON line from each (permission_cache_harness.php).
 * A child that boots the application and then dies on an uncaught exception used to exit 0 with Laravel's rendered exception on
 * stdout, so the harness's exit-status check passed it and the test failed with `JsonException: Syntax error`, naming nothing
 * (PR #49, DB-F3: most likely a reader's cache write that waited out its 5 s lock-wait timeout; the real text was never shown). These cases pin the fix: the child exits
 * non-zero, and the harness says what the child printed. They change no assertion of the security scenarios.
 */

it('makes a bootstrapped child that dies on an uncaught exception exit non-zero, with the exception on stderr', function () {
    // The reader decides for a user that does not exist: User::findOrFail throws after the application has booted.
    $reader = pceReader(['profile' => 'database'], 999_999_999, ['tickets.view'], [], ['CACHE_STORE' => 'database', 'CACHE_PREFIX' => 'harness-guard-']);
    $reader->start();
    $reader->wait();

    expect($reader->getExitCode())->toBe(1)
        ->and($reader->getErrorOutput())->toContain('ModelNotFoundException')
        ->and($reader->getOutput())->not->toContain('ModelNotFoundException'); // nothing is rendered to stdout, where the JSON result is expected
});

it('reports the real failure of such a child instead of a JSON syntax error', function () {
    $reader = pceReader(['profile' => 'database'], 999_999_999, ['tickets.view'], [], ['CACHE_STORE' => 'database', 'CACHE_PREFIX' => 'harness-guard-']);
    $reader->start();

    expect(fn () => pceOutput($reader))->toThrow(RuntimeException::class, 'ModelNotFoundException');
});

it('names what a child printed when it exits 0 without a JSON result as its last stdout line', function () {
    $child = new Process([PHP_BINARY, '-r', 'echo "first line\n\nIn Connection.php line 633:\n  SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded\n";']);
    $child->start();

    expect(fn () => pceOutput($child))->toThrow(RuntimeException::class, '1205 Lock wait timeout exceeded');
});

it('still returns the last JSON line of a well-behaved child, whatever it printed before', function () {
    $child = new Process([PHP_BINARY, '-r', 'echo "noise\n"; echo json_encode(["ok" => true]);']);
    $child->start();

    expect(pceOutput($child))->toBe(['ok' => true]);
});
