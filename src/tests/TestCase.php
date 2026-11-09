<?php

namespace Tests;

use App\Support\TestDatabaseSafety;
use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

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

        // The database session driver garbage-collects expired sessions with a 2% lottery, which adds
        // one `delete from sessions ...` to a random HTTP request. Tests that count queries compare two
        // measurements exactly, so that random query made them fail about one run in ten (hosted
        // `main` CI after EPIC-015 WP5: operator report 16 -> 17, /time page 9 -> 8, with no code
        // change on either path). Session GC is framework behaviour no test here depends on, so it is
        // off for every test; the session read and write queries still count, equally, in both shapes.
        config(['session.lottery' => [0, 100]]);
    }

    /**
     * Model an authorized operator granting the built-in `user` role's audience extra permissions, for a suite whose
     * subject is a feature a customer reaches only by such a grant (R1-1: `time.log`, `billing.view` and the other retired
     * defaults are no longer built in). Call it after seedRolesAndPermissions(); it changes the test database's `user`
     * role only, never the code's definition.
     */
    protected function grantUserRole(string ...$permissions): void
    {
        Role::findByName('user', 'web')->givePermissionTo($permissions);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
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
