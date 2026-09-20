<?php

namespace Tests;

use App\Support\TestDatabaseSafety;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Enforce the test-database safety contract again immediately before
     * RefreshDatabase can wipe any tables.
     *
     * RefreshDatabase is triggered inside setUpTraits(), which runs after
     * refreshApplication(). AppServiceProvider guards all testing bootstraps;
     * this second check keeps the destructive test boundary explicit.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        $connection = config('database.default');
        $database = (string) config("database.connections.{$connection}.database");

        TestDatabaseSafety::assertSafe($this->app->environment(), $database);
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
