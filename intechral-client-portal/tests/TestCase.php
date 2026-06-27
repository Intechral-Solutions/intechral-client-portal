<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application and enforce the test-database safety guard before
     * RefreshDatabase can wipe any tables.
     *
     * RefreshDatabase is triggered inside setUpTraits(), which runs after
     * refreshApplication(). Placing the guard here — after the app boots but
     * before setUpTraits() fires — ensures it intercepts every test run.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database   = (string) config("database.connections.{$connection}.database");

        if (! str_contains($database, 'testing')) {
            throw new RuntimeException(
                "Test safety guard rejected database \"{$database}\". " .
                "The active database name must contain \"testing\" to prevent " .
                "RefreshDatabase from wiping a non-test database. " .
                "Check DB_DATABASE in phpunit.xml or .env.testing."
            );
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        // Laravel 13 uses PreventRequestForgery for CSRF — disable in tests
        $this->withoutMiddleware(PreventRequestForgery::class);
    }

    /**
     * Seed permissions and roles — call in tests that need role/permission checks.
     */
    protected function seedRolesAndPermissions(): void
    {
        $this->seed(PermissionSeeder::class);
        $this->seed(RoleSeeder::class);
    }
}
