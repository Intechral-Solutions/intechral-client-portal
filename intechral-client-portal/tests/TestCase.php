<?php

namespace Tests;

use Database\Seeders\PermissionSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
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
