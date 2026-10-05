<?php

/*
 * Query-budget tests compare exact query counts across HTTP requests, so the harness must not add
 * random queries. The database session driver's garbage-collection lottery (`delete from sessions`,
 * 2% per request in config/session.php) is such a query; TestCase turns it off.
 */
it('keeps session garbage collection out of query-counting tests', function () {
    expect(config('session.driver'))->toBe('database')
        ->and(config('session.lottery'))->toBe([0, 100]);
});
