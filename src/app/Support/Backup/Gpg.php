<?php

namespace App\Support\Backup;

use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Public-key encryption of backup artifacts with the host's `gpg` (Release 0.9 WP-B1).
 *
 * Only a public key is ever on the host. It is read from a file the owner exported, and must be exactly the key whose
 * fingerprint the operator states, so a swapped file is refused rather than used. `gpg` runs with a fresh private
 * home directory per backup (never the account's keyring), and every encrypted artifact is read back to confirm it
 * is addressed only to that key's encryption subkey(s). Encryption is confidentiality, not authenticity: anyone
 * with the public key can encrypt. A set is authenticated by the SHA-256 digest recorded when it was made.
 */
final class Gpg
{
    public function __construct(private readonly string $home, private readonly string $binary = 'gpg') {}

    /**
     * The recipient key in $keyFile, which must hold exactly one primary key, equal to $fingerprint, with at least
     * one encryption-capable subkey.
     *
     * @return array{fingerprint: string, encryption_keyids: list<string>}
     */
    public function recipient(string $keyFile, string $fingerprint): array
    {
        $expected = strtoupper(preg_replace('/\s+/', '', $fingerprint) ?? '');

        if (! preg_match('/\A([0-9A-F]{40}|[0-9A-F]{64})\z/', $expected)) {
            throw new RuntimeException('The recipient fingerprint must be 40 or 64 hexadecimal characters.');
        }

        if (! is_file($keyFile) || is_link($keyFile)) {
            throw new RuntimeException('The recipient key file does not exist or is a symbolic link.');
        }

        $output = $this->gpg(['--with-colons', '--import-options', 'show-only', '--import', $keyFile]);

        $primaries = [];
        $encryption = [];
        $current = null;
        foreach (explode("\n", $output) as $line) {
            $fields = explode(':', $line);
            if ($fields[0] === 'pub' || $fields[0] === 'sub') {
                $current = ['type' => $fields[0], 'keyid' => $fields[4] ?? '', 'caps' => $fields[11] ?? '', 'validity' => $fields[1] ?? ''];
            } elseif ($fields[0] === 'fpr' && $current !== null) {
                if ($current['type'] === 'pub') {
                    $primaries[] = $fields[9] ?? '';
                }
                if (str_contains($current['caps'], 'e') && ! in_array($current['validity'], ['r', 'e'], true)) {
                    $encryption[] = $current['keyid'];
                }
                $current = null;
            }
        }

        if (count($primaries) !== 1) {
            throw new RuntimeException('The recipient key file must hold exactly one public key; it holds '.count($primaries).'.');
        }

        if ($primaries[0] !== $expected) {
            throw new RuntimeException("The recipient key file holds key {$primaries[0]}, not the expected {$expected}.");
        }

        if ($encryption === []) {
            throw new RuntimeException("Key {$expected} has no usable encryption subkey.");
        }

        return ['fingerprint' => $expected, 'encryption_keyids' => $encryption];
    }

    /** Encrypt $input to the key in $keyFile, writing $output. gzip has already compressed it. */
    public function encrypt(string $input, string $output, string $keyFile): void
    {
        $this->gpg(['--trust-model', 'always', '--recipient-file', $keyFile, '--compress-algo', 'none', '--encrypt', '--output', $output, $input]);
    }

    /**
     * The key ids an encrypted file is addressed to, read from its packets. No secret key is needed (and none is on
     * the host), so gpg's own exit status, which reports the missing secret key, is not used.
     *
     * @return list<string>
     */
    public function recipientsOf(string $file): array
    {
        $process = $this->process(['--status-fd', '1', '--list-packets', $file]);
        $process->run();

        preg_match_all('/^\[GNUPG:\] ENC_TO ([0-9A-F]{16}) /m', $process->getOutput(), $m);

        return array_values(array_unique($m[1]));
    }

    /** @param  list<string>  $arguments */
    private function gpg(array $arguments): string
    {
        $process = $this->process($arguments);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException('gpg failed (exit '.$process->getExitCode().'): '.trim($process->getErrorOutput()));
        }

        return $process->getOutput();
    }

    /** @param  list<string>  $arguments */
    private function process(array $arguments): Process
    {
        return new Process(
            [$this->binary, '--homedir', $this->home, '--batch', '--no-tty', '--quiet', ...$arguments],
            null,
            ['GNUPGHOME' => $this->home, 'LC_ALL' => 'C'],
            null,
            600,
        );
    }
}
