<?php

namespace Tests\Support;

use App\Support\Backup\BackupCreator;
use App\Support\Backup\BackupSet;
use App\Support\Backup\DumpReader;
use App\Support\Rehearsal\RehearsalTarget;
use Closure;
use Illuminate\Encryption\Encrypter;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * What the WP-B1 backup tests share. The backup SOURCE is the primary rehearsal database with its deterministic
 * fixtures and attachment files ({@see RehearsalSandbox::baseline()}), so a real `mariadb-dump` reads committed data
 * without touching the development or testing database; the drill writes only to the rehearsal restore target.
 * Keys are throw-away: one GnuPG key pair per process (the "owner" key) in a private home under the sandbox, and a
 * fixed test APP_KEY. Backup roots are private directories under the sandbox, outside its storage.
 */
final class BackupSandbox
{
    public const APP_KEY = 'wp-b1-test-key-0123456789abcdefX';

    public const CIPHER = 'aes-256-cbc';

    /** @var array{home: string, public: string, fingerprint: string}|null */
    private static ?array $owner = null;

    /** @var array{home: string, public: string, fingerprint: string}|null */
    private static ?array $stranger = null;

    /** @return array{home: string, public: string, fingerprint: string} the owner's key pair */
    public static function owner(): array
    {
        return self::$owner ??= self::keyPair('owner');
    }

    /** @return array{home: string, public: string, fingerprint: string} another key pair, not the owner's */
    public static function stranger(): array
    {
        return self::$stranger ??= self::keyPair('stranger');
    }

    public static function encrypter(string $key = self::APP_KEY): Encrypter
    {
        return new Encrypter($key, self::CIPHER);
    }

    /** @param  Closure(array<string, mixed>): void|null  $afterSnapshot */
    public static function creator(?Closure $afterSnapshot = null, ?array $database = null): BackupCreator
    {
        $primary = RehearsalTarget::primary();

        return new BackupCreator(
            $database ?? $primary->connectionConfig(),
            $primary->filesRoot(),
            self::encrypter(),
            self::CIPHER,
            'testing',
            ['id' => '20261102000000-test000', 'commit' => str_repeat('a', 40)],
            null,
            $afterSnapshot,
            [RehearsalTarget::MARKER_TABLE],
        );
    }

    /** A new private (0700) directory under the sandbox, outside its storage. */
    public static function directory(string $prefix = 'backups'): string
    {
        $path = RehearsalSandbox::root().'/'.$prefix.'-'.bin2hex(random_bytes(4));
        mkdir($path, 0700);
        chmod($path, 0700);

        return $path;
    }

    /** Make a set of the primary rehearsal database; returns the creator's result. */
    public static function create(?string $root = null, ?Closure $afterSnapshot = null): array
    {
        $owner = self::owner();

        return self::creator($afterSnapshot)->create($root ?? self::directory(), $owner['public'], $owner['fingerprint']);
    }

    /** Wait until the clock is in a later whole second than now (file times are whole seconds). */
    public static function nextSecond(): void
    {
        $now = time();
        while (time() <= $now) {
            usleep(20_000);
        }
    }

    /**
     * Run scripts/backup/open-backup-set.sh with $keys' keyring.
     *
     * @param  list<string>  $extra
     */
    public static function opener(string $set, string $out, ?array $keys = null, array $extra = []): Process
    {
        $process = new Process(['bash', base_path('scripts/backup/open-backup-set.sh'), $set, $out, ...$extra], null,
            ['GNUPGHOME' => ($keys ?? self::owner())['home'], 'LC_ALL' => 'C'], null, 120);
        $process->run();

        return $process;
    }

    /** Open $set into a new directory beside it, or fail the test. */
    public static function open(string $set): string
    {
        $out = dirname($set).'/opened-'.bin2hex(random_bytes(3));
        $process = self::opener($set, $out);

        if (! $process->isSuccessful()) {
            throw new RuntimeException('open-backup-set.sh failed: '.$process->getErrorOutput());
        }

        return $out;
    }

    /** A copy of opened set $dir, so a test can damage it. */
    public static function copy(string $dir): string
    {
        $copy = dirname($dir).'/'.basename($dir).'-copy-'.bin2hex(random_bytes(3));
        mkdir($copy, 0700);
        foreach (array_diff(scandir($dir), ['.', '..']) as $name) {
            copy($dir.'/'.$name, $copy.'/'.$name);
            chmod($copy.'/'.$name, 0600);
        }

        return $copy;
    }

    /**
     * Change opened set $dir and seal it again CONSISTENTLY (set.json, SHA256SUMS, COMPLETE), as a buggy or malicious
     * producer would, so that the checksum checks pass and the deeper checks are what must catch the change.
     *
     *  - $sql rewrites the decompressed dump text;
     *  - $rederive recomputes the manifest's database section from the new dump (so dump and manifest agree);
     *  - $manifest rewrites the manifest array;
     *  - $archive changes the extracted attachments tree (a directory holding `tickets/`) before it is packed again.
     */
    public static function tamper(string $dir, ?Closure $sql = null, bool $rederive = false, ?Closure $manifest = null, ?Closure $archive = null): void
    {
        if ($sql !== null) {
            $text = (string) gzdecode((string) file_get_contents($dir.'/'.BackupSet::DATABASE));
            $changed = $sql($text);
            if ($changed === $text) {
                throw new RuntimeException('The dump tamper changed nothing.');
            }
            file_put_contents($dir.'/'.BackupSet::DATABASE, gzencode($changed));
        }

        if ($archive !== null) {
            $work = self::directory('tamper');
            (new Process(['tar', '-xzf', $dir.'/'.BackupSet::ATTACHMENTS, '-C', $work]))->mustRun();
            $archive($work);
            (new Process(['tar', '-czf', $dir.'/'.BackupSet::ATTACHMENTS, '-C', $work, 'tickets']))->mustRun();
            BackupSet::removeTree($work);
        }

        $data = json_decode((string) file_get_contents($dir.'/'.BackupSet::MANIFEST), true);
        if ($rederive) {
            $dump = DumpReader::read($dir.'/'.BackupSet::DATABASE);
            $data['database'] = ['schema_sha256' => $dump['schema_sha256'], 'tables' => $dump['tables'], 'tickets' => $dump['tickets'], 'migrations' => $dump['migrations']];
        }
        if ($manifest !== null) {
            $data = $manifest($data);
        }
        file_put_contents($dir.'/'.BackupSet::MANIFEST, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        self::reseal($dir);
    }

    /** Recompute an opened set's seal from its current payload files. */
    public static function reseal(string $dir): void
    {
        $set = json_decode((string) file_get_contents($dir.'/'.BackupSet::SET_FILE), true);
        foreach (BackupSet::PAYLOAD as $name) {
            $set['artifacts'][$name.'.gpg']['plaintext_sha256'] = hash_file('sha256', $dir.'/'.$name);
            $set['artifacts'][$name.'.gpg']['plaintext_bytes'] = filesize($dir.'/'.$name);
        }
        file_put_contents($dir.'/'.BackupSet::SET_FILE, json_encode($set, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");

        $sums = '';
        foreach (explode("\n", trim((string) file_get_contents($dir.'/'.BackupSet::SUMS))) as $line) {
            $name = substr($line, 66);
            $sums .= ($name === BackupSet::SET_FILE ? hash_file('sha256', $dir.'/'.$name) : substr($line, 0, 64)).'  '.$name."\n";
        }
        file_put_contents($dir.'/'.BackupSet::SUMS, $sums);
        file_put_contents($dir.'/'.BackupSet::COMPLETE, 'format='.BackupSet::FORMAT."\nbackup_id={$set['backup_id']}\nsha256sums=".hash('sha256', $sums)."\n");
    }

    /** @return array{home: string, public: string, fingerprint: string} */
    private static function keyPair(string $name): array
    {
        $home = RehearsalSandbox::root().'/gnupg-'.$name;
        @mkdir($home, 0700);
        chmod($home, 0700);

        $gpg = fn (array $args) => tap(new Process(['gpg', '--homedir', $home, '--batch', '--pinentry-mode', 'loopback', '--passphrase', '', ...$args], null, ['LC_ALL' => 'C'], null, 120))->mustRun();

        $gpg(['--quick-gen-key', "WP-B1 {$name} <{$name}@backup.test>", 'ed25519', 'sign', 'never']);
        preg_match('/^fpr:+([0-9A-F]{40}):/m', $gpg(['--with-colons', '--list-keys'])->getOutput(), $m);
        $gpg(['--quick-add-key', $m[1], 'cv25519', 'encr', 'never']);
        $public = $home.'/public.asc';
        file_put_contents($public, $gpg(['--armor', '--export', $m[1]])->getOutput());

        return ['home' => $home, 'public' => $public, 'fingerprint' => $m[1]];
    }
}
