<?php

require_once dirname(__DIR__, 2).'/Support/source_scan.php';

use App\Models\Organization;
use Illuminate\Database\Eloquent\MassAssignmentException;

/*
 * Release 1.0 (ORG-2, CP6 F-7): the Organization's mass-assignment contract is the restrictive ORG-1 one, `['name', 'slug']`. ORG-2 widened it to the whole Directory profile
 * and the CP2-CP5 review required it restored: a generic `create()`/`fill()` path must never be able to reach a profile column, `classification`, `created_by`, the portal
 * state, or a relationship column. Those are written by App\Services\OrganizationDirectory, by explicit column, after its authority checks.
 *
 * Three halves: the declared list is exactly that; filling the model with every other column does nothing (or refuses); and no application code mass-assigns an Organization
 * through the model's own `create`/`fill`/`update` with a request array (a source scan, with the limits stated below).
 *
 * WHAT THE SCAN CANNOT SEE: an assignment through a variable alias of the model, a relation's `create`, or a `forceFill`/unguarded block (the latter two are searched by name,
 * below, but only for the Organization class by name). The declared-list and behavioural halves are what actually hold the line; the scan catches the obvious regressions.
 */

const ORGANIZATION_INTERNAL_COLUMNS = [
    'website', 'phone', 'address', 'notes', 'classification', 'created_by', 'owner_id',
    'portal_enabled_at', 'portal_enabled_by', 'disabled_at', 'can_manage_customers', 'managed_by_organization_id',
];

it('declares exactly name and slug as mass assignable', function () {
    expect((new Organization)->getFillable())->toBe(['name', 'slug']);
});

it('ignores every profile, internal, portal-state and relationship column when the model is filled', function () {
    $attributes = array_fill_keys(ORGANIZATION_INTERNAL_COLUMNS, 'forged');

    try {
        $organization = (new Organization)->fill([...$attributes, 'name' => 'Allowed', 'slug' => 'allowed']);
    } catch (MassAssignmentException) {
        // A strict environment refuses instead of discarding. Either is the contract: nothing reaches the model.
        expect(true)->toBeTrue();

        return;
    }

    expect($organization->getAttributes())->toBe(['name' => 'Allowed', 'slug' => 'allowed']);
});

it('is not mass-assigned with request data anywhere in the application', function () {
    $offenders = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator(app_path(), FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $code = guardStrip(file_get_contents($file->getPathname()));
        $relative = str_replace('\\', '/', substr($file->getPathname(), strlen(app_path()) + 1));

        foreach ([
            '/\bOrganization::(?:create|forceCreate|firstOrCreate|updateOrCreate|insert|upsert)\(/' => 'static create',
            '/\bOrganization::query\(\)->(?:create|forceCreate|firstOrCreate|updateOrCreate)\(/' => 'query create',
            '/\bOrganization::unguarded\(|\bOrganization::unguard\(/' => 'unguarded',
            '/\$(?:organization|org|customer|partner)\w*->(?:fill|forceFill)\(\s*\$(?:request|data|input|validated|attributes)/' => 'fill with request data',
            '/\$(?:organization|org|customer|partner)\w*->update\(\s*\$(?:request|data|input|validated|attributes)/' => 'update with request data',
        ] as $pattern => $name) {
            if (preg_match($pattern, $code) === 1) {
                $offenders[] = "{$relative}: {$name}";
            }
        }
    }

    expect($offenders)->toBe([], 'the Organization is mass-assigned: '.implode('; ', $offenders));
});
