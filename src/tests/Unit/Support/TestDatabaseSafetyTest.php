<?php

use App\Support\TestDatabaseSafety;

test('the designated test environment and database are accepted', function () {
    expect(fn () => TestDatabaseSafety::assertSafe(
        'testing',
        TestDatabaseSafety::DATABASE,
    ))->not->toThrow(RuntimeException::class);
});

test('the development database is rejected in the testing environment', function () {
    expect(fn () => TestDatabaseSafety::assertSafe('testing', 'portal'))
        ->toThrow(RuntimeException::class, 'DB_DATABASE="portal"');
});

test('the test database is rejected outside the testing environment', function () {
    expect(fn () => TestDatabaseSafety::assertSafe('local', TestDatabaseSafety::DATABASE))
        ->toThrow(RuntimeException::class, 'APP_ENV="local"');
});
