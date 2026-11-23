<?php

require_once dirname(__DIR__, 2).'/Support/source_scan.php';

/*
 * Release 1.0 (ORG-2; ADR-011 section 6, invariant S12): the Classification of an Organization (general, client, vendor) is DESCRIPTIVE ONLY. It must never
 * be an authority input: no policy, gate, scope, middleware, authority service or lifecycle decision may read it. This is the source-level half of that proof
 * (the behavioural half is tests/Feature/Crm/OrganizationClassificationTest.php, which runs every value through every decision).
 *
 * WHAT IT SCANS: every PHP file under app/, comments removed, for the name `classification` and the enum `Classification`. A file that mentions either must
 * be on the allowlist below, each with why it only describes: the Directory service that writes it, the model that declares it, the enum itself, the forms and
 * pages that show it, the integrity checks that validate the catalogue, the migration support. An AUTHORITY file (a policy, scope, gate, middleware, or one of
 * the services that decide membership, invitation, relationship, support or request filing) is never on it, and the test names those files and requires them
 * to be free of the word. WHAT IT CANNOT SEE: a read through a variable key (`$row->{$column}`), the column named inside a JSON blob, or a read from a view or
 * a test (only app/ is scanned). The behavioural matrix covers what a scan cannot.
 */

/** @return array<string, string> file relative to app/ => why it may name the Classification */
function classificationAllowlist(): array
{
    return [
        'Support/Organizations/Classification.php' => 'the enum: the one catalogue',
        'Models/Organization.php' => 'declares the column: the cast, and the hidden list that keeps it out of serialization',
        'Services/OrganizationDirectory.php' => 'the one writer: validates against the catalogue and records old and new values',
        'Services/OrganizationPreflight.php' => 'the read-only check that every stored value is in the catalogue',
        'Console/Commands/OrganizationPreflightCommand.php' => 'prints that check',
        'Support/Rehearsal/IntegritySnapshot.php' => 'the integrity check that every stored value is in the catalogue',
        'Http/Presenters/OrganizationWorkspacePresenter.php' => 'the workspace header shows the Classification label as an overline (ORG-3b): a description for an actor who already passed manageDirectory, read by no decision',
    ];
}

/** @return list<string> files that decide authority, and so must never name it */
function classificationAuthorityFiles(): array
{
    return [
        'Policies/OrganizationPolicy.php', 'Policies/ProjectPolicy.php', 'Policies/TaskPolicy.php', 'Policies/TicketPolicy.php',
        'Shared/Scopes/OrganizationMembershipScope.php', 'Shared/Scopes/DirectoryOperatorScope.php',
        'Shared/Pilot/CustomerPilotGate.php',
        'Services/OrganizationAuthority.php', 'Services/OrganizationMembership.php', 'Services/OrganizationInvitations.php',
        'Services/OrganizationRelationships.php', 'Services/PartnerSupportAccess.php', 'Services/PartnerSupportGrants.php', 'Services/ServiceRequestAccess.php',
        'Support/Organizations/PortalState.php',
    ];
}

/** @return list<string> every app/ file (relative) whose code names the Classification */
function classificationNamedIn(): array
{
    $found = [];
    $root = app_path();

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        if (preg_match('/classification/i', guardStrip(file_get_contents($file->getPathname()))) === 1) {
            $found[] = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
        }
    }

    sort($found);

    return $found;
}

it('names the Classification only in the allowlisted files, each of which only describes it', function () {
    $named = classificationNamedIn();
    $allowed = array_keys(classificationAllowlist());

    expect(array_values(array_diff($named, $allowed)))->toBe([], 'a file names the Classification without being reviewed as describing it')
        ->and(array_values(array_diff($allowed, $named)))->toBe([], 'an allowlisted file no longer names it: remove the stale entry');
});

it('keeps every authority file free of the Classification', function () {
    foreach (classificationAuthorityFiles() as $file) {
        expect(is_file(app_path($file)))->toBeTrue("{$file} does not exist: the list of authority files is stale");
        expect(preg_match('/classification/i', guardStrip(file_get_contents(app_path($file)))))->toBe(0, "{$file} reads the Classification");
    }
});

it('does not let the Classification into a route, a middleware or a gate definition', function () {
    foreach (array_merge(glob(base_path('routes/*.php')), glob(app_path('Http/Middleware/*.php')), glob(app_path('Providers/*.php'))) as $file) {
        expect(preg_match('/classification/i', guardStrip(file_get_contents($file))))->toBe(0, basename($file).' names the Classification');
    }
});
