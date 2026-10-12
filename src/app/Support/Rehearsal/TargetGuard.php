<?php

namespace App\Support\Rehearsal;

use Illuminate\Database\Connection;
use Throwable;

/**
 * The safety contract for a rehearsal target. It is checked immediately before every destructive step
 * (not once at start-up), and every check is independent of the others: a matching name is never taken as
 * proof that a database is disposable.
 *
 * A target is acceptable only when ALL of these hold:
 *
 *  1. Authority. The runtime environment AND the configured `app.env` are `local` or `testing` (the global
 *     `--env` option changes only the first). `production`, and anything unrecognised, is refused.
 *  2. Identity by configuration. Driver MySQL/MariaDB; host exactly `db`; port 3306; the database is the
 *     exact reserved name for its role; no URL, socket or read/write split can redirect it; it is not a
 *     protected database (the development `portal`, the Pest testing database, the system schemas) and it
 *     is not the database the application itself is configured to use.
 *  3. Identity on the wire. The live connection reports that database, and the account is not `root` and
 *     holds no global privilege (`ON *.*` beyond USAGE). The shared `portal` account still holds ALL on the
 *     development and Pest testing databases, so the grants do not keep the harness away from them: these
 *     runtime checks (1, 2 and 4) do.
 *  4. Ownership. The database is either empty or carries the rehearsal marker table, with this harness's
 *     signature and this role. A database with tables and no marker is "unidentified" and is refused.
 *  5. Files. Its private-file directory is provably the harness's own ({@see RehearsalFiles}).
 *
 * MariaDB DDL commits implicitly and a transaction would not undo a dropped table, so the guard is the only
 * protection; it runs first and refuses before any statement that changes anything.
 */
final class TargetGuard
{
    public const ENVIRONMENTS = ['local', 'testing'];

    /**
     * @param  array<string, mixed>  $config  the connection configuration
     * @return list<string>
     */
    public static function configViolations(
        RehearsalTarget $target,
        array $config,
        string $runtimeEnvironment,
        string $configuredEnvironment,
        string $applicationDatabase,
    ): array {
        $violations = [];

        foreach (['runtime environment' => $runtimeEnvironment, 'configured app.env' => $configuredEnvironment] as $label => $value) {
            if (! in_array($value, self::ENVIRONMENTS, true)) {
                $violations[] = "{$label} is \"{$value}\"; rehearsal runs only in ".implode(' or ', self::ENVIRONMENTS);
            }
        }

        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $violations[] = 'the connection driver is "'.($config['driver'] ?? '').'", not mysql/mariadb';
        }

        if (($config['host'] ?? null) !== RehearsalTarget::HOST) {
            $violations[] = 'the database host is "'.($config['host'] ?? '').'", not "'.RehearsalTarget::HOST.'"';
        }

        if ((string) ($config['port'] ?? '') !== (string) RehearsalTarget::PORT) {
            $violations[] = 'the database port is "'.($config['port'] ?? '').'", not '.RehearsalTarget::PORT;
        }

        $database = (string) ($config['database'] ?? '');

        if ($database !== $target->database()) {
            $violations[] = "the database is \"{$database}\", not the reserved \"{$target->database()}\"";
        }

        if (in_array($database, RehearsalTarget::PROTECTED_DATABASES, true)) {
            $violations[] = "\"{$database}\" is a protected database";
        }

        if ($applicationDatabase !== '' && $database === $applicationDatabase) {
            $violations[] = "\"{$database}\" is the database the application itself is configured to use";
        }

        if (in_array(strtolower((string) ($config['username'] ?? '')), ['', 'root'], true)) {
            $violations[] = 'the database account is empty or root';
        }

        foreach (['url', 'unix_socket'] as $key) {
            if (! empty($config[$key])) {
                $violations[] = "the connection sets \"{$key}\", which could redirect it";
            }
        }

        if (! empty($config['read']) || ! empty($config['write'])) {
            $violations[] = 'the connection defines read/write hosts, which could redirect it';
        }

        return $violations;
    }

    /**
     * @param  list<string>  $grants  the lines of SHOW GRANTS
     * @return list<string>
     */
    public static function grantViolations(array $grants): array
    {
        $violations = [];

        foreach ($grants as $grant) {
            if (preg_match('/^GRANT\s+(.+?)\s+ON\s+`?\*`?\.`?\*`?\s+TO\b/i', $grant, $match)
                && strtoupper(trim($match[1])) !== 'USAGE') {
                $violations[] = 'the account holds a global privilege ('.trim($match[1]).' ON *.*)';
            }
        }

        return $violations;
    }

    /**
     * @param  list<string>  $tables  every table in the database
     * @param  list<array{signature: ?string, role: ?string}>  $markers  the rows of the marker table, if any
     * @return list<string>
     */
    public static function ownershipViolations(array $tables, array $markers, RehearsalTarget $target): array
    {
        if ($tables === []) {
            return [];
        }

        if (! in_array(RehearsalTarget::MARKER_TABLE, $tables, true)) {
            return ['the database has tables but no rehearsal marker: it is unidentified and will not be touched'];
        }

        if (count($markers) !== 1
            || ($markers[0]['signature'] ?? null) !== RehearsalTarget::SIGNATURE
            || ($markers[0]['role'] ?? null) !== $target->role) {
            return ["the rehearsal marker is not this harness's marker for role \"{$target->role}\""];
        }

        return [];
    }

    /**
     * Verify $target in full and return a report that is safe to print (no credentials).
     *
     * @return array<string, mixed>
     *
     * @throws UnsafeRehearsalTarget
     */
    public static function assert(RehearsalTarget $target): array
    {
        $target->register();

        $config = (array) config('database.connections.'.$target->connectionName());
        $violations = self::configViolations(
            $target,
            $config,
            app()->environment(),
            (string) config('app.env'),
            (string) config('database.connections.mysql.database'),
        );

        $report = [
            'role' => $target->role,
            'environment' => app()->environment(),
            'configured_environment' => (string) config('app.env'),
            'database' => $target->database(),
            'host' => (string) ($config['host'] ?? ''),
            'port' => (int) ($config['port'] ?? 0),
        ];

        // The live checks are skipped when the configuration is already wrong: a connection is never opened
        // to a target the configuration alone has ruled out.
        if ($violations === []) {
            $violations = [...$violations, ...self::liveViolations($target, $report)];
        }

        $violations = [...$violations, ...RehearsalFiles::violations($target->filesRoot(), RehearsalFiles::kindFor($target))];

        if ($violations !== []) {
            throw new UnsafeRehearsalTarget($violations);
        }

        return $report;
    }

    /**
     * @param  array<string, mixed>  $report  filled in with what the live checks learn
     * @return list<string>
     */
    private static function liveViolations(RehearsalTarget $target, array &$report): array
    {
        try {
            $connection = $target->connection();
            $identity = $connection->selectOne('select database() as db, current_user() as account');
        } catch (Throwable $e) {
            return ['cannot connect to "'.$target->database().'" ('.strtok($e->getMessage(), "\n").'). '
                .'Provision the rehearsal databases first: ./dev rehearse:provision'];
        }

        $violations = [];
        $report['account'] = (string) $identity->account;

        if ($identity->db !== $target->database()) {
            $violations[] = 'the live connection reports database "'.$identity->db.'", not "'.$target->database().'"';
        }

        $grants = array_map(
            fn (object $row) => (string) array_values((array) $row)[0],
            $connection->select('show grants for current_user()')
        );
        $violations = [...$violations, ...self::grantViolations($grants)];

        $tables = self::tables($connection, $target);
        $markers = in_array(RehearsalTarget::MARKER_TABLE, $tables, true)
            ? array_map(
                fn (object $row) => ['signature' => $row->signature, 'role' => $row->role],
                $connection->select('select signature, role from `'.RehearsalTarget::MARKER_TABLE.'` limit 2')
            )
            : [];

        $violations = [...$violations, ...self::ownershipViolations($tables, $markers, $target)];
        $report['database_state'] = $tables === [] ? 'empty' : 'marked';
        $report['tables'] = count($tables);

        return $violations;
    }

    /** @return list<string> */
    private static function tables(Connection $connection, RehearsalTarget $target): array
    {
        return array_map(
            fn (object $row) => (string) $row->t,
            $connection->select(
                'select table_name as t from information_schema.tables where table_schema = ? order by table_name',
                [$target->database()]
            )
        );
    }
}
