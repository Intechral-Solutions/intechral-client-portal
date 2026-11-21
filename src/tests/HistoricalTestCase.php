<?php

namespace Tests;

use App\Support\Rehearsal\RehearsalTarget;
use App\Support\Rehearsal\TargetGuard;
use Illuminate\Database\Eloquent\Factories\Factory;
use Tests\Support\HistoricalSchema;
use Tests\Support\RehearsalSandbox;

/**
 * Release 1.0 (ORG-2, CP1): the base of every test under `tests/Historical`. These tests run the application, or a migration, against a HISTORICAL
 * schema on the disposable rehearsal database (see {@see HistoricalSchema}), never against the shared testing database: they do not use
 * RefreshDatabase, and the application's default connection is the rehearsal primary for the whole test.
 *
 * The boot-time guard (AppServiceProvider) and {@see TestCase::refreshApplication()} still run first, against the testing database the process is
 * configured for, exactly as for every other test. Only then is the default connection redirected, and only to the one target
 * {@see TargetGuard} accepts. A test that wants a schema calls `HistoricalSchema::use(...)` in `beforeEach`.
 *
 * The rehearsal lock is held for the whole process ({@see RehearsalSandbox::use()}), so a second Pest process, `./dev rehearse` or a parallel worker is
 * refused rather than rebuilding the database under a running test; storage is moved to a throw-away directory so the harness's file handling never
 * touches the real one.
 */
abstract class HistoricalTestCase extends TestCase
{
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $target = RehearsalTarget::primary();
        TargetGuard::assertConfiguration($target);   // configuration only: the live guard runs before every destructive step (HistoricalSchema::use)

        // Every test boots a new application, so the redirect never outlives it.
        config(['database.default' => $target->connectionName()]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        RehearsalSandbox::use();

    }

    protected function tearDown(): void
    {
        // What RefreshDatabase does at the end of a Feature test: whatever transaction the test left open is rolled back.
        if ($this->app !== null && in_array(RehearsalTarget::primary()->connectionName(), array_keys($this->app['db']->getConnections()), true)) {
            $connection = $this->app['db']->connection(RehearsalTarget::primary()->connectionName());

            while ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        }

        // Back to discovery by the application's own convention for every other test in the process.
        Factory::flushState();

        parent::tearDown();
    }
}
