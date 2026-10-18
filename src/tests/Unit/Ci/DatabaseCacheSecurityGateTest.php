<?php

require_once __DIR__.'/../../Support/verify_database_cache_security.php';

/*
 * R0.9 WP2: the CI gate that makes the database-store permission-cache tests mandatory must itself fail when a scenario is
 * missing, under-counted, skipped, failed or errored, when the report is empty, and when the tests did not come from the
 * expected file. Each case below builds a JUnit report shaped like Pest's and runs the same function CI runs.
 */

/** A report in which every required scenario ran as many times as required. */
function gateReport(array $override = [], array $drop = [], string $file = 'tests/Feature/Admin/PermissionCacheDatabaseStoreTest.php', array $extraCases = []): string
{
    $cases = [];
    foreach (REQUIRED_SCENARIOS as $code => [$minimum]) {
        if (in_array($code, $drop, true)) {
            continue;
        }
        $count = $override[$code] ?? $minimum;
        for ($index = 1; $index <= $count; $index++) {
            $cases[] = ['name' => "group → it $code: a scenario with data set \"#$index\"", 'child' => ''];
        }
    }
    $cases = [...$cases, ...$extraCases];

    $xml = '<?xml version="1.0"?><testsuites><testsuite name="x">';
    foreach ($cases as $case) {
        $xml .= '<testcase name="'.htmlspecialchars($case['name']).'" classname="Tests.Feature.Admin.'.basename($file, '.php').'" file="'.$file.'">'.$case['child'].'</testcase>';
    }

    return $xml.'</testsuite></testsuites>';
}

it('accepts a report in which every required scenario passed', function () {
    $result = verifyDatabaseCacheSecurityReport(gateReport());

    expect($result['problems'])->toBe([])
        ->and($result['cases'])->toBe(array_sum(array_column(REQUIRED_SCENARIOS, 0)));
});

it('requires the negative control and the stale-reader scenario by name, not just a number of tests', function () {
    expect(verifyDatabaseCacheSecurityReport(gateReport(drop: ['DB-A-control']))['problems'])->toHaveCount(1)
        ->and(verifyDatabaseCacheSecurityReport(gateReport(drop: ['DB-A']))['problems'][0])->toContain('MISSING: DB-A ran and passed 0');
});

it('does not let DB-A-control stand in for DB-A, nor the reverse', function () {
    $onlyControl = gateReport(override: ['DB-A' => 0, 'DB-A-control' => 3]);

    expect(verifyDatabaseCacheSecurityReport($onlyControl)['problems'][0])->toContain('MISSING: DB-A ran and passed 0');
});

it('notices a dataset variant that no longer exists', function () {
    $problems = verifyDatabaseCacheSecurityReport(gateReport(override: ['DB-D' => 3]))['problems'];

    expect($problems)->toHaveCount(1)->and($problems[0])->toContain('MISSING: DB-D ran and passed 3 time(s), 4 required');
});

it('fails on a skipped, failed or errored test case', function (string $child, string $expected) {
    $report = gateReport(extraCases: [['name' => 'group → it DB-A: extra', 'child' => $child]]);

    expect(verifyDatabaseCacheSecurityReport($report)['problems'])->toContain($expected);
})->with([
    'skipped' => ['<skipped/>', 'SKIPPED: group → it DB-A: extra'],
    'failed' => ['<failure>boom</failure>', 'FAILED: group → it DB-A: extra'],
    'errored' => ['<error>boom</error>', 'FAILED: group → it DB-A: extra'],
]);

it('does not count a skipped or failed run of a scenario as a pass', function () {
    $report = gateReport(override: ['DB-P' => 0], extraCases: [['name' => 'preconditions → it DB-P: skipped', 'child' => '<skipped/>']]);

    expect(verifyDatabaseCacheSecurityReport($report)['problems'])->toContain('SKIPPED: preconditions → it DB-P: skipped')
        ->and(implode("\n", verifyDatabaseCacheSecurityReport($report)['problems']))->toContain('MISSING: DB-P ran and passed 0');
});

it('fails on an empty report, an unreadable report and tests from another file', function () {
    expect(verifyDatabaseCacheSecurityReport('<testsuites/>')['problems'][0])->toContain('no test cases')
        ->and(verifyDatabaseCacheSecurityReport('not xml')['problems'])->toBe(['the JUnit report is not valid XML'])
        ->and(implode("\n", verifyDatabaseCacheSecurityReport(gateReport(file: 'tests/Feature/Admin/PermissionCacheEpochTest.php'))['problems']))->toContain('UNEXPECTED FILE');
});

it('exits non-zero from the command line exactly when there are problems', function () {
    $script = base_path('tests/Support/verify_database_cache_security.php');
    $good = tempnam(sys_get_temp_dir(), 'gate');
    $bad = tempnam(sys_get_temp_dir(), 'gate');
    file_put_contents($good, gateReport());
    file_put_contents($bad, gateReport(drop: ['DB-F2']));

    try {
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($good).' 2>&1', $goodOutput, $goodCode);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' '.escapeshellarg($bad).' 2>&1', $badOutput, $badCode);
        exec(escapeshellarg(PHP_BINARY).' '.escapeshellarg($script).' /nonexistent 2>&1', $missingOutput, $missingCode);
    } finally {
        @unlink($good);
        @unlink($bad);
    }

    expect($goodCode)->toBe(0)
        ->and(implode("\n", $goodOutput))->toContain('all present and passed')
        ->and($badCode)->toBe(1)
        ->and(implode("\n", $badOutput))->toContain('MISSING: DB-F2')
        ->and($missingCode)->toBe(2);
});

/*
 * The exclusion boundary. The whole-suite CI step runs `--exclude-group=database-cache-security` so the database-store scenarios
 * run once, in their own mandatory step. That is only safe while (1) the group names exactly the one test file, and (2) the
 * dedicated step, with --fail-on-skipped and the verifier, is still in the workflow. Plain string checks, no YAML parser.
 */

/** @return list<string> repo-relative paths of the test files that declare the group */
function gateGroupDeclarers(string $testsRoot): array
{
    $found = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($testsRoot, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && preg_match('/group\(\s*[\'"]database-cache-security[\'"]/', (string) file_get_contents($file->getPathname())) === 1) {
            $found[] = 'tests/'.ltrim(substr($file->getPathname(), strlen($testsRoot)), '/');
        }
    }
    sort($found);

    return $found;
}

/** @return list<string> */
function gateWorkflowProblems(string $workflow): array
{
    // A YAML comment (a whole line, or after whitespace) disables what it covers.
    $lines = array_filter(
        array_map(fn (string $line) => trim((string) preg_replace('/(^|\s)#.*$/', '', $line)), explode("\n", $workflow)),
        fn (string $line) => $line !== '',
    );
    $has = fn (string $pattern) => array_filter($lines, fn (string $line) => preg_match($pattern, $line) === 1) !== [];
    $problems = [];

    if (! $has('/vendor\/bin\/pest\s+tests\/Feature\/Admin\/PermissionCacheDatabaseStoreTest\.php\b.*--fail-on-skipped\b/')) {
        $problems[] = 'the dedicated database-store Pest invocation (with --fail-on-skipped) is missing';
    }
    if (! $has('/php\s+tests\/Support\/verify_database_cache_security\.php\b/')) {
        $problems[] = 'the JUnit scenario verifier is not run';
    }

    return $problems;
}

it('declares the database-cache-security group in exactly one test file', function () {
    expect(gateGroupDeclarers(base_path('tests')))->toBe(['tests/Feature/Admin/PermissionCacheDatabaseStoreTest.php']);
});

it('keeps the dedicated database-store step and verifier in the workflow whose whole-suite step excludes the group', function () {
    // The development container mounts only src/, where the workflow is not visible. Hosted CI has the whole checkout, and
    // sets CI, so there the file must exist: the guard cannot go vacuous where it matters.
    $path = base_path('../.github/workflows/ci.yml');
    if (! is_file($path)) {
        expect(getenv('CI'))->toBeIn([false, '', '0'], 'hosted CI must be able to read .github/workflows/ci.yml');

        return;
    }
    $workflow = (string) file_get_contents($path);

    expect(gateWorkflowProblems($workflow))->toBe([])
        ->and($workflow)->toContain('--exclude-group=database-cache-security');
});

it('notices a second declarer of the group, and a workflow that lost the dedicated step or the verifier', function () {
    $root = sys_get_temp_dir().'/gate-groups-'.bin2hex(random_bytes(3));
    mkdir($root.'/Feature', 0777, true);
    $group = 'database-'.'cache-security'; // built, so this file never declares the group itself
    file_put_contents($root.'/Feature/A.php', "<?php\nuses()->group('$group');\n");
    file_put_contents($root.'/Feature/B.php', "<?php\npest()->group(\"$group\")->in(__DIR__);\n");
    file_put_contents($root.'/Feature/C.php', "<?php\n// no group here\n");

    try {
        $declarers = gateGroupDeclarers($root);
    } finally {
        array_map('unlink', glob($root.'/Feature/*.php'));
        rmdir($root.'/Feature');
        rmdir($root);
    }

    $good = "      - run: ./vendor/bin/pest tests/Feature/Admin/PermissionCacheDatabaseStoreTest.php --fail-on-skipped --log-junit x\n"
        ."      - run: php tests/Support/verify_database_cache_security.php x\n"
        ."      - run: ./vendor/bin/pest --exclude-group=database-cache-security\n";

    expect($declarers)->toBe(['tests/Feature/A.php', 'tests/Feature/B.php'])
        ->and(gateWorkflowProblems($good))->toBe([])
        ->and(gateWorkflowProblems(str_replace('--fail-on-skipped ', '', $good)))->toHaveCount(1)
        ->and(gateWorkflowProblems(str_replace('php tests/Support/verify_database_cache_security.php', '# php tests/Support/verify_database_cache_security.php', $good)))->toBe(['the JUnit scenario verifier is not run'])
        ->and(gateWorkflowProblems("      - run: ./vendor/bin/pest --exclude-group=database-cache-security\n"))->toHaveCount(2);
});
