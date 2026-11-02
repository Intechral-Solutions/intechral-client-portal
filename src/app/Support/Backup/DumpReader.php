<?php

namespace App\Support\Backup;

use App\Support\Helpdesk\TicketReference;
use RuntimeException;

/**
 * Reads a `mariadb-dump` file and describes it, without a database (Release 0.9 WP-B1).
 *
 * The backup's consistency rests on this reader: the attachment rows a backup copies files for, the ticket identity
 * fingerprint and the per-table row digests all come from the dump itself, which `mariadb-dump --single-transaction`
 * wrote from ONE consistent snapshot. Nothing is taken from a separate query against the live database.
 *
 * It is also the restore gate. A dump is accepted only if every statement is one `mariadb-dump` writes for a single
 * database, in its order: exactly the session `SET`s of {@see SESSION_SETS} (the SQL mode among them, before any table
 * statement, so the server reads quoting exactly as this reader does), each `CREATE TABLE` immediately after the
 * `DROP TABLE IF EXISTS` of the same table and ending in its InnoDB/utf8mb4 options, `LOCK`/`UNLOCK TABLES`,
 * `ALTER TABLE … DISABLE/ENABLE KEYS`, `INSERT INTO` with a plain column list, and the 11.8 client's per-table
 * autocommit/`COMMIT` lines. Outside quoted values every identifier must be a plain name, never qualified with a
 * database, no `SELECT` may appear, no comment may start inside a statement (plain or version-gated: the server may
 * skip or run it), and no control character other than a newline may appear outside quoted values. Anything else (`USE`, `CREATE`/`DROP DATABASE`, a trigger, view or routine,
 * `DELIMITER`, a client command, another SQL mode or character set) is a violation. This is a strict allowlist, not a
 * sandbox: the loader also runs the client with `--binary-mode --sandbox`, and a set is authenticated by its digest.
 *
 * Lexing follows the server's rules: `'…'`, `"…"` and backtick quoting with backslash escapes and doubled quotes,
 * `-- `, `#` and `/* … *\/` comments, and versioned comments `/*!NNNNN … *\/` (`/*M!NNNNNN … *\/`), which ARE
 * statements. The first line `mariadb-dump` 11.4+ writes (`/*M!999999\- enable the sandbox mode *\/`) is a
 * client directive and is recognised only there; anywhere else a backslash outside a quoted value is a violation, because the
 * `mariadb` client would read it as a command (`\!` runs a shell command).
 *
 * Messages never quote row values: a dump holds customer data.
 */
final class DumpReader
{
    public const TRAILER = '-- Dump completed';

    public const SANDBOX_LINE = '/*M!999999\- enable the sandbox mode */';

    /** Columns decoded from these tables; every other table is only counted and digested. */
    private const DECODED = [
        'tickets' => ['id', 'ticket_number'],
        'ticket_attachments' => ['id', 'ticket_id', 'reply_id', 'user_id', 'path', 'size'],
        'migrations' => ['migration'],
    ];

    private const NAME = '[A-Za-z0-9_]{1,64}';

    /**
     * The session statements `mariadb-dump` (10.11, 11.4, 11.8) writes inside versioned comments, exactly, each with
     * the exact version gate it is written under (a higher gate would make the server skip what this reader counts). Nothing else
     * is accepted: a `SET` of another SQL mode (`NO_BACKSLASH_ESCAPES`, `ANSI_QUOTES`) or character set would make the
     * server read string boundaries differently from this reader, and so let a crafted value smuggle a statement.
     */
    public const SESSION_SETS = [
        'SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT' => '!40101',
        'SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS' => '!40101',
        'SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION' => '!40101',
        'SET NAMES utf8mb4' => '!40101',
        'SET @OLD_TIME_ZONE=@@TIME_ZONE' => '!40103',
        "SET TIME_ZONE='+00:00'" => '!40103',
        'SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0' => '!40014',
        'SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0' => '!40014',
        self::SQL_MODE => '!40101',
        'SET @OLD_NOTE_VERBOSITY=@@NOTE_VERBOSITY, NOTE_VERBOSITY=0' => 'M!100616',
        'SET @saved_cs_client     = @@character_set_client' => '!40101',
        'SET character_set_client = utf8mb4' => '!40101',
        'SET character_set_client = @saved_cs_client' => '!40101',
        'SET TIME_ZONE=@OLD_TIME_ZONE' => '!40103',
        'SET SQL_MODE=@OLD_SQL_MODE' => '!40101',
        'SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS' => '!40014',
        'SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS' => '!40014',
        'SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT' => '!40101',
        'SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS' => '!40101',
        'SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION' => '!40101',
        'SET NOTE_VERBOSITY=@OLD_NOTE_VERBOSITY' => 'M!100616',
        // 10.11 writes the SQL_NOTES pair where 11.x writes NOTE_VERBOSITY.
        'SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0' => '!40111',
        'SET SQL_NOTES=@OLD_SQL_NOTES' => '!40111',
    ];

    /** Fixes the session's SQL mode (no NO_BACKSLASH_ESCAPES, no ANSI_QUOTES) before any data; required before any table statement. */
    public const SQL_MODE = "SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO'";

    /** The non-versioned statements, exactly: `UNLOCK TABLES` and the per-table transaction the 11.8 client writes. */
    private const PLAIN_STATEMENTS = ['UNLOCK TABLES', 'SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0', 'COMMIT', 'SET AUTOCOMMIT=@OLD_AUTOCOMMIT'];

    /**
     * Columns a recovery deliberately changes, left out of each table's `stable_sha256` (every other column is in it):
     * the remember-me tokens are blanked so that no pre-recovery browser stays signed in.
     */
    public const RECOVERY_BLANKED = ['users' => ['remember_token']];

    /** @var array<string, array{create_sha256: string, engine: string, columns: ?list<string>, rows: int, ctx: \HashContext, stable: ?\HashContext}> */
    private array $tables = [];

    /** @var list<string> */
    private array $violations = [];

    /** @var list<array{0: int, 1: string}> */
    private array $tickets = [];

    /** @var array<int, array{ticket_id: int, reply_id: ?int, user_id: int, path: string, size: int}> */
    private array $attachments = [];

    /** @var list<string> */
    private array $migrations = [];

    private ?string $serverVersion = null;

    private bool $sandbox = false;

    private bool $trailer = false;

    private bool $sawStatementAfterTrailer = false;

    private bool $sqlModeSet = false;

    private ?string $pendingDrop = null;

    private function __construct() {}

    /**
     * Describe the dump at $path (plain, or gzip when it ends in `.gz`).
     *
     * @return array{
     *     server_version: ?string, sandbox: bool, trailer: bool, violations: list<string>,
     *     schema_sha256: string,
     *     tables: array<string, array{create_sha256: string, engine: string, rows: int, rows_sha256: string}>,
     *     tickets: array{count: int, identity_sha256: string, canonical: int, non_canonical: int, duplicates: int},
     *     attachments: array<int, array{ticket_id: int, reply_id: ?int, user_id: int, path: string, size: int}>,
     *     migrations: array{count: int, last: ?string, sha256: string},
     * }
     */
    public static function read(string $path): array
    {
        $handle = @fopen((str_ends_with($path, '.gz') ? 'compress.zlib://' : '').$path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('The database dump cannot be opened: '.basename($path));
        }

        $reader = new self;

        try {
            $reader->scan($handle);
        } finally {
            fclose($handle);
        }

        return $reader->summary();
    }

    /** @param  resource  $handle */
    private function scan($handle): void
    {
        $state = 'normal';
        $statement = '';
        $skeleton = '';   // the statement with the contents of quoted values left out (identifiers kept)
        $first = true;
        $lastComment = null;

        while (($line = fgets($handle)) !== false) {
            if ($first) {
                $first = false;
                if (rtrim($line) === self::SANDBOX_LINE) {
                    $this->sandbox = true;

                    continue;
                }
            }

            $length = strlen($line);
            $i = 0;

            while ($i < $length) {
                if ($state === 'line-comment') {
                    $end = strpos($line, "\n", $i);
                    $comment = rtrim(substr($line, $i, $end === false ? null : $end - $i));
                    if (trim($statement) === '') {
                        $lastComment = $comment;
                        $this->header($comment);
                    }
                    $state = 'normal';
                    $i = $end === false ? $length : $end + 1;

                    continue;
                }

                if ($state === 'block-comment') {
                    $end = strpos($line, '*/', $i);
                    if ($end === false) {
                        $i = $length;

                        continue;
                    }
                    $state = 'normal';
                    $i = $end + 2;

                    continue;
                }

                if ($state !== 'normal') {
                    // Inside a quoted string or identifier: jump to the next escape or quote character.
                    $quote = ['single' => "'", 'double' => '"', 'backtick' => '`'][$state];
                    $skip = strcspn($line, $state === 'backtick' ? '`' : $quote.'\\', $i);
                    $statement .= substr($line, $i, $skip);
                    $skeleton .= $state === 'backtick' ? substr($line, $i, $skip) : '';
                    $i += $skip;
                    if ($i >= $length) {
                        continue;
                    }
                    $char = $line[$i];
                    if ($char === '\\') {
                        $statement .= substr($line, $i, 2);
                        $i += 2;

                        continue;
                    }
                    if (($line[$i + 1] ?? '') === $quote) {
                        $statement .= $quote.$quote;
                        $skeleton .= $state === 'backtick' ? '``' : '';
                        $i += 2;

                        continue;
                    }
                    $statement .= $char;
                    $skeleton .= $char;
                    $state = 'normal';
                    $i++;

                    continue;
                }

                $skip = strcspn($line, "'\"`;-#/\\", $i);
                $statement .= substr($line, $i, $skip);
                $skeleton .= substr($line, $i, $skip);
                $i += $skip;
                if ($i >= $length) {
                    continue;
                }

                $char = $line[$i];
                $next = $line[$i + 1] ?? '';

                // A comment of any kind (plain or version-gated, which the server may skip or run) may only stand
                // between statements: inside one, the server could end the statement where this reader does not.
                $opensComment = ($char === '-' && $next === '-') || $char === '#' || ($char === '/' && $next === '*');
                if ($opensComment && trim($statement) !== '') {
                    $this->violations[] = 'a comment inside a statement';
                }

                if ($char === '\\') {
                    // Outside a quoted value a backslash can only be a client command (`\!` runs a shell command); a dump has none.
                    $this->violations[] = 'a backslash (client command) outside a quoted value';
                    $statement .= $char;
                    $skeleton .= $char;
                    $i++;
                } elseif ($char === ';') {
                    $this->statement($statement, $skeleton);
                    $statement = $skeleton = '';
                    $lastComment = null;
                    $i++;
                } elseif ($char === "'" || $char === '"' || $char === '`') {
                    $state = ['\'' => 'single', '"' => 'double', '`' => 'backtick'][$char];
                    $statement .= $char;
                    $skeleton .= $char;
                    $i++;
                } elseif ($char === '-' && $next === '-' && ord($line[$i + 2] ?? "\n") <= 32) {
                    $state = 'line-comment';
                } elseif ($char === '#') {
                    $state = 'line-comment';
                } elseif ($char === '/' && $next === '*' && ($line[$i + 2] ?? '') !== '!' && substr($line, $i + 2, 2) !== 'M!') {
                    $state = 'block-comment';
                    $i += 2;
                } else {
                    $statement .= $char;
                    $skeleton .= $char;
                    $i++;
                }
            }
        }

        if ($state !== 'normal' && $state !== 'line-comment') {
            $this->violations[] = 'the dump ends inside an unterminated '.$state.($state === 'block-comment' ? '' : ' quote');
        }

        if (trim($statement) !== '') {
            $this->violations[] = 'the dump ends with an unterminated statement (truncated?)';
        }

        if ($this->pendingDrop !== null) {
            $this->violations[] = "table `{$this->pendingDrop}` is dropped and never created again";
        }

        // The trailer must be the last thing in the file, after every statement.
        $this->trailer = $lastComment !== null && str_starts_with($lastComment, self::TRAILER) && ! $this->sawStatementAfterTrailer;
    }

    private function header(string $comment): void
    {
        if ($this->serverVersion === null && preg_match('/^-- Server version\s+(\S+)/', $comment, $m)) {
            $this->serverVersion = $m[1];
        }

        if (str_starts_with($comment, self::TRAILER)) {
            $this->trailer = true;
        }
    }

    private function statement(string $statement, string $skeleton): void
    {
        $statement = trim($statement);
        $skeleton = trim($skeleton);

        if ($statement === '') {
            return;
        }

        if ($this->trailer) {
            $this->sawStatementAfterTrailer = true;
        }

        $name = self::NAME;

        if (preg_match('~\A/\*(M?!\d{5,6}) (.*) \*/\z~s', $statement, $m)) {
            [, $gate, $inner] = $m;
            if ((self::SESSION_SETS[$inner] ?? null) === $gate) {
                $this->sqlModeSet = $this->sqlModeSet || $inner === self::SQL_MODE;

                return;
            }
            if ($gate === '!40000' && preg_match("/\\AALTER TABLE `({$name})` (DISABLE|ENABLE) KEYS\\z/", $inner, $t) && isset($this->tables[$t[1]]) && $this->pendingDrop === null) {
                return;
            }
            $this->violations[] = 'unexpected versioned statement '.$this->kind($inner);

            return;
        }

        if (in_array($statement, self::PLAIN_STATEMENTS, true) && $this->pendingDrop === null) {
            return;
        }

        // Every table statement: the SQL mode is fixed first; identifiers are plain names, never qualified with another
        // database; no SELECT; and a dropped table is created again immediately.
        $problems = [];
        if (! $this->sqlModeSet) {
            $problems[] = 'a table statement comes before the dump\'s SQL_MODE line';
        }
        preg_match_all('/`((?:[^`]|``)*)`/', $skeleton, $identifiers);
        foreach ($identifiers[1] as $identifier) {
            if (! preg_match("/\\A{$name}\\z/", $identifier)) {
                $problems[] = 'an identifier that is not a plain table or column name';
                break;
            }
        }
        if (preg_match('/`\s*\.|\.\s*`|\bSELECT\b/i', $skeleton)) {
            $problems[] = 'a qualified name or a SELECT';
        }
        if (preg_match('/[\x00-\x09\x0B-\x1F\x7F]/', $skeleton)) {
            $problems[] = 'a control character outside a quoted value';
        }
        if ($problems !== []) {
            $this->violations[] = 'unexpected statement '.$this->kind($statement).': '.implode('; ', array_unique($problems));

            return;
        }

        if (preg_match("/\\ADROP TABLE IF EXISTS `({$name})`\\z/", $statement, $m) && $this->pendingDrop === null) {
            $this->pendingDrop = $m[1];

            return;
        }

        if (preg_match("/\\ACREATE TABLE `({$name})` \\(\\n.*\\n\\) ENGINE=(\\w+)(?: AUTO_INCREMENT=\\d+)? DEFAULT CHARSET=utf8mb4(?: COLLATE=utf8mb4_\\w+)?\\z/s", $statement, $m)
            && $this->pendingDrop === $m[1]) {
            $this->pendingDrop = null;
            $this->create($m[1], $m[2], $statement);

            return;
        }

        if (preg_match("/\\ALOCK TABLES `({$name})` WRITE\\z/", $statement, $m) && isset($this->tables[$m[1]]) && $this->pendingDrop === null) {
            return;
        }

        if (preg_match("/\\AINSERT INTO `({$name})` \\(/", $statement, $m) && $this->pendingDrop === null) {
            $this->insert($m[1], $statement);

            return;
        }

        $this->violations[] = 'unexpected statement '.$this->kind($statement);
    }

    /** The first word or two of a statement, for a message that must not echo data. */
    private function kind(string $statement): string
    {
        preg_match('/\A[A-Za-z\\\\]+(?:\s+[A-Za-z]+)?/', ltrim($statement, '/*!M0123456789 '), $m);

        return '"'.($m[0] ?? substr($statement, 0, 2)).'"';
    }

    private function create(string $table, string $engine, string $statement): void
    {
        if (isset($this->tables[$table])) {
            $this->violations[] = "table `{$table}` is created twice";

            return;
        }

        if (strcasecmp($engine, 'InnoDB') !== 0) {
            // --single-transaction is a consistent snapshot of InnoDB tables only.
            $this->violations[] = "table `{$table}` uses the {$engine} engine, not InnoDB, so the snapshot is not consistent for it";
        }

        $this->tables[$table] = [
            'create_sha256' => hash('sha256', self::normalizedCreate($statement)),
            'engine' => $engine,
            'columns' => null,
            'rows' => 0,
            'ctx' => hash_init('sha256'),
            'stable' => isset(self::RECOVERY_BLANKED[$table]) ? hash_init('sha256') : null,
        ];
    }

    /**
     * The CREATE TABLE text compared between a backup and its restore. Only the AUTO_INCREMENT counter is removed:
     * it is a property of the table, not of its rows, and the server may report it differently after a reload.
     */
    public static function normalizedCreate(string $statement): string
    {
        return preg_replace('/ AUTO_INCREMENT=\d+/', '', $statement) ?? $statement;
    }

    private function insert(string $table, string $statement): void
    {
        if (! isset($this->tables[$table])) {
            $this->violations[] = "rows for table `{$table}` come before its CREATE TABLE";

            return;
        }

        $close = strpos($statement, ') VALUES ');
        if ($close === false) {
            $this->violations[] = "an INSERT into `{$table}` has no column list";

            return;
        }

        $rawColumns = explode(', ', substr($statement, strlen("INSERT INTO `{$table}` ("), $close - strlen("INSERT INTO `{$table}` (")));
        foreach ($rawColumns as $raw) {
            if (! preg_match('/\A`'.self::NAME.'`\z/', $raw)) {
                $this->violations[] = "an INSERT into `{$table}` has a malformed column list";

                return;
            }
        }
        $columns = array_map(fn (string $c) => trim($c, '`'), $rawColumns);

        if ($this->tables[$table]['columns'] === null) {
            $this->tables[$table]['columns'] = $columns;
        } elseif ($this->tables[$table]['columns'] !== $columns) {
            $this->violations[] = "INSERTs into `{$table}` name different columns";

            return;
        }

        $decode = self::DECODED[$table] ?? [];
        $positions = [];
        foreach ($decode as $column) {
            $position = array_search($column, $columns, true);
            if ($position === false) {
                $this->violations[] = "table `{$table}` has no `{$column}` column";

                return;
            }
            $positions[$column] = $position;
        }

        try {
            $blanked = array_flip(array_keys(array_intersect($columns, self::RECOVERY_BLANKED[$table] ?? [])));
            foreach (self::tuples($statement, $close + strlen(') VALUES ')) as [$raw, $values, $tokens]) {
                if (count($values) !== count($columns)) {
                    $this->violations[] = "a row of `{$table}` has ".count($values).' values for '.count($columns).' columns';

                    return;
                }
                $this->tables[$table]['rows']++;
                hash_update($this->tables[$table]['ctx'], $raw."\n");
                if ($this->tables[$table]['stable'] !== null) {
                    $stable = array_map(fn (string $t, int $i) => isset($blanked[$i]) ? 'NULL' : $t, $tokens, array_keys($tokens));
                    hash_update($this->tables[$table]['stable'], '('.implode(',', $stable).")\n");
                }

                if ($positions !== []) {
                    $this->decoded($table, array_map(fn (int $p) => $values[$p], $positions));
                }
            }
        } catch (RuntimeException $e) {
            $this->violations[] = "an INSERT into `{$table}` cannot be parsed: ".$e->getMessage();
        }
    }

    /** @param  array<string, ?string>  $row  decoded values by column; null is SQL NULL */
    private function decoded(string $table, array $row): void
    {
        match ($table) {
            'tickets' => $this->tickets[] = [(int) $row['id'], (string) $row['ticket_number']],
            'migrations' => $this->migrations[] = (string) $row['migration'],
            'ticket_attachments' => $this->attachments[(int) $row['id']] = [
                'ticket_id' => (int) $row['ticket_id'],
                'reply_id' => $row['reply_id'] === null ? null : (int) $row['reply_id'],
                'user_id' => (int) $row['user_id'],
                'path' => (string) $row['path'],
                'size' => (int) $row['size'],
            ],
        };
    }

    /**
     * The value tuples of an extended INSERT, from $offset: each as its raw text (for the row digest) and its values
     * (strings unescaped, NULL as null, numbers and hex literals as written).
     *
     * @return \Generator<int, array{0: string, 1: list<?string>, 2: list<string>}>
     */
    public static function tuples(string $statement, int $offset): \Generator
    {
        $length = strlen($statement);
        $i = $offset;

        while (true) {
            if (($statement[$i] ?? '') !== '(') {
                throw new RuntimeException('expected "(" at offset '.$i);
            }
            $start = $i++;
            $values = [];
            $tokens = [];

            while (true) {
                $at = $i;
                if (($statement[$i] ?? '') === "'") {
                    [$value, $i] = self::string($statement, $i);
                    $values[] = $value;
                } else {
                    $span = strcspn($statement, ',)', $i);
                    $token = substr($statement, $i, $span);
                    if (! preg_match('/\A(NULL|-?\d+(\.\d+)?([eE][-+]?\d+)?|0x[0-9A-Fa-f]*)\z/', $token)) {
                        throw new RuntimeException('unexpected value token at offset '.$i);
                    }
                    $values[] = $token === 'NULL' ? null : $token;
                    $i += $span;
                }
                $tokens[] = substr($statement, $at, $i - $at);

                $char = $statement[$i] ?? '';
                $i++;
                if ($char === ',') {
                    continue;
                }
                if ($char === ')') {
                    break;
                }
                throw new RuntimeException('unterminated row at offset '.$start);
            }

            yield [substr($statement, $start, $i - $start), $values, $tokens];

            $i += strspn($statement, " \n\r\t", $i);
            if ($i >= $length) {
                return;
            }
            if ($statement[$i] !== ',') {
                throw new RuntimeException('expected "," between rows at offset '.$i);
            }
            $i++;
            $i += strspn($statement, " \n\r\t", $i);
        }
    }

    /** @return array{0: string, 1: int} the unescaped string literal starting at $i, and the offset after it */
    private static function string(string $statement, int $i): array
    {
        static $escapes = ['0' => "\0", "'" => "'", '"' => '"', 'b' => "\x08", 'n' => "\n", 'r' => "\r", 't' => "\t", 'Z' => "\x1A", '\\' => '\\'];

        $value = '';
        $i++;

        while (true) {
            $span = strcspn($statement, "'\\", $i);
            $value .= substr($statement, $i, $span);
            $i += $span;
            $char = $statement[$i] ?? null;

            if ($char === null) {
                throw new RuntimeException('unterminated string');
            }
            if ($char === '\\') {
                $next = $statement[$i + 1] ?? '';
                // MariaDB keeps the backslash for an unknown escape (and for \% and \_).
                $value .= $escapes[$next] ?? '\\'.$next;
                $i += 2;

                continue;
            }
            if (($statement[$i + 1] ?? '') === "'") {
                $value .= "'";
                $i += 2;

                continue;
            }

            return [$value, $i + 1];
        }
    }

    /** @return array<string, mixed> */
    private function summary(): array
    {
        ksort($this->tables);

        $tables = [];
        $schema = hash_init('sha256');
        foreach ($this->tables as $name => $table) {
            $tables[$name] = [
                'create_sha256' => $table['create_sha256'],
                'engine' => $table['engine'],
                'rows' => $table['rows'],
                'rows_sha256' => hash_final($table['ctx']),
            ] + ($table['stable'] !== null ? ['stable_sha256' => hash_final($table['stable'])] : []);
            hash_update($schema, $name."\t".$table['create_sha256']."\n");
        }

        usort($this->tickets, fn (array $a, array $b) => $a[0] <=> $b[0]);
        $identity = hash_init('sha256');
        $canonical = 0;
        $seen = [];
        $duplicates = 0;
        foreach ($this->tickets as [$id, $reference]) {
            hash_update($identity, $id."\t".$reference."\n");
            $canonical += TicketReference::isCanonical($reference) ? 1 : 0;
            $key = strtolower(trim($reference));
            $duplicates += isset($seen[$key]) ? 1 : 0;
            $seen[$key] = true;
        }

        ksort($this->attachments);
        $migrations = $this->migrations;
        sort($migrations);

        return [
            'server_version' => $this->serverVersion,
            'sandbox' => $this->sandbox,
            'trailer' => $this->trailer,
            'violations' => array_values(array_unique($this->violations)),
            'schema_sha256' => hash_final($schema),
            'tables' => $tables,
            'tickets' => [
                'count' => count($this->tickets),
                'identity_sha256' => hash_final($identity),
                'canonical' => $canonical,
                'non_canonical' => count($this->tickets) - $canonical,
                'duplicates' => $duplicates,
            ],
            'attachments' => $this->attachments,
            'migrations' => [
                'count' => count($migrations),
                'last' => $migrations === [] ? null : end($migrations),
                'sha256' => hash('sha256', implode("\n", $migrations)),
            ],
        ];
    }
}
