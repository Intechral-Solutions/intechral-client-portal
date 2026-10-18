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
