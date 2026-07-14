<?php

use App\Shared\Permissions\PermissionCatalogue;

describe('PermissionCatalogue', function () {

    it('returns a non-empty list of all permissions', function () {
        $all = PermissionCatalogue::all();

        expect($all)->toBeArray()->not->toBeEmpty();
    });

    it('includes all expected modules in the permission list', function () {
        $all = PermissionCatalogue::all();

        $expectedModules = ['tickets', 'projects', 'billing', 'time', 'crm', 'cms', 'users', 'roles', 'settings', 'org'];

        foreach ($expectedModules as $module) {
            $hasModule = collect($all)->contains(fn ($p) => str_starts_with($p, $module.'.'));
            expect($hasModule)->toBeTrue("Expected permissions for module '{$module}' to exist");
        }
    });

    it('user defaults are a subset of all permissions', function () {
        $all = PermissionCatalogue::all();
        $defaults = PermissionCatalogue::userDefaults();

        foreach ($defaults as $permission) {
            expect($all)->toContain($permission);
        }
    });

    it('user defaults do not include admin-level permissions', function () {
        $defaults = PermissionCatalogue::userDefaults();

        $adminPerms = collect($defaults)->filter(fn ($p) => str_ends_with($p, '.admin'));

        expect($adminPerms)->toBeEmpty('User defaults should not include .admin permissions');
    });

    it('permissions follow the module.action naming convention', function () {
        foreach (PermissionCatalogue::all() as $permission) {
            expect($permission)->toMatch('/^[a-z_]+\.[a-z_]+$/',
                "Permission '{$permission}' does not match 'module.action' convention"
            );
        }
    });

    it('has no duplicate permissions', function () {
        $all = PermissionCatalogue::all();

        expect(array_unique($all))->toHaveCount(count($all));
    });
});
