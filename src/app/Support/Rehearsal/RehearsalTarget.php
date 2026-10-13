<?php

namespace App\Support\Rehearsal;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * One of the two disposable places a migration rehearsal may write (Release 0.9, R0.9-1A): a MariaDB
 * database and a private-file root, both reserved for rehearsal.
 *
 * The names are constants, not configuration, so no environment variable, option or caller can point the
 * harness at another database. The connection is built here at run time, from the application's own
 * credentials and nothing else; it is never a standing entry in `config/database.php`, so
 * `php artisan migrate --database=...` cannot reach it by accident either. A name alone is not trusted:
 * {@see TargetGuard} verifies the environment, host, live database, account privileges and an identifying
 * marker before anything destructive happens.
 *
 *  - `primary`: where the baseline is built, migrated and snapshotted.
 *  - `restore`: where a backup of `primary` is restored, to prove the round trip.
 */
final class RehearsalTarget
{
    public const PRIMARY = 'primary';

    public const RESTORE = 'restore';

    public const DATABASES = [
        self::PRIMARY => 'intechral_client_portal_rehearsal',
        self::RESTORE => 'intechral_client_portal_rehearsal_restore',
    ];

    public const HOST = 'db';

    public const PORT = 3306;

    /** Table that identifies a database as one this harness created, and for which role. */
    public const MARKER_TABLE = 'rehearsal_marker';

    /** File that identifies a directory as one this harness created. */
    public const MARKER_FILE = '.rehearsal-marker';

    public const SIGNATURE = 'intechral-client-portal-rehearsal-v1';

    /** Databases that must never be a rehearsal target, whatever else is true. */
    public const PROTECTED_DATABASES = [
        'portal',
        'intechral_client_portal_testing',
        'mysql',
        'information_schema',
        'performance_schema',
        'sys',
    ];

    private function __construct(public readonly string $role) {}

    public static function primary(): self
    {
        return new self(self::PRIMARY);
    }

    public static function restore(): self
    {
        return new self(self::RESTORE);
    }

    public static function for(string $role): self
    {
        if (! array_key_exists($role, self::DATABASES)) {
            throw new InvalidArgumentException("Unknown rehearsal role \"{$role}\".");
        }

        return new self($role);
    }

    public function database(): string
    {
        return self::DATABASES[$this->role];
    }

    public function connectionName(): string
    {
        return 'rehearsal_'.$this->role;
    }

    /** The private-file root for this role. Rehearsal fixtures and restores write only beneath it. */
    public function filesRoot(): string
    {
        return self::baseDirectory().DIRECTORY_SEPARATOR.$this->role.DIRECTORY_SEPARATOR.'files';
    }

    /** Everything the harness writes outside the database lives under this one directory. */
    public static function baseDirectory(): string
    {
        return storage_path('rehearsal');
    }

    public static function backupsRoot(): string
    {
        return self::baseDirectory().DIRECTORY_SEPARATOR.'backups';
    }

    /**
     * The connection configuration. Credentials come from the application's own `mysql` connection; host,
     * port and database are fixed here. Nothing from `DB_DATABASE`, `DB_HOST` or `DB_URL` is used.
     *
     * @return array<string, mixed>
     */
    public function connectionConfig(): array
    {
        $base = (array) config('database.connections.mysql');

        return array_merge($base, [
            'driver' => 'mysql',
            'url' => null,
            'host' => self::HOST,
            'port' => self::PORT,
            'database' => $this->database(),
            'unix_socket' => '',
            'read' => null,
            'write' => null,
        ]);
    }

    /** Register (and discard any earlier instance of) the named connection. */
    public function register(): void
    {
        config(['database.connections.'.$this->connectionName() => $this->connectionConfig()]);
        DB::purge($this->connectionName());
    }

    public function connection(): Connection
    {
        $this->register();

        return DB::connection($this->connectionName());
    }
}
