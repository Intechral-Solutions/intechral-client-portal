<?php

/*
 * R0.9 WP1 — the SMTP transport timeout is explicit, environment-controlled, and really bounds an unresponsive send.
 *
 * Symfony Mailer reads the socket with a per-read idle timeout (`stream_set_timeout`), which is what makes a hung SMTP
 * peer time out WITHOUT relying on pcntl or a queue worker's --timeout (neither is guaranteed on shared hosting). A peer
 * that greets and then goes silent costs TWO timeouts: the EHLO read times out, Symfony falls back to HELO, and that
 * read times out too. Before WP1 the timeout was `null`, so it fell back to PHP's default_socket_timeout (60 s) and such
 * a send held a worker for ~120 s (measured). The assertions use a 1 s timeout against a real hung socket peer.
 */

use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

/** Load config/mail.php under a given MAIL_TIMEOUT (null = unset) and return the SMTP timeout it resolves to. */
function wp1ConfiguredMailTimeout(?string $env): mixed
{
    $previous = getenv('MAIL_TIMEOUT');
    $env === null ? putenv('MAIL_TIMEOUT') : putenv("MAIL_TIMEOUT={$env}");
    unset($_ENV['MAIL_TIMEOUT'], $_SERVER['MAIL_TIMEOUT']);

    try {
        return (require config_path('mail.php'))['mailers']['smtp']['timeout'];
    } finally {
        $previous === false ? putenv('MAIL_TIMEOUT') : putenv("MAIL_TIMEOUT={$previous}");
    }
}

/**
 * A real TCP peer in another process: it accepts, optionally sends a 220 greeting, then goes silent for 30 s.
 *
 * @return array{process: resource, port: int}
 */
function wp1StartHungSmtpPeer(bool $greet): array
{
    $script = '$s=stream_socket_server("tcp://127.0.0.1:0",$e,$m);'
        .'echo parse_url(stream_socket_get_name($s,false),PHP_URL_PORT),"\n";flush();'
        .'$c=stream_socket_accept($s,20);'
        .($greet ? 'fwrite($c,"220 hung.test ESMTP\r\n");' : '')
        .'sleep(30);';
    $process = proc_open([PHP_BINARY, '-r', $script], [1 => ['pipe', 'w']], $pipes);
    $port = (int) trim((string) fgets($pipes[1]));

    return ['process' => $process, 'port' => $port];
}

function wp1StopPeer(array $peer): void
{
    proc_terminate($peer['process']);
    proc_close($peer['process']);
}

it('defaults to 20 seconds and takes the timeout from MAIL_TIMEOUT', function () {
    expect(wp1ConfiguredMailTimeout(null))->toBe(20.0)
        ->and(wp1ConfiguredMailTimeout('45'))->toBe(45.0)
        ->and(wp1ConfiguredMailTimeout('7.5'))->toBe(7.5);
});

it('never lets a blank, zero or negative MAIL_TIMEOUT mean "no wait"', function () {
    expect(wp1ConfiguredMailTimeout(''))->toBe(20.0)
        ->and(wp1ConfiguredMailTimeout('0'))->toBe(20.0)
        ->and(wp1ConfiguredMailTimeout('-5'))->toBe(1.0);
});

it('applies the configured timeout to the real SMTP socket', function () {
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.timeout' => 33.0]);

    $stream = Mail::mailer('smtp')->getSymfonyTransport()->getStream();

    expect($stream->getTimeout())->toBe(33.0);
});

it('times out a peer that never sends its greeting after one timeout', function () {
    $peer = wp1StartHungSmtpPeer(greet: false);
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => $peer['port'], 'mail.mailers.smtp.timeout' => 1.0]);

    $start = microtime(true);
    try {
        Mail::raw('x', fn ($m) => $m->to('a@example.test')->subject('hung'));
        $outcome = null;
    } catch (TransportException $e) {
        $outcome = $e->getMessage();
    } finally {
        $elapsed = microtime(true) - $start;
        wp1StopPeer($peer);
    }

    expect($outcome)->toContain('timed out')->and($elapsed)->toBeGreaterThan(0.9)->toBeLessThan(2.5);
});

it('times out a peer that greets and then goes silent after two timeouts (EHLO, then the HELO fallback)', function () {
    $peer = wp1StartHungSmtpPeer(greet: true);
    config(['mail.default' => 'smtp', 'mail.mailers.smtp.host' => '127.0.0.1', 'mail.mailers.smtp.port' => $peer['port'], 'mail.mailers.smtp.timeout' => 1.0]);

    $start = microtime(true);
    try {
        Mail::raw('x', fn ($m) => $m->to('a@example.test')->subject('hung'));
        $outcome = null;
    } catch (TransportException $e) {
        $outcome = $e->getMessage();
    } finally {
        $elapsed = microtime(true) - $start;
        wp1StopPeer($peer);
    }

    expect($outcome)->toContain('timed out')->and($elapsed)->toBeGreaterThan(1.9)->toBeLessThan(4.5);
});
