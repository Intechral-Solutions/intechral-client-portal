<?php

use App\Support\Backup\DumpReader;

/*
 * WP-B1: the dump reader is both the source of the backup's attachment inventory and ticket fingerprint, and the
 * gate a dump passes before it is loaded. These tests need no database.
 */

function dumpFile(string $body, bool $trailer = true, bool $sandbox = true, bool $sqlMode = true): string
{
    $path = tempnam(sys_get_temp_dir(), 'wpb1-dump-');
    $text = ($sandbox ? DumpReader::SANDBOX_LINE."\n" : '')
        ."-- MariaDB dump 10.19-11.4.13-MariaDB, for debian-linux-gnu (x86_64)\n--\n-- Server version\t11.4.13-MariaDB\n\n"
        ."/*!40101 SET NAMES utf8mb4 */;\n/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;\n"
        .($sqlMode ? '/*!40101 '.DumpReader::SQL_MODE." */;\n" : '')
        .$body
        ."/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;\n"
        .($trailer ? "\n-- Dump completed on 2026-11-02 16:09:24\n" : '');
    file_put_contents($path, $text);
    register_shutdown_function(fn () => @unlink($path));

    return $path;
}

function minimalTables(string $ticketRows = "(1,'TK-ABCDE-FGHJK'),\n(2,'TK-00000-11111')", string $attachmentRows = "(1,1,NULL,3,'tickets/1/a.txt',10),(2,2,7,3,'tickets/2/b.pdf',20)"): string
{
    return "DROP TABLE IF EXISTS `tickets`;\n/*!40101 SET @saved_cs_client     = @@character_set_client */;\n"
        ."CREATE TABLE `tickets` (\n  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,\n  `ticket_number` varchar(16) NOT NULL,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB AUTO_INCREMENT=3 DEFAULT CHARSET=utf8mb4;\n"
        ."SET @OLD_AUTOCOMMIT=@@AUTOCOMMIT, @@AUTOCOMMIT=0;\nLOCK TABLES `tickets` WRITE;\n/*!40000 ALTER TABLE `tickets` DISABLE KEYS */;\n"
        ."INSERT INTO `tickets` (`id`, `ticket_number`) VALUES {$ticketRows};\n"
        ."/*!40000 ALTER TABLE `tickets` ENABLE KEYS */;\nUNLOCK TABLES;\nCOMMIT;\nSET AUTOCOMMIT=@OLD_AUTOCOMMIT;\n"
        ."DROP TABLE IF EXISTS `ticket_attachments`;\nCREATE TABLE `ticket_attachments` (\n  `id` bigint(20) unsigned NOT NULL,\n  `ticket_id` bigint(20) unsigned NOT NULL,\n  `reply_id` bigint(20) unsigned DEFAULT NULL,\n  `user_id` bigint(20) unsigned NOT NULL,\n  `path` varchar(255) NOT NULL,\n  `size` bigint(20) unsigned NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        ."LOCK TABLES `ticket_attachments` WRITE;\nINSERT INTO `ticket_attachments` (`id`, `ticket_id`, `reply_id`, `user_id`, `path`, `size`) VALUES {$attachmentRows};\nUNLOCK TABLES;\n"
        ."DROP TABLE IF EXISTS `migrations`;\nCREATE TABLE `migrations` (\n  `id` int(10) unsigned NOT NULL,\n  `migration` varchar(255) NOT NULL\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        ."INSERT INTO `migrations` (`id`, `migration`) VALUES (1,'2026_01_01_000000_a'),(2,'2026_01_02_000000_b');\n";
}

it('reads tables, rows, ticket references, attachment rows and migrations from a well-formed dump', function () {
    $summary = DumpReader::read(dumpFile(minimalTables()));

    expect($summary['violations'])->toBe([])
        ->and($summary['trailer'])->toBeTrue()
        ->and($summary['sandbox'])->toBeTrue()
        ->and($summary['server_version'])->toBe('11.4.13-MariaDB')
        ->and(array_keys($summary['tables']))->toBe(['migrations', 'ticket_attachments', 'tickets'])
        ->and($summary['tables']['tickets']['rows'])->toBe(2)
        ->and($summary['tickets'])->toMatchArray(['count' => 2, 'canonical' => 2, 'non_canonical' => 0, 'duplicates' => 0])
        ->and($summary['attachments'])->toBe([
            1 => ['ticket_id' => 1, 'reply_id' => null, 'user_id' => 3, 'path' => 'tickets/1/a.txt', 'size' => 10],
            2 => ['ticket_id' => 2, 'reply_id' => 7, 'user_id' => 3, 'path' => 'tickets/2/b.pdf', 'size' => 20],
        ])
        ->and($summary['migrations'])->toMatchArray(['count' => 2, 'last' => '2026_01_02_000000_b']);
});

it('ignores the AUTO_INCREMENT counter in the schema fingerprint, and nothing else', function () {
    $a = DumpReader::read(dumpFile(minimalTables()));
    $b = DumpReader::read(dumpFile(str_replace('AUTO_INCREMENT=3', 'AUTO_INCREMENT=99', minimalTables())));
    $c = DumpReader::read(dumpFile(str_replace('varchar(16)', 'varchar(17)', minimalTables())));

    expect($b['schema_sha256'])->toBe($a['schema_sha256'])
        ->and($c['schema_sha256'])->not->toBe($a['schema_sha256']);
});

it('counts legacy and duplicate ticket references, case-insensitively', function () {
    $summary = DumpReader::read(dumpFile(minimalTables("(1,'TKT-0001'),(2,'TK-ABCDE-FGHJK'),(3,'tk-abcde-fghjk')")));

    expect($summary['tickets'])->toMatchArray(['count' => 3, 'canonical' => 1, 'non_canonical' => 2, 'duplicates' => 1]);
});

it('decodes escaped strings, so a path with quotes, backslashes or semicolons is read exactly', function () {
    $summary = DumpReader::read(dumpFile(minimalTables(attachmentRows: "(1,1,NULL,3,'tickets/1/it\\'s;a\\\\b.txt',10)")));

    expect($summary['violations'])->toBe([])
        ->and($summary['attachments'][1]['path'])->toBe("tickets/1/it's;a\\b.txt");
});

it('changes the row digest when any value changes, and the identity fingerprint when a reference moves', function () {
    $a = DumpReader::read(dumpFile(minimalTables()));
    $b = DumpReader::read(dumpFile(minimalTables(attachmentRows: "(1,1,NULL,3,'tickets/1/a.txt',11),(2,2,7,3,'tickets/2/b.pdf',20)")));
    $c = DumpReader::read(dumpFile(minimalTables("(1,'TK-00000-11111'),(2,'TK-ABCDE-FGHJK')")));

    expect($b['tables']['ticket_attachments']['rows_sha256'])->not->toBe($a['tables']['ticket_attachments']['rows_sha256'])
        ->and($c['tickets']['identity_sha256'])->not->toBe($a['tickets']['identity_sha256'])
        ->and($c['tables']['tickets']['rows_sha256'])->not->toBe($a['tables']['tickets']['rows_sha256']);
});

it('rejects statements a single-database dump never contains', function (string $statement) {
    $summary = DumpReader::read(dumpFile(minimalTables().$statement."\n"));

    expect($summary['violations'])->not->toBe([]);
})->with([
    'USE' => ['USE `portal`;'],
    'CREATE DATABASE' => ['CREATE DATABASE `other`;'],
    'DROP DATABASE' => ['/*!40000 DROP DATABASE IF EXISTS `portal`*/;'],
    'qualified insert' => ['INSERT INTO `portal`.`tickets` (`id`) VALUES (9);'],
    'drop of another database\'s table' => ['DROP TABLE IF EXISTS `portal`.`users`;'],
    'update' => ["UPDATE `tickets` SET `ticket_number` = 'x';"],
    'delete' => ['DELETE FROM `tickets`;'],
    'global set' => ['/*!40101 SET GLOBAL general_log = 1 */;'],
    'set password' => ["/*!40101 SET PASSWORD = 'x' */;"],
    'grant' => ['GRANT ALL ON *.* TO `x`;'],
    'trigger' => ['/*!50003 CREATE*/ /*!50003 TRIGGER t BEFORE INSERT ON `tickets` FOR EACH ROW SET @x = 1 */;'],
    'insert before create' => ['INSERT INTO `users` (`id`) VALUES (1);'],
    'second create' => ["CREATE TABLE `tickets` (\n  `id` int\n) ENGINE=InnoDB;"],
    'non-InnoDB table' => ["DROP TABLE IF EXISTS `logs`;\nCREATE TABLE `logs` (\n  `id` int\n) ENGINE=MyISAM DEFAULT CHARSET=utf8mb4;"],
    // Independent review H1: statements that make the server read quoting differently from the reader, or reach elsewhere.
    'another SQL mode' => ["/*!40101 SET SQL_MODE='NO_BACKSLASH_ESCAPES' */;"],
    'ANSI quotes' => ["/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='ANSI_QUOTES' */;"],
    'another character set' => ['/*!40101 SET NAMES big5 */;'],
    'a SELECT after the table options' => ["DROP TABLE IF EXISTS `x`;\nCREATE TABLE `x` (\n  `a` int\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 SELECT * FROM `y`;"],
    'a foreign key into another database' => ["DROP TABLE IF EXISTS `x`;\nCREATE TABLE `x` (\n  `a` bigint,\n  CONSTRAINT `f` FOREIGN KEY (`a`) REFERENCES `portal`.`users` (`id`)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"],
    'an expression in the column list' => ["INSERT INTO `migrations` (`id`, (SELECT 1)) VALUES (3,'c');"],
    'a drop without its create' => ['DROP TABLE IF EXISTS `rehearsal_marker`;'],
    'a create without its drop' => ["CREATE TABLE `y` (\n  `a` int\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;"],
    'client command in a versioned comment' => ['/*!999999 system ls */;'],
    'shell escape inside a CREATE TABLE body' => ["CREATE TABLE `x` (\n  `a` int \\! touch /tmp/pwned\n) ENGINE=InnoDB;"],
    'shell escape between rows' => ["INSERT INTO `migrations` (`id`, `migration`) VALUES (3,'c')\\! id;"],
    'a second sandbox line later in the file' => [DumpReader::SANDBOX_LINE],
]);

it('cannot be made to disagree with the server about where a string ends (review H1)', function () {
    // With NO_BACKSLASH_ESCAPES the server would end the string at \' and run the DROP; the reader must refuse the SET first.
    $smuggle = "/*!40101 SET SQL_MODE='NO_BACKSLASH_ESCAPES' */;\n"
        ."INSERT INTO `migrations` (`id`, `migration`) VALUES (3,'\\'); DROP TABLE other.precious; -- ');\n";
    $summary = DumpReader::read(dumpFile(minimalTables().$smuggle));

    expect(implode(' ', $summary['violations']))->toContain('unexpected versioned statement');
});

it('cannot be split differently by a comment inside a statement (closure review H1)', function (string $opener) {
    // The server skips (or reads as a comment) what the reader would take as the start of a string, so `);` would end the
    // CREATE for the server only, and the DROP would run against another database.
    $smuggle = "DROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (\n  `a` int {$opener} '\n); DROP TABLE other.precious; CREATE TABLE other.smuggled (x int); -- '\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n";
    $summary = DumpReader::read(dumpFile(minimalTables().$smuggle));

    expect(implode(' ', $summary['violations']))->toContain('a comment inside a statement');
})->with([
    'a version-gated comment the server skips' => ['/*M!999999 \' */'],
    'a version-gated comment the server runs' => ['/*!40101 \' */'],
    'a block comment' => ['/* \' */'],
    'a line comment after a vertical tab' => ["--\x0b"],
    'a line comment after a space' => ['-- '],
    'a hash comment' => ['#'],
]);

it('refuses a control character outside quoted values', function () {
    $summary = DumpReader::read(dumpFile(minimalTables()."DROP TABLE IF EXISTS `t`;\nCREATE TABLE `t` (\n  `a`\x0bint\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"));

    expect(implode(' ', $summary['violations']))->toContain('a control character outside a quoted value');
});

it('accepts a session line only under the version gate mariadb-dump writes it with (closure review)', function (string $line) {
    // A gate above the server's version makes the server skip the line, so the reader must not count it.
    $summary = DumpReader::read(dumpFile($line."\n".minimalTables(), sqlMode: false));

    expect(implode(' ', $summary['violations']))->toContain('unexpected versioned statement')->toContain("before the dump's SQL_MODE line");
})->with([
    'SQL mode under a gate no server reaches' => ['/*M!999999 '.DumpReader::SQL_MODE.' */;'],
    'SQL mode under another real gate' => ['/*!40103 '.DumpReader::SQL_MODE.' */;'],
]);

it('refuses a table statement before the dump fixes the SQL mode', function () {
    expect(implode(' ', DumpReader::read(dumpFile(minimalTables(), sqlMode: false))['violations']))->toContain('before the dump\'s SQL_MODE line');
});

it('keeps a stable users digest that ignores only the remember-me token', function () {
    $users = fn (string $token, string $name = 'A') => "DROP TABLE IF EXISTS `users`;\nCREATE TABLE `users` (\n  `id` int,\n  `name` varchar(9),\n  `remember_token` varchar(100)\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;\n"
        ."INSERT INTO `users` (`id`, `name`, `remember_token`) VALUES (1,'{$name}',{$token}),(2,'B',NULL);\n";
    $a = DumpReader::read(dumpFile(minimalTables().$users("'tok'")))['tables']['users'];
    $b = DumpReader::read(dumpFile(minimalTables().$users('NULL')))['tables']['users'];
    $c = DumpReader::read(dumpFile(minimalTables().$users('NULL', 'Z')))['tables']['users'];

    expect($a['stable_sha256'])->toBe($b['stable_sha256'])
        ->and($a['rows_sha256'])->not->toBe($b['rows_sha256'])
        ->and($c['stable_sha256'])->not->toBe($b['stable_sha256'])
        ->and(DumpReader::read(dumpFile(minimalTables()))['tables']['tickets'])->not->toHaveKey('stable_sha256');
});

it('rejects an INSERT whose rows do not have one value per column', function () {
    $summary = DumpReader::read(dumpFile(minimalTables("(1,'TK-ABCDE-FGHJK',3)")));

    expect($summary['violations'])->not->toBe([]);
});

it('treats a dump without its completion trailer as incomplete', function () {
    expect(DumpReader::read(dumpFile(minimalTables(), trailer: false))['trailer'])->toBeFalse();
});

it('treats a dump with statements after the trailer as incomplete', function () {
    $path = dumpFile(minimalTables());
    file_put_contents($path, "INSERT INTO `tickets` (`id`, `ticket_number`) VALUES (3,'TK-ZZZZZ-ZZZZZ');\n", FILE_APPEND);

    expect(DumpReader::read($path)['trailer'])->toBeFalse();
});

it('flags a dump truncated inside a statement or a string', function (int $cut) {
    $full = (string) file_get_contents(dumpFile(minimalTables()));
    $path = dumpFile('');
    $offset = strpos($full, "'tickets/2/b.pdf'") + $cut;
    file_put_contents($path, substr($full, 0, $offset));
    $summary = DumpReader::read($path);

    expect($summary['violations'])->not->toBe([])->and($summary['trailer'])->toBeFalse();
})->with(['inside a string' => [5], 'after a value' => [18]]);

it('reads a gzip-compressed dump the same as the plain one', function () {
    $plain = dumpFile(minimalTables());
    file_put_contents($plain.'.gz', gzencode((string) file_get_contents($plain)));
    register_shutdown_function(fn () => @unlink($plain.'.gz'));

    expect(DumpReader::read($plain.'.gz'))->toBe(DumpReader::read($plain));
});

it('never quotes row values in its messages', function () {
    $summary = DumpReader::read(dumpFile(minimalTables("(1,'secret-customer-value',9)")));

    expect(implode(' ', $summary['violations']))->not->toContain('secret-customer-value');
});
