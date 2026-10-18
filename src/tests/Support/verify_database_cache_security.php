<?php

/*
 * CI gate for the permission-cache security tests on the DATABASE cache store (R0.9 WP2).
 *
 *   php tests/Support/verify_database_cache_security.php <junit.xml>
 *
 * `pest --fail-on-skipped` already fails a run that skips a test. It cannot notice a test, or one dataset variant of it,
 * that no longer exists: deleting a scenario would leave a smaller, green run. This reads the JUnit report of that run and
 * exits non-zero unless every required scenario (below) ran at least as many times as listed, in
 * tests/Feature/Admin/PermissionCacheDatabaseStoreTest.php, and no test case in the report was skipped, failed or errored.
 *
 * Adding a scenario or a dataset variant: add it here, with the number of cases it produces. Removing one is a security
 * decision and has to be made here too, in review.
 */

const REQUIRED_FILE = 'PermissionCacheDatabaseStoreTest';

/** scenario code => [minimum cases, what it proves] */
const REQUIRED_SCENARIOS = [
    'DB-P' => [1, 'pinned server, profile and engine'],
    'DB-A' => [2, 'stale reader cannot restore a revoked permission; a later reader sees the revocation (both layouts)'],
    'DB-A-control' => [1, 'negative control: the unfenced registrar loses the same race'],
    'DB-B' => [2, 'readers after the commit use the new epoch (both layouts)'],
    'DB-J' => [1, 'a torn read fills only the obsolete epoch'],
    'DB-C1' => [2, 'rollback leaves committed grants and epoch (both layouts)'],
    'DB-C2' => [1, 'rollback through the application hooks'],
    'DB-E1' => [2, "the writer's own cache write commits with the transaction (both layouts)"],
    'DB-E2' => [1, 'KeyWriteFailed versus KeyWritten for identical upserts'],
    'DB-D' => [4, 'consecutive revocations, stale readers out of order (two orders, both layouts)'],
    'DB-F1' => [2, 'miss during an open writer transaction, store after the commit (both layouts)'],
    'DB-F2' => [2, 'miss during an open writer transaction, store before the commit (both layouts)'],
    'DB-F3' => [2, 'entry stored during a transaction that rolls back (both layouts)'],
    'DB-G1' => [1, 'persistent worker across an open and a committed revocation'],
    'DB-G2' => [1, 'control: no job-boundary reset keeps granting'],
    'DB-G3' => [1, 'reader inside a REPEATABLE READ transaction'],
    'DB-L1' => [1, 'epoch read never blocks and never shows the uncommitted epoch'],
    'DB-L3' => [1, 'same-key cache write waits for a loading writer'],
];

/**
 * @return array{cases: int, problems: list<string>}
 */
function verifyDatabaseCacheSecurityReport(string $junitXml): array
{
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($junitXml);
    if ($xml === false) {
        return ['cases' => 0, 'problems' => ['the JUnit report is not valid XML']];
    }

    $problems = [];
    $passed = array_fill_keys(array_keys(REQUIRED_SCENARIOS), 0);
    $total = 0;

    foreach ($xml->xpath('//testcase') ?: [] as $case) {
        $name = (string) $case['name'];
        $where = (string) $case['classname'].' '.(string) $case['file'];
        $total++;

        $broken = false;
        if (isset($case->skipped)) {
            $problems[] = "SKIPPED: $name";
            $broken = true;
        }
        if (isset($case->failure) || isset($case->error)) {
            $problems[] = "FAILED: $name";
            $broken = true;
        }
        if (! str_contains($where, REQUIRED_FILE)) {
            $problems[] = 'UNEXPECTED FILE in the report (expected only '.REQUIRED_FILE."): $where";
        }
        if ($broken) {
            continue;
        }

        foreach (array_keys(REQUIRED_SCENARIOS) as $code) {
            if (preg_match('/(^|[^A-Za-z0-9-])'.preg_quote($code, '/').':/', $name) === 1) {
                $passed[$code]++;
            }
        }
    }

    if ($total === 0) {
        $problems[] = 'the report contains no test cases (the file did not run, or MariaDB was unavailable before any test started)';
    }

    foreach (REQUIRED_SCENARIOS as $code => [$minimum, $proves]) {
        if ($passed[$code] < $minimum) {
            $problems[] = sprintf('MISSING: %s ran and passed %d time(s), %d required (%s)', $code, $passed[$code], $minimum, $proves);
        }
    }

    return ['cases' => $total, 'problems' => array_values(array_unique($problems))];
}

if (PHP_SAPI === 'cli' && isset($argv[0]) && realpath($argv[0]) === __FILE__) {
    if ($argc !== 2 || ! is_file($argv[1])) {
        fwrite(STDERR, "usage: php verify_database_cache_security.php <junit.xml>\n");
        exit(2);
    }

    ['cases' => $cases, 'problems' => $problems] = verifyDatabaseCacheSecurityReport((string) file_get_contents($argv[1]));
    if ($problems !== []) {
        fwrite(STDERR, "Database-store permission-cache security gate FAILED:\n  - ".implode("\n  - ", $problems)."\n");
        exit(1);
    }

    printf("Database-store permission-cache security gate: %d test cases, %d required scenarios, all present and passed, none skipped.\n", $cases, count(REQUIRED_SCENARIOS));
}
